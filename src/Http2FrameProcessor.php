<?php
declare(strict_types=1);

final class Http2FrameProcessor
{
    public function __construct(private readonly Http2Connection $connection)
    {
    }

    /**
     * @return list<Http2Event>
     */
    public function process(Http2Frame $frame): array
    {
        return match ($frame->type) {
            Http2Connection::FRAME_TYPE_SETTINGS => $this->connection->processSettingsFrame($frame),
            Http2Connection::FRAME_TYPE_RST_STREAM => $this->connection->processRstStreamFrame($frame),
            Http2Connection::FRAME_TYPE_PING => $this->connection->processPingFrame($frame),
            Http2Connection::FRAME_TYPE_HEADERS => $this->connection->processHeadersFrame($frame),
            Http2Connection::FRAME_TYPE_CONTINUATION => $this->connection->processContinuationFrame($frame),
            Http2Connection::FRAME_TYPE_DATA => $this->connection->processDataFrame($frame),
            Http2Connection::FRAME_TYPE_GOAWAY => $this->connection->processGoAwayFrame($frame),
            default => [],
        };
    }
}
