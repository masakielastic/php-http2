<?php
declare(strict_types=1);

final class Http2CompletionEventFactory
{
    public function __construct(private readonly string $role)
    {
    }

    /**
     * @return list<Http2Event>
     */
    public function completionEventsForStream(int $streamId, Http2StreamState $state): array
    {
        $events = [];

        $requestEvent = $this->requestReceivedEvent($streamId, $state);
        if ($requestEvent !== null) {
            $events[] = $requestEvent;
        }

        $responseEvent = $this->responseReceivedEvent($streamId, $state);
        if ($responseEvent !== null) {
            $events[] = $responseEvent;
        }

        return $events;
    }

    private function requestReceivedEvent(int $streamId, Http2StreamState $state): ?Http2RequestReceivedEvent
    {
        if ($this->role !== 'server') {
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

    private function responseReceivedEvent(int $streamId, Http2StreamState $state): ?Http2ResponseReceivedEvent
    {
        if ($this->role !== 'client') {
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
