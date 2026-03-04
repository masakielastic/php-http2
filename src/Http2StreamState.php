<?php
declare(strict_types=1);

final class Http2StreamState
{
    public bool $headersReceived = false;
    public bool $endStreamReceived = false;
    public bool $requestEmitted = false;
    public bool $locallyInitiated = false;
    public bool $responseEmitted = false;
    public ?string $headerBlock = null;
    public ?array $headers = null;
}
