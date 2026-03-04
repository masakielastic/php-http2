<?php
declare(strict_types=1);

final class HpackRequestEncoder
{
    public function buildRequestHeaderBlock(string $host, string $path): string
    {
        $headers = [
            chr(0x82),
            chr(0x87),
        ];

        if ($path === '/') {
            $headers[] = chr(0x84);
        } else {
            $headers[] = $this->encodeLiteralHeaderWithoutIndexing(4, $path);
        }

        $headers[] = $this->encodeLiteralHeaderWithoutIndexing(1, $host);

        return implode('', $headers);
    }

    private function encodeLiteralHeaderWithoutIndexing(int $nameIndex, string $value): string
    {
        return $this->encodeInt($nameIndex, 4) . $this->encodeString($value);
    }

    private function encodeString(string $value): string
    {
        return $this->encodeInt(strlen($value), 7) . $value;
    }

    private function encodeInt(int $value, int $prefixBits, int $prefixMask = 0x00): string
    {
        $maxPrefixValue = (1 << $prefixBits) - 1;
        if ($value < $maxPrefixValue) {
            return chr($prefixMask | $value);
        }

        $output = chr($prefixMask | $maxPrefixValue);
        $value -= $maxPrefixValue;

        while ($value >= 128) {
            $output .= chr(($value % 128) + 128);
            $value = intdiv($value, 128);
        }

        $output .= chr($value);

        return $output;
    }
}
