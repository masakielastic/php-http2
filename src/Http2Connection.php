<?php
declare(strict_types=1);

final class Http2Connection
{
    public const FRAME_TYPE_DATA = 0x00;
    public const FRAME_TYPE_HEADERS = 0x01;
    public const FRAME_TYPE_RST_STREAM = 0x03;
    public const FRAME_TYPE_SETTINGS = 0x04;
    public const FRAME_TYPE_PING = 0x06;
    public const FRAME_TYPE_GOAWAY = 0x07;
    public const FRAME_TYPE_CONTINUATION = 0x09;

    private const ROLE_CLIENT = 'client';
    private const ROLE_SERVER = 'server';
    private const CLIENT_PREFACE = "PRI * HTTP/2.0\r\n\r\nSM\r\n\r\n";
    private const ERROR_NO_ERROR = 0x00;
    private const ERROR_PROTOCOL_ERROR = 0x01;
    private const ERROR_FRAME_SIZE_ERROR = 0x06;
    private const ERROR_STREAM_CLOSED = 0x05;
    private const FLAG_ACK = 0x01;
    private const FLAG_END_STREAM = 0x01;
    private const FLAG_END_HEADERS = 0x04;

    private readonly Http2IncrementalFrameDecoder $decoder;
    private readonly Http2OutboundBuffer $outboundBuffer;
    private readonly Http2BufferedFrameWriter $frameWriter;
    private readonly Http2FrameProcessor $frameProcessor;
    /** @var array<int, Http2StreamState> */
    private array $streams = [];
    private string $prefaceBuffer = '';
    private bool $prefaceReceived = false;
    private bool $goAwayReceived = false;
    private bool $goAwaySent = false;
    private ?int $continuationStreamId = null;
    private string $continuationHeaderBlock = '';
    private bool $continuationEndStream = false;

    private function __construct(
        private readonly string $role,
        private readonly ?HpackHeaderDecoder $headerDecoder = null,
    ) 
    {
        $this->decoder = new Http2IncrementalFrameDecoder();
        $this->outboundBuffer = new Http2OutboundBuffer();
        $this->frameWriter = new Http2BufferedFrameWriter($this->outboundBuffer);
        $this->frameProcessor = new Http2FrameProcessor($this);
    }

    public static function client(): self
    {
        return new self(self::ROLE_CLIENT, new HpackHeaderDecoder());
    }

    public static function server(): self
    {
        return new self(self::ROLE_SERVER, new HpackHeaderDecoder());
    }

    public function initiateConnection(): void
    {
        if ($this->role !== self::ROLE_CLIENT) {
            throw new LogicException('Only client connections can initiate the HTTP/2 preface.');
        }

        $this->outboundBuffer->append(self::CLIENT_PREFACE);
        $this->frameWriter->writeFrame(self::FRAME_TYPE_SETTINGS, 0x00, 0, '');
    }

    /**
     * @return list<Http2Event>
     */
    public function receiveData(string $payload): array
    {
        $events = [];
        if ($this->role === self::ROLE_SERVER) {
            $payload = $this->consumePreface($payload, $events);
            if ($payload === '') {
                return $events;
            }
        }

        $this->decoder->append($payload);

        while (($frame = $this->decoder->nextFrame()) !== null) {
            $events[] = new Http2FrameReceivedEvent($frame);
            try {
                $this->validateIncomingFrame($frame);
                foreach ($this->frameProcessor->process($frame) as $event) {
                    $events[] = $event;
                }
            } catch (Http2ProtocolException $e) {
                $error = $this->handleProtocolFailure($frame, $e);
                $events[] = $error;
                if ($error->connectionError) {
                    break;
                }
            }
        }

        return $events;
    }

