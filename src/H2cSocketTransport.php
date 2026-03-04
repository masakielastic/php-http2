<?php
declare(strict_types=1);

final class H2cSocketTransport implements Http2Transport
{
    public function __construct(private readonly Socket $socket)
    {
    }

    public function configure(int $timeoutSec): void
    {
        $timeout = ['sec' => $timeoutSec, 'usec' => 0];
        socket_set_option($this->socket, SOL_SOCKET, SO_RCVTIMEO, $timeout);
        socket_set_option($this->socket, SOL_SOCKET, SO_SNDTIMEO, $timeout);
    }

    public function readSome(int $maxLength): ?string
    {
        $chunk = @socket_read($this->socket, $maxLength, PHP_BINARY_READ);
        if ($chunk === false || $chunk === '') {
            return null;
        }

        return $chunk;
    }

    public function read(int $length): ?string
    {
        $buffer = '';

        while (strlen($buffer) < $length) {
            $chunk = $this->readSome($length - strlen($buffer));
            if ($chunk === null) {
                return null;
            }

            $buffer .= $chunk;
        }

        return $buffer;
    }

    public function write(string $payload): void
    {
        $written = 0;
        $length = strlen($payload);

        while ($written < $length) {
            $chunk = @socket_write($this->socket, substr($payload, $written), $length - $written);
            if ($chunk === false || $chunk === 0) {
                throw new RuntimeException('failed to write to h2c socket transport');
            }

            $written += $chunk;
        }
    }

    public function close(): void
    {
        socket_close($this->socket);
    }

    public function describePeer(): string
    {
        if (@socket_getpeername($this->socket, $address, $port)) {
            return sprintf('%s:%d', $address, $port);
        }

        return 'unknown';
    }

    public function getMetadata(): array
    {
        return [];
    }
}
