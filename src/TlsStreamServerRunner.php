<?php
declare(strict_types=1);

final class TlsStreamServerRunner
{
    public function __construct(
        private readonly Logger $logger,
        private readonly Http2ServerConnection $connection,
    ) {
    }

    public function run(string $address, string $certFile, ?string $keyFile = null): never
    {
        $context = StreamServerSupport::createTlsServerContext($certFile, $keyFile, 'h2');
        $serverSocket = StreamServerSupport::createServerSocket($address, $context);
        $this->logger->log(sprintf('listening on %s (tls, alpn=h2)', $address));

        while (true) {
            $transport = null;

            try {
                $stream = StreamServerSupport::acceptStream($serverSocket);
                $transport = new StreamTransport($stream);
                $this->logger->log(sprintf('accepted connection from %s', $transport->describePeer()));
                $negotiatedProtocol = StreamServerSupport::enableTlsServer($stream);
                $this->logger->log('ALPN (negotiated): ' . ($negotiatedProtocol ?? '(not found)'));
                $this->connection->serveTransport($transport, $negotiatedProtocol);
            } catch (Throwable $e) {
                $this->logger->log('[!] connection error: ' . $e->getMessage());
            } finally {
                if ($transport instanceof StreamTransport) {
                    $transport->close();
                }
            }
        }
    }
}
