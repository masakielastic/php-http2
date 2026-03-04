<?php
declare(strict_types=1);

final class Http2ClientProtocol
{
    private const REQUEST_STREAM_ID = 1;

    private readonly Http2Connection $connection;

    public function __construct(private readonly HpackRequestEncoder $hpackEncoder)
    {
        $this->connection = Http2Connection::client();
    }

    public function initiateConnection(): void
    {
        $this->connection->initiateConnection();
    }

    /**
     * @return list<Http2Event>
     */
    public function receiveData(string $payload): array
    {
        return $this->connection->receiveData($payload);
    }

    public function sendRequest(string $host, string $path): void
    {
        $headerBlock = $this->hpackEncoder->buildRequestHeaderBlock($host, $path);
        $this->connection->sendHeaders(self::REQUEST_STREAM_ID, $headerBlock, true);
    }

    public function dataToSend(): string
    {
        return $this->connection->dataToSend();
    }
}
