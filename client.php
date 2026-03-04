<?php
declare(strict_types=1);

error_reporting(E_ALL);
ini_set('display_errors', '1');

require_once __DIR__ . '/src/autoload.php';

function usage(): string
{
    return <<<TXT
usage:
  php client.php --help
  php client.php HOST PORT PATH [tls|h2c]
  php client.php HOST PORT PATH [--mode=tls|h2c] [--insecure] [--timeout=N] [--alpn=LIST] [--peer-name=NAME]

examples:
  php client.php example.com 443 /
  php client.php 127.0.0.1 8080 / --mode=h2c
  php client.php 127.0.0.1 18443 / --mode=tls --insecure --timeout=5 --peer-name=localhost
TXT;
}

$usage = usage();
CliSupport::exitIfHelpRequested($argv, $usage);

$host = $argv[1] ?? 'example.com';
$port = (int)($argv[2] ?? 443);
$path = $argv[3] ?? '/';
$mode = 'tls';
$flags = array_slice($argv, 4);
$insecure = false;
$timeoutSec = 2;
$alpn = 'h2,http/1.1';
$peerName = null;

foreach ($flags as $flag) {
    if ($flag === '--help' || $flag === '-h') {
        CliSupport::exitIfHelpRequested([$flag], $usage);
    }

    if ($flag === 'tls' || $flag === 'h2c') {
        $mode = $flag;
        continue;
    }

    if ($flag === '--insecure') {
        $insecure = true;
        continue;
    }

    if (str_starts_with($flag, '--mode=')) {
        $mode = substr($flag, strlen('--mode='));
        continue;
    }

    if (str_starts_with($flag, '--timeout=')) {
        $value = substr($flag, strlen('--timeout='));
        if ($value === '' || !ctype_digit($value) || (int)$value < 1) {
            CliSupport::fail("invalid timeout value: {$flag}", $usage);
        }

        $timeoutSec = (int)$value;
        continue;
    }

    if (str_starts_with($flag, '--alpn=')) {
        $value = substr($flag, strlen('--alpn='));
        if ($value === '') {
            CliSupport::fail("invalid alpn value: {$flag}", $usage);
        }

        $alpn = $value;
        continue;
    }

    if (str_starts_with($flag, '--peer-name=')) {
        $value = substr($flag, strlen('--peer-name='));
        if ($value === '') {
            CliSupport::fail("invalid peer name value: {$flag}", $usage);
        }

        $peerName = $value;
        continue;
    }

    CliSupport::fail("unknown option: {$flag}", $usage);
}

if (!in_array($mode, ['tls', 'h2c'], true)) {
    CliSupport::fail("invalid mode: {$mode}", $usage);
}

if ($mode === 'h2c' && $insecure) {
    CliSupport::fail('--insecure is only valid with tls mode', $usage);
}

$logger = new Logger();
$client = new Http2ClientConnection(
    $logger,
    new TlsStreamConnector($logger),
    new H2cSocketConnector(),
    new HpackRequestEncoder(),
);

if ($mode === 'h2c') {
    exit($client->runH2c($host, $port, $path, $timeoutSec));
}

$tlsOptions = (new TlsClientOptions())
    ->withTimeoutSec($timeoutSec)
    ->withVerifyPeer(!$insecure)
    ->withAlpn($alpn)
    ->withPeerName($peerName);

exit($client->runTls($host, $port, $path, $tlsOptions));
