<?php
declare(strict_types=1);

final class Http2ContinuationBuffer
{
    private ?int $streamId = null;
    private string $headerBlock = '';
    private bool $endStream = false;

    public function expectsContinuation(): bool
    {
        return $this->streamId !== null;
    }

    public function expectedStreamId(): ?int
    {
        return $this->streamId;
    }

    public function begin(int $streamId, string $headerBlock, bool $endStream): void
    {
        $this->streamId = $streamId;
        $this->headerBlock = $headerBlock;
        $this->endStream = $endStream;
    }

    public function append(Http2Frame $frame): void
    {
        if ($this->streamId === null || $frame->streamId !== $this->streamId) {
            throw new RuntimeException('invalid CONTINUATION sequence');
        }

        $this->headerBlock .= $frame->payload;
    }

    /**
     * @return array{streamId:int, headerBlock:string, endStream:bool}
     */
    public function release(): array
    {
        if ($this->streamId === null) {
            throw new RuntimeException('no CONTINUATION sequence in progress');
        }

        $state = [
            'streamId' => $this->streamId,
            'headerBlock' => $this->headerBlock,
            'endStream' => $this->endStream,
        ];

        $this->streamId = null;
        $this->headerBlock = '';
        $this->endStream = false;

        return $state;
    }
}
