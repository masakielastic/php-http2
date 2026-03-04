<?php
declare(strict_types=1);

final class Http2FrameReader
{
    public function __construct(private readonly Http2Transport $transport)
    {
    }

    public function readFrame(): ?Http2Frame
    {
        $header = $this->transport->read(9);
        if ($header === null || strlen($header) < 9) {
            return null;
        }
        $decoded = Http2FrameCodec::decodeHeader($header);

        $payload = $decoded['length'] > 0 ? $this->transport->read($decoded['length']) : '';
        if ($decoded['length'] > 0 && ($payload === null || strlen($payload) < $decoded['length'])) {
            return null;
        }

        return new Http2Frame(
            $decoded['length'],
            $decoded['type'],
            $decoded['flags'],
            $decoded['streamId'],
            $payload
        );
    }
}
