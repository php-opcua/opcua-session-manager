<?php

declare(strict_types=1);

use PhpOpcua\SessionManager\Client\SocketConnection;
use PhpOpcua\SessionManager\Exception\DaemonException;

function createFakeServer(bool $respond, string $response = '', int $delayMs = 0): array
{
    $socketPath = sys_get_temp_dir() . '/opcua_test_' . bin2hex(random_bytes(4)) . '.sock';

    if (file_exists($socketPath)) {
        unlink($socketPath);
    }

    $server = stream_socket_server("unix://{$socketPath}", $errorCode, $errorMessage);
    if ($server === false) {
        throw new RuntimeException("Cannot create test server: [{$errorCode}] {$errorMessage}");
    }

    $pid = pcntl_fork();
    if ($pid === -1) {
        throw new RuntimeException('Cannot fork');
    }

    if ($pid === 0) {
        $conn = stream_socket_accept($server, 5);
        if ($conn !== false) {
            $data = '';
            while (!str_contains($data, "\n")) {
                $chunk = fread($conn, 65536);
                if ($chunk === false || $chunk === '') {
                    break;
                }
                $data .= $chunk;
            }

            if ($delayMs > 0) {
                usleep($delayMs * 1000);
            }

            if ($respond && $response !== '') {
                fwrite($conn, $response . "\n");
            }

            fclose($conn);
        }
        fclose($server);
        if (file_exists($socketPath)) {
            unlink($socketPath);
        }
        exit(0);
    }

    fclose($server);
    usleep(50_000);

    return ['socketPath' => $socketPath, 'pid' => $pid];
}

function cleanupFakeServer(array $server): void
{
    pcntl_waitpid($server['pid'], $status, WNOHANG);
    posix_kill($server['pid'], SIGTERM);
    pcntl_waitpid($server['pid'], $status);
    if (file_exists($server['socketPath'])) {
        unlink($server['socketPath']);
    }
}

describe('SocketConnection', function () {

    it('throws when socket file does not exist', function () {
        expect(fn() => SocketConnection::send('/nonexistent/socket.sock', ['command' => 'ping']))
            ->toThrow(DaemonException::class, 'Socket not found');
    });

    it('throws when socket file is not a valid socket', function () {
        $tmpFile = tempnam(sys_get_temp_dir(), 'opcua_fake_sock_');

        try {
            expect(fn() => SocketConnection::send($tmpFile, ['command' => 'ping'], 1.0))
                ->toThrow(DaemonException::class, 'Cannot connect to daemon');
        } finally {
            unlink($tmpFile);
        }
    });

    it('throws on empty response when server closes without responding', function () {
        $server = createFakeServer(respond: false);

        try {
            expect(fn() => SocketConnection::send($server['socketPath'], ['command' => 'ping'], 2.0))
                ->toThrow(DaemonException::class, 'Empty response from daemon');
        } finally {
            cleanupFakeServer($server);
        }
    });

    it('throws on timeout when server holds connection open without responding', function () {
        $server = createFakeServer(respond: false, delayMs: 3000);

        try {
            expect(fn() => SocketConnection::send($server['socketPath'], ['command' => 'ping'], 1.0))
                ->toThrow(DaemonException::class, 'Daemon request timed out');
        } finally {
            cleanupFakeServer($server);
        }
    });

    it('throws on invalid JSON response', function () {
        $server = createFakeServer(respond: true, response: 'not-json{{{');

        try {
            expect(fn() => SocketConnection::send($server['socketPath'], ['command' => 'ping'], 2.0))
                ->toThrow(JsonException::class);
        } finally {
            cleanupFakeServer($server);
        }
    });

    it('throws on non-array JSON response', function () {
        $server = createFakeServer(respond: true, response: '"just a string"');

        try {
            expect(fn() => SocketConnection::send($server['socketPath'], ['command' => 'ping'], 2.0))
                ->toThrow(DaemonException::class, 'Invalid response from daemon');
        } finally {
            cleanupFakeServer($server);
        }
    });

    it('returns decoded array on valid JSON response', function () {
        $server = createFakeServer(respond: true, response: json_encode(['success' => true, 'data' => 'ok']));

        try {
            $result = SocketConnection::send($server['socketPath'], ['command' => 'ping'], 2.0);
            expect($result)->toBe(['success' => true, 'data' => 'ok']);
        } finally {
            cleanupFakeServer($server);
        }
    });

    it('sends JSON payload with newline delimiter', function () {
        $server = createFakeServer(respond: true, response: json_encode(['success' => true, 'data' => null]));

        try {
            $result = SocketConnection::send($server['socketPath'], ['command' => 'ping', 'extra' => 'value'], 2.0);
            expect($result['success'])->toBeTrue();
        } finally {
            cleanupFakeServer($server);
        }
    });

    it('closeAndThrowIf closes socket and throws when condition is true', function () {
        $method = new ReflectionMethod(SocketConnection::class, 'closeAndThrowIf');
        $method->setAccessible(true);

        $stream = fopen('php://memory', 'r+');

        expect(function () use ($method, $stream) {
            $method->invoke(null, $stream, true, 'Write failed');
        })->toThrow(DaemonException::class, 'Write failed');

        expect(is_resource($stream))->toBeFalse();
    });

    it('closeAndThrowIf does nothing when condition is false', function () {
        $method = new ReflectionMethod(SocketConnection::class, 'closeAndThrowIf');
        $method->setAccessible(true);

        $stream = fopen('php://memory', 'r+');
        $method->invoke(null, $stream, false, 'Write failed');

        expect(is_resource($stream))->toBeTrue();
        fclose($stream);
    });

});
