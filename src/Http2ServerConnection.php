<?php
declare(strict_types=1);

final class Http2ServerConnection
{
    private const DEFAULT_TIMEOUT_SEC = 5;
    private const MAX_FRAMES = 100;

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
        $protocol = new Http2ServerProtocol();
        $responseSent = false;
        $frameIndex = 0;

        while ($frameIndex < self::MAX_FRAMES) {
            $payload = $transport->readSome(16_384);
            if ($payload === null) {
                $this->logger->log($responseSent ? 'response completed' : 'connection closed before request completed');
                return;
            }

            $events = $protocol->receiveData($payload);
            foreach ($events as $event) {
                if ($event instanceof Http2ConnectionPrefaceReceivedEvent) {
                    $this->logger->log('<<< client preface');
                    $this->logger->log('>>> sending SETTINGS');
                    continue;
                }

                if ($event instanceof Http2FrameReceivedEvent) {
                    $this->logFrame($frameIndex, $event->frame);
                    $frameIndex++;

                    if ($event->frame->type !== 0x04 && $event->frame->type !== 0x06 && $event->frame->type !== 0x07 && $event->frame->type !== 0x00 && $event->frame->type !== 0x01 && $event->frame->type !== 0x09) {
                        $this->logger->log(sprintf('ignoring unsupported frame type 0x%02x', $event->frame->type));
                    }
                    continue;
                }

                if ($event instanceof Http2SettingsReceivedEvent) {
                    $this->logger->log('>>> sending SETTINGS ACK');
                    continue;
                }

                if ($event instanceof Http2ProtocolErrorEvent) {
                    $this->logger->log(sprintf(
                        '[!] protocol error: %s (error_code=%d%s)',
                        $event->message,
                        $event->errorCode,
                        $event->streamId !== null ? sprintf(', stream=%d', $event->streamId) : ''
                    ));
                    if ($event->connectionError) {
                        $this->flushOutbound($transport, $protocol);
                        return;
                    }
                    continue;
                }

                if ($event instanceof Http2GoAwayReceivedEvent) {
                    $this->logger->log(sprintf(
                        'GOAWAY received; last_stream_id=%d error_code=%d',
                        $event->lastStreamId,
                        $event->errorCode
                    ));
                    return;
                }

                if ($event instanceof Http2StreamResetEvent) {
                    $this->logger->log(sprintf(
                        'RST_STREAM received on stream %d; error_code=%d',
                        $event->streamId,
                        $event->errorCode
                    ));
                    return;
                }

                if ($event instanceof Http2RequestReceivedEvent) {
                    $this->logger->log(sprintf('>>> sending HEADERS on stream %d', $event->streamId));
                    $this->logger->log(sprintf('>>> sending DATA on stream %d', $event->streamId));
                    $protocol->sendResponse($event->streamId, $this->buildResponseHeaders(), 'Hello World');
                    $responseSent = true;
                    continue;
                }
            }

            $this->flushOutbound($transport, $protocol);
        }

        $this->logger->log('[!] frame limit reached');
    }

    private function buildResponseHeaders(): string
    {
        return
            "\x88" .
            "\x0f\x10\x0a" . 'text/plain' .
            "\x0f\x0d\x02" . '11';
    }

    private function logFrame(int $index, Http2Frame $frame): void
    {
        $this->logger->log(sprintf(
            'FRAME #%d len=%d type=0x%02x(%s) flags=0x%02x sid=%d',
            $index,
            $frame->length,
            $frame->type,
            Http2FrameCodec::typeName($frame->type),
            $frame->flags,
            $frame->streamId
        ));
    }

    private function flushOutbound(Http2Transport $transport, Http2ServerProtocol $protocol): void
    {
        $payload = $protocol->dataToSend();
        if ($payload !== '') {
            $transport->write($payload);
        }
    }
}
