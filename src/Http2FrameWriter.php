<?php
declare(strict_types=1);

final class Http2FrameWriter
{
    public function __construct(private readonly Http2Transport $transport)
    {
    }

    public function writeFrame(int $type, int $flags, int $streamId, string $payload): void
    {
        $this->transport->write(Http2FrameCodec::pack($type, $flags, $streamId, $payload));
    }
}