    private function validateIncomingFrame(Http2Frame $frame): void
    {
        if ($this->goAwayReceived && $frame->type !== self::FRAME_TYPE_GOAWAY) {
            throw new Http2ProtocolException('received frame after GOAWAY', self::ERROR_PROTOCOL_ERROR, $frame->streamId ?: null, true);
        }

        if ($this->continuationStreamId !== null && $frame->type !== self::FRAME_TYPE_CONTINUATION) {
            throw new Http2ProtocolException('expected CONTINUATION frame', self::ERROR_PROTOCOL_ERROR, $frame->streamId ?: $this->continuationStreamId, true);
        }
    }

    /**
     * @return list<Http2Event>
     */
    /**
     * @return list<Http2Event>
     */
    private function completionEventsForStream(int $streamId): array
    {
        $events = [];

        foreach ($this->emitRequestReceivedIfComplete($streamId) as $event) {
            $events[] = $event;
        }

        foreach ($this->emitResponseReceivedIfComplete($streamId) as $event) {
            $events[] = $event;
        }

        return $events;
    }

    public function sendHeaders(int $streamId, string $headerBlock, bool $endStream = false): void
    {
        if ($this->goAwayReceived || $this->goAwaySent) {
            throw new Http2ProtocolException('cannot open new stream after GOAWAY', self::ERROR_PROTOCOL_ERROR, $streamId, true);
        }

        $state = $this->getOrCreateStreamState($streamId);
        $state->openLocal($endStream);

        $flags = self::FLAG_END_HEADERS;
        if ($endStream) {
            $flags |= self::FLAG_END_STREAM;
        }

        $this->frameWriter->writeFrame(self::FRAME_TYPE_HEADERS, $flags, $streamId, $headerBlock);
    }

    public function sendData(int $streamId, string $payload, bool $endStream = false): void
    {
        $state = $this->getOrCreateStreamState($streamId);
        if (!$state->canSendData()) {
            throw new Http2ProtocolException('DATA not allowed in current local stream state', self::ERROR_STREAM_CLOSED, $streamId, false);
        }

        if ($endStream) {
            $state->markLocalClosed();
        }

        $flags = $endStream ? self::FLAG_END_STREAM : 0x00;
        $this->frameWriter->writeFrame(self::FRAME_TYPE_DATA, $flags, $streamId, $payload);
    }

    public function resetStream(int $streamId, int $errorCode = 0): void
    {
        if ($streamId === 0) {
            throw new Http2ProtocolException('RST_STREAM on stream 0 is invalid', self::ERROR_PROTOCOL_ERROR, null, true);
        }

        $this->getOrCreateStreamState($streamId)->close();
        $this->frameWriter->writeFrame(self::FRAME_TYPE_RST_STREAM, 0x00, $streamId, pack('N', $errorCode));
    }

