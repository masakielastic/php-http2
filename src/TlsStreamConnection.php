<?php
declare(strict_types=1);

final class TlsStreamConnection
{
    public function __construct(
        public readonly mixed $stream,
        public readonly array $meta,
        public readonly ?string $negotiatedProtocol,
    ) {
    }
}
