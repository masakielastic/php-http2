<?php
declare(strict_types=1);

final class CliSupport
{
    public static function exitIfHelpRequested(array $argv, string $usage): void
    {
        if (in_array('--help', $argv, true) || in_array('-h', $argv, true)) {
            fwrite(STDOUT, $usage . PHP_EOL);
            exit(0);
        }
    }

    public static function fail(string $message, string $usage): never
    {
        fwrite(STDERR, '[!] ' . $message . PHP_EOL);
        fwrite(STDERR, $usage . PHP_EOL);
        exit(1);
    }
}
