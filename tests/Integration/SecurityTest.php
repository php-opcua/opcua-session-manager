<?php

declare(strict_types=1);

use Gianfriaur\OpcuaPhpClient\Types\NodeId;
use Gianfriaur\OpcuaSessionManager\Client\ManagedClient;
use Gianfriaur\OpcuaSessionManager\Client\SocketConnection;
use Gianfriaur\OpcuaSessionManager\Exception\DaemonException;
use Gianfriaur\OpcuaSessionManager\Tests\Integration\Helpers\TestHelper;

// ── Auth daemon management ────────────────────────────────────────────

const AUTH_SOCKET_PATH = '/tmp/opcua-session-manager-security-test.sock';
const AUTH_TOKEN = 'test-secret-token-a8b3c9f2e1d0';

$_authProcess = null;

function ensureAuthDaemon(): void
{
    global $_authProcess;
    if ($_authProcess !== null) {
        return;
    }

    $socketPath = AUTH_SOCKET_PATH;
    $pidFile = $socketPath . '.pid';

    if (file_exists($socketPath)) {
        unlink($socketPath);
    }
    if (file_exists($pidFile)) {
        unlink($pidFile);
    }

    $binPath = __DIR__ . '/../../bin/opcua-session-manager';
    $cmd = sprintf(
        'php %s --socket %s --timeout 60 --cleanup-interval 5 --auth-token %s --max-sessions 3',
        escapeshellarg($binPath),
        escapeshellarg($socketPath),
        escapeshellarg(AUTH_TOKEN),
    );

    $descriptors = [
        0 => ['pipe', 'r'],
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w'],
    ];

    $_authProcess = proc_open($cmd, $descriptors, $pipes);

    if (!is_resource($_authProcess)) {
        throw new \RuntimeException('Failed to start auth daemon');
    }

    $maxWait = 50;
    while ($maxWait > 0 && !file_exists($socketPath)) {
        usleep(100_000);
        $maxWait--;
    }

    if (!file_exists($socketPath)) {
        stopAuthDaemon();
        throw new \RuntimeException('Auth daemon socket did not appear');
    }
}

function stopAuthDaemon(): void
{
    global $_authProcess;
    $socketPath = AUTH_SOCKET_PATH;
    $pidFile = $socketPath . '.pid';

    if ($_authProcess === null) {
        return;
    }

    $status = proc_get_status($_authProcess);
    if ($status['running']) {
        proc_terminate($_authProcess, 15);
        $maxWait = 30;
        while ($maxWait > 0) {
            $status = proc_get_status($_authProcess);
            if (!$status['running']) {
                break;
            }
            usleep(100_000);
            $maxWait--;
        }
        $status = proc_get_status($_authProcess);
        if ($status['running']) {
            proc_terminate($_authProcess, 9);
        }
    }
    proc_close($_authProcess);
    $_authProcess = null;

    if (file_exists($socketPath)) {
        unlink($socketPath);
    }
    if (file_exists($pidFile)) {
        unlink($pidFile);
    }
}

// ── Lifecycle ─────────────────────────────────────────────────────────

beforeAll(function () {
    TestHelper::startDaemon();
    ensureAuthDaemon();
});

afterAll(function () {
    TestHelper::stopDaemon();
    stopAuthDaemon();
});

// ── Method whitelist ──────────────────────────────────────────────────

