<?php
declare(strict_types=1);

interface Http2Transport
{
    public function configure(int $timeoutSec): void;

    public function readSome(int $maxLength): ?string;

    public function read(int $length): ?string;

    public function write(string $payload): void;

    public function close(): void;

    public function describePeer(): string;

    public function getMetadata(): array;
}
