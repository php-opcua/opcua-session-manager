<?php

declare(strict_types=1);

namespace PhpOpcua\SessionManager\Daemon;

use DateTimeImmutable;
use PhpOpcua\Client\ClientBuilder;
use PhpOpcua\Client\Exception\ConnectionException;
use PhpOpcua\Client\Security\SecurityMode;
use PhpOpcua\Client\Security\SecurityPolicy;
use PhpOpcua\Client\TrustStore\FileTrustStore;
use PhpOpcua\Client\TrustStore\TrustPolicy;
use PhpOpcua\Client\Types\BuiltinType;
use PhpOpcua\Client\Types\BrowseDirection;
use PhpOpcua\Client\Types\ConnectionState;
use PhpOpcua\Client\Types\NodeClass;
use PhpOpcua\Client\Types\NodeId;
use PhpOpcua\Client\Types\SubscriptionResult;
use PhpOpcua\Client\Types\TransferResult;
use PhpOpcua\SessionManager\Exception\SessionNotFoundException;
use PhpOpcua\SessionManager\Serialization\TypeSerializer;
use InvalidArgumentException;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Psr\SimpleCache\CacheInterface;
use Throwable;

/**
 * Processes IPC commands from clients, enforcing a method whitelist and sanitizing credentials and error messages.
 */
class CommandHandler
{
    private const ALLOWED_METHODS = [
        'getEndpoints',
        'browse',
        'browseWithContinuation',
        'browseNext',
        'browseAll',
        'browseRecursive',
        'translateBrowsePaths',
        'resolveNodeId',
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
        'transferSubscriptions',
        'republish',
        'historyReadRaw',
        'historyReadProcessed',
        'historyReadAtTime',
        'isConnected',
        'getConnectionState',
        'reconnect',
        'getTimeout',
        'getAutoRetry',
        'getBatchSize',
        'getDefaultBrowseMaxDepth',
        'getServerMaxNodesPerRead',
        'getServerMaxNodesPerWrite',
        'discoverDataTypes',
        'invalidateCache',
        'flushCache',
        'modifyMonitoredItems',
        'setTriggering',
        'trustCertificate',
        'untrustCertificate',
        'getTrustStore',
        'getTrustPolicy',
        'getEventDispatcher',
        'getLogger',
    ];

    private const MAX_ERROR_MESSAGE_LENGTH = 500;

    private const SENSITIVE_CONFIG_KEYS = [
        'password',
        'clientKeyPath',
        'userKeyPath',
        'caCertPath',
    ];

    private TypeSerializer $serializer;
    private LoggerInterface $clientLogger;

    /**
     * @param SessionStore $store
     * @param int $maxSessions
     * @param ?array $allowedCertDirs
     * @param ?LoggerInterface $clientLogger Logger to inject into each OPC UA Client created by the daemon.
     * @param ?CacheInterface $clientCache Cache driver to inject into each OPC UA Client created by the daemon.
     */
    public function __construct(
        private readonly SessionStore    $store,
        private readonly int             $maxSessions = 100,
        private readonly ?array          $allowedCertDirs = null,
        ?LoggerInterface                 $clientLogger = null,
        private readonly ?CacheInterface $clientCache = null,
    )
    {
        $this->serializer = new TypeSerializer();
        $this->clientLogger = $clientLogger ?? new NullLogger();
    }

