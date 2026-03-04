<?php
declare(strict_types=1);

final class HexDumper
{
    public static function dump(string $binary, int $max = 256): string
    {
        $length = strlen($binary);
        $limit = min($length, $max);
        $output = '';

        for ($offset = 0; $offset < $limit; $offset += 16) {
            $chunk = substr($binary, $offset, 16);
            $hex = implode(' ', str_split(bin2hex($chunk), 2));
            $ascii = preg_replace('/[^\x20-\x7e]/', '.', $chunk);
            $output .= sprintf("%04x  %-47s  %s\n", $offset, $hex, $ascii);
        }

        if ($length > $max) {
            $output .= sprintf("... (%d bytes total)\n", $length);
        }

        return $output;
    }
}
