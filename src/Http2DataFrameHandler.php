<?php
declare(strict_types=1);

final class Http2DataFrameHandler
{
    private const ERROR_STREAM_CLOSED = 0x05;
    private const FLAG_END_STREAM = 0x01;

    /**
     * @param callable(int): Http2StreamState $streamStateResolver
     */
    public function __construct(
        private readonly Http2StreamEventFactory $streamEventFactory,
        private readonly \Closure $streamStateResolver,
    ) {
    }

    /**
     * @return list<Http2Event>
     */
    public function processDataFrame(Http2Frame $frame): array
    {
        $endStream = ($frame->flags & self::FLAG_END_STREAM) !== 0;
        $state = $this->applyRemoteDataFrame($frame->streamId, $endStream);

        return $this->streamEventFactory->eventsForDataFrame(
            $frame->streamId,
            $frame->payload,
            $endStream,
            $state,
        );
    }

    private function applyRemoteDataFrame(int $streamId, bool $endStream): Http2StreamState
    {
        $state = ($this->streamStateResolver)($streamId);
        if (!$state->headersReceived || !$state->allowsRemoteData()) {
            throw new Http2ProtocolException('DATA not allowed in current remote stream state', self::ERROR_STREAM_CLOSED, $streamId, false);
        }

        if ($endStream) {
            $state->transitionOnRemoteEndStream();
        }

        return $state;
    }
}
