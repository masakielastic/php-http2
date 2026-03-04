# http2-client

This repository is for experimenting with a minimal HTTP/2 implementation in PHP.

At the moment it includes:

- an HTTP/2 client
- a minimal HTTP/2 server that returns `Hello World`
- a development certificate generation script for running with TLS + ALPN `h2`

## Requirements

- PHP 8.1 or later
- OpenSSL
- `curl` 8.x recommended
- the PHP `sockets` extension if you use the h2c socket server

## HTTP/2 Client

This client connects to an external HTTP/2 server.

Regular TLS connection:

```bash
php client.php example.com 443 /
```

h2c connection:

```bash
php client.php 127.0.0.1 8080 / --mode=h2c
```

Local verification against a self-signed TLS server:

```bash
php client.php 127.0.0.1 18443 / --mode=tls --insecure
```

Adjusting the timeout:

```bash
php client.php 127.0.0.1 18443 / --mode=tls --insecure --timeout=5
```

When you want to explicitly set the TLS `peer_name` or ALPN:

```bash
php client.php 127.0.0.1 18443 / --mode=tls --insecure --peer-name=localhost --alpn=h2
```

Arguments:

- `HOST PORT PATH [--mode=tls|h2c] [--insecure] [--timeout=N] [--alpn=LIST] [--peer-name=NAME]`
- the legacy fourth positional argument `tls|h2c` is still supported for compatibility
- `--insecure` is available only in `tls` mode
- `--timeout=N` can be used in both h2c and TLS mode
- `--alpn=LIST` and `--peer-name=NAME` are used in `tls` mode

CLI help:

```bash
php client.php --help
```

## HTTP/2 Server

The server does not interpret HTTP request headers.
It only looks at frame boundaries and always returns a fixed `Hello World` response.

If you want to use a stream accepted elsewhere, you can call `Http2ServerConnection::serveStream()` directly.
`Http2ServerConnection` does not manage its own listening socket and is intentionally limited to APIs that process existing connections through `serveStream()` and `serveTransport()`.
You can use `StreamServerSupport` to create listening sockets and assist with TLS handshakes.

Main class mapping:

- `Http2Server` -> `Http2ServerConnection`
- `Http2Client` -> `Http2ClientConnection`
- `Http2StreamReader` -> `Http2FrameReader`
- `Http2Frame` pack/type handling -> `Http2FrameCodec`
- `HpackEncoder` -> `HpackRequestEncoder`
- `TlsConnector` -> `TlsStreamConnector`
- `TlsConnection` -> `TlsStreamConnection`
- `SocketServerSupport` -> `StreamServerSupport`

### Known Limitations

- no HPACK decoding is performed for request `HEADERS`
- responses are always fixed: `200`, `content-type: text/plain`, `content-length: 11`, body `Hello World`
- effectively assumes one request per connection
- no multi-stream support
- some frames such as `WINDOW_UPDATE` are ignored when received
- `PRIORITY`, `RST_STREAM`, `PUSH_PROMISE`, and others are not supported
- flow control is not implemented
- error handling does not attempt to return detailed HTTP/2 error frames
- in TLS mode, connections are rejected unless ALPN negotiates `h2`
- development certificates are self-signed, so `curl` requires `-k`

### h2c

Starts in cleartext HTTP/2 mode.

```bash
php server.php 127.0.0.1 8080 --mode=h2c
curl --http2-prior-knowledge http://127.0.0.1:8080/
php client.php 127.0.0.1 8080 / --mode=h2c
```

### Injected Stream

You can also pass a user-managed stream into the server.

```php
<?php
require_once __DIR__ . '/src/autoload.php';

$logger = new Logger();
$server = new Http2ServerConnection($logger);

$stream = /* user-managed stream */;
$server->serveStream($stream);
```

If you already handled TLS and ALPN yourself, you can call it with the negotiated result explicitly.

```php
$server->serveStream($stream, 'h2');
```

A runnable example is available at [examples/injected_stream_server.php](/home/masakielastic/playground/http2-client/examples/injected_stream_server.php).
The TLS version is available at [examples/injected_tls_stream_server.php](/home/masakielastic/playground/http2-client/examples/injected_tls_stream_server.php).

Running the cleartext injection example:

```bash
php examples/injected_stream_server.php tcp://127.0.0.1:8082
curl --http2-prior-knowledge http://127.0.0.1:8082/
```

Running the TLS injection example:

```bash
php examples/injected_tls_stream_server.php 127.0.0.1 18444 certs/dev-cert.pem certs/dev-key.pem
curl --http2 -k -sv https://127.0.0.1:18444/
```

### TLS + ALPN h2

First, generate a self-signed development certificate.

```bash
./scripts/generate-dev-cert.sh
```

Generated files:

- `certs/dev-cert.pem`
- `certs/dev-key.pem`

By default, the SAN includes both `127.0.0.1` and `localhost`.

Start the TLS server:

```bash
php server.php 127.0.0.1 18443 --mode=tls --cert=certs/dev-cert.pem --key=certs/dev-key.pem
```

Connect with `curl` using ALPN `h2`:

```bash
curl --http2 -k -sv https://127.0.0.1:18443/
```

Expected result:

- `ALPN: server accepted h2`
- `HTTP/2 200`
- response body `Hello World`

Server arguments:

- `HOST PORT [--mode=h2c]`
- `HOST PORT [--mode=tls] --cert=FILE [--key=FILE]`
- the legacy form `HOST PORT CERT [KEY] [--mode=tls]` is still supported for compatibility
- `--mode` can be `h2c` or `tls`
- the default is `h2c` if not specified

CLI help:

```bash
php server.php --help
```

## Development Certificate

Without arguments, `scripts/generate-dev-cert.sh` generates a certificate in `certs/` that includes both `127.0.0.1` and `localhost`.

```bash
./scripts/generate-dev-cert.sh [output_dir] [common_name] [alt_name]
```

Example:

```bash
./scripts/generate-dev-cert.sh certs localhost 127.0.0.1
```
