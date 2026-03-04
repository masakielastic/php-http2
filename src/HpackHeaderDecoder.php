<?php
declare(strict_types=1);

final class HpackHeaderDecoder
{
    /**
     * Static table entries from RFC 7541 Appendix A.
     *
     * @var array<int, array{name: string, value: string}>
     */
    private const STATIC_TABLE = [
        1 => ['name' => ':authority', 'value' => ''],
        2 => ['name' => ':method', 'value' => 'GET'],
        3 => ['name' => ':method', 'value' => 'POST'],
        4 => ['name' => ':path', 'value' => '/'],
        5 => ['name' => ':path', 'value' => '/index.html'],
        6 => ['name' => ':scheme', 'value' => 'http'],
        7 => ['name' => ':scheme', 'value' => 'https'],
        8 => ['name' => ':status', 'value' => '200'],
        9 => ['name' => ':status', 'value' => '204'],
        10 => ['name' => ':status', 'value' => '206'],
        11 => ['name' => ':status', 'value' => '304'],
        12 => ['name' => ':status', 'value' => '400'],
        13 => ['name' => ':status', 'value' => '404'],
        14 => ['name' => ':status', 'value' => '500'],
        15 => ['name' => 'accept-charset', 'value' => ''],
        16 => ['name' => 'accept-encoding', 'value' => 'gzip, deflate'],
        17 => ['name' => 'accept-language', 'value' => ''],
        18 => ['name' => 'accept-ranges', 'value' => ''],
        19 => ['name' => 'accept', 'value' => ''],
        20 => ['name' => 'access-control-allow-origin', 'value' => ''],
        21 => ['name' => 'age', 'value' => ''],
        22 => ['name' => 'allow', 'value' => ''],
        23 => ['name' => 'authorization', 'value' => ''],
        24 => ['name' => 'cache-control', 'value' => ''],
        25 => ['name' => 'content-disposition', 'value' => ''],
        26 => ['name' => 'content-encoding', 'value' => ''],
        27 => ['name' => 'content-language', 'value' => ''],
        28 => ['name' => 'content-length', 'value' => ''],
        29 => ['name' => 'content-location', 'value' => ''],
        30 => ['name' => 'content-range', 'value' => ''],
        31 => ['name' => 'content-type', 'value' => ''],
        32 => ['name' => 'cookie', 'value' => ''],
        33 => ['name' => 'date', 'value' => ''],
        34 => ['name' => 'etag', 'value' => ''],
        35 => ['name' => 'expect', 'value' => ''],
        36 => ['name' => 'expires', 'value' => ''],
        37 => ['name' => 'from', 'value' => ''],
        38 => ['name' => 'host', 'value' => ''],
        39 => ['name' => 'if-match', 'value' => ''],
        40 => ['name' => 'if-modified-since', 'value' => ''],
        41 => ['name' => 'if-none-match', 'value' => ''],
        42 => ['name' => 'if-range', 'value' => ''],
        43 => ['name' => 'if-unmodified-since', 'value' => ''],
        44 => ['name' => 'last-modified', 'value' => ''],
        45 => ['name' => 'link', 'value' => ''],
        46 => ['name' => 'location', 'value' => ''],
        47 => ['name' => 'max-forwards', 'value' => ''],
        48 => ['name' => 'proxy-authenticate', 'value' => ''],
        49 => ['name' => 'proxy-authorization', 'value' => ''],
        50 => ['name' => 'range', 'value' => ''],
        51 => ['name' => 'referer', 'value' => ''],
        52 => ['name' => 'refresh', 'value' => ''],
        53 => ['name' => 'retry-after', 'value' => ''],
        54 => ['name' => 'server', 'value' => ''],
        55 => ['name' => 'set-cookie', 'value' => ''],
        56 => ['name' => 'strict-transport-security', 'value' => ''],
        57 => ['name' => 'transfer-encoding', 'value' => ''],
        58 => ['name' => 'user-agent', 'value' => ''],
        59 => ['name' => 'vary', 'value' => ''],
        60 => ['name' => 'via', 'value' => ''],
        61 => ['name' => 'www-authenticate', 'value' => ''],
    ];

    /**
     * @return list<array{name: string, value: string}>
     */
    public function decode(string $payload): array
    {
        $offset = 0;
        $headers = [];

        while ($offset < strlen($payload)) {
            $first = ord($payload[$offset]);

            if (($first & 0x80) !== 0) {
                $index = $this->decodeInt($payload, $offset, 7);
                $header = $this->resolveHeader($index);
                $headers[] = $header;
                continue;
            }

            if (($first & 0x40) !== 0) {
                $name = $this->decodeHeaderName($payload, $offset, 6);
                $value = $this->decodeString($payload, $offset);
                $headers[] = ['name' => $name, 'value' => $value];
                continue;
            }

            if (($first & 0xE0) === 0x20) {
                $this->decodeInt($payload, $offset, 5);
                continue;
            }

            if (($first & 0xF0) === 0x00 || ($first & 0xF0) === 0x10) {
                $name = $this->decodeHeaderName($payload, $offset, 4);
                $value = $this->decodeString($payload, $offset);
                $headers[] = ['name' => $name, 'value' => $value];
                continue;
            }

            throw new RuntimeException('unsupported HPACK representation');
        }

        return $headers;
    }

    private function decodeHeaderName(string $payload, int &$offset, int $prefixBits): string
    {
        $nameIndex = $this->decodeInt($payload, $offset, $prefixBits);
        if ($nameIndex === 0) {
            return $this->decodeString($payload, $offset);
        }

        $header = $this->resolveHeader($nameIndex);
        return $header['name'];
    }

    private function decodeString(string $payload, int &$offset): string
    {
        if (!isset($payload[$offset])) {
            throw new RuntimeException('unexpected end of HPACK string');
        }

        $isHuffman = (ord($payload[$offset]) & 0x80) !== 0;
        if ($isHuffman) {
            throw new RuntimeException('HPACK Huffman strings are not supported yet');
        }

        $length = $this->decodeInt($payload, $offset, 7);
        $value = substr($payload, $offset, $length);
        if (strlen($value) !== $length) {
            throw new RuntimeException('unexpected end of HPACK string payload');
        }

        $offset += $length;
        return $value;
    }

    private function decodeInt(string $payload, int &$offset, int $prefixBits): int
    {
        if (!isset($payload[$offset])) {
            throw new RuntimeException('unexpected end of HPACK integer');
        }

        $mask = (1 << $prefixBits) - 1;
        $value = ord($payload[$offset]) & $mask;
        $offset++;

        if ($value < $mask) {
            return $value;
        }

        $multiplier = 0;
        while (true) {
            if (!isset($payload[$offset])) {
                throw new RuntimeException('unexpected end of HPACK integer continuation');
            }

            $byte = ord($payload[$offset]);
            $offset++;
            $value += ($byte & 0x7f) << $multiplier;

            if (($byte & 0x80) === 0) {
                return $value;
            }

            $multiplier += 7;
        }
    }

    /**
     * @return array{name: string, value: string}
     */
    private function resolveHeader(int $index): array
    {
        if (!isset(self::STATIC_TABLE[$index])) {
            throw new RuntimeException(sprintf('unsupported HPACK index: %d', $index));
        }

        return self::STATIC_TABLE[$index];
    }
}
