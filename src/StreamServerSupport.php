<?php
declare(strict_types=1);

final class StreamServerSupport
{
    public static function createServerSocket(string $address, mixed $context = null): mixed
    {
        $flags = STREAM_SERVER_BIND | STREAM_SERVER_LISTEN;
        $serverSocket = @stream_socket_server($address, $errno, $errstr, $flags, $context);
        if ($serverSocket === false) {
            throw new RuntimeException(sprintf('failed to bind %s: %s (%d)', $address, $errstr, $errno));
        }

        return $serverSocket;
    }

    public static function acceptStream(mixed $serverSocket, int $timeoutSec = -1): mixed
    {
        $stream = @stream_socket_accept($serverSocket, $timeoutSec);
        if ($stream === false) {
            throw new RuntimeException('failed to accept connection');
        }

        return $stream;
    }

    public static function createTlsServerContext(string $certFile, ?string $keyFile = null, string $alpn = 'h2'): mixed
    {
        $context = stream_context_create([
            'ssl' => [
                'local_cert' => $certFile,
                'alpn_protocols' => $alpn,
                'crypto_method' => STREAM_CRYPTO_METHOD_TLS_SERVER,
            ],
        ]);

        if ($keyFile !== null) {
            stream_context_set_option($context, 'ssl', 'local_pk', $keyFile);
        }

        return $context;
    }

    public static function enableTlsServer(mixed $stream): ?string
    {
        $result = @stream_socket_enable_crypto($stream, true, STREAM_CRYPTO_METHOD_TLS_SERVER);
        if ($result !== true) {
            throw new RuntimeException('TLS handshake failed');
        }

        return self::detectNegotiatedProtocol(stream_get_meta_data($stream));
    }

    public static function detectNegotiatedProtocol(array $meta): ?string
    {
        if (!isset($meta['crypto']) || !is_array($meta['crypto'])) {
            return null;
        }

        foreach (['alpn_protocol', 'alpn_selected', 'ssl_alpn_protocol', 'negotiated_protocol', 'protocol'] as $key) {
            if (isset($meta['crypto'][$key]) && is_string($meta['crypto'][$key]) && $meta['crypto'][$key] !== '') {
                return $meta['crypto'][$key];
            }
        }

        return null;
    }
}
