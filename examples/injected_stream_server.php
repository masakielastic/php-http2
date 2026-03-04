<?php
declare(strict_types=1);

error_reporting(E_ALL);
ini_set('display_errors', '1');

require_once __DIR__ . '/../src/autoload.php';

$address = $argv[1] ?? 'tcp://127.0.0.1:8082';

$logger = new Logger();
$connection = new Http2ServerConnection($logger);

try {
    $serverSocket = StreamServerSupport::createServerSocket($address);
    fwrite(STDOUT, "waiting on {$address}\n");
    $stream = StreamServerSupport::acceptStream($serverSocket);
    $connection->serveStream($stream);
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
