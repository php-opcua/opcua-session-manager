<?php

declare(strict_types=1);

namespace PhpOpcua\SessionManager\Daemon;

use Psr\EventDispatcher\EventDispatcherInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Psr\SimpleCache\CacheInterface;
use PhpOpcua\SessionManager\Ipc\TransportFactory;
use React\EventLoop\Loop;
use React\EventLoop\LoopInterface;
use React\Socket\ConnectionInterface;
use React\Socket\ServerInterface;
use React\Socket\SocketServer;
use RuntimeException;
use Throwable;

/**
 * Long-running ReactPHP daemon that manages persistent OPC UA sessions over a Unix socket.
 */
class SessionManagerDaemon
{
    public const VERSION = '4.3.0';

    private const MAX_BUFFER_SIZE = 1_048_576;
    private const MAX_FRAME_BYTES = 65_536;
    private const IPC_CONNECTION_TIMEOUT = 30;
    private const MAX_CONCURRENT_CONNECTIONS = 50;

    private SessionStore $store;
    private CommandHandler $handler;
    private LoopInterface $loop;
    private ?ServerInterface $server = null;
    private ?AutoPublisher $autoPublisher = null;
    private string $pidFilePath;
    private int $activeConnections = 0;
    private LoggerInterface $logger;

    /** @var array<string, array{endpoint: string, config: array, subscriptions: array}> */
    private array $autoConnections = [];

    /**
     * @param string $socketPath Endpoint URI (`unix://…`, `tcp://127.0.0.1:port`) or a Unix socket path. TCP is loopback-only — any non-loopback host throws at listener construction.
     * @param int $timeout Session inactivity timeout in seconds.
     * @param int $cleanupInterval Interval in seconds between expired session cleanup runs.
     * @param ?string $authToken Shared secret for IPC authentication, or null to disable.
     * @param int $maxSessions Maximum number of concurrent sessions.
     * @param int $socketMode File permissions for the socket file.
     * @param ?array $allowedCertDirs Restrict certificate paths to these directories, or null for no restriction.
     * @param ?LoggerInterface $logger Logger for daemon events. Also injected into each OPC UA Client.
     * @param ?CacheInterface $clientCache Cache driver injected into each OPC UA Client created by the daemon.
     * @param ?EventDispatcherInterface $clientEventDispatcher PSR-14 event dispatcher injected into each OPC UA Client.
     * @param bool $autoPublish When true and an event dispatcher is provided, the daemon automatically calls publish() for sessions with active subscriptions.
     */
    public function __construct(
        private readonly string  $socketPath,
        private readonly int     $timeout = 600,
        private readonly int     $cleanupInterval = 30,
        private readonly ?string $authToken = null,
        private readonly int     $maxSessions = 100,
        private readonly int     $socketMode = 0600,
        private readonly ?array  $allowedCertDirs = null,
        ?LoggerInterface         $logger = null,
        ?CacheInterface          $clientCache = null,
        private readonly ?EventDispatcherInterface $clientEventDispatcher = null,
        private readonly bool    $autoPublish = false,
    )
    {
        $this->logger = $logger ?? new NullLogger();
        $this->store = new SessionStore();
        $this->handler = new CommandHandler(
            $this->store,
            $this->maxSessions,
            $this->allowedCertDirs,
            $this->logger,
            $clientCache,
            $this->clientEventDispatcher,
        );
        $this->loop = Loop::get();
        $this->assertLoopbackIfTcp($this->socketPath);
        $this->pidFilePath = $this->resolvePidFilePath($this->socketPath);
    }

