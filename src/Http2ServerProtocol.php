<?php
declare(strict_types=1);

final class Http2ServerProtocol
{
    private readonly Http2Connection $connection;

    public function __construct()
    {
        $this->connection = Http2Connection::server();
    }

    /**
     * @return list<Http2Event>
     */
    public function receiveData(string $payload): array
    {
        return $this->connection->receiveData($payload);
    }

    public function sendResponse(int $streamId, string $headerBlock, string $body): void
    {
        $this->connection->sendHeaders($streamId, $headerBlock, $body === '');
        if ($body !== '') {
            $this->connection->sendData($streamId, $body, true);
        }
    }

    public function dataToSend(): string
    {
        return $this->connection->dataToSend();
    }
}
