<?php
declare(strict_types=1);

final class Http2ClientConnection
{
    private const FRAME_TYPE_DATA = 0x00;
    private const REQUEST_STREAM_ID = 1;
    private const MAX_FRAMES = 50;

    public function __construct(
        private readonly Logger $logger,
        private readonly ?TlsStreamConnector $tlsConnector,
        private readonly ?H2cSocketConnector $h2cConnector,
        private readonly HpackRequestEncoder $hpackEncoder,
    ) {
    }

    public function runTls(
        string $host,
        int $port,
        string $path,
        ?TlsClientOptions $options = null,
    ): int
    {
        if ($this->tlsConnector === null) {
            throw new LogicException('TlsStreamConnector is required when creating the stream inside Http2ClientConnection::runTls().');
        }

        $options ??= new TlsClientOptions();

        try {
            $connection = $this->tlsConnector->connectWithAlpn($host, $port, $options);
        } catch (Throwable $e) {
            $this->logger->log('[!] ' . $e->getMessage());
            return 1;
        }

        return $this->runWithStream(
            $connection->stream,
            $host,
            $path,
            $connection->negotiatedProtocol,
            true,
            $options->timeoutSec,
        );
    }

    public function runH2c(string $host, int $port, string $path, int $timeoutSec = 2): int
    {
        if ($this->h2cConnector === null) {
            throw new LogicException('H2cSocketConnector is required when creating the socket inside Http2ClientConnection::runH2c().');
        }

        try {
            $transport = $this->h2cConnector->connect($host, $port, $timeoutSec);
        } catch (Throwable $e) {
            $this->logger->log('[!] ' . $e->getMessage());
            return 1;
        }

        try {
            return $this->runWithTransport($transport, $host, $path, null);
        } finally {
            $transport->close();
        }
    }

    public function runWithStream(
        mixed $stream,
        string $host,
        string $path,
        ?string $negotiatedProtocol = null,
        bool $closeStream = false,
        int $timeoutSec = 2,
    ): int {
        $transport = new StreamTransport($stream);
        $transport->configure($timeoutSec);

        $detectedProtocol = $negotiatedProtocol ?? StreamServerSupport::detectNegotiatedProtocol($transport->getMetadata());
        if ($detectedProtocol !== 'h2') {
            $this->logger->log('[!] negotiated ALPN is not h2. Exiting (this demo expects HTTP/2).');
            if ($closeStream) {
                $transport->close();
            }
            return 2;
        }

        try {
            return $this->runWithTransport($transport, $host, $path, $detectedProtocol);
        } finally {
            if ($closeStream) {
                $transport->close();
            }
        }
    }

    private function runWithTransport(
        Http2Transport $transport,
        string $host,
        string $path,
        ?string $negotiatedProtocol,
    ): int {
        if ($negotiatedProtocol !== null && $negotiatedProtocol !== 'h2') {
            $this->logger->log('[!] negotiated ALPN is not h2. Exiting (this demo expects HTTP/2).');
            return 2;
        }

        $protocol = new Http2ClientProtocol($this->hpackEncoder);
        $this->logger->log('>>> sending client preface (24 bytes)');
        $this->logger->log('>>> sending SETTINGS (empty payload)');
        $protocol->initiateConnection();
        $this->flushOutbound($transport, $protocol);

        return $this->handleFrames($transport, $protocol, $host, $path);
    }

    private function handleFrames(
        Http2Transport $transport,
        Http2ClientProtocol $protocol,
        string $host,
        string $path
    ): int
    {
        $requestSent = false;
        $responseDone = false;
        $frameIndex = 0;

        while ($frameIndex < self::MAX_FRAMES) {
            $payload = $transport->readSome(16_384);
            if ($payload === null) {
                $meta = $transport->getMetadata();
                $this->logger->log('no more frames / timeout=' . (!empty($meta['timed_out']) ? 'yes' : 'no'));
                break;
            }

            $events = $protocol->receiveData($payload);
            foreach ($events as $event) {
                if ($event instanceof Http2FrameReceivedEvent) {
                    $this->logFrame($frameIndex, $event->frame);
                    $frameIndex++;
                    continue;
                }

                if ($event instanceof Http2SettingsReceivedEvent && !$requestSent) {
                    $this->logger->log('>>> sending SETTINGS ACK');
                    $this->logger->log(">>> sending HEADERS for GET {$path}");
                    $protocol->sendRequest($host, $path);
                    $requestSent = true;
                    continue;
                }

                if ($event instanceof Http2StreamEndedEvent && $event->streamId === self::REQUEST_STREAM_ID) {
                    $this->logger->log('END_STREAM received on response stream; exiting.');
                    $responseDone = true;
                    break 2;
                }

                if ($event instanceof Http2GoAwayReceivedEvent) {
                    $this->logger->log('GOAWAY received; exiting.');
                    break 2;
                }
            }

            $this->flushOutbound($transport, $protocol);
        }

        if ($frameIndex >= self::MAX_FRAMES) {
            $this->logger->log('[!] frame limit reached');
        }
        $this->logger->log($responseDone ? 'done' : 'done (response may be incomplete)');

        return 0;
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

        if ($frame->length === 0) {
            return;
        }

        if ($frame->type === self::FRAME_TYPE_DATA) {
            $this->logger->log('DATA payload:');
            $this->logger->dumpData($frame->payload);
            return;
        }

        $this->logger->log(HexDumper::dump($frame->payload, 256));
    }

    private function flushOutbound(Http2Transport $transport, Http2ClientProtocol $protocol): void
    {
        $payload = $protocol->dataToSend();
        if ($payload !== '') {
            $transport->write($payload);
        }
    }
}
