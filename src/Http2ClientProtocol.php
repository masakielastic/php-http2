<?php
declare(strict_types=1);

final class Http2ClientProtocol
{
    private const CLIENT_PREFACE = "PRI * HTTP/2.0\r\n\r\nSM\r\n\r\n";
    private const FRAME_TYPE_DATA = 0x00;
    private const FRAME_TYPE_HEADERS = 0x01;
    private const FRAME_TYPE_SETTINGS = 0x04;
    private const FRAME_TYPE_GOAWAY = 0x07;
    private const FRAME_TYPE_CONTINUATION = 0x09;
    private const FLAG_ACK = 0x01;
    private const FLAG_END_STREAM = 0x01;
    private const FLAG_END_HEADERS = 0x04;
    private const REQUEST_STREAM_ID = 1;

    private readonly Http2IncrementalFrameDecoder $decoder;
    private readonly Http2OutboundBuffer $outboundBuffer;
    private readonly Http2BufferedFrameWriter $frameWriter;
    private ?int $continuationStreamId = null;
    private string $continuationHeaderBlock = '';
    private bool $continuationEndStream = false;

    public function __construct(private readonly HpackRequestEncoder $hpackEncoder)
    {
        $this->decoder = new Http2IncrementalFrameDecoder();
        $this->outboundBuffer = new Http2OutboundBuffer();
        $this->frameWriter = new Http2BufferedFrameWriter($this->outboundBuffer);
    }

    public function initiateConnection(): void
    {
        $this->outboundBuffer->append(self::CLIENT_PREFACE);
        $this->frameWriter->writeFrame(self::FRAME_TYPE_SETTINGS, 0x00, 0, '');
    }

    /**
     * @return list<Http2Event>
     */
    public function receiveData(string $payload): array
    {
        $events = [];
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

    public function sendRequest(string $host, string $path): void
    {
        $headerBlock = $this->hpackEncoder->buildRequestHeaderBlock($host, $path);
        $flags = self::FLAG_END_STREAM | self::FLAG_END_HEADERS;
        $this->frameWriter->writeFrame(self::FRAME_TYPE_HEADERS, $flags, self::REQUEST_STREAM_ID, $headerBlock);
    }

    public function dataToSend(): string
    {
        return $this->outboundBuffer->drain();
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
