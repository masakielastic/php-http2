<?php
declare(strict_types=1);

final class Http2FrameSender
{
    public function __construct(private readonly Http2BufferedFrameWriter $frameWriter)
    {
    }

    public function sendSettingsAck(): void
    {
        $this->frameWriter->writeFrame(Http2Connection::FRAME_TYPE_SETTINGS, 0x01, 0, '');
    }

    public function sendPingAck(string $payload): void
    {
        $this->frameWriter->writeFrame(Http2Connection::FRAME_TYPE_PING, 0x01, 0, $payload);
    }

    public function sendHeaders(int $streamId, string $headerBlock, bool $endStream): void
    {
        $flags = 0x04;
        if ($endStream) {
            $flags |= 0x01;
        }

        $this->frameWriter->writeFrame(Http2Connection::FRAME_TYPE_HEADERS, $flags, $streamId, $headerBlock);
    }

    public function sendData(int $streamId, string $payload, bool $endStream): void
    {
        $flags = $endStream ? 0x01 : 0x00;
        $this->frameWriter->writeFrame(Http2Connection::FRAME_TYPE_DATA, $flags, $streamId, $payload);
    }

    public function sendRstStream(int $streamId, int $errorCode): void
    {
        $this->frameWriter->writeFrame(Http2Connection::FRAME_TYPE_RST_STREAM, 0x00, $streamId, pack('N', $errorCode));
    }

    public function sendGoAway(int $lastStreamId, int $errorCode): void
    {
        $payload = pack('NN', $lastStreamId & 0x7fffffff, $errorCode);
        $this->frameWriter->writeFrame(Http2Connection::FRAME_TYPE_GOAWAY, 0x00, 0, $payload);
    }

    public function sendInitialSettings(): void
    {
        $this->frameWriter->writeFrame(Http2Connection::FRAME_TYPE_SETTINGS, 0x00, 0, '');
    }
}
