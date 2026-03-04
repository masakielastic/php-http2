<?php
declare(strict_types=1);

final class Http2BufferedFrameWriter
{
    public function __construct(private readonly Http2OutboundBuffer $buffer)
    {
    }

    public function writeFrame(int $type, int $flags, int $streamId, string $payload): void
    {
        $this->buffer->append(Http2FrameCodec::pack($type, $flags, $streamId, $payload));
    }
}
