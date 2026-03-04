<?php
declare(strict_types=1);

final class TlsStreamConnector
{
    public function __construct(private readonly Logger $logger)
    {
    }

    public function connectWithAlpn(string $host, int $port, TlsClientOptions $options): TlsStreamConnection
    {
        $ipv4 = gethostbyname($host);
        $targetHost = ($ipv4 !== $host) ? $ipv4 : $host;
        $target = "tls://{$targetHost}:{$port}";
        $peerName = $options->peerName ?? $host;

        $context = stream_context_create([
            'ssl' => [
                'verify_peer' => $options->verifyPeer,
                'verify_peer_name' => $options->verifyPeer,
                'SNI_enabled' => true,
                'peer_name' => $peerName,
                'alpn_protocols' => $options->alpn,
            ],
        ]);

        $this->logger->log("target: {$target} (SNI/peer_name={$peerName})");
        $this->logger->log("alpn_protocols(configured): {$options->alpn}");
        if (!$options->verifyPeer) {
            $this->logger->log('TLS peer verification: disabled (--insecure)');
        }

        $errno = 0;
        $errstr = '';
        $stream = @stream_socket_client($target, $errno, $errstr, $options->timeoutSec, STREAM_CLIENT_CONNECT, $context);
        if ($stream === false) {
            throw new RuntimeException("connect failed: ({$errno}) {$errstr}");
        }

        $transport = new StreamTransport($stream);
        $transport->configure($options->timeoutSec);

        $meta = $transport->getMetadata();
        $negotiatedProtocol = StreamServerSupport::detectNegotiatedProtocol($meta);

        $this->logger->log('ALPN (negotiated): ' . ($negotiatedProtocol ?? '(not found; check meta dump)'));

        return new TlsStreamConnection($stream, $meta, $negotiatedProtocol);
    }
}
