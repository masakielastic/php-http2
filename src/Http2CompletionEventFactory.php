<?php
declare(strict_types=1);

final class Http2CompletionEventFactory
{
    private readonly Http2RequestCompletionEmitter $requestEmitter;
    private readonly Http2ResponseCompletionEmitter $responseEmitter;

    public function __construct(string $role)
    {
        $this->requestEmitter = new Http2RequestCompletionEmitter($role === 'server');
        $this->responseEmitter = new Http2ResponseCompletionEmitter($role === 'client');
    }

    /**
     * @return list<Http2Event>
     */
    public function completionEventsForStream(int $streamId, Http2StreamState $state): array
    {
        $events = [];

        $requestEvent = $this->requestEmitter->requestReceivedEvent($streamId, $state);
        if ($requestEvent !== null) {
            $events[] = $requestEvent;
        }

        $responseEvent = $this->responseEmitter->responseReceivedEvent($streamId, $state);
        if ($responseEvent !== null) {
            $events[] = $responseEvent;
        }

        return $events;
    }
}
