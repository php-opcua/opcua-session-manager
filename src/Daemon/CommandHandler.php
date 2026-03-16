<?php

declare(strict_types=1);

namespace Gianfriaur\OpcuaSessionManager\Daemon;

use Gianfriaur\OpcuaPhpClient\Client;
use Gianfriaur\OpcuaPhpClient\Security\SecurityMode;
use Gianfriaur\OpcuaPhpClient\Security\SecurityPolicy;
use Gianfriaur\OpcuaPhpClient\Types\BuiltinType;
use Gianfriaur\OpcuaPhpClient\Types\NodeId;
use Gianfriaur\OpcuaPhpClient\Types\Variant;
use Gianfriaur\OpcuaSessionManager\Exception\SessionNotFoundException;
use Gianfriaur\OpcuaSessionManager\Serialization\TypeSerializer;

class CommandHandler
{
    private const ALLOWED_METHODS = [
        'getEndpoints',
        'browse',
        'browseWithContinuation',
        'browseNext',
        'read',
        'readMulti',
        'write',
        'writeMulti',
        'call',
        'createSubscription',
        'createMonitoredItems',
        'createEventMonitoredItem',
        'deleteMonitoredItems',
        'deleteSubscription',
        'publish',
        'historyReadRaw',
        'historyReadProcessed',
        'historyReadAtTime',
    ];

    private const MAX_ERROR_MESSAGE_LENGTH = 500;

    private const SENSITIVE_CONFIG_KEYS = [
        'password',
        'clientKeyPath',
        'userKeyPath',
        'caCertPath',
    ];

    private TypeSerializer $serializer;

    public function __construct(
        private readonly SessionStore $store,
        private readonly int $maxSessions = 100,
        private readonly ?array $allowedCertDirs = null,
    ) {
        $this->serializer = new TypeSerializer();
    }

    public function handle(array $command): array
    {
        try {
            $type = $command['command'] ?? '';

            return match ($type) {
                'open' => $this->handleOpen($command),
                'close' => $this->handleClose($command),
                'query' => $this->handleQuery($command),
                'list' => $this->handleList(),
                'ping' => $this->handlePing(),
                default => $this->error('unknown_command', "Unknown command: {$type}"),
            };
        } catch (SessionNotFoundException $e) {
            return $this->error('session_not_found', $e->getMessage());
        } catch (\Throwable $e) {
            return $this->error(
                basename(str_replace('\\', '/', get_class($e))),
                $this->sanitizeErrorMessage($e->getMessage()),
            );
        }
    }

    private function handleOpen(array $command): array
    {
        if ($this->maxSessions > 0 && $this->store->count() >= $this->maxSessions) {
            return $this->error('max_sessions_reached', "Maximum number of sessions ({$this->maxSessions}) reached");
        }

        $endpointUrl = $command['endpointUrl'] ?? '';
        $config = $command['config'] ?? [];

        $this->validateCertPaths($config);

        $client = new Client();

        if (isset($config['securityPolicy'])) {
            $client->setSecurityPolicy(SecurityPolicy::from($config['securityPolicy']));
        }
        if (isset($config['securityMode'])) {
            $client->setSecurityMode(SecurityMode::from((int) $config['securityMode']));
        }
        if (isset($config['username'], $config['password'])) {
            $client->setUserCredentials($config['username'], $config['password']);
        }
        if (isset($config['clientCertPath'], $config['clientKeyPath'])) {
            $client->setClientCertificate(
                $config['clientCertPath'],
                $config['clientKeyPath'],
                $config['caCertPath'] ?? null,
            );
        }
        if (isset($config['userCertPath'], $config['userKeyPath'])) {
            $client->setUserCertificate($config['userCertPath'], $config['userKeyPath']);
        }

        $client->connect($endpointUrl);

        $sessionId = bin2hex(random_bytes(16));
        $sanitizedConfig = $this->sanitizeConfig($config);
        $session = new Session(
            id: $sessionId,
            client: $client,
            endpointUrl: $endpointUrl,
            config: $sanitizedConfig,
            lastUsed: microtime(true),
        );
        $this->store->create($session);

        return $this->success(['sessionId' => $sessionId]);
    }