    /**
     * @param array $command
     * @return array
     */
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
        } catch (Throwable $e) {
            return $this->error(
                basename(str_replace('\\', '/', get_class($e))),
                $this->sanitizeErrorMessage($e->getMessage()),
            );
        }
    }

    private function handleOpen(array $command): array
    {
        $endpointUrl = $command['endpointUrl'] ?? '';
        $config = $command['config'] ?? [];
        $forceNew = (bool)($command['forceNew'] ?? false);

        $sanitizedConfig = $this->sanitizeConfig($config);

        if (!$forceNew) {
            $existing = $this->store->findByEndpointAndConfig($endpointUrl, $sanitizedConfig);
            if ($existing !== null) {
                $existing->touch();
                return $this->success(['sessionId' => $existing->id, 'reused' => true]);
            }
        }

        if ($this->maxSessions > 0 && $this->store->count() >= $this->maxSessions) {
            return $this->error('max_sessions_reached', "Maximum number of sessions ({$this->maxSessions}) reached");
        }

        $this->validateCertPaths($config);

        $builder = ClientBuilder::create(logger: $this->clientLogger);

        if ($this->clientCache !== null) {
            $builder->setCache($this->clientCache);
        }

        if (isset($config['opcuaTimeout'])) {
            $builder->setTimeout((float)$config['opcuaTimeout']);
        }

        if (isset($config['autoRetry'])) {
            $builder->setAutoRetry((int)$config['autoRetry']);
        }

        if (isset($config['batchSize'])) {
            $builder->setBatchSize((int)$config['batchSize']);
        }

        if (isset($config['defaultBrowseMaxDepth'])) {
            $builder->setDefaultBrowseMaxDepth((int)$config['defaultBrowseMaxDepth']);
        }

        if (isset($config['securityPolicy'])) {
            $builder->setSecurityPolicy(SecurityPolicy::from($config['securityPolicy']));
        }
        if (isset($config['securityMode'])) {
            $builder->setSecurityMode(SecurityMode::from((int)$config['securityMode']));
        }
        if (isset($config['username'], $config['password'])) {
            $builder->setUserCredentials($config['username'], $config['password']);
        }
        if (isset($config['clientCertPath'], $config['clientKeyPath'])) {
            $builder->setClientCertificate(
                $config['clientCertPath'],
                $config['clientKeyPath'],
                $config['caCertPath'] ?? null,
            );
        }
        if (isset($config['userCertPath'], $config['userKeyPath'])) {
            $builder->setUserCertificate($config['userCertPath'], $config['userKeyPath']);
        }

        if (isset($config['trustStorePath'])) {
            $builder->setTrustStore(new FileTrustStore($config['trustStorePath']));
        }
        if (isset($config['trustPolicy'])) {
            $builder->setTrustPolicy(TrustPolicy::from($config['trustPolicy']));
        }
        if (isset($config['autoAccept'])) {
            $builder->autoAccept((bool)$config['autoAccept'], (bool)($config['autoAcceptForce'] ?? false));
        }

        if (isset($config['autoDetectWriteType'])) {
            $builder->setAutoDetectWriteType((bool)$config['autoDetectWriteType']);
        }
        if (isset($config['readMetadataCache'])) {
            $builder->setReadMetadataCache((bool)$config['readMetadataCache']);
        }

        $client = $builder->connect($endpointUrl);

        $sessionId = bin2hex(random_bytes(16));
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
        } catch (Throwable) {
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

        try {
            $result = $session->client->$method(...$args);
        } catch (ConnectionException $e) {
            $recovered = $this->attemptSessionRecovery($session);
            if (!$recovered) {
                throw $e;
            }
            $result = $session->client->$method(...$args);
        }

        $this->trackSubscriptionChanges($session, $method, $args, $result);

        return $this->success($this->serializer->serialize($result));
    }

    /**
     * @param Session $session
     * @return bool
     */
    private function attemptSessionRecovery(Session $session): bool
    {
        $this->clientLogger->warning('Connection lost for session {id}, attempting recovery', ['id' => $session->id]);

        try {
            $session->client->reconnect();
        } catch (Throwable $e) {
            $this->clientLogger->error('Reconnect failed for session {id}: {message}', [
                'id' => $session->id,
                'message' => $e->getMessage(),
            ]);
            return false;
        }

        $this->clientLogger->info('Reconnected session {id}', ['id' => $session->id]);

        if (!$session->hasSubscriptions()) {
            return true;
        }

        $subscriptionIds = $session->getSubscriptionIds();
        $this->clientLogger->info('Transferring {count} subscription(s) for session {id}', [
            'count' => count($subscriptionIds),
            'id' => $session->id,
        ]);

        try {
            $results = $session->client->transferSubscriptions($subscriptionIds, sendInitialValues: true);
            $this->processTransferResults($session, $subscriptionIds, $results);
        } catch (Throwable $e) {
            $this->clientLogger->warning('Subscription transfer failed for session {id}: {message}', [
                'id' => $session->id,
                'message' => $e->getMessage(),
            ]);
        }

        return true;
    }

    /**
     * @param Session $session
     * @param int[] $subscriptionIds
     * @param TransferResult[] $results
     * @return void
     */
    private function processTransferResults(Session $session, array $subscriptionIds, array $results): void
    {
        foreach ($results as $i => $result) {
            $subId = $subscriptionIds[$i] ?? null;
            if ($subId === null) {
                continue;
            }

            if ($result->statusCode !== 0) {
                $this->clientLogger->warning('Subscription {subId} transfer failed (status: 0x{status}), removing from tracking', [
                    'subId' => $subId,
                    'status' => sprintf('%08X', $result->statusCode),
                ]);
                $session->removeSubscription($subId);
                continue;
            }

            $this->clientLogger->info('Subscription {subId} transferred successfully ({seqCount} available sequence numbers)', [
                'subId' => $subId,
                'seqCount' => count($result->availableSequenceNumbers),
            ]);

            foreach ($result->availableSequenceNumbers as $seqNum) {
                try {
                    $session->client->republish($subId, $seqNum);
                    $this->clientLogger->debug('Republished sequence {seq} for subscription {subId}', [
                        'seq' => $seqNum,
                        'subId' => $subId,
                    ]);
                } catch (Throwable) {
                }
            }
        }
    }

    /**
     * @param Session $session
     * @param string $method
     * @param array $args
     * @param mixed $result
     * @return void
     */
    private function trackSubscriptionChanges(Session $session, string $method, array $args, mixed $result): void
    {
        if ($method === 'createSubscription' && $result instanceof SubscriptionResult) {
            $session->addSubscription($result->subscriptionId);
        }

        if ($method === 'deleteSubscription' && is_int($result) && $result === 0 && isset($args[0])) {
            $session->removeSubscription((int)$args[0]);
        }
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
        self::throwInvalidArgumentIf(!is_file($path), "{$label} does not exist or is not a file: {$path}");

        if ($this->allowedCertDirs === null) {
            return;
        }

        $realPath = realpath($path);
        self::throwInvalidArgumentIf($realPath === false, "{$label} path cannot be resolved: {$path}");

        foreach ($this->allowedCertDirs as $allowedDir) {
            $realDir = realpath($allowedDir);
            if ($realDir !== false && str_starts_with($realPath, $realDir . '/')) {
                return;
            }
        }

        throw new InvalidArgumentException("{$label} is not in an allowed directory: {$path}");
    }

    /**
     * @param bool $condition
     * @param string $message
     * @return void
     *
     * @throws InvalidArgumentException
     */
    private static function throwInvalidArgumentIf(bool $condition, string $message): void
    {
        if ($condition) {
            throw new InvalidArgumentException($message);
        }
    }

    /**
     * @param int[] $values
     * @return NodeClass[]
     */
    private function deserializeNodeClasses(array $values): array
    {
        return array_map(fn(int $v) => NodeClass::from($v), $values);
    }

    private function deserializeParams(string $method, array $params): array
    {
        return match ($method) {
            'getEndpoints' => [
                (string)$params[0],
                (bool)($params[1] ?? true),
            ],
            'browse', 'browseAll' => [
                $this->serializer->deserializeNodeId($params[0]),
                BrowseDirection::from((int)($params[1] ?? 0)),
                isset($params[2]) ? $this->serializer->deserializeNodeId($params[2]) : null,
                (bool)($params[3] ?? true),
                $this->deserializeNodeClasses($params[4] ?? []),
                (bool)($params[5] ?? true),
            ],
            'browseWithContinuation' => [
                $this->serializer->deserializeNodeId($params[0]),
                BrowseDirection::from((int)($params[1] ?? 0)),
                isset($params[2]) ? $this->serializer->deserializeNodeId($params[2]) : null,
                (bool)($params[3] ?? true),
                $this->deserializeNodeClasses($params[4] ?? []),
            ],
            'browseRecursive' => [
                $this->serializer->deserializeNodeId($params[0]),
                BrowseDirection::from((int)($params[1] ?? 0)),
                isset($params[2]) ? (int)$params[2] : null,
                isset($params[3]) ? $this->serializer->deserializeNodeId($params[3]) : null,
                (bool)($params[4] ?? true),
                $this->deserializeNodeClasses($params[5] ?? []),
            ],
            'browseNext' => [
                (string)$params[0],
            ],
            'translateBrowsePaths' => [
                array_map(fn(array $bp) => [
                    'startingNodeId' => $this->serializer->deserializeNodeId($bp['startingNodeId']),
                    'relativePath' => array_map(fn(array $elem) => [
                        'referenceTypeId' => isset($elem['referenceTypeId'])
                            ? $this->serializer->deserializeNodeId($elem['referenceTypeId'])
                            : null,
                        'isInverse' => (bool)($elem['isInverse'] ?? false),
                        'includeSubtypes' => (bool)($elem['includeSubtypes'] ?? true),
                        'targetName' => $this->serializer->deserializeQualifiedName($elem['targetName']),
                    ], $bp['relativePath'] ?? []),
                ], $params[0] ?? []),
            ],
            'resolveNodeId' => [
                (string)$params[0],
                isset($params[1]) ? $this->serializer->deserializeNodeId($params[1]) : null,
                (bool)($params[2] ?? true),
            ],
            'read' => [
                $this->serializer->deserializeNodeId($params[0]),
                (int)($params[1] ?? 13),
                (bool)($params[2] ?? false),
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
                isset($params[2]) ? $this->serializer->deserializeBuiltinType((int)$params[2]) : null,
            ],
            'writeMulti' => [
                array_map(fn(array $item) => [
                    'nodeId' => $this->serializer->deserializeNodeId($item['nodeId']),
                    'value' => $item['value'],
                    'type' => isset($item['type']) ? $this->serializer->deserializeBuiltinType((int)$item['type']) : null,
                    'attributeId' => $item['attributeId'] ?? 13,
                ], $params[0]),
            ],
            'call' => [
                $this->serializer->deserializeNodeId($params[0]),
                $this->serializer->deserializeNodeId($params[1]),
                array_map(fn(array $v) => $this->serializer->deserializeVariant($v), $params[2] ?? []),
            ],
            'createSubscription' => [
                (float)($params[0] ?? 500.0),
                (int)($params[1] ?? 2400),
                (int)($params[2] ?? 10),
                (int)($params[3] ?? 0),
                (bool)($params[4] ?? true),
                (int)($params[5] ?? 0),
            ],
            'createMonitoredItems' => [
                (int)$params[0],
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
                (int)$params[0],
                $this->serializer->deserializeNodeId($params[1]),
                $params[2] ?? ['EventId', 'EventType', 'SourceName', 'Time', 'Message', 'Severity'],
                (int)($params[3] ?? 1),
            ],
            'deleteMonitoredItems' => [
                (int)$params[0],
                array_map('intval', $params[1]),
            ],
            'deleteSubscription' => [
                (int)$params[0],
            ],
            'publish' => [
                $params[0] ?? [],
            ],
            'transferSubscriptions' => [
                array_map('intval', $params[0] ?? []),
                (bool)($params[1] ?? false),
            ],
            'republish' => [
                (int)$params[0],
                (int)$params[1],
            ],
            'historyReadRaw' => [
                $this->serializer->deserializeNodeId($params[0]),
                isset($params[1]) ? new DateTimeImmutable($params[1]) : null,
                isset($params[2]) ? new DateTimeImmutable($params[2]) : null,
                (int)($params[3] ?? 0),
                (bool)($params[4] ?? false),
            ],
            'historyReadProcessed' => [
                $this->serializer->deserializeNodeId($params[0]),
                new DateTimeImmutable($params[1]),
                new DateTimeImmutable($params[2]),
                (float)$params[3],
                $this->serializer->deserializeNodeId($params[4]),
            ],
            'historyReadAtTime' => [
                $this->serializer->deserializeNodeId($params[0]),
                array_map(fn(string $ts) => new DateTimeImmutable($ts), $params[1]),
            ],
            'discoverDataTypes' => [
                isset($params[0]) ? (int)$params[0] : null,
                (bool)($params[1] ?? true),
            ],
            'invalidateCache' => [
                $this->serializer->deserializeNodeId($params[0]),
            ],
            'modifyMonitoredItems' => [
                (int)$params[0],
                array_map(fn(array $item) => [
                    'monitoredItemId' => (int)$item['monitoredItemId'],
                    'samplingInterval' => isset($item['samplingInterval']) ? (float)$item['samplingInterval'] : null,
                    'queueSize' => isset($item['queueSize']) ? (int)$item['queueSize'] : null,
                    'clientHandle' => isset($item['clientHandle']) ? (int)$item['clientHandle'] : null,
                    'discardOldest' => isset($item['discardOldest']) ? (bool)$item['discardOldest'] : null,
                ], $params[1]),
            ],
            'setTriggering' => [
                (int)$params[0],
                (int)$params[1],
                array_map('intval', $params[2] ?? []),
                array_map('intval', $params[3] ?? []),
            ],
            'trustCertificate' => [
                (string)$params[0],
            ],
            'untrustCertificate' => [
                (string)$params[0],
            ],
            'isConnected', 'getConnectionState', 'reconnect',
            'getTimeout', 'getAutoRetry', 'getBatchSize',
            'getDefaultBrowseMaxDepth', 'getServerMaxNodesPerRead',
            'getServerMaxNodesPerWrite', 'flushCache',
            'getTrustStore', 'getTrustPolicy', 'getEventDispatcher', 'getLogger' => [],
            default => throw new InvalidArgumentException("Unsupported method: {$method}"),
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
