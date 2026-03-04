<?php
declare(strict_types=1);

final class H2cSocketServer
{
    public function __construct(
        private readonly Logger $logger,
        private readonly Http2ServerConnection $connection,
    ) {
    }

    public function run(string $host, int $port): never
    {
        $serverSocket = socket_create(AF_INET, SOCK_STREAM, SOL_TCP);
        if (!$serverSocket instanceof Socket) {
            throw new RuntimeException('failed to create h2c socket server');
        }

        socket_set_option($serverSocket, SOL_SOCKET, SO_REUSEADDR, 1);

        if (@socket_bind($serverSocket, $host, $port) === false) {
            throw new RuntimeException(sprintf('failed to bind tcp://%s:%d: %s', $host, $port, socket_strerror(socket_last_error($serverSocket))));
        }

        if (@socket_listen($serverSocket, 128) === false) {
            throw new RuntimeException('failed to listen on h2c socket server');
        }

        $this->logger->log(sprintf('listening on tcp://%s:%d (h2c-socket)', $host, $port));

        while (true) {
            $transport = null;

            try {
                $client = @socket_accept($serverSocket);
                if (!$client instanceof Socket) {
                    throw new RuntimeException('failed to accept h2c socket');
                }

                $transport = new H2cSocketTransport($client);
                $this->logger->log(sprintf('accepted connection from %s', $transport->describePeer()));
                $this->connection->serveTransport($transport);
            } catch (Throwable $e) {
                $this->logger->log('[!] connection error: ' . $e->getMessage());
            } finally {
                if ($transport instanceof H2cSocketTransport) {
                    $transport->close();
                }
            }
        }
    }
}