    public function sendGoAway(int $lastStreamId = 0, int $errorCode = 0): void
    {
        $this->goAwaySent = true;
        $payload = pack('NN', $lastStreamId & 0x7fffffff, $errorCode);
        $this->frameWriter->writeFrame(self::FRAME_TYPE_GOAWAY, 0x00, 0, $payload);
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
        $payload = (string) substr($payload, strlen($chunk));

        if (strlen($this->prefaceBuffer) < strlen(self::CLIENT_PREFACE)) {
            return '';
        }

        if ($this->prefaceBuffer !== self::CLIENT_PREFACE) {
            $events[] = $this->failConnection('invalid client preface', self::ERROR_PROTOCOL_ERROR);
            return '';
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
                throw new Http2ProtocolException('SETTINGS ACK must have empty payload', self::ERROR_FRAME_SIZE_ERROR, null, true);
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
    private function handleRstStreamFrame(Http2Frame $frame): array
    {
        if ($frame->streamId === 0) {
            throw new Http2ProtocolException('RST_STREAM on stream 0 is invalid', self::ERROR_PROTOCOL_ERROR, null, true);
        }

        if ($frame->length !== 4) {
            throw new Http2ProtocolException('RST_STREAM must have a 4-byte error code', self::ERROR_FRAME_SIZE_ERROR, $frame->streamId, true);
        }

        $errorCode = unpack('Ncode', $frame->payload)['code'];
        $this->getOrCreateStreamState($frame->streamId)->close();

        return [new Http2StreamResetEvent($frame->streamId, $errorCode)];
    }

    private function handleGoAwayFrame(Http2Frame $frame): Http2GoAwayReceivedEvent
    {
        if ($frame->streamId !== 0) {
            throw new Http2ProtocolException('GOAWAY must be sent on stream 0', self::ERROR_PROTOCOL_ERROR, null, true);
        }

        if ($frame->length < 8) {
            throw new Http2ProtocolException('GOAWAY must include last stream id and error code', self::ERROR_FRAME_SIZE_ERROR, null, true);
        }

        $parts = unpack('Nlast_stream_id/Nerror_code', substr($frame->payload, 0, 8));
        $this->goAwayReceived = true;

        return new Http2GoAwayReceivedEvent(
            $frame,
            $parts['last_stream_id'] & 0x7fffffff,
            $parts['error_code'],
        );
    }

    /**
     * @return list<Http2Event>
     */
    private function handleHeadersFrame(Http2Frame $frame): array
    {
        if ($frame->streamId === 0) {
            throw new Http2ProtocolException('HEADERS on stream 0 is invalid', self::ERROR_PROTOCOL_ERROR, null, true);
        }

        $endStream = ($frame->flags & self::FLAG_END_STREAM) !== 0;
        if (($frame->flags & self::FLAG_END_HEADERS) !== 0) {
            return $this->buildCompletedHeadersEvents($frame->streamId, $frame->payload, $endStream);
        }

        if ($this->continuationStreamId !== null) {
            throw new Http2ProtocolException('invalid CONTINUATION sequence', self::ERROR_PROTOCOL_ERROR, $frame->streamId, true);
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
            throw new Http2ProtocolException('invalid CONTINUATION sequence', self::ERROR_PROTOCOL_ERROR, $frame->streamId, true);
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
        $decodedHeaders = null;
        if ($this->headerDecoder !== null) {
            try {
                $decodedHeaders = $this->headerDecoder->decode($headerBlock);
            } catch (Throwable) {
                $decodedHeaders = null;
            }
        }

        $this->recordHeadersFrame($streamId, $headerBlock, $decodedHeaders, $endStream);
        $events = [new Http2HeadersReceivedEvent($streamId, $headerBlock, $endStream, $decodedHeaders)];
        foreach ($this->completionEventsForStream($streamId) as $event) {
            $events[] = $event;
        }
        if ($endStream) {
            $events[] = new Http2StreamEndedEvent($streamId);
        }

        return $events;
    }

    /**
     * @param list<array{name: string, value: string}>|null $decodedHeaders
     */
    private function recordHeadersFrame(int $streamId, string $headerBlock, ?array $decodedHeaders, bool $endStream): void
    {
        $state = $this->getOrCreateStreamState($streamId);
        $state->headersReceived = true;
        $state->headerBlock = $headerBlock;
        $state->headers = $decodedHeaders;
        $state->openRemote($endStream);
    }

    private function recordDataFrame(int $streamId, bool $endStream): void
    {
        $state = $this->getOrCreateStreamState($streamId);
        if (!$state->headersReceived || !$state->canReceiveData()) {
            throw new Http2ProtocolException('DATA not allowed in current remote stream state', self::ERROR_STREAM_CLOSED, $streamId, false);
        }

        if ($endStream) {
            $state->markRemoteClosed();
        }
    }

    /**
     * @return list<Http2Event>
     */
    private function emitRequestReceivedIfComplete(int $streamId): array
    {
        if ($this->role !== self::ROLE_SERVER) {
            return [];
        }

        $state = $this->getOrCreateStreamState($streamId);
        if (!$state->headersReceived || !$state->isRemoteClosed() || $state->requestEmitted) {
            return [];
        }

        $state->requestEmitted = true;

        return [
            new Http2RequestReceivedEvent(
                $streamId,
                $state->headerBlock ?? '',
                $state->headers,
            ),
        ];
    }

    /**
     * @return list<Http2Event>
     */
    private function emitResponseReceivedIfComplete(int $streamId): array
    {
        if ($this->role !== self::ROLE_CLIENT) {
            return [];
        }

        $state = $this->getOrCreateStreamState($streamId);
        if (!$state->locallyInitiated || !$state->headersReceived || !$state->isRemoteClosed() || $state->responseEmitted) {
            return [];
        }

        $state->responseEmitted = true;

        return [
            new Http2ResponseReceivedEvent(
                $streamId,
                $state->headerBlock ?? '',
                $state->headers,
            ),
        ];
    }

    private function handleProtocolFailure(Http2Frame $frame, Http2ProtocolException $e): Http2ProtocolErrorEvent
    {
        $streamId = $e->streamId ?? ($frame->streamId !== 0 ? $frame->streamId : null);
        if (!$e->connectionError && $streamId !== null) {
            return $this->failStream($streamId, $e->getMessage(), $e->errorCode);
        }

        return $this->failConnection($e->getMessage(), $e->errorCode, $streamId);
    }

    private function failStream(int $streamId, string $message, int $errorCode): Http2ProtocolErrorEvent
    {
        if ($streamId !== 0) {
            $this->resetStream($streamId, $errorCode);
        }

        return new Http2ProtocolErrorEvent($message, $errorCode, $streamId, false);
    }

    private function failConnection(string $message, int $errorCode, ?int $streamId = null): Http2ProtocolErrorEvent
    {
        if (!$this->goAwaySent) {
            $this->sendGoAway($streamId ?? 0, $errorCode);
        }

        return new Http2ProtocolErrorEvent($message, $errorCode, $streamId, true);
    }

    private function getOrCreateStreamState(int $streamId): Http2StreamState
    {
        if (!isset($this->streams[$streamId])) {
            $this->streams[$streamId] = new Http2StreamState();
        }

        return $this->streams[$streamId];
    }

    /**
     * @return list<Http2Event>
     */
    public function processSettingsFrame(Http2Frame $frame): array
    {
        return $this->handleSettingsFrame($frame);
    }

    /**
     * @return list<Http2Event>
     */
    public function processRstStreamFrame(Http2Frame $frame): array
    {
        return $this->handleRstStreamFrame($frame);
    }

    /**
     * @return list<Http2Event>
     */
    public function processPingFrame(Http2Frame $frame): array
    {
        $this->handlePingFrame($frame);
        return [];
    }

    /**
     * @return list<Http2Event>
     */
    public function processHeadersFrame(Http2Frame $frame): array
    {
        return $this->handleHeadersFrame($frame);
    }

    /**
     * @return list<Http2Event>
     */
    public function processContinuationFrame(Http2Frame $frame): array
    {
        return $this->handleContinuationFrame($frame);
    }

    /**
     * @return list<Http2Event>
     */
    public function processDataFrame(Http2Frame $frame): array
    {
        $endStream = ($frame->flags & self::FLAG_END_STREAM) !== 0;
        $this->recordDataFrame($frame->streamId, $endStream);

        $events = [new Http2DataReceivedEvent($frame->streamId, $frame->payload, $endStream)];
        foreach ($this->completionEventsForStream($frame->streamId) as $event) {
            $events[] = $event;
        }
        if ($endStream) {
            $events[] = new Http2StreamEndedEvent($frame->streamId);
        }

        return $events;
    }

    /**
     * @return list<Http2Event>
     */
    public function processGoAwayFrame(Http2Frame $frame): array
    {
        return [$this->handleGoAwayFrame($frame)];
    }
}
