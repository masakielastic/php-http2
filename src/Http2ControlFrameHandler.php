<?php
declare(strict_types=1);

final class Http2ControlFrameHandler
{
    private const ERROR_PROTOCOL_ERROR = 0x01;
    private const ERROR_FRAME_SIZE_ERROR = 0x06;
    private const FLAG_ACK = 0x01;

    /**
     * @param callable(int): Http2StreamState $streamStateResolver
     */
    public function __construct(
        private readonly Http2FrameSender $frameSender,
        private readonly \Closure $streamStateResolver,
    ) {
    }

    /**
     * @return list<Http2Event>
     */
    public function processSettingsFrame(Http2Frame $frame): array
    {
        if (($frame->flags & self::FLAG_ACK) !== 0) {
            if ($frame->length !== 0) {
                throw new Http2ProtocolException('SETTINGS ACK must have empty payload', self::ERROR_FRAME_SIZE_ERROR, null, true);
            }

            return [];
        }

        $this->frameSender->sendSettingsAck();

        return [new Http2SettingsReceivedEvent()];
    }

    /**
     * @return list<Http2Event>
     */
    public function processPingFrame(Http2Frame $frame): array
    {
        if ($frame->length !== 8) {
            return [];
        }

        if (($frame->flags & self::FLAG_ACK) !== 0) {
            return [];
        }

        $this->frameSender->sendPingAck($frame->payload);

        return [];
    }

    /**
     * @return list<Http2Event>
     */
    public function processRstStreamFrame(Http2Frame $frame): array
    {
        if ($frame->streamId === 0) {
            throw new Http2ProtocolException('RST_STREAM on stream 0 is invalid', self::ERROR_PROTOCOL_ERROR, null, true);
        }

        if ($frame->length !== 4) {
            throw new Http2ProtocolException('RST_STREAM must have a 4-byte error code', self::ERROR_FRAME_SIZE_ERROR, $frame->streamId, true);
        }

        $errorCode = unpack('Ncode', $frame->payload)['code'];
        ($this->streamStateResolver)($frame->streamId)->close();

        return [new Http2StreamResetEvent($frame->streamId, $errorCode)];
    }

    public function processGoAwayFrame(Http2Frame $frame): Http2GoAwayReceivedEvent
    {
        if ($frame->streamId !== 0) {
            throw new Http2ProtocolException('GOAWAY must be sent on stream 0', self::ERROR_PROTOCOL_ERROR, null, true);
        }

        if ($frame->length < 8) {
            throw new Http2ProtocolException('GOAWAY must include last stream id and error code', self::ERROR_FRAME_SIZE_ERROR, null, true);
        }

        $parts = unpack('Nlast_stream_id/Nerror_code', substr($frame->payload, 0, 8));

        return new Http2GoAwayReceivedEvent(
            $frame,
            $parts['last_stream_id'] & 0x7fffffff,
            $parts['error_code'],
        );
    }
}
