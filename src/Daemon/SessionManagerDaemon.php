<?php

declare(strict_types=1);

namespace Gianfriaur\OpcuaSessionManager\Daemon;

use React\EventLoop\Loop;
use React\EventLoop\LoopInterface;
use React\Socket\ConnectionInterface;
use React\Socket\UnixServer;

class SessionManagerDaemon
{
    private const MAX_BUFFER_SIZE = 1_048_576; // 1 MB
    private const IPC_CONNECTION_TIMEOUT = 30; // seconds
    private const MAX_CONCURRENT_CONNECTIONS = 50;

    private SessionStore $store;
    private CommandHandler $handler;
    private LoopInterface $loop;
    private ?UnixServer $server = null;
    private string $pidFilePath;
    private int $activeConnections = 0;

    public function __construct(
        private readonly string $socketPath,
        private readonly int $timeout = 600,
        private readonly int $cleanupInterval = 30,
        private readonly ?string $authToken = null,
        private readonly int $maxSessions = 100,
        private readonly int $socketMode = 0600,
        private readonly ?array $allowedCertDirs = null,
    ) {
        $this->store = new SessionStore();
        $this->handler = new CommandHandler($this->store, $this->maxSessions, $this->allowedCertDirs);
        $this->loop = Loop::get();
        $this->pidFilePath = $this->socketPath . '.pid';
    }

    public function run(): void
    {
        $this->acquirePidLock();

        if (file_exists($this->socketPath)) {
            unlink($this->socketPath);
        }

        $this->server = new UnixServer($this->socketPath, $this->loop);

        chmod($this->socketPath, $this->socketMode);

        $this->server->on('connection', function (ConnectionInterface $connection) {
            // Reject if too many concurrent connections
            if ($this->activeConnections >= self::MAX_CONCURRENT_CONNECTIONS) {
                $response = ['success' => false, 'error' => ['type' => 'too_many_connections', 'message' => 'Too many concurrent connections']];
                $connection->write(json_encode($response) . "\n");
                $connection->end();
                return;
            }

            $this->activeConnections++;

            // Per-connection timeout to prevent slowloris-style attacks
            $timeoutTimer = $this->loop->addTimer(self::IPC_CONNECTION_TIMEOUT, function () use ($connection) {
                $response = ['success' => false, 'error' => ['type' => 'connection_timeout', 'message' => 'IPC connection timed out']];
                $connection->write(json_encode($response) . "\n");
                $connection->end();
            });

            $connection->on('close', function () use (&$timeoutTimer) {
                $this->activeConnections--;
                if ($timeoutTimer !== null) {
                    $this->loop->cancelTimer($timeoutTimer);
                    $timeoutTimer = null;
                }
            });

            $buffer = '';
            $connection->on('data', function (string $data) use ($connection, &$buffer, &$timeoutTimer) {
                $buffer .= $data;

                if (strlen($buffer) > self::MAX_BUFFER_SIZE) {
                    $response = ['success' => false, 'error' => ['type' => 'payload_too_large', 'message' => 'Request exceeds maximum size of 1MB']];
                    $connection->write(json_encode($response) . "\n");
                    $connection->end();
                    return;
                }

                $newlinePos = strpos($buffer, "\n");
                if ($newlinePos === false) {
                    return;
                }

                // Cancel timeout — we received a complete request
                if ($timeoutTimer !== null) {
                    $this->loop->cancelTimer($timeoutTimer);
                    $timeoutTimer = null;
                }

                $json = substr($buffer, 0, $newlinePos);
                $buffer = substr($buffer, $newlinePos + 1);

                $command = json_decode($json, true);
                if (!is_array($command)) {
                    $response = ['success' => false, 'error' => ['type' => 'invalid_json', 'message' => 'Invalid JSON']];
                    $connection->write(json_encode($response) . "\n");
                    $connection->end();
                    return;
                }

                if ($this->authToken !== null) {
                    $providedToken = $command['authToken'] ?? null;
                    if (!is_string($providedToken) || !hash_equals($this->authToken, $providedToken)) {
                        $response = ['success' => false, 'error' => ['type' => 'auth_failed', 'message' => 'Invalid or missing auth token']];
                        $connection->write(json_encode($response) . "\n");
                        $connection->end();
                        return;
                    }
                    unset($command['authToken']);
                }

                $response = $this->handler->handle($command);

                $connection->write(json_encode($response) . "\n");
                $connection->end();
            });
        });

        $this->loop->addPeriodicTimer($this->cleanupInterval, function () {
            $this->cleanupExpiredSessions();
        });

        $this->setupSignalHandlers();

        if ($this->authToken === null) {
            echo "WARNING: No auth token configured. Any local process can control the daemon.\n";
            echo "         Use --auth-token or --auth-token-file for production deployments.\n";
        }

        echo "OPC UA Session Manager started on {$this->socketPath}\n";
        echo "Timeout: {$this->timeout}s, Cleanup interval: {$this->cleanupInterval}s, Max sessions: {$this->maxSessions}\n";
        echo "Socket permissions: " . decoct($this->socketMode) . "\n";

        $this->loop->run();
    }

    private function cleanupExpiredSessions(): void
    {
        $expired = $this->store->getExpired($this->timeout);
        foreach ($expired as $session) {
            try {
                $session->client->disconnect();
            } catch (\Throwable) {
            }
            $this->store->remove($session->id);
            echo "Session {$session->id} expired (endpoint: {$session->endpointUrl})\n";
        }
    }

    private function shutdown(): void
    {
        echo "\nShutting down...\n";

        foreach ($this->store->all() as $session) {
            try {
                $session->client->disconnect();
                echo "Disconnected session {$session->id}\n";
            } catch (\Throwable) {
            }
            $this->store->remove($session->id);
        }

        if ($this->server !== null) {
            $this->server->close();
        }

        if (file_exists($this->socketPath)) {
            unlink($this->socketPath);
        }

        $this->releasePidLock();

        $this->loop->stop();
    }

    private function setupSignalHandlers(): void
    {
        if (!function_exists('pcntl_signal')) {
            return;
        }

        $this->loop->addSignal(SIGTERM, fn() => $this->shutdown());
        $this->loop->addSignal(SIGINT, fn() => $this->shutdown());
    }

    private function acquirePidLock(): void
    {
        if (file_exists($this->pidFilePath)) {
            $existingPid = (int) file_get_contents($this->pidFilePath);
            if ($existingPid > 0 && $this->isProcessRunning($existingPid)) {
                throw new \RuntimeException(
                    "Another daemon instance is already running (PID: {$existingPid}). "
                    . "If this is stale, remove {$this->pidFilePath}"
                );
            }
            unlink($this->pidFilePath);
        }

        file_put_contents($this->pidFilePath, (string) getmypid());
    }

    private function releasePidLock(): void
    {
        if (file_exists($this->pidFilePath)) {
            unlink($this->pidFilePath);
        }
    }

    private function isProcessRunning(int $pid): bool
    {
        if (function_exists('posix_kill')) {
            return posix_kill($pid, 0);
        }

        return file_exists("/proc/{$pid}");
    }
}
