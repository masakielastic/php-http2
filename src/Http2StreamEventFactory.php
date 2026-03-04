<?php
declare(strict_types=1);

final class Http2StreamEventFactory
{
    public function __construct(private readonly Http2CompletionEventFactory $completionEventFactory)
    {
    }

    /**
     * @param list<array{name: string, value: string}>|null $headers
     * @return list<Http2Event>
     */
    public function eventsForHeadersFrame(
        int $streamId,
        string $headerBlock,
        bool $endStream,
        ?array $headers,
        Http2StreamState $state,
    ): array {
        $events = [
            new Http2HeadersReceivedEvent($streamId, $headerBlock, $endStream, $headers),
        ];

        foreach ($this->completionEventFactory->completionEventsForStream($streamId, $state) as $event) {
            $events[] = $event;
        }

        if ($endStream) {
            $events[] = new Http2StreamEndedEvent($streamId);
        }

        return $events;
    }

    /**
     * @return list<Http2Event>
     */
    public function eventsForDataFrame(
        int $streamId,
        string $payload,
        bool $endStream,
        Http2StreamState $state,
    ): array {
        $events = [
            new Http2DataReceivedEvent($streamId, $payload, $endStream),
        ];

        foreach ($this->completionEventFactory->completionEventsForStream($streamId, $state) as $event) {
            $events[] = $event;
        }

        if ($endStream) {
            $events[] = new Http2StreamEndedEvent($streamId);
        }

        return $events;
    }
}