    /**
     * Refuse a TCP listen URI that binds to a non-loopback host. Matches the
     * posture {@see \PhpOpcua\SessionManager\Ipc\TcpLoopbackTransport} enforces
     * on the client side — exposing the daemon to the network requires an
     * explicit extra transport layer (TLS, SSH tunnel), not a silent bind to
     * `0.0.0.0`.
     *
     * @param string $endpoint
     * @return void
     * @throws RuntimeException If a non-loopback TCP host is specified.
     */
    private function assertLoopbackIfTcp(string $endpoint): void
    {
        if (! str_starts_with($endpoint, 'tcp://')) {
            return;
        }

        $authority = substr($endpoint, strlen('tcp://'));
        if (str_starts_with($authority, '[')) {
            $close = strpos($authority, ']');
            $host = $close !== false ? substr($authority, 1, $close - 1) : '';
        } else {
            $colon = strrpos($authority, ':');
            $host = $colon !== false ? substr($authority, 0, $colon) : $authority;
        }

        if ($host === '127.0.0.1' || $host === '::1' || $host === 'localhost') {
            return;
        }
        if (str_starts_with($host, '127.')
            && filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false
        ) {
            return;
        }

        throw new RuntimeException(sprintf(
            'SessionManagerDaemon refuses to bind TCP listener to non-loopback host "%s". '
            . 'Use 127.0.0.1 or ::1; layer TLS / SSH tunnel explicitly if remote access is required.',
            $host,
        ));
    }

