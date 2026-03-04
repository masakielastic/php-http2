<?php
declare(strict_types=1);

final class Http2ServerProtocol
{
    private const CLIENT_PREFACE = "PRI * HTTP/2.0\r\n\r\nSM\r\n\r\n";
    private const FRAME_TYPE_DATA = 0x00;
    private const FRAME_TYPE_HEADERS = 0x01;
    private const FRAME_TYPE_SETTINGS = 0x04;
    private const FRAME_TYPE_PING = 0x06;
    private const FRAME_TYPE_GOAWAY = 0x07;
    private const FRAME_TYPE_CONTINUATION = 0x09;
    private const FLAG_ACK = 0x01;
    private const FLAG_END_STREAM = 0x01;
    private const FLAG_END_HEADERS = 0x04;

    private readonly Http2IncrementalFrameDecoder $decoder;
    private readonly Http2OutboundBuffer $outboundBuffer;
    private readonly Http2BufferedFrameWriter $frameWriter;
    private string $prefaceBuffer = '';
    private bool $prefaceReceived = false;
    private ?int $continuationStreamId = null;
    private string $continuationHeaderBlock = '';
    private bool $continuationEndStream = false;

    public function __construct()
    {
        $this->decoder = new Http2IncrementalFrameDecoder();
        $this->outboundBuffer = new Http2OutboundBuffer();
        $this->frameWriter = new Http2BufferedFrameWriter($this->outboundBuffer);
    }

    /**
     * @return list<Http2Event>
     */
    public function receiveData(string $payload): array
    {
        $events = [];
        $payload = $this->consumePreface($payload, $events);
        if ($payload === '') {
            return $events;
        }

        $this->decoder->append($payload);

        while (($frame = $this->decoder->nextFrame()) !== null) {
            if ($this->continuationStreamId !== null && $frame->type !== self::FRAME_TYPE_CONTINUATION) {
                throw new RuntimeException('expected CONTINUATION frame');
            }

            $events[] = new Http2FrameReceivedEvent($frame);

            switch ($frame->type) {
                case self::FRAME_TYPE_SETTINGS:
                    foreach ($this->handleSettingsFrame($frame) as $event) {
                        $events[] = $event;
                    }
                    break;

                case self::FRAME_TYPE_PING:
                    $this->handlePingFrame($frame);
                    break;

                case self::FRAME_TYPE_HEADERS:
                    foreach ($this->handleHeadersFrame($frame) as $event) {
                        $events[] = $event;
                    }
                    break;

                case self::FRAME_TYPE_CONTINUATION:
                    foreach ($this->handleContinuationFrame($frame) as $event) {
                        $events[] = $event;
                    }
                    break;

                case self::FRAME_TYPE_DATA:
                    $endStream = ($frame->flags & self::FLAG_END_STREAM) !== 0;
                    $events[] = new Http2DataReceivedEvent($frame->streamId, $frame->payload, $endStream);
                    if ($endStream) {
                        $events[] = new Http2StreamEndedEvent($frame->streamId);
                    }
                    break;

                case self::FRAME_TYPE_GOAWAY:
                    $events[] = new Http2GoAwayReceivedEvent($frame);
                    break;
            }
        }

        return $events;
    }

    public function sendResponse(int $streamId, string $headerBlock, string $body): void
    {
        $headerFlags = self::FLAG_END_HEADERS;
        if ($body === '') {
            $headerFlags |= self::FLAG_END_STREAM;
        }

        $this->frameWriter->writeFrame(self::FRAME_TYPE_HEADERS, $headerFlags, $streamId, $headerBlock);
        if ($body !== '') {
            $this->frameWriter->writeFrame(self::FRAME_TYPE_DATA, self::FLAG_END_STREAM, $streamId, $body);
        }
    }

    public function dataToSend(): string
    {
        return $this->outboundBuffer->drain();
    }

    /**
     * @param list<Http2Event> $events
     */
    private function consumePreface(string $payload, array &$events): string
    {
        if ($this->prefaceReceived) {
            return $payload;
        }

        $remaining = strlen(self::CLIENT_PREFACE) - strlen($this->prefaceBuffer);
        $chunk = substr($payload, 0, $remaining);
        $this->prefaceBuffer .= $chunk;
        $payload = (string)substr($payload, strlen($chunk));

        if (strlen($this->prefaceBuffer) < strlen(self::CLIENT_PREFACE)) {
            return '';
        }

        if ($this->prefaceBuffer !== self::CLIENT_PREFACE) {
            throw new RuntimeException('invalid client preface');
        }

        $this->prefaceReceived = true;
        $events[] = new Http2ConnectionPrefaceReceivedEvent();
        $this->frameWriter->writeFrame(self::FRAME_TYPE_SETTINGS, 0x00, 0, '');

        return $payload;
    }

    /**
     * @return list<Http2Event>
     */
    private function handleSettingsFrame(Http2Frame $frame): array
    {
        if (($frame->flags & self::FLAG_ACK) !== 0) {
            if ($frame->length !== 0) {
                throw new RuntimeException('SETTINGS ACK must have empty payload');
            }

            return [];
        }

        $this->frameWriter->writeFrame(self::FRAME_TYPE_SETTINGS, self::FLAG_ACK, 0, '');

        return [new Http2SettingsReceivedEvent()];
    }

    private function handlePingFrame(Http2Frame $frame): void
    {
        if ($frame->length !== 8) {
            return;
        }

        if (($frame->flags & self::FLAG_ACK) !== 0) {
            return;
        }

        $this->frameWriter->writeFrame(self::FRAME_TYPE_PING, self::FLAG_ACK, 0, $frame->payload);
    }

    /**
     * @return list<Http2Event>
     */
    private function handleHeadersFrame(Http2Frame $frame): array
    {
        if ($frame->streamId === 0) {
            throw new RuntimeException('HEADERS on stream 0 is invalid');
        }

        $endStream = ($frame->flags & self::FLAG_END_STREAM) !== 0;
        if (($frame->flags & self::FLAG_END_HEADERS) !== 0) {
            return $this->buildCompletedHeadersEvents($frame->streamId, $frame->payload, $endStream);
        }

        if ($this->continuationStreamId !== null) {
            throw new RuntimeException('invalid CONTINUATION sequence');
        }

        $this->continuationStreamId = $frame->streamId;
        $this->continuationHeaderBlock = $frame->payload;
        $this->continuationEndStream = $endStream;

        return [];
    }

    /**
     * @return list<Http2Event>
     */
    private function handleContinuationFrame(Http2Frame $frame): array
    {
        if ($this->continuationStreamId === null || $frame->streamId !== $this->continuationStreamId) {
            throw new RuntimeException('invalid CONTINUATION sequence');
        }

        $this->continuationHeaderBlock .= $frame->payload;
        if (($frame->flags & self::FLAG_END_HEADERS) === 0) {
            return [];
        }

        $events = $this->buildCompletedHeadersEvents(
            $this->continuationStreamId,
            $this->continuationHeaderBlock,
            $this->continuationEndStream,
        );

        $this->continuationStreamId = null;
        $this->continuationHeaderBlock = '';
        $this->continuationEndStream = false;

        return $events;
    }

    /**
     * @return list<Http2Event>
     */
    private function buildCompletedHeadersEvents(int $streamId, string $headerBlock, bool $endStream): array
    {
        $events = [new Http2HeadersReceivedEvent($streamId, $headerBlock, $endStream)];
        if ($endStream) {
            $events[] = new Http2StreamEndedEvent($streamId);
        }

        return $events;
    }
}
