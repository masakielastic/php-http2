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

    private readonly Http2IncrementalFrameDecoder $decoder;
    private readonly Http2OutboundBuffer $outboundBuffer;
    private readonly Http2BufferedFrameWriter $frameWriter;
    private readonly Http2FrameSender $frameSender;
    private readonly Http2LocalFrameStateUpdater $localFrameStateUpdater;
    private readonly Http2ControlFrameHandler $controlFrameHandler;
    private readonly Http2HeaderFrameHandler $headerFrameHandler;
    private readonly Http2DataFrameHandler $dataFrameHandler;
    private readonly Http2FrameProcessor $frameProcessor;
    private readonly Http2CompletionEventFactory $completionEventFactory;
    private readonly Http2StreamEventFactory $streamEventFactory;
    private readonly Http2PrefaceHandler $prefaceHandler;
    private readonly Http2ContinuationBuffer $continuationBuffer;
    /** @var array<int, Http2StreamState> */
    private array $streams = [];
    private bool $goAwayReceived = false;
    private bool $goAwaySent = false;

    private function __construct(
        private readonly string $role,
        private readonly ?HpackHeaderDecoder $headerDecoder = null,
    ) 
    {
        $this->decoder = new Http2IncrementalFrameDecoder();
        $this->outboundBuffer = new Http2OutboundBuffer();
        $this->frameWriter = new Http2BufferedFrameWriter($this->outboundBuffer);
        $this->frameSender = new Http2FrameSender($this->frameWriter);
        $this->localFrameStateUpdater = new Http2LocalFrameStateUpdater(
            fn (int $streamId): Http2StreamState => $this->getOrCreateStreamState($streamId),
        );
        $this->controlFrameHandler = new Http2ControlFrameHandler(
            $this->frameSender,
            fn (int $streamId): Http2StreamState => $this->getOrCreateStreamState($streamId),
        );
        $this->completionEventFactory = new Http2CompletionEventFactory($this->role);
        $this->streamEventFactory = new Http2StreamEventFactory($this->completionEventFactory);
        $this->prefaceHandler = new Http2PrefaceHandler(self::CLIENT_PREFACE);
        $this->continuationBuffer = new Http2ContinuationBuffer();
        $this->headerFrameHandler = new Http2HeaderFrameHandler(
            $this->continuationBuffer,
            $this->headerDecoder,
            $this->streamEventFactory,
            fn (int $streamId): Http2StreamState => $this->getOrCreateStreamState($streamId),
        );
        $this->dataFrameHandler = new Http2DataFrameHandler(
            $this->streamEventFactory,
            fn (int $streamId): Http2StreamState => $this->getOrCreateStreamState($streamId),
        );
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
        $this->frameSender->sendInitialSettings();
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

        if ($this->continuationBuffer->expectsContinuation() && $frame->type !== self::FRAME_TYPE_CONTINUATION) {
            throw new Http2ProtocolException(
                'expected CONTINUATION frame',
                self::ERROR_PROTOCOL_ERROR,
                $frame->streamId ?: $this->continuationBuffer->expectedStreamId(),
                true
            );
        }
    }

    public function sendHeaders(int $streamId, string $headerBlock, bool $endStream = false): void
    {
        if ($this->goAwayReceived || $this->goAwaySent) {
            throw new Http2ProtocolException('cannot open new stream after GOAWAY', self::ERROR_PROTOCOL_ERROR, $streamId, true);
        }

        $this->localFrameStateUpdater->applyLocalHeadersFrame($streamId, $endStream);
        $this->frameSender->sendHeaders($streamId, $headerBlock, $endStream);
    }

    public function sendData(int $streamId, string $payload, bool $endStream = false): void
    {
        $this->localFrameStateUpdater->applyLocalDataFrame($streamId, $endStream);
        $this->frameSender->sendData($streamId, $payload, $endStream);
    }

    public function resetStream(int $streamId, int $errorCode = 0): void
    {
        if ($streamId === 0) {
            throw new Http2ProtocolException('RST_STREAM on stream 0 is invalid', self::ERROR_PROTOCOL_ERROR, null, true);
        }

        $this->getOrCreateStreamState($streamId)->close();
        $this->frameSender->sendRstStream($streamId, $errorCode);
    }

    public function sendGoAway(int $lastStreamId = 0, int $errorCode = 0): void
    {
        $this->goAwaySent = true;
        $this->frameSender->sendGoAway($lastStreamId, $errorCode);
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
        return $this->prefaceHandler->consume(
            $payload,
            $events,
            function (array &$events): void {
                $events[] = new Http2ConnectionPrefaceReceivedEvent();
                $this->frameSender->sendInitialSettings();
            },
            fn (): Http2ProtocolErrorEvent => $this->failConnection('invalid client preface', self::ERROR_PROTOCOL_ERROR),
        );
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
        return $this->controlFrameHandler->processSettingsFrame($frame);
    }

    /**
     * @return list<Http2Event>
     */
    public function processRstStreamFrame(Http2Frame $frame): array
    {
        return $this->controlFrameHandler->processRstStreamFrame($frame);
    }

    /**
     * @return list<Http2Event>
     */
    public function processPingFrame(Http2Frame $frame): array
    {
        return $this->controlFrameHandler->processPingFrame($frame);
    }

    /**
     * @return list<Http2Event>
     */
    public function processHeadersFrame(Http2Frame $frame): array
    {
        return $this->headerFrameHandler->processHeadersFrame($frame);
    }

    /**
     * @return list<Http2Event>
     */
    public function processContinuationFrame(Http2Frame $frame): array
    {
        return $this->headerFrameHandler->processContinuationFrame($frame);
    }

    /**
     * @return list<Http2Event>
     */
    public function processDataFrame(Http2Frame $frame): array
    {
        return $this->dataFrameHandler->processDataFrame($frame);
    }

    /**
     * @return list<Http2Event>
     */
    public function processGoAwayFrame(Http2Frame $frame): array
    {
        $this->goAwayReceived = true;

        return [$this->controlFrameHandler->processGoAwayFrame($frame)];
    }
}
