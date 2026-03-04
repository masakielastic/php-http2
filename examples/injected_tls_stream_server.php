<?php
declare(strict_types=1);

error_reporting(E_ALL);
ini_set('display_errors', '1');

require_once __DIR__ . '/../src/autoload.php';

$host = $argv[1] ?? '127.0.0.1';
$port = (int)($argv[2] ?? 18444);
$certFile = $argv[3] ?? (__DIR__ . '/../certs/dev-cert.pem');
$keyFile = $argv[4] ?? (__DIR__ . '/../certs/dev-key.pem');

$context = StreamServerSupport::createTlsServerContext($certFile, $keyFile, 'h2');

$address = sprintf('tcp://%s:%d', $host, $port);
$logger = new Logger();
$connection = new Http2ServerConnection($logger);

try {
    $serverSocket = StreamServerSupport::createServerSocket($address, $context);
    fwrite(STDOUT, "waiting on {$address} (tls)\n");
    $stream = StreamServerSupport::acceptStream($serverSocket);
    $negotiatedProtocol = StreamServerSupport::enableTlsServer($stream);
    if ($negotiatedProtocol !== 'h2') {
        throw new RuntimeException('unexpected ALPN: ' . ($negotiatedProtocol ?? '(not found)'));
    }

    fwrite(STDOUT, "ALPN negotiated: {$negotiatedProtocol}\n");
    $connection->serveStream($stream, $negotiatedProtocol);
} catch (Throwable $e) {
    fwrite(STDERR, $e->getMessage() . PHP_EOL);
    exit(1);
} finally {
    if (isset($stream) && is_resource($stream)) {
        fclose($stream);
    }

    if (isset($serverSocket) && is_resource($serverSocket)) {
        fclose($serverSocket);
    }
}