    private function handleClose(array $command): array
    {
        $sessionId = $command['sessionId'] ?? '';
        $session = $this->store->get($sessionId);

        try {
            $session->client->disconnect();
        } catch (\Throwable) {
        }

        $this->store->remove($sessionId);

        return $this->success(null);
    }

    private function handleQuery(array $command): array
    {
        $sessionId = $command['sessionId'] ?? '';
        $method = $command['method'] ?? '';
        $params = $command['params'] ?? [];

        if (!in_array($method, self::ALLOWED_METHODS, true)) {
            return $this->error('forbidden_method', "Method not allowed: {$method}");
        }

        $session = $this->store->get($sessionId);
        $session->touch();

        $args = $this->deserializeParams($method, $params);
        $result = $session->client->$method(...$args);

        return $this->success($this->serializer->serialize($result));
    }

    private function handleList(): array
    {
        $sessions = [];
        foreach ($this->store->all() as $session) {
            $sessions[] = [
                'id' => $session->id,
                'endpointUrl' => $session->endpointUrl,
                'lastUsed' => $session->lastUsed,
                'config' => $this->sanitizeConfig($session->config),
            ];
        }

        return $this->success([
            'count' => $this->store->count(),
            'sessions' => $sessions,
        ]);
    }

    private function handlePing(): array
    {
        return $this->success([
            'status' => 'ok',
            'sessions' => $this->store->count(),
            'time' => microtime(true),
        ]);
    }

    private function sanitizeConfig(array $config): array
    {
        return array_diff_key($config, array_flip(self::SENSITIVE_CONFIG_KEYS));
    }

    private function sanitizeErrorMessage(string $message): string
    {
        // Strip file paths from error messages
        $message = preg_replace('#/[^\s:]+/[^\s:]+#', '[path]', $message);

        if (strlen($message) > self::MAX_ERROR_MESSAGE_LENGTH) {
            $message = substr($message, 0, self::MAX_ERROR_MESSAGE_LENGTH) . '...';
        }

        return $message;
    }

    private function validateCertPaths(array $config): void
    {
        $certKeys = [
            'clientCertPath' => 'Client certificate',
            'clientKeyPath' => 'Client key',
            'caCertPath' => 'CA certificate',
            'userCertPath' => 'User certificate',
            'userKeyPath' => 'User key',
        ];

        foreach ($certKeys as $key => $label) {
            if (isset($config[$key])) {
                $this->validateSingleCertPath($config[$key], $label);
            }
        }
    }

    private function validateSingleCertPath(string $path, string $label): void
    {
        if (!is_file($path)) {
            throw new \InvalidArgumentException("{$label} does not exist or is not a file: {$path}");
        }

        if ($this->allowedCertDirs === null) {
            return;
        }

        $realPath = realpath($path);
        if ($realPath === false) {
            throw new \InvalidArgumentException("{$label} path cannot be resolved: {$path}");
        }

        foreach ($this->allowedCertDirs as $allowedDir) {
            $realDir = realpath($allowedDir);
            if ($realDir !== false && str_starts_with($realPath, $realDir . '/')) {
                return;
            }
        }

        throw new \InvalidArgumentException("{$label} is not in an allowed directory: {$path}");
    }

