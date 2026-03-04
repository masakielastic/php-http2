<?php
declare(strict_types=1);

final class Http2PrefaceHandler
{
    private string $buffer = '';
    private bool $received = false;

    public function __construct(private readonly string $expectedPreface)
    {
    }

    public function hasReceivedPreface(): bool
    {
        return $this->received;
    }

    /**
     * @param list<Http2Event> $events
     */
    public function consume(string $payload, array &$events, callable $onPrefaceComplete, callable $onPrefaceError): string
    {
        if ($this->received) {
            return $payload;
        }

        $remaining = strlen($this->expectedPreface) - strlen($this->buffer);
        $chunk = substr($payload, 0, $remaining);
        $this->buffer .= $chunk;
        $payload = (string) substr($payload, strlen($chunk));

        if (strlen($this->buffer) < strlen($this->expectedPreface)) {
            return '';
        }

        if ($this->buffer !== $this->expectedPreface) {
            $events[] = $onPrefaceError();
            return '';
        }

        $this->received = true;
        $onPrefaceComplete($events);

        return $payload;
    }
}
