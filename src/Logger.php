<?php
declare(strict_types=1);

final class Logger
{
    public function log(string $message): void
    {
        fwrite(STDOUT, $message . PHP_EOL);
        fflush(STDOUT);
    }

    public function dumpData(string $payload): void
    {
        fwrite(STDOUT, $payload);
        if (!str_ends_with($payload, "\n")) {
            fwrite(STDOUT, PHP_EOL);
        }
        fflush(STDOUT);
    }
}