    private function deserializeParams(string $method, array $params): array
    {
        return match ($method) {
            'getEndpoints' => [
                (string) $params[0],
            ],
            'browse' => [
                $this->serializer->deserializeNodeId($params[0]),
                (int) ($params[1] ?? 0),
                isset($params[2]) ? $this->serializer->deserializeNodeId($params[2]) : null,
                (bool) ($params[3] ?? true),
                (int) ($params[4] ?? 0),
            ],
            'browseWithContinuation' => [
                $this->serializer->deserializeNodeId($params[0]),
                (int) ($params[1] ?? 0),
                isset($params[2]) ? $this->serializer->deserializeNodeId($params[2]) : null,
                (bool) ($params[3] ?? true),
                (int) ($params[4] ?? 0),
            ],
            'browseNext' => [
                (string) $params[0],
            ],
            'read' => [
                $this->serializer->deserializeNodeId($params[0]),
                (int) ($params[1] ?? 13),
            ],
            'readMulti' => [
                array_map(fn(array $item) => [
                    'nodeId' => $this->serializer->deserializeNodeId($item['nodeId']),
                    'attributeId' => $item['attributeId'] ?? 13,
                ], $params[0]),
            ],
            'write' => [
                $this->serializer->deserializeNodeId($params[0]),
                $params[1],
                $this->serializer->deserializeBuiltinType((int) $params[2]),
            ],
            'writeMulti' => [
                array_map(fn(array $item) => [
                    'nodeId' => $this->serializer->deserializeNodeId($item['nodeId']),
                    'value' => $item['value'],
                    'type' => $this->serializer->deserializeBuiltinType((int) $item['type']),
                    'attributeId' => $item['attributeId'] ?? 13,
                ], $params[0]),
            ],
            'call' => [
                $this->serializer->deserializeNodeId($params[0]),
                $this->serializer->deserializeNodeId($params[1]),
                array_map(fn(array $v) => $this->serializer->deserializeVariant($v), $params[2] ?? []),
            ],
            'createSubscription' => [
                (float) ($params[0] ?? 500.0),
                (int) ($params[1] ?? 2400),
                (int) ($params[2] ?? 10),
                (int) ($params[3] ?? 0),
                (bool) ($params[4] ?? true),
                (int) ($params[5] ?? 0),
            ],
            'createMonitoredItems' => [
                (int) $params[0],
                array_map(fn(array $item) => [
                    'nodeId' => $this->serializer->deserializeNodeId($item['nodeId']),
                    'attributeId' => $item['attributeId'] ?? 13,
                    'samplingInterval' => $item['samplingInterval'] ?? 250.0,
                    'queueSize' => $item['queueSize'] ?? 1,
                    'clientHandle' => $item['clientHandle'] ?? 0,
                    'monitoringMode' => $item['monitoringMode'] ?? 0,
                ], $params[1]),
            ],
            'createEventMonitoredItem' => [
                (int) $params[0],
                $this->serializer->deserializeNodeId($params[1]),
                $params[2] ?? ['EventId', 'EventType', 'SourceName', 'Time', 'Message', 'Severity'],
                (int) ($params[3] ?? 1),
            ],
            'deleteMonitoredItems' => [
                (int) $params[0],
                array_map('intval', $params[1]),
            ],
            'deleteSubscription' => [
                (int) $params[0],
            ],
            'publish' => [
                $params[0] ?? [],
            ],
            'historyReadRaw' => [
                $this->serializer->deserializeNodeId($params[0]),
                isset($params[1]) ? new \DateTimeImmutable($params[1]) : null,
                isset($params[2]) ? new \DateTimeImmutable($params[2]) : null,
                (int) ($params[3] ?? 0),
                (bool) ($params[4] ?? false),
            ],
            'historyReadProcessed' => [
                $this->serializer->deserializeNodeId($params[0]),
                new \DateTimeImmutable($params[1]),
                new \DateTimeImmutable($params[2]),
                (float) $params[3],
                $this->serializer->deserializeNodeId($params[4]),
            ],
            'historyReadAtTime' => [
                $this->serializer->deserializeNodeId($params[0]),
                array_map(fn(string $ts) => new \DateTimeImmutable($ts), $params[1]),
            ],
            default => throw new \InvalidArgumentException("Unsupported method: {$method}"),
        };
    }

    private function success(mixed $data): array
    {
        return ['success' => true, 'data' => $data];
    }

    private function error(string $type, string $message): array
    {
        return ['success' => false, 'error' => ['type' => $type, 'message' => $message]];
    }
}
