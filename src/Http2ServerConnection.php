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
        $requests = [];
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

                if ($event instanceof Http2HeadersReceivedEvent) {
                    $requests[$event->streamId]['headersDone'] = true;
                    $requests[$event->streamId]['ended'] = $event->endStream;
                    continue;
                }

                if ($event instanceof Http2DataReceivedEvent) {
                    if (!isset($requests[$event->streamId])) {
                        throw new RuntimeException('DATA received on unexpected stream');
                    }

                    if ($event->endStream) {
                        $requests[$event->streamId]['ended'] = true;
                    }
                    continue;
                }

                if ($event instanceof Http2StreamEndedEvent) {
                    if (!isset($requests[$event->streamId])) {
                        $requests[$event->streamId] = ['headersDone' => false, 'ended' => true, 'responseSent' => false];
                    } else {
                        $requests[$event->streamId]['ended'] = true;
                    }
                    continue;
                }

                if ($event instanceof Http2GoAwayReceivedEvent) {
                    $this->logger->log('GOAWAY received');
                    return;
                }
            }

            foreach ($requests as $streamId => $state) {
                $headersDone = !empty($state['headersDone']);
                $ended = !empty($state['ended']);
                $streamResponseSent = !empty($state['responseSent']);
                if (!$headersDone || !$ended || $streamResponseSent) {
                    continue;
                }

                $this->logger->log(sprintf('>>> sending HEADERS on stream %d', $streamId));
                $this->logger->log(sprintf('>>> sending DATA on stream %d', $streamId));
                $protocol->sendResponse($streamId, $this->buildResponseHeaders(), 'Hello World');
                $requests[$streamId]['responseSent'] = true;
                $responseSent = true;
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
