<?php

declare(strict_types=1);

namespace Gianfriaur\OpcuaSessionManager\Tests\Integration\Helpers;

use Gianfriaur\OpcuaPhpClient\Types\NodeId;
use Gianfriaur\OpcuaPhpClient\Types\ReferenceDescription;
use Gianfriaur\OpcuaSessionManager\Client\ManagedClient;
use Gianfriaur\OpcuaSessionManager\Client\SocketConnection;
use RuntimeException;
use Throwable;

final class TestHelper
{
    // ── Endpoint URLs (same as opcua-php-client) ────────────────────────
    public const ENDPOINT_NO_SECURITY = 'opc.tcp://localhost:4840/UA/TestServer';
    public const ENDPOINT_USERPASS = 'opc.tcp://localhost:4841/UA/TestServer';
    public const ENDPOINT_CERTIFICATE = 'opc.tcp://localhost:4842/UA/TestServer';
    public const ENDPOINT_ALL_SECURITY = 'opc.tcp://localhost:4843/UA/TestServer';
    public const ENDPOINT_DISCOVERY = 'opc.tcp://localhost:4844';
    public const ENDPOINT_AUTO_ACCEPT = 'opc.tcp://localhost:4845/UA/TestServer';
    public const ENDPOINT_SIGN_ONLY = 'opc.tcp://localhost:4846/UA/TestServer';
    public const ENDPOINT_LEGACY = 'opc.tcp://localhost:4847/UA/TestServer';

    // ── Socket path for test daemon ─────────────────────────────────────
    public const SOCKET_PATH = '/tmp/opcua-session-manager-test.sock';

    // ── Certificate paths ───────────────────────────────────────────────
    public static function getCertsDir(): string
    {
        $dir = getenv('OPCUA_CERTS_DIR') ?: __DIR__ . '/../../../../opcua-test-server-suite/certs';

        return realpath($dir) ?: $dir;
    }

    public static function getClientCertPath(): string
    {
        return self::getCertsDir() . '/client/cert.pem';
    }

    public static function getClientKeyPath(): string
    {
        return self::getCertsDir() . '/client/key.pem';
    }

    public static function getCaCertPath(): string
    {
        return self::getCertsDir() . '/ca/ca-cert.pem';
    }

    // ── Users ───────────────────────────────────────────────────────────
    public const USER_ADMIN = ['username' => 'admin', 'password' => 'admin123'];
    public const USER_OPERATOR = ['username' => 'operator', 'password' => 'operator123'];
    public const USER_VIEWER = ['username' => 'viewer', 'password' => 'viewer123'];
    public const USER_TEST = ['username' => 'test', 'password' => 'test'];

    // ── Well-known NodeIds ──────────────────────────────────────────────
    public const NODE_ROOT = [0, 84];
    public const NODE_OBJECTS = [0, 85];
    public const NODE_SERVER = [0, 2253];
    public const NODE_SERVER_STATUS = [0, 2256];
    public const NODE_SERVER_STATE = [0, 2259];

    // ── Daemon process management ───────────────────────────────────────
    private static $daemonProcess = null;
    private static int $daemonStartCount = 0;

    public static function startDaemon(): void
    {
        self::$daemonStartCount++;
        if (self::$daemonProcess !== null) {
            return;
        }

        // Clean up stale socket and PID file
        if (file_exists(self::SOCKET_PATH)) {
            unlink(self::SOCKET_PATH);
        }
        if (file_exists(self::SOCKET_PATH . '.pid')) {
            unlink(self::SOCKET_PATH . '.pid');
        }

        $binPath = __DIR__ . '/../../../bin/opcua-session-manager';
        $cmd = sprintf(
            'php %s --socket %s --timeout 60 --cleanup-interval 5',
            escapeshellarg($binPath),
            escapeshellarg(self::SOCKET_PATH),
        );

        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];

        self::$daemonProcess = proc_open($cmd, $descriptors, $pipes);

        if (!is_resource(self::$daemonProcess)) {
            throw new RuntimeException('Failed to start daemon');
        }

        // Wait for socket to appear
        $maxWait = 50; // 5 seconds max
        while ($maxWait > 0 && !file_exists(self::SOCKET_PATH)) {
            usleep(100_000);
            $maxWait--;
        }

        if (!file_exists(self::SOCKET_PATH)) {
            self::stopDaemon();
            throw new RuntimeException('Daemon socket did not appear within 5 seconds');
        }

        // Verify daemon is responding
        $response = SocketConnection::send(self::SOCKET_PATH, ['command' => 'ping']);
        if (!($response['success'] ?? false)) {
            self::stopDaemon();
            throw new RuntimeException('Daemon is not responding to ping');
        }
    }

    public static function stopDaemon(): void
    {
        if (self::$daemonProcess === null) {
            return;
        }

        $status = proc_get_status(self::$daemonProcess);
        if ($status['running']) {
            // Send SIGTERM
            proc_terminate(self::$daemonProcess, 15);
            // Wait a bit for graceful shutdown
            $maxWait = 30;
            while ($maxWait > 0) {
                $status = proc_get_status(self::$daemonProcess);
                if (!$status['running']) {
                    break;
                }
                usleep(100_000);
                $maxWait--;
            }

            // Force kill if still running
            $status = proc_get_status(self::$daemonProcess);
            if ($status['running']) {
                proc_terminate(self::$daemonProcess, 9);
            }
        }

        proc_close(self::$daemonProcess);
        self::$daemonProcess = null;

        if (file_exists(self::SOCKET_PATH)) {
            unlink(self::SOCKET_PATH);
        }
        if (file_exists(self::SOCKET_PATH . '.pid')) {
            unlink(self::SOCKET_PATH . '.pid');
        }
    }

    public static function isDaemonRunning(): bool
    {
        if (self::$daemonProcess === null) {
            return false;
        }

        $status = proc_get_status(self::$daemonProcess);

        return $status['running'];
    }

    // ── Client helpers ──────────────────────────────────────────────────

    public static function createManagedClient(): ManagedClient
    {
        return new ManagedClient(self::SOCKET_PATH);
    }

    public static function connectNoSecurity(): ManagedClient
    {
        $client = self::createManagedClient();
        $client->connect(self::ENDPOINT_NO_SECURITY);

        return $client;
    }

    public static function browseToNode(ManagedClient $client, array $path): NodeId
    {
        $currentNodeId = NodeId::numeric(0, 85); // Objects folder

        foreach ($path as $name) {
            $refs = $client->browse($currentNodeId);
            $found = false;

            foreach ($refs as $ref) {
                $browseName = $ref->browseName->name;
                $displayName = (string)$ref->displayName;

                if ($browseName === $name || $displayName === $name) {
                    $currentNodeId = $ref->nodeId;
                    $found = true;
                    break;
                }
            }

            if (!$found) {
                $availableNames = array_map(
                    fn(ReferenceDescription $r) => $r->browseName->name,
                    $refs,
                );
                throw new RuntimeException(
                    "Could not find child node '{$name}' under node. "
                    . "Available: " . implode(', ', $availableNames)
                );
            }
        }

        return $currentNodeId;
    }

    public static function findRefByName(array $refs, string $name): ?ReferenceDescription
    {
        foreach ($refs as $ref) {
            if ($ref->browseName->name === $name || (string)$ref->displayName === $name) {
                return $ref;
            }
        }

        return null;
    }

    public static function safeDisconnect(?ManagedClient $client): void
    {
        if ($client === null) {
            return;
        }
        try {
            $client->disconnect();
        } catch (Throwable) {
        }
    }
}
