<?php
declare(strict_types=1);

final class H2cSocketConnector
{
    public function connect(string $host, int $port, int $timeoutSec): H2cSocketTransport
    {
        $socket = socket_create(AF_INET, SOCK_STREAM, SOL_TCP);
        if (!$socket instanceof Socket) {
            throw new RuntimeException('failed to create h2c client socket');
        }

        $timeout = ['sec' => $timeoutSec, 'usec' => 0];
        socket_set_option($socket, SOL_SOCKET, SO_RCVTIMEO, $timeout);
        socket_set_option($socket, SOL_SOCKET, SO_SNDTIMEO, $timeout);

        if (@socket_connect($socket, $host, $port) === false) {
            $message = socket_strerror(socket_last_error($socket));
            socket_close($socket);
            throw new RuntimeException(sprintf('h2c connect failed: %s', $message));
        }

        return new H2cSocketTransport($socket);
    }
}
