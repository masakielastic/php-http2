<?php
declare(strict_types=1);

final class Http2StreamState
{
    public const STATE_IDLE = 'idle';
    public const STATE_OPEN = 'open';
    public const STATE_HALF_CLOSED_LOCAL = 'half-closed-local';
    public const STATE_HALF_CLOSED_REMOTE = 'half-closed-remote';
    public const STATE_CLOSED = 'closed';

    public string $state = self::STATE_IDLE;
    public bool $headersReceived = false;
    public bool $requestEmitted = false;
    public bool $locallyInitiated = false;
    public bool $responseEmitted = false;
    public ?string $headerBlock = null;
    public ?array $headers = null;

    public function transitionOnLocalHeaders(bool $endStream): void
    {
        if (!$this->allowsLocalHeaders()) {
            throw new Http2ProtocolException('HEADERS not allowed in current local stream state', 0x05, null, false);
        }

        $this->locallyInitiated = true;

        if ($this->state === self::STATE_IDLE) {
            $this->state = $endStream ? self::STATE_HALF_CLOSED_LOCAL : self::STATE_OPEN;
            return;
        }

        if ($endStream) {
            $this->transitionOnLocalEndStream();
        }
    }

    public function transitionOnRemoteHeaders(bool $endStream): void
    {
        if (!$this->allowsRemoteHeaders()) {
            throw new Http2ProtocolException('HEADERS not allowed in current remote stream state', 0x05, null, false);
        }

        if ($this->state === self::STATE_IDLE) {
            $this->state = $endStream ? self::STATE_HALF_CLOSED_REMOTE : self::STATE_OPEN;
            return;
        }

        if ($endStream) {
            $this->transitionOnRemoteEndStream();
        }
    }

    public function transitionOnLocalEndStream(): void
    {
        $this->state = match ($this->state) {
            self::STATE_IDLE => self::STATE_HALF_CLOSED_LOCAL,
            self::STATE_OPEN => self::STATE_HALF_CLOSED_LOCAL,
            self::STATE_HALF_CLOSED_REMOTE => self::STATE_CLOSED,
            self::STATE_HALF_CLOSED_LOCAL, self::STATE_CLOSED => $this->state,
            default => throw new Http2ProtocolException('invalid local stream transition', 0x05, null, false),
        };
    }

    public function transitionOnRemoteEndStream(): void
    {
        $this->state = match ($this->state) {
            self::STATE_IDLE => self::STATE_HALF_CLOSED_REMOTE,
            self::STATE_OPEN => self::STATE_HALF_CLOSED_REMOTE,
            self::STATE_HALF_CLOSED_LOCAL => self::STATE_CLOSED,
            self::STATE_HALF_CLOSED_REMOTE, self::STATE_CLOSED => $this->state,
            default => throw new Http2ProtocolException('invalid remote stream transition', 0x05, null, false),
        };
    }

    public function isRemoteClosed(): bool
    {
        return $this->state === self::STATE_HALF_CLOSED_REMOTE || $this->state === self::STATE_CLOSED;
    }

    public function isRequestComplete(): bool
    {
        return $this->headersReceived && $this->isRemoteClosed() && !$this->requestEmitted;
    }

    public function isResponseComplete(): bool
    {
        return $this->locallyInitiated && $this->headersReceived && $this->isRemoteClosed() && !$this->responseEmitted;
    }

    public function close(): void
    {
        $this->state = self::STATE_CLOSED;
    }

    public function allowsLocalHeaders(): bool
    {
        return $this->state === self::STATE_IDLE || $this->state === self::STATE_OPEN || $this->state === self::STATE_HALF_CLOSED_REMOTE;
    }

    public function allowsRemoteHeaders(): bool
    {
        return $this->state === self::STATE_IDLE || $this->state === self::STATE_OPEN || $this->state === self::STATE_HALF_CLOSED_LOCAL;
    }

    public function allowsLocalData(): bool
    {
        return $this->state === self::STATE_OPEN || $this->state === self::STATE_HALF_CLOSED_REMOTE;
    }

    public function allowsRemoteData(): bool
    {
        return $this->state === self::STATE_OPEN || $this->state === self::STATE_HALF_CLOSED_LOCAL;
    }
}
