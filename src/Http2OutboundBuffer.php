<?php
declare(strict_types=1);

final class Http2OutboundBuffer
{
    private string $buffer = '';

    public function append(string $payload): void
    {
        if ($payload === '') {
            return;
        }

        $this->buffer .= $payload;
    }

    public function drain(): string
    {
        $payload = $this->buffer;
        $this->buffer = '';

        return $payload;
    }

    public function isEmpty(): bool
    {
        return $this->buffer === '';
    }
}