    /**
     * Compute the PID file path for the given IPC endpoint.
     *
     * Unix endpoints append `.pid` next to the socket file; TCP endpoints fall
     * back to the system temp directory keyed by host+port so that multiple
     * daemons on the same host don't clobber each other.
     *
     * @param string $endpoint
     * @return string
     */
    private function resolvePidFilePath(string $endpoint): string
    {
        $unixPath = TransportFactory::toUnixPath($endpoint);
        if ($unixPath !== null) {
            return $unixPath . '.pid';
        }

        $slug = preg_replace('~[^a-zA-Z0-9_.-]+~', '_', $endpoint) ?? 'endpoint';

        return rtrim(sys_get_temp_dir(), DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR
            . 'opcua-session-manager-' . $slug . '.pid';
    }

    /**
     * Translate the caller-supplied `$socketPath` into the URI understood by
     * {@see \React\Socket\SocketServer}: scheme-less strings become
     * `unix://<path>`, explicit `unix://` / `tcp://` URIs pass through.
     *
     * TCP endpoints are rejected at server construction by `TcpLoopbackTransport`
     * if they reference non-loopback hosts, keeping the "local origin only" trust
     * posture the Unix-socket default grants by filesystem permissions.
     *
     * @return string
     */
    private function resolveListenUri(): string
    {
        $unixPath = TransportFactory::toUnixPath($this->socketPath);
        if ($unixPath !== null) {
            return 'unix://' . $unixPath;
        }

        return $this->socketPath;
    }

    /**
     * Register connections to be auto-connected when the daemon starts.
     *
     * Each entry must have 'endpoint', 'config', and 'subscriptions' keys. Connections
     * are established on the first event loop tick after the daemon starts.
     *
     * @param array<string, array{endpoint: string, config: array, subscriptions: array}> $connections
     * @return void
     */
    public function autoConnect(array $connections): void
    {
        $this->autoConnections = $connections;
    }

    /**
     * @return void
     *
     * @throws RuntimeException If another daemon instance is already running.
     */
    public function run(): void
    {
        $this->acquirePidLock();

        $unixPath = TransportFactory::toUnixPath($this->socketPath);
        if ($unixPath !== null && file_exists($unixPath)) {
            unlink($unixPath);
        }

        $previousUmask = $unixPath !== null ? umask(0077) : null;
        try {
            $this->server = new SocketServer($this->resolveListenUri(), [], $this->loop);
        } finally {
            if ($previousUmask !== null) {
                umask($previousUmask);
            }
        }

        if ($unixPath !== null) {
            chmod($unixPath, $this->socketMode);
        }

        $this->server->on('connection', function (ConnectionInterface $connection) {
            if ($this->activeConnections >= self::MAX_CONCURRENT_CONNECTIONS) {
                $response = ['success' => false, 'error' => ['type' => 'too_many_connections', 'message' => 'Too many concurrent connections']];
                $connection->write(json_encode($response) . "\n");
                $connection->end();
                return;
            }

            $this->activeConnections++;

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

                if ($newlinePos > self::MAX_FRAME_BYTES) {
                    $response = ['success' => false, 'error' => ['type' => 'payload_too_large', 'message' => 'Request frame exceeds maximum size of ' . self::MAX_FRAME_BYTES . ' bytes']];
                    $connection->write(json_encode($response) . "\n");
                    $connection->end();
                    return;
                }

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
                        $this->logger->warning('Authentication failed from IPC client');
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
            $this->logger->warning('No auth token configured. Any local process can control the daemon.');
        }

        $this->logger->info('OPC UA Session Manager started on {socket}', ['socket' => $this->resolveListenUri()]);
        $this->logger->info('Timeout: {timeout}s, Cleanup interval: {cleanup}s, Max sessions: {max}', [
            'timeout' => $this->timeout,
            'cleanup' => $this->cleanupInterval,
            'max' => $this->maxSessions,
        ]);
        if (TransportFactory::toUnixPath($this->socketPath) !== null) {
            $this->logger->info('Socket permissions: {mode}', ['mode' => decoct($this->socketMode)]);
        }

        $this->setupAutoPublisher();

        if (!empty($this->autoConnections)) {
            $this->loop->futureTick(fn() => $this->processAutoConnections());
        }

        $this->loop->run();
    }

    private function cleanupExpiredSessions(): void
    {
        $expired = $this->store->getExpired($this->timeout);
        foreach ($expired as $session) {
            $this->autoPublisher?->stopSession($session->id);
            try {
                $session->client->disconnect();
            } catch (Throwable) {
            }
            $this->store->remove($session->id);
            $this->logger->info('Session {id} expired (endpoint: {endpoint})', [
                'id' => $session->id,
                'endpoint' => $session->endpointUrl,
            ]);
        }
    }

    private function shutdown(): void
    {
        $this->logger->info('Shutting down...');

        $this->autoPublisher?->stopAll();

        foreach ($this->store->all() as $session) {
            try {
                $session->client->disconnect();
                $this->logger->info('Disconnected session {id}', ['id' => $session->id]);
            } catch (Throwable) {
            }
            $this->store->remove($session->id);
        }

        if ($this->server !== null) {
            $this->server->close();
        }

        $unixPath = TransportFactory::toUnixPath($this->socketPath);
        if ($unixPath !== null && file_exists($unixPath)) {
            unlink($unixPath);
        }

        $this->releasePidLock();

        $this->loop->stop();
    }

    private function setupAutoPublisher(): void
    {
        if (!$this->autoPublish || $this->clientEventDispatcher === null) {
            return;
        }

        $this->autoPublisher = new AutoPublisher(
            store: $this->store,
            loop: $this->loop,
            logger: $this->logger,
            recoveryCallback: fn(Session $session) => $this->handler->attemptSessionRecovery($session),
        );
        $this->handler->setAutoPublisher($this->autoPublisher);
        $this->logger->info('Auto-publish enabled');
    }

    private function processAutoConnections(): void
    {
        foreach ($this->autoConnections as $name => $connection) {
            try {
                $sessionId = $this->handler->autoConnectSession(
                    $connection['endpoint'],
                    $connection['config'],
                    $connection['subscriptions'],
                );
                $this->logger->info($sessionId !== null
                    ? 'Auto-connected "{name}" (session: {id})'
                    : 'Auto-connect failed for "{name}"',
                    ['name' => $name, 'id' => $sessionId],
                );
            } catch (Throwable $e) {
                $this->logger->error('Auto-connect failed for "{name}": {msg}', [
                    'name' => $name,
                    'msg' => $e->getMessage(),
                ]);
            }
        }
        $this->autoConnections = [];
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
            $existingPid = (int)file_get_contents($this->pidFilePath);
            if ($existingPid > 0 && $this->isProcessRunning($existingPid)) {
                throw new RuntimeException(
                    "Another daemon instance is already running (PID: {$existingPid}). "
                    . "If this is stale, remove {$this->pidFilePath}"
                );
            }
            unlink($this->pidFilePath);
        }

        file_put_contents($this->pidFilePath, (string)getmypid());
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
