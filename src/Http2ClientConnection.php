<?php
declare(strict_types=1);

final class Http2ClientConnection
{
    private const CLIENT_PREFACE = "PRI * HTTP/2.0\r\n\r\nSM\r\n\r\n";
    private const FRAME_TYPE_DATA = 0x00;
    private const FRAME_TYPE_HEADERS = 0x01;
    private const FRAME_TYPE_SETTINGS = 0x04;
    private const FRAME_TYPE_GOAWAY = 0x07;
    private const FLAG_ACK = 0x01;
    private const FLAG_END_STREAM = 0x01;
    private const FLAG_END_HEADERS = 0x04;
    private const REQUEST_STREAM_ID = 1;

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

        $reader = new Http2FrameReader($transport);

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

        $reader = new Http2FrameReader($transport);
        $writer = new Http2FrameWriter($transport);
        $this->sendClientPreface($transport);
        $this->sendClientSettings($writer);

        return $this->handleFrames($transport, $reader, $writer, $host, $path);
    }

    private function sendClientPreface(Http2Transport $transport): void
    {
        $this->logger->log('>>> sending client preface (' . strlen(self::CLIENT_PREFACE) . ' bytes)');
        $transport->write(self::CLIENT_PREFACE);
    }

    private function sendClientSettings(Http2FrameWriter $writer): void
    {
        $this->logger->log('>>> sending SETTINGS (empty payload)');
        $writer->writeFrame(self::FRAME_TYPE_SETTINGS, 0x00, 0, '');
    }

    private function handleFrames(
        Http2Transport $transport,
        Http2FrameReader $reader,
        Http2FrameWriter $writer,
        string $host,
        string $path
    ): int
    {
        $gotServerSettings = false;
        $ackedServerSettings = false;
        $requestSent = false;
        $responseDone = false;
        $maxFrames = 50;

        for ($index = 0; $index < $maxFrames; $index++) {
            $frame = $reader->readFrame();
            if ($frame === null) {
                $meta = $transport->getMetadata();
                $this->logger->log('no more frames / timeout=' . (!empty($meta['timed_out']) ? 'yes' : 'no'));
                break;
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

            $this->logPayload($frame);

            if ($frame->type === self::FRAME_TYPE_SETTINGS && ($frame->flags & self::FLAG_ACK) === 0) {
                $gotServerSettings = true;
                if (!$ackedServerSettings) {
                    $this->logger->log('>>> sending SETTINGS ACK');
                    $writer->writeFrame(self::FRAME_TYPE_SETTINGS, self::FLAG_ACK, 0, '');
                    $ackedServerSettings = true;
                }
            }

            if ($gotServerSettings && $ackedServerSettings && !$requestSent) {
                $this->sendRequestHeaders($writer, $host, $path);
                $requestSent = true;
            }

            if ($requestSent && $frame->streamId === self::REQUEST_STREAM_ID && ($frame->flags & self::FLAG_END_STREAM) !== 0) {
                $this->logger->log('END_STREAM received on response stream; exiting.');
                $responseDone = true;
                break;
            }

            if ($frame->type === self::FRAME_TYPE_GOAWAY) {
                $this->logger->log('GOAWAY received; exiting.');
                break;
            }
        }

        $this->logger->log($responseDone ? 'done' : 'done (response may be incomplete)');

        return 0;
    }

    private function logPayload(Http2Frame $frame): void
    {
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

    private function sendRequestHeaders(Http2FrameWriter $writer, string $host, string $path): void
    {
        $headerBlock = $this->hpackEncoder->buildRequestHeaderBlock($host, $path);
        $flags = self::FLAG_END_STREAM | self::FLAG_END_HEADERS;
        $this->logger->log(">>> sending HEADERS for GET {$path}");
        $writer->writeFrame(self::FRAME_TYPE_HEADERS, $flags, self::REQUEST_STREAM_ID, $headerBlock);
    }
}
