<?php
declare(strict_types=1);

final class Http2HeaderFrameHandler
{
    private const ERROR_PROTOCOL_ERROR = 0x01;
    private const FLAG_END_STREAM = 0x01;
    private const FLAG_END_HEADERS = 0x04;

    /**
     * @param callable(int): Http2StreamState $streamStateResolver
     */
    public function __construct(
        private readonly Http2ContinuationBuffer $continuationBuffer,
        private readonly ?HpackHeaderDecoder $headerDecoder,
        private readonly Http2StreamEventFactory $streamEventFactory,
        private readonly \Closure $streamStateResolver,
    ) {
    }

    /**
     * @return list<Http2Event>
     */
    public function processHeadersFrame(Http2Frame $frame): array
    {
        if ($frame->streamId === 0) {
            throw new Http2ProtocolException('HEADERS on stream 0 is invalid', self::ERROR_PROTOCOL_ERROR, null, true);
        }

        $endStream = ($frame->flags & self::FLAG_END_STREAM) !== 0;
        if (($frame->flags & self::FLAG_END_HEADERS) !== 0) {
            return $this->completeHeadersBlock($frame->streamId, $frame->payload, $endStream);
        }

        if ($this->continuationBuffer->expectsContinuation()) {
            throw new Http2ProtocolException('invalid CONTINUATION sequence', self::ERROR_PROTOCOL_ERROR, $frame->streamId, true);
        }

        $this->continuationBuffer->begin($frame->streamId, $frame->payload, $endStream);

        return [];
    }

    /**
     * @return list<Http2Event>
     */
    public function processContinuationFrame(Http2Frame $frame): array
    {
        if (!$this->continuationBuffer->expectsContinuation() || $frame->streamId !== $this->continuationBuffer->expectedStreamId()) {
            throw new Http2ProtocolException('invalid CONTINUATION sequence', self::ERROR_PROTOCOL_ERROR, $frame->streamId, true);
        }

        $this->continuationBuffer->append($frame);
        if (($frame->flags & self::FLAG_END_HEADERS) === 0) {
            return [];
        }

        $continuation = $this->continuationBuffer->release();

        return $this->completeHeadersBlock(
            $continuation['streamId'],
            $continuation['headerBlock'],
            $continuation['endStream'],
        );
    }

    /**
     * @return list<Http2Event>
     */
    private function completeHeadersBlock(int $streamId, string $headerBlock, bool $endStream): array
    {
        $decodedHeaders = $this->decodeHeadersBlock($headerBlock);
        $state = $this->applyRemoteHeadersFrame($streamId, $headerBlock, $decodedHeaders, $endStream);

        return $this->streamEventFactory->eventsForHeadersFrame(
            $streamId,
            $headerBlock,
            $endStream,
            $decodedHeaders,
            $state,
        );
    }

    /**
     * @return list<array{name: string, value: string}>|null
     */
    private function decodeHeadersBlock(string $headerBlock): ?array
    {
        if ($this->headerDecoder === null) {
            return null;
        }

        try {
            return $this->headerDecoder->decode($headerBlock);
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * @param list<array{name: string, value: string}>|null $decodedHeaders
     */
    private function applyRemoteHeadersFrame(int $streamId, string $headerBlock, ?array $decodedHeaders, bool $endStream): Http2StreamState
    {
        $state = ($this->streamStateResolver)($streamId);
        $state->headersReceived = true;
        $state->headerBlock = $headerBlock;
        $state->headers = $decodedHeaders;
        $state->transitionOnRemoteHeaders($endStream);

        return $state;
    }
}
