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

    public function openLocal(bool $endStream): void
    {
        if (!$this->canSendHeaders()) {
            throw new RuntimeException('HEADERS not allowed in current local stream state');
        }

        $this->locallyInitiated = true;

        if ($this->state === self::STATE_IDLE) {
            $this->state = $endStream ? self::STATE_HALF_CLOSED_LOCAL : self::STATE_OPEN;
            return;
        }

        if ($endStream) {
            $this->markLocalClosed();
        }
    }

    public function openRemote(bool $endStream): void
    {
        if (!$this->canReceiveHeaders()) {
            throw new RuntimeException('HEADERS not allowed in current remote stream state');
        }

        if ($this->state === self::STATE_IDLE) {
            $this->state = $endStream ? self::STATE_HALF_CLOSED_REMOTE : self::STATE_OPEN;
            return;
        }

        if ($endStream) {
            $this->markRemoteClosed();
        }
    }

    public function markLocalClosed(): void
    {
        $this->state = match ($this->state) {
            self::STATE_IDLE => self::STATE_HALF_CLOSED_LOCAL,
            self::STATE_OPEN => self::STATE_HALF_CLOSED_LOCAL,
            self::STATE_HALF_CLOSED_REMOTE => self::STATE_CLOSED,
            self::STATE_HALF_CLOSED_LOCAL, self::STATE_CLOSED => $this->state,
            default => throw new RuntimeException('invalid local stream transition'),
        };
    }

    public function markRemoteClosed(): void
    {
        $this->state = match ($this->state) {
            self::STATE_IDLE => self::STATE_HALF_CLOSED_REMOTE,
            self::STATE_OPEN => self::STATE_HALF_CLOSED_REMOTE,
            self::STATE_HALF_CLOSED_LOCAL => self::STATE_CLOSED,
            self::STATE_HALF_CLOSED_REMOTE, self::STATE_CLOSED => $this->state,
            default => throw new RuntimeException('invalid remote stream transition'),
        };
    }

    public function isRemoteClosed(): bool
    {
        return $this->state === self::STATE_HALF_CLOSED_REMOTE || $this->state === self::STATE_CLOSED;
    }

    public function canSendHeaders(): bool
    {
        return $this->state === self::STATE_IDLE || $this->state === self::STATE_OPEN || $this->state === self::STATE_HALF_CLOSED_REMOTE;
    }

    public function canReceiveHeaders(): bool
    {
        return $this->state === self::STATE_IDLE || $this->state === self::STATE_OPEN || $this->state === self::STATE_HALF_CLOSED_LOCAL;
    }

    public function canSendData(): bool
    {
        return $this->state === self::STATE_OPEN || $this->state === self::STATE_HALF_CLOSED_REMOTE;
    }

    public function canReceiveData(): bool
    {
        return $this->state === self::STATE_OPEN || $this->state === self::STATE_HALF_CLOSED_LOCAL;
    }
}
