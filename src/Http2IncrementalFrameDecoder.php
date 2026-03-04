<?php
declare(strict_types=1);

final class Http2IncrementalFrameDecoder
{
    private string $buffer = '';

    public function append(string $payload): void
    {
        if ($payload === '') {
            return;
        }

        $this->buffer .= $payload;
    }

    public function nextFrame(): ?Http2Frame
    {
        if (strlen($this->buffer) < 9) {
            return null;
        }

        $decoded = Http2FrameCodec::decodeHeader(substr($this->buffer, 0, 9));
        $frameLength = 9 + $decoded['length'];
        if (strlen($this->buffer) < $frameLength) {
            return null;
        }

        $payload = $decoded['length'] > 0 ? substr($this->buffer, 9, $decoded['length']) : '';
        $this->buffer = (string)substr($this->buffer, $frameLength);

        return new Http2Frame(
            $decoded['length'],
            $decoded['type'],
            $decoded['flags'],
            $decoded['streamId'],
            $payload
        );
    }
}
