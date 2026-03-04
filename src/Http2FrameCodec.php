<?php
declare(strict_types=1);

final class Http2FrameCodec
{
    public static function pack(int $type, int $flags, int $streamId, string $payload): string
    {
        $length = strlen($payload);
        $header = chr(($length >> 16) & 0xff) . chr(($length >> 8) & 0xff) . chr($length & 0xff);
        $header .= chr($type & 0xff) . chr($flags & 0xff);
        $header .= pack('N', $streamId & 0x7FFFFFFF);

        return $header . $payload;
    }

    public static function decodeHeader(string $header): array
    {
        if (strlen($header) !== 9) {
            throw new InvalidArgumentException('HTTP/2 frame header must be exactly 9 bytes');
        }

        return [
            'length' => (ord($header[0]) << 16) | (ord($header[1]) << 8) | ord($header[2]),
            'type' => ord($header[3]),
            'flags' => ord($header[4]),
            'streamId' => (unpack('N', substr($header, 5, 4))[1]) & 0x7FFFFFFF,
        ];
    }

    public static function typeName(int $type): string
    {
        return match ($type) {
            0x00 => 'DATA',
            0x01 => 'HEADERS',
            0x02 => 'PRIORITY',
            0x03 => 'RST_STREAM',
            0x04 => 'SETTINGS',
            0x05 => 'PUSH_PROMISE',
            0x06 => 'PING',
            0x07 => 'GOAWAY',
            0x08 => 'WINDOW_UPDATE',
            0x09 => 'CONTINUATION',
            default => 'UNKNOWN',
        };
    }
}
