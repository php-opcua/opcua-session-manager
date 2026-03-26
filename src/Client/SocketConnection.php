<?php

declare(strict_types=1);

namespace PhpOpcua\SessionManager\Client;

use PhpOpcua\SessionManager\Exception\DaemonException;

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
        self::throwDaemonExceptionIf(
            !file_exists($socketPath),
            "Socket not found: {$socketPath}. Is the daemon running?",
        );

        $socket = @stream_socket_client(
            "unix://{$socketPath}",
            $errorCode,
            $errorMessage,
            $timeout,
        );

        self::throwDaemonExceptionIf(
            $socket === false,
            "Cannot connect to daemon: [{$errorCode}] {$errorMessage}",
        );

        stream_set_timeout($socket, (int)$timeout, (int)(($timeout - (int)$timeout) * 1_000_000));

        $json = json_encode($payload, JSON_THROW_ON_ERROR);
        $written = fwrite($socket, $json . "\n");

        self::closeAndThrowIf($socket, $written === false, 'Failed to write to daemon socket');

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

        self::throwDaemonExceptionIf($meta['timed_out'], 'Daemon request timed out');

        $response = trim($response);

        self::throwDaemonExceptionIf($response === '', 'Empty response from daemon');

        $decoded = json_decode($response, true, 512, JSON_THROW_ON_ERROR);

        self::throwDaemonExceptionIf(!is_array($decoded), 'Invalid response from daemon');

        return $decoded;
    }

    /**
     * @param bool $condition
     * @param string $message
     * @return void
     *
     * @throws DaemonException
     */
    private static function throwDaemonExceptionIf(bool $condition, string $message): void
    {
        if ($condition) {
            throw new DaemonException($message);
        }
    }

    /**
     * @param resource $socket
     * @param bool $condition
     * @param string $message
     * @return void
     *
     * @throws DaemonException
     */
    private static function closeAndThrowIf($socket, bool $condition, string $message): void
    {
        if ($condition) {
            fclose($socket);
            throw new DaemonException($message);
        }
    }
}
