<?php
declare(strict_types=1);

final class Http2RequestCompletionEmitter
{
    public function __construct(private readonly bool $enabled)
    {
    }

    public function requestReceivedEvent(int $streamId, Http2StreamState $state): ?Http2RequestReceivedEvent
    {
        if (!$this->enabled) {
            return null;
        }

        if (!$state->headersReceived || !$state->isRemoteClosed() || $state->requestEmitted) {
            return null;
        }

        $state->requestEmitted = true;

        return new Http2RequestReceivedEvent(
            $streamId,
            $state->headerBlock ?? '',
            $state->headers,
        );
    }
}