describe('Security: Method whitelist (integration)', function () {

    it('rejects forbidden methods via daemon IPC', function () {
        $response = SocketConnection::send(TestHelper::SOCKET_PATH, [
            'command' => 'open',
            'endpointUrl' => TestHelper::ENDPOINT_NO_SECURITY,
            'config' => [],
        ]);
        $sessionId = $response['data']['sessionId'];

        try {
            $response = SocketConnection::send(TestHelper::SOCKET_PATH, [
                'command' => 'query',
                'sessionId' => $sessionId,
                'method' => 'setUserCredentials',
                'params' => ['admin', 'password'],
            ]);

            expect($response['success'])->toBeFalse();
            expect($response['error']['type'])->toBe('forbidden_method');
        } finally {
            SocketConnection::send(TestHelper::SOCKET_PATH, [
                'command' => 'close',
                'sessionId' => $sessionId,
            ]);
        }
    })->group('integration');

    it('rejects __destruct via daemon IPC', function () {
        $response = SocketConnection::send(TestHelper::SOCKET_PATH, [
            'command' => 'open',
            'endpointUrl' => TestHelper::ENDPOINT_NO_SECURITY,
            'config' => [],
        ]);
        $sessionId = $response['data']['sessionId'];

        try {
            $response = SocketConnection::send(TestHelper::SOCKET_PATH, [
                'command' => 'query',
                'sessionId' => $sessionId,
                'method' => '__destruct',
                'params' => [],
            ]);

            expect($response['success'])->toBeFalse();
            expect($response['error']['type'])->toBe('forbidden_method');
        } finally {
            SocketConnection::send(TestHelper::SOCKET_PATH, [
                'command' => 'close',
                'sessionId' => $sessionId,
            ]);
        }
    })->group('integration');

    it('rejects connect via query', function () {
        $response = SocketConnection::send(TestHelper::SOCKET_PATH, [
            'command' => 'open',
            'endpointUrl' => TestHelper::ENDPOINT_NO_SECURITY,
            'config' => [],
        ]);
        $sessionId = $response['data']['sessionId'];

        try {
            $response = SocketConnection::send(TestHelper::SOCKET_PATH, [
                'command' => 'query',
                'sessionId' => $sessionId,
                'method' => 'connect',
                'params' => ['opc.tcp://evil:4840'],
            ]);

            expect($response['success'])->toBeFalse();
            expect($response['error']['type'])->toBe('forbidden_method');
        } finally {
            SocketConnection::send(TestHelper::SOCKET_PATH, [
                'command' => 'close',
                'sessionId' => $sessionId,
            ]);
        }
    })->group('integration');

    it('rejects disconnect via query', function () {
        $response = SocketConnection::send(TestHelper::SOCKET_PATH, [
            'command' => 'open',
            'endpointUrl' => TestHelper::ENDPOINT_NO_SECURITY,
            'config' => [],
        ]);
        $sessionId = $response['data']['sessionId'];

        try {
            $response = SocketConnection::send(TestHelper::SOCKET_PATH, [
                'command' => 'query',
                'sessionId' => $sessionId,
                'method' => 'disconnect',
                'params' => [],
            ]);

            expect($response['success'])->toBeFalse();
            expect($response['error']['type'])->toBe('forbidden_method');
        } finally {
            SocketConnection::send(TestHelper::SOCKET_PATH, [
                'command' => 'close',
                'sessionId' => $sessionId,
            ]);
        }
    })->group('integration');

    it('allows whitelisted methods', function () {
        $response = SocketConnection::send(TestHelper::SOCKET_PATH, [
            'command' => 'open',
            'endpointUrl' => TestHelper::ENDPOINT_NO_SECURITY,
            'config' => [],
        ]);
        $sessionId = $response['data']['sessionId'];

        try {
            $response = SocketConnection::send(TestHelper::SOCKET_PATH, [
                'command' => 'query',
                'sessionId' => $sessionId,
                'method' => 'browse',
                'params' => [
                    ['ns' => 0, 'id' => 85, 'type' => 'numeric'],
                ],
            ]);

            expect($response['success'])->toBeTrue();
            expect($response['data'])->toBeArray()->not->toBeEmpty();
        } finally {
            SocketConnection::send(TestHelper::SOCKET_PATH, [
                'command' => 'close',
                'sessionId' => $sessionId,
            ]);
        }
    })->group('integration');

})->group('integration');

// ── Credential stripping ──────────────────────────────────────────────

describe('Security: Credential stripping (integration)', function () {

    it('does not expose sensitive fields in list output', function () {
        $response = SocketConnection::send(TestHelper::SOCKET_PATH, [
            'command' => 'open',
            'endpointUrl' => TestHelper::ENDPOINT_NO_SECURITY,
            'config' => [],
        ]);
        expect($response['success'])->toBeTrue();
        $sessionId = $response['data']['sessionId'];

        try {
            $listResponse = SocketConnection::send(TestHelper::SOCKET_PATH, ['command' => 'list']);
            foreach ($listResponse['data']['sessions'] as $s) {
                expect($s['config'])->not->toHaveKey('password');
                expect($s['config'])->not->toHaveKey('clientKeyPath');
                expect($s['config'])->not->toHaveKey('userKeyPath');
                expect($s['config'])->not->toHaveKey('caCertPath');
            }
        } finally {
            SocketConnection::send(TestHelper::SOCKET_PATH, [
                'command' => 'close',
                'sessionId' => $sessionId,
            ]);
        }
    })->group('integration');

})->group('integration');

// ── Buffer overflow ───────────────────────────────────────────────────

describe('Security: Buffer overflow protection (integration)', function () {

    it('rejects oversized payloads', function () {
        $socket = stream_socket_client('unix://' . TestHelper::SOCKET_PATH, $errno, $errstr, 5);
        expect($socket)->not->toBeFalse();

        // Send >1MB of data without a newline, then the newline
        $payload = str_repeat('A', 1_100_000);
        fwrite($socket, $payload . "\n");

        $response = '';
        stream_set_timeout($socket, 5);
        while (!feof($socket)) {
            $chunk = fread($socket, 65536);
            if ($chunk === false || $chunk === '') {
                break;
            }
            $response .= $chunk;
            if (str_contains($response, "\n")) {
                break;
            }
        }
        fclose($socket);

        $decoded = json_decode(trim($response), true);
        expect($decoded)->not->toBeNull();
        expect($decoded['success'])->toBeFalse();
        expect($decoded['error']['type'])->toBe('payload_too_large');
    })->group('integration');

})->group('integration');

// ── Socket permissions ────────────────────────────────────────────────

