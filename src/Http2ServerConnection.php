<?php
declare(strict_types=1);

final class Http2ServerConnection
{
    private const CLIENT_PREFACE = "PRI * HTTP/2.0\r\n\r\nSM\r\n\r\n";
    private const FRAME_TYPE_DATA = 0x00;
    private const FRAME_TYPE_HEADERS = 0x01;
    private const FRAME_TYPE_SETTINGS = 0x04;
    private const FRAME_TYPE_PING = 0x06;
    private const FRAME_TYPE_GOAWAY = 0x07;
    private const FRAME_TYPE_CONTINUATION = 0x09;
    private const FLAG_ACK = 0x01;
    private const FLAG_END_STREAM = 0x01;
    private const FLAG_END_HEADERS = 0x04;
    private const DEFAULT_TIMEOUT_SEC = 5;

    public function __construct(private readonly Logger $logger)
    {
    }

    public function serveStream(mixed $stream, ?string $negotiatedProtocol = null): void
    {
        $this->serveTransport(new StreamTransport($stream), $negotiatedProtocol);
    }

    public function serveTransport(Http2Transport $transport, ?string $negotiatedProtocol = null): void
    {
        $transport->configure(self::DEFAULT_TIMEOUT_SEC);

        if ($negotiatedProtocol !== null && $negotiatedProtocol !== 'h2') {
            throw new RuntimeException('negotiated ALPN is not h2');
        }

        $this->handleConnection($transport);
    }

    private function handleConnection(Http2Transport $transport): void
    {
        $preface = $transport->read(strlen(self::CLIENT_PREFACE));
        if ($preface !== self::CLIENT_PREFACE) {
            $this->logger->log('[!] invalid client preface');
            return;
        }

        $this->logger->log('<<< client preface');
        $writer = new Http2FrameWriter($transport);
        $this->sendSettings($writer);

        $reader = new Http2FrameReader($transport);
        $requestStreamId = null;
        $requestHeadersDone = false;
        $requestEnded = false;
        $responseSent = false;

        for ($index = 0; $index < 100; $index++) {
            $frame = $reader->readFrame();
            if ($frame === null) {
                $this->logger->log($responseSent ? 'response completed' : 'connection closed before request completed');
                return;
            }

            $this->logger->log(sprintf(
                'FRAME #%d len=%d type=0x%02x(%s) flags=0x%02x sid=%d',
                $index,
                $frame->length,
                $frame->type,
                Http2FrameCodec::typeName($frame->type),
                $frame->flags,
                $frame->streamId
            ));

            if ($frame->type === self::FRAME_TYPE_SETTINGS) {
                $this->handleSettings($writer, $frame);
                continue;
            }

            if ($frame->type === self::FRAME_TYPE_PING) {
                $this->handlePing($writer, $frame);
                continue;
            }

            if ($frame->type === self::FRAME_TYPE_GOAWAY) {
                $this->logger->log('GOAWAY received');
                return;
            }

            if ($frame->type === self::FRAME_TYPE_HEADERS) {
                if ($frame->streamId === 0) {
                    $this->logger->log('[!] HEADERS on stream 0 is invalid');
                    return;
                }

                $requestStreamId = $frame->streamId;
                $requestHeadersDone = ($frame->flags & self::FLAG_END_HEADERS) !== 0;
                $requestEnded = ($frame->flags & self::FLAG_END_STREAM) !== 0;

                if (!$requestHeadersDone) {
                    $requestEnded = $this->consumeContinuationFrames($reader, $requestStreamId, $requestEnded);
                    $requestHeadersDone = true;
                }
            } elseif ($frame->type === self::FRAME_TYPE_DATA) {
                if ($requestStreamId === null || $frame->streamId !== $requestStreamId) {
                    $this->logger->log('[!] DATA received on unexpected stream');
                    return;
                }

                if (($frame->flags & self::FLAG_END_STREAM) !== 0) {
                    $requestEnded = true;
                }
            } else {
                $this->logger->log(sprintf('ignoring unsupported frame type 0x%02x', $frame->type));
                continue;
            }

            if ($requestHeadersDone && $requestEnded && $requestStreamId !== null && !$responseSent) {
                $this->sendHelloWorldResponse($writer, $requestStreamId);
                $responseSent = true;
            }
        }

        $this->logger->log('[!] frame limit reached');
    }

    private function handleSettings(Http2FrameWriter $writer, Http2Frame $frame): void
    {
        if (($frame->flags & self::FLAG_ACK) !== 0) {
            if ($frame->length !== 0) {
                $this->logger->log('[!] SETTINGS ACK must have empty payload');
            }
            return;
        }

        $this->logger->log('>>> sending SETTINGS ACK');
        $writer->writeFrame(self::FRAME_TYPE_SETTINGS, self::FLAG_ACK, 0, '');
    }

    private function handlePing(Http2FrameWriter $writer, Http2Frame $frame): void
    {
        if ($frame->length !== 8) {
            $this->logger->log('[!] invalid PING length');
            return;
        }

        if (($frame->flags & self::FLAG_ACK) !== 0) {
            return;
        }

        $this->logger->log('>>> sending PING ACK');
        $writer->writeFrame(self::FRAME_TYPE_PING, self::FLAG_ACK, 0, $frame->payload);
    }

    private function consumeContinuationFrames(Http2FrameReader $reader, int $streamId, bool $requestEnded): bool
    {
        for ($count = 0; $count < 10; $count++) {
            $frame = $reader->readFrame();
            if ($frame === null) {
                throw new RuntimeException('expected CONTINUATION frame');
            }

            $this->logger->log(sprintf(
                'CONTINUATION len=%d flags=0x%02x sid=%d',
                $frame->length,
                $frame->flags,
                $frame->streamId
            ));

            if ($frame->type !== self::FRAME_TYPE_CONTINUATION || $frame->streamId !== $streamId) {
                throw new RuntimeException('invalid CONTINUATION sequence');
            }

            if (($frame->flags & self::FLAG_END_HEADERS) !== 0) {
                return $requestEnded;
            }
        }

        throw new RuntimeException('too many CONTINUATION frames');
    }

    private function sendSettings(Http2FrameWriter $writer): void
    {
        $this->logger->log('>>> sending SETTINGS');
        $writer->writeFrame(self::FRAME_TYPE_SETTINGS, 0x00, 0, '');
    }

    private function sendHelloWorldResponse(Http2FrameWriter $writer, int $streamId): void
    {
        $headers = $this->buildResponseHeaders();
        $body = 'Hello World';

        $this->logger->log(sprintf('>>> sending HEADERS on stream %d', $streamId));
        $writer->writeFrame(self::FRAME_TYPE_HEADERS, self::FLAG_END_HEADERS, $streamId, $headers);

        $this->logger->log(sprintf('>>> sending DATA on stream %d', $streamId));
        $writer->writeFrame(self::FRAME_TYPE_DATA, self::FLAG_END_STREAM, $streamId, $body);
    }

    private function buildResponseHeaders(): string
    {
        return
            "\x88" .
            "\x0f\x10\x0a" . 'text/plain' .
            "\x0f\x0d\x02" . '11';
    }
}
