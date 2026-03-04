<?php
declare(strict_types=1);

final class Http2ProtocolException extends RuntimeException
{
    public function __construct(
        string $message,
        public readonly int $errorCode,
        public readonly ?int $streamId = null,
        public readonly bool $connectionError = true,
    ) {
        parent::__construct($message);
    }
}
