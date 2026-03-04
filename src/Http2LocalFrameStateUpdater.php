<?php
declare(strict_types=1);

final class Http2LocalFrameStateUpdater
{
    private const ERROR_STREAM_CLOSED = 0x05;

    /**
     * @param callable(int): Http2StreamState $streamStateResolver
     */
    public function __construct(private readonly \Closure $streamStateResolver)
    {
    }

    public function applyLocalHeadersFrame(int $streamId, bool $endStream): void
    {
        ($this->streamStateResolver)($streamId)->transitionOnLocalHeaders($endStream);
    }

    public function applyLocalDataFrame(int $streamId, bool $endStream): void
    {
        $state = ($this->streamStateResolver)($streamId);
        if (!$state->allowsLocalData()) {
            throw new Http2ProtocolException('DATA not allowed in current local stream state', self::ERROR_STREAM_CLOSED, $streamId, false);
        }

        if ($endStream) {
            $state->transitionOnLocalEndStream();
        }
    }
}
