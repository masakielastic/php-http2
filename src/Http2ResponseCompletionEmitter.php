<?php
declare(strict_types=1);

final class Http2ResponseCompletionEmitter
{
    public function __construct(private readonly bool $enabled)
    {
    }

    public function responseReceivedEvent(int $streamId, Http2StreamState $state): ?Http2ResponseReceivedEvent
    {
        if (!$this->enabled) {
            return null;
        }

        if (!$state->locallyInitiated || !$state->headersReceived || !$state->isRemoteClosed() || $state->responseEmitted) {
            return null;
        }

        $state->responseEmitted = true;

        return new Http2ResponseReceivedEvent(
            $streamId,
            $state->headerBlock ?? '',
            $state->headers,
        );
    }
}
