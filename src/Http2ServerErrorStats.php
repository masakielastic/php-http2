<?php
declare(strict_types=1);

final class Http2ServerErrorStats
{
    private int $connectionProtocolErrors = 0;

    /** @var array<string, int> */
    private array $errorsByMessage = [];

    public function recordConnectionProtocolError(string $message): void
    {
        $this->connectionProtocolErrors++;
        if (!isset($this->errorsByMessage[$message])) {
            $this->errorsByMessage[$message] = 0;
        }

        $this->errorsByMessage[$message]++;
    }

    public function totalConnectionProtocolErrors(): int
    {
        return $this->connectionProtocolErrors;
    }

    public function summary(): string
    {
        if ($this->connectionProtocolErrors === 0) {
            return 'connection_protocol_errors=0';
        }

        $parts = [];
        foreach ($this->errorsByMessage as $message => $count) {
            $parts[] = sprintf('%s:%d', $message, $count);
        }

        return sprintf(
            'connection_protocol_errors=%d [%s]',
            $this->connectionProtocolErrors,
            implode(', ', $parts)
        );
    }
}
