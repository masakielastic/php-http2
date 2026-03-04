<?php
declare(strict_types=1);

require_once __DIR__ . '/../src/autoload.php';

error_reporting(E_ALL);
ini_set('display_errors', '1');

$host = $argv[1] ?? 'example.com';
$port = (int)($argv[2] ?? 443);
$path = $argv[3] ?? '/';
$timeoutSec = (int)($argv[4] ?? 2);
$alpn = 'h2';

$context = stream_context_create([
    'ssl' => [
        'verify_peer' => true,
        'verify_peer_name' => true,
        'SNI_enabled' => true,
        'peer_name' => $host,
        'alpn_protocols' => $alpn,
    ],
]);

$stream = @stream_socket_client(
    "tls://{$host}:{$port}",
    $errno,
    $errstr,
    $timeoutSec,
    STREAM_CLIENT_CONNECT,
    $context
);

if ($stream === false) {
    throw new RuntimeException("connect failed: ({$errno}) {$errstr}");
}

stream_set_timeout($stream, $timeoutSec);
stream_set_read_buffer($stream, 0);
stream_set_write_buffer($stream, 0);

$meta = stream_get_meta_data($stream);
$negotiatedProtocol = null;
if (isset($meta['crypto']) && is_array($meta['crypto'])) {
    foreach (['alpn_protocol', 'alpn_selected', 'ssl_alpn_protocol', 'negotiated_protocol', 'protocol'] as $key) {
        if (isset($meta['crypto'][$key]) && is_string($meta['crypto'][$key]) && $meta['crypto'][$key] !== '') {
            $negotiatedProtocol = $meta['crypto'][$key];
            break;
        }
    }
}

$logger = new Logger();
$client = new Http2ClientConnection($logger, null, null, new HpackRequestEncoder());

try {
    exit($client->runWithStream($stream, $host, $path, $negotiatedProtocol));
} finally {
    fclose($stream);
}
