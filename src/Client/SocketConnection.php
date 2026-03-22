<?php

declare(strict_types=1);

namespace Gianfriaur\OpcuaSessionManager\Client;

use Gianfriaur\OpcuaSessionManager\Exception\DaemonException;

/**
 * Low-level Unix socket transport for sending JSON-encoded IPC commands to the daemon.
 */
class SocketConnection
{
    /**
     * @param string $socketPath Path to the daemon's Unix socket file.
     * @param array $payload The command payload to send.
     * @param float $timeout Timeout in seconds.
     * @return array The decoded JSON response from the daemon.
     *
     * @throws DaemonException If the socket is missing, the connection fails, the request times out, or the response is invalid.
     */
    public static function send(string $socketPath, array $payload, float $timeout = 30.0): array
    {
        if (!file_exists($socketPath)) {
            throw new DaemonException("Socket not found: {$socketPath}. Is the daemon running?");
        }

        $socket = @stream_socket_client(
            "unix://{$socketPath}",
            $errorCode,
            $errorMessage,
            $timeout,
        );

        if ($socket === false) {
            throw new DaemonException("Cannot connect to daemon: [{$errorCode}] {$errorMessage}");
        }

        stream_set_timeout($socket, (int)$timeout, (int)(($timeout - (int)$timeout) * 1_000_000));

        $json = json_encode($payload, JSON_THROW_ON_ERROR);
        $written = fwrite($socket, $json . "\n");

        if ($written === false) {
            fclose($socket);
            throw new DaemonException('Failed to write to daemon socket');
        }

        $response = '';
        while (!feof($socket)) {
            $chunk = fread($socket, 65536);
            if ($chunk === false) {
                break;
            }
            $response .= $chunk;

            if (str_contains($response, "\n")) {
                break;
            }
        }

        $meta = stream_get_meta_data($socket);
        fclose($socket);

        if ($meta['timed_out']) {
            throw new DaemonException('Daemon request timed out');
        }

        $response = trim($response);
        if ($response === '') {
            throw new DaemonException('Empty response from daemon');
        }

        $decoded = json_decode($response, true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($decoded)) {
            throw new DaemonException('Invalid response from daemon');
        }

        return $decoded;
    }
}