describe('Security: Socket permissions (integration)', function () {

    it('creates socket file without world-writable bit', function () {
        $perms = fileperms(TestHelper::SOCKET_PATH) & 0777;
        expect($perms & 0002)->toBe(0, 'Socket should not be world-writable');
    })->group('integration');

})->group('integration');

// ── Auth token ────────────────────────────────────────────────────────

describe('Security: Auth token (integration)', function () {

    it('rejects requests without auth token', function () {
        $response = SocketConnection::send(AUTH_SOCKET_PATH, [
            'command' => 'ping',
        ]);

        expect($response['success'])->toBeFalse();
        expect($response['error']['type'])->toBe('auth_failed');
    })->group('integration');

    it('rejects requests with wrong auth token', function () {
        $response = SocketConnection::send(AUTH_SOCKET_PATH, [
            'command' => 'ping',
            'authToken' => 'wrong-token',
        ]);

        expect($response['success'])->toBeFalse();
        expect($response['error']['type'])->toBe('auth_failed');
    })->group('integration');

    it('accepts requests with correct auth token', function () {
        $response = SocketConnection::send(AUTH_SOCKET_PATH, [
            'command' => 'ping',
            'authToken' => AUTH_TOKEN,
        ]);

        expect($response['success'])->toBeTrue();
        expect($response['data']['status'])->toBe('ok');
    })->group('integration');

    it('works with ManagedClient and auth token', function () {
        $client = new ManagedClient(
            socketPath: AUTH_SOCKET_PATH,
            authToken: AUTH_TOKEN,
        );

        $client->connect(TestHelper::ENDPOINT_NO_SECURITY);
        expect($client->getSessionId())->toBeString()->not->toBeEmpty();

        $dataValue = $client->read(NodeId::numeric(0, 2259));
        expect($dataValue->getValue())->toBeInt();

        $client->disconnect();
    })->group('integration');

    it('rejects ManagedClient without auth token', function () {
        $client = new ManagedClient(socketPath: AUTH_SOCKET_PATH);

        expect(fn() => $client->connect(TestHelper::ENDPOINT_NO_SECURITY))
            ->toThrow(DaemonException::class);
    })->group('integration');

})->group('integration');

// ── Max sessions ──────────────────────────────────────────────────────

describe('Security: Max sessions limit (integration)', function () {

    it('enforces max sessions limit', function () {
        $sessionIds = [];
        try {
            // Open 3 sessions (the max for auth daemon)
            for ($i = 0; $i < 3; $i++) {
                $response = SocketConnection::send(AUTH_SOCKET_PATH, [
                    'command' => 'open',
                    'endpointUrl' => TestHelper::ENDPOINT_NO_SECURITY,
                    'config' => [],
                    'authToken' => AUTH_TOKEN,
                ]);
                expect($response['success'])->toBeTrue();
                $sessionIds[] = $response['data']['sessionId'];
            }

            // 4th should be rejected
            $response = SocketConnection::send(AUTH_SOCKET_PATH, [
                'command' => 'open',
                'endpointUrl' => TestHelper::ENDPOINT_NO_SECURITY,
                'config' => [],
                'authToken' => AUTH_TOKEN,
            ]);

            expect($response['success'])->toBeFalse();
            expect($response['error']['type'])->toBe('max_sessions_reached');
        } finally {
            foreach ($sessionIds as $id) {
                SocketConnection::send(AUTH_SOCKET_PATH, [
                    'command' => 'close',
                    'sessionId' => $id,
                    'authToken' => AUTH_TOKEN,
                ]);
            }
        }
    })->group('integration');

    it('allows new sessions after closing one at max capacity', function () {
        $sessionIds = [];
        try {
            // Fill to max
            for ($i = 0; $i < 3; $i++) {
                $response = SocketConnection::send(AUTH_SOCKET_PATH, [
                    'command' => 'open',
                    'endpointUrl' => TestHelper::ENDPOINT_NO_SECURITY,
                    'config' => [],
                    'authToken' => AUTH_TOKEN,
                ]);
                expect($response['success'])->toBeTrue();
                $sessionIds[] = $response['data']['sessionId'];
            }

            // Close one
            SocketConnection::send(AUTH_SOCKET_PATH, [
                'command' => 'close',
                'sessionId' => array_shift($sessionIds),
                'authToken' => AUTH_TOKEN,
            ]);

            // Now should be able to open again
            $response = SocketConnection::send(AUTH_SOCKET_PATH, [
                'command' => 'open',
                'endpointUrl' => TestHelper::ENDPOINT_NO_SECURITY,
                'config' => [],
                'authToken' => AUTH_TOKEN,
            ]);
            expect($response['success'])->toBeTrue();
            $sessionIds[] = $response['data']['sessionId'];
        } finally {
            foreach ($sessionIds as $id) {
                SocketConnection::send(AUTH_SOCKET_PATH, [
                    'command' => 'close',
                    'sessionId' => $id,
                    'authToken' => AUTH_TOKEN,
                ]);
            }
        }
    })->group('integration');

})->group('integration');
