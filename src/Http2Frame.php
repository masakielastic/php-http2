<?php
declare(strict_types=1);

final class Http2Frame
{
    public function __construct(
        public readonly int $length,
        public readonly int $type,
        public readonly int $flags,
        public readonly int $streamId,
        public readonly string $payload,
    ) {
    }
}
