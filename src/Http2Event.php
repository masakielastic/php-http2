<?php
declare(strict_types=1);

interface Http2Event
{
}

final class Http2FrameReceivedEvent implements Http2Event
{
    public function __construct(public readonly Http2Frame $frame)
    {
    }
}

final class Http2SettingsReceivedEvent implements Http2Event
{
}

final class Http2HeadersReceivedEvent implements Http2Event
{
    /**
     * @param list<array{name: string, value: string}>|null $headers
     */
    public function __construct(
        public readonly int $streamId,
        public readonly string $headerBlock,
        public readonly bool $endStream,
        public readonly ?array $headers = null,
    ) {
    }
}

final class Http2DataReceivedEvent implements Http2Event
{
    public function __construct(
        public readonly int $streamId,
        public readonly string $data,
        public readonly bool $endStream,
    ) {
    }
}

final class Http2StreamEndedEvent implements Http2Event
{
    public function __construct(public readonly int $streamId)
    {
    }
}

final class Http2GoAwayReceivedEvent implements Http2Event
{
    public function __construct(
        public readonly Http2Frame $frame,
        public readonly int $lastStreamId,
        public readonly int $errorCode,
    )
    {
    }
}

final class Http2ConnectionPrefaceReceivedEvent implements Http2Event
{
}

final class Http2RequestReceivedEvent implements Http2Event
{
    /**
     * @param list<array{name: string, value: string}>|null $headers
     */
    public function __construct(
        public readonly int $streamId,
        public readonly string $headerBlock,
        public readonly ?array $headers = null,
    ) {
    }
}

final class Http2ResponseReceivedEvent implements Http2Event
{
    /**
     * @param list<array{name: string, value: string}>|null $headers
     */
    public function __construct(
        public readonly int $streamId,
        public readonly string $headerBlock,
        public readonly ?array $headers = null,
    ) {
    }
}

final class Http2StreamResetEvent implements Http2Event
{
    public function __construct(
        public readonly int $streamId,
        public readonly int $errorCode,
    ) {
    }
}
