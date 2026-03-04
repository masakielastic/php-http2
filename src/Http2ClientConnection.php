<?php
declare(strict_types=1);

final class Http2ClientConnection
{
    private const H2_ERROR_NO_ERROR = 0x00;
    private const H2_ERROR_PROTOCOL_ERROR = 0x01;
    private const H2_ERROR_INTERNAL_ERROR = 0x02;
    private const H2_ERROR_FLOW_CONTROL_ERROR = 0x03;
    private const H2_ERROR_SETTINGS_TIMEOUT = 0x04;
    private const H2_ERROR_STREAM_CLOSED = 0x05;
    private const H2_ERROR_FRAME_SIZE_ERROR = 0x06;
    private const H2_ERROR_REFUSED_STREAM = 0x07;
    private const H2_ERROR_CANCEL = 0x08;
    private const EXIT_OK = 0;
    private const EXIT_CONNECT_FAILED = 1;
    private const EXIT_ALPN_MISMATCH = 2;
    private const EXIT_PROTOCOL_ERROR = 3;
    private const EXIT_STREAM_RESET = 4;
    private const EXIT_GOAWAY = 5;
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
            return self::EXIT_CONNECT_FAILED;
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
            return self::EXIT_CONNECT_FAILED;
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
            return self::EXIT_ALPN_MISMATCH;
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
            return self::EXIT_ALPN_MISMATCH;
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
        $responseComplete = false;
        $frameIndex = 0;
        $exitCode = self::EXIT_OK;

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

                if ($event instanceof Http2ResponseReceivedEvent && $event->streamId === self::REQUEST_STREAM_ID) {
                    $this->logger->log('END_STREAM received on response stream; exiting.');
                    $responseComplete = true;
                    break 2;
                }

                if ($event instanceof Http2ProtocolErrorEvent) {
                    $this->logger->log(sprintf(
                        '[!] protocol error: %s (error_code=%d%s)',
                        $event->message,
                        $event->errorCode,
                        $event->streamId !== null ? sprintf(', stream=%d', $event->streamId) : ''
                    ));
                    if ($event->connectionError) {
                        $exitCode = $this->exitCodeForProtocolError($event);
                        $this->logRetryGuidanceForProtocolError($event, $host, $path);
                        $this->flushOutbound($transport, $protocol);
                        break 2;
                    }
                    continue;
                }

                if ($event instanceof Http2StreamResetEvent && $event->streamId === self::REQUEST_STREAM_ID) {
                    $this->logger->log(sprintf('RST_STREAM received on response stream; error_code=%d', $event->errorCode));
                    $this->logRetryGuidanceForStreamReset($event, $host, $path);
                    $exitCode = self::EXIT_STREAM_RESET;
                    break 2;
                }

                if ($event instanceof Http2GoAwayReceivedEvent) {
                    $this->logger->log(sprintf(
                        'GOAWAY received; last_stream_id=%d error_code=%d',
                        $event->lastStreamId,
                        $event->errorCode
                    ));
                    $this->logRetryGuidanceForGoAway($event, $host, $path);
                    $exitCode = self::EXIT_GOAWAY;
                    break 2;
                }
            }

            $this->flushOutbound($transport, $protocol);
        }

        if ($frameIndex >= self::MAX_FRAMES) {
            $this->logger->log('[!] frame limit reached');
            $exitCode = self::EXIT_PROTOCOL_ERROR;
        }
        $this->logger->log($responseComplete ? 'done' : 'done (response may be incomplete)');

        return $responseComplete ? self::EXIT_OK : $exitCode;
    }

    private function exitCodeForProtocolError(Http2ProtocolErrorEvent $event): int
    {
        return match ($event->errorCode) {
            self::H2_ERROR_PROTOCOL_ERROR,
            self::H2_ERROR_FRAME_SIZE_ERROR,
            self::H2_ERROR_FLOW_CONTROL_ERROR,
            self::H2_ERROR_SETTINGS_TIMEOUT => self::EXIT_PROTOCOL_ERROR,
            default => self::EXIT_PROTOCOL_ERROR,
        };
    }

    private function logRetryGuidanceForProtocolError(Http2ProtocolErrorEvent $event, string $host, string $path): void
    {
        if ($event->connectionError) {
            $this->logger->log(sprintf(
                'retry: not recommended automatically after connection-level protocol error for %s%s',
                $host,
                $path
            ));
        }
    }

    private function logRetryGuidanceForStreamReset(Http2StreamResetEvent $event, string $host, string $path): void
    {
        if ($event->errorCode === self::H2_ERROR_REFUSED_STREAM) {
            $this->logger->log(sprintf(
                'retry: safe to retry the request on a new connection for %s%s',
                $host,
                $path
            ));
            return;
        }

        if ($event->errorCode === self::H2_ERROR_CANCEL) {
            $this->logger->log(sprintf(
                'retry: possible, but depends on whether %s%s is idempotent',
                $host,
                $path
            ));
        }
    }

    private function logRetryGuidanceForGoAway(Http2GoAwayReceivedEvent $event, string $host, string $path): void
    {
        if ($event->lastStreamId < self::REQUEST_STREAM_ID) {
            $this->logger->log(sprintf(
                'retry: safe to retry the request on a new connection for %s%s',
                $host,
                $path
            ));
            return;
        }

        if ($event->errorCode === self::H2_ERROR_NO_ERROR) {
            $this->logger->log(sprintf(
                'retry: likely unnecessary because the request stream was processed for %s%s',
                $host,
                $path
            ));
        }
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
