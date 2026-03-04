<?php
declare(strict_types=1);

error_reporting(E_ALL);
ini_set('display_errors', '1');

require_once __DIR__ . '/src/autoload.php';

function usage(): string
{
    return <<<TXT
usage:
  php server.php --help
  php server.php HOST PORT [--mode=h2c]
  php server.php HOST PORT [--mode=tls] --cert=FILE [--key=FILE]
  php server.php HOST PORT CERT [KEY] [--mode=tls]

examples:
  php server.php 127.0.0.1 8080 --mode=h2c
  php server.php 127.0.0.1 18443 --mode=tls --cert=certs/dev-cert.pem --key=certs/dev-key.pem
TXT;
}

$usage = usage();
CliSupport::exitIfHelpRequested($argv, $usage);

$mode = 'h2c';
$certOption = null;
$keyOption = null;
$positionals = [];

foreach (array_slice($argv, 1) as $arg) {
    if (str_starts_with($arg, '--mode=')) {
        $mode = substr($arg, strlen('--mode='));
        continue;
    }

    if (str_starts_with($arg, '--cert=')) {
        $certOption = substr($arg, strlen('--cert='));
        continue;
    }

    if (str_starts_with($arg, '--key=')) {
        $keyOption = substr($arg, strlen('--key='));
        continue;
    }

    if (str_starts_with($arg, '--')) {
        CliSupport::fail("unknown option: {$arg}", $usage);
    }

    $positionals[] = $arg;
}

$host = $positionals[0] ?? '127.0.0.1';
$port = isset($positionals[1]) ? (int)$positionals[1] : 8080;
$certFile = $certOption ?? ($positionals[2] ?? null);
$keyFile = $keyOption ?? ($positionals[3] ?? null);

$address = sprintf('tcp://%s:%d', $host, $port);

if ($port < 1 || $port > 65535) {
    CliSupport::fail("invalid port: {$port}", $usage);
}

if (!in_array($mode, ['h2c', 'tls'], true)) {
    CliSupport::fail("invalid mode: {$mode}", $usage);
}

if ($mode === 'h2c' && ($certFile !== null || $keyFile !== null)) {
    CliSupport::fail('certificate arguments require --mode=tls', $usage);
}

if ($mode === 'tls' && $certFile === null) {
    CliSupport::fail('tls mode requires a certificate file', $usage);
}

if ($keyFile !== null && $certFile === null) {
    CliSupport::fail('key file requires a certificate file', $usage);
}

$logger = new Logger();
$connection = new Http2ServerConnection($logger);

try {
    if ($mode === 'tls') {
        $runner = new TlsStreamServerRunner($logger, $connection);
        $runner->run($address, $certFile, $keyFile);
    }

    $server = new H2cSocketServer($logger, $connection);
    $server->run($host, $port);
} catch (Throwable $e) {
    $logger->log('[!] ' . $e->getMessage());
    exit(1);
}
