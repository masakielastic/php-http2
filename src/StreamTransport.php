<?php
declare(strict_types=1);

final class StreamTransport implements Http2Transport
{
    public function __construct(private readonly mixed $stream)
    {
    }

    public function configure(int $timeoutSec): void
    {
        stream_set_timeout($this->stream, $timeoutSec);
        stream_set_read_buffer($this->stream, 0);
        stream_set_write_buffer($this->stream, 0);
    }

    public function readSome(int $maxLength): ?string
    {
        while (true) {
            $chunk = fread($this->stream, $maxLength);
            if ($chunk === false) {
                return null;
            }

            if ($chunk === '') {
                $meta = stream_get_meta_data($this->stream);
                if (!empty($meta['timed_out']) || feof($this->stream)) {
                    return null;
                }

                usleep(10_000);
                continue;
            }

            return $chunk;
        }
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
            $chunk = fwrite($this->stream, substr($payload, $written));
            if ($chunk === false || $chunk === 0) {
                throw new RuntimeException('failed to write to stream transport');
            }

            $written += $chunk;
        }
    }

    public function close(): void
    {
        if (is_resource($this->stream)) {
            fclose($this->stream);
        }
    }

    public function describePeer(): string
    {
        return stream_socket_get_name($this->stream, true) ?: 'unknown';
    }

    public function getMetadata(): array
    {
        return stream_get_meta_data($this->stream);
    }

    public function getStream(): mixed
    {
        return $this->stream;
    }
}
