<?php

declare(strict_types=1);

namespace PhpOpcua\SessionManager\Daemon;

use PhpOpcua\Client\ClientBuilder;
use PhpOpcua\Client\Exception\ConnectionException;
use PhpOpcua\Client\Security\SecurityMode;
use PhpOpcua\Client\Security\SecurityPolicy;
use PhpOpcua\Client\TrustStore\FileTrustStore;
use PhpOpcua\Client\TrustStore\TrustPolicy;
use PhpOpcua\Client\Module\Subscription\SubscriptionResult;
use PhpOpcua\Client\Module\Subscription\TransferResult;
use PhpOpcua\Client\Wire\WireTypeRegistry;
use PhpOpcua\SessionManager\Exception\SessionNotFoundException;
use PhpOpcua\SessionManager\Serialization\BuiltInParamDeserializer;
use PhpOpcua\SessionManager\Serialization\ParamDeserializerRegistry;
use PhpOpcua\SessionManager\Serialization\TypeSerializer;
use InvalidArgumentException;
use ReflectionClass;
use Psr\EventDispatcher\EventDispatcherInterface;
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
        'username',
    ];

    private TypeSerializer $serializer;
    private ParamDeserializerRegistry $paramRegistry;
    private LoggerInterface $clientLogger;
    private ?AutoPublisher $autoPublisher = null;

    /**
     * @param SessionStore $store
     * @param int $maxSessions
     * @param ?array $allowedCertDirs
     * @param ?LoggerInterface $clientLogger Logger to inject into each OPC UA Client created by the daemon.
     * @param ?CacheInterface $clientCache Cache driver to inject into each OPC UA Client created by the daemon.
     * @param ?EventDispatcherInterface $clientEventDispatcher PSR-14 event dispatcher to inject into each OPC UA Client.
     * @param ?ParamDeserializerRegistry $paramRegistry Custom deserializer registry — defaults to one wired with {@see BuiltInParamDeserializer}.
     */
    public function __construct(
        private readonly SessionStore              $store,
        private readonly int                       $maxSessions = 100,
        private readonly ?array                    $allowedCertDirs = null,
        ?LoggerInterface                           $clientLogger = null,
        private readonly ?CacheInterface           $clientCache = null,
        private readonly ?EventDispatcherInterface $clientEventDispatcher = null,
        ?ParamDeserializerRegistry                 $paramRegistry = null,
    )
    {
        $this->serializer = new TypeSerializer();
        $this->clientLogger = $clientLogger ?? new NullLogger();
        $this->paramRegistry = $paramRegistry ?? $this->defaultParamRegistry();
    }

    /**
     * Build the default {@see ParamDeserializerRegistry} with the single
     * {@see BuiltInParamDeserializer} that covers every method shipped with
     * this package. Callers wanting to support custom service methods should
     * pass a pre-configured registry to the constructor instead of patching
     * this method.
     *
     * @return ParamDeserializerRegistry
     */
    private function defaultParamRegistry(): ParamDeserializerRegistry
    {
        $registry = new ParamDeserializerRegistry();
        $registry->register(new BuiltInParamDeserializer($this->serializer));

        return $registry;
    }

    /**
     * Add a custom {@see ParamDeserializerInterface} on top of the built-in
     * registry. Useful for third-party modules that ship service methods with
     * non-trivial argument encoding needs.
     *
     * @param \PhpOpcua\SessionManager\Serialization\ParamDeserializerInterface $deserializer
     * @return void
     */
    public function registerParamDeserializer(\PhpOpcua\SessionManager\Serialization\ParamDeserializerInterface $deserializer): void
    {
        $this->paramRegistry->register($deserializer);
    }

    /**
     * Set the auto-publisher instance for automatic subscription publishing.
     *
     * When set, the command handler triggers auto-publish start/stop on subscription
     * lifecycle changes and blocks manual publish() calls for active sessions.
     *
     * @param AutoPublisher $autoPublisher
     * @return void
     */
    public function setAutoPublisher(AutoPublisher $autoPublisher): void
    {
        $this->autoPublisher = $autoPublisher;
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
                'describe' => $this->handleDescribe($command),
                'invoke' => $this->handleInvoke($command),
                'list' => $this->handleList(),
                'ping' => $this->handlePing(),
                default => $this->error('unknown_command', "Unknown command: {$type}"),
            };
        } catch (SessionNotFoundException $e) {
            return $this->error('session_not_found', $e->getMessage());
        } catch (Throwable $e) {
            return $this->error(
                (new ReflectionClass($e))->getShortName(),
                $this->sanitizeErrorMessage($e->getMessage()),
            );
        }
    }

    private function handleOpen(array $command): array
    {
        $endpointUrl = $command['endpointUrl'] ?? '';
        $forceNew = (bool)($command['forceNew'] ?? false);

        $config = SessionConfig::fromArray($command['config'] ?? []);
        $sanitizedConfig = $config->sanitized()->toArray();

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

        $this->validateCertPaths($config->toArray());

        $client = $this->buildClientFromConfig($endpointUrl, $config);

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

    /**
     * Build and connect a {@see \PhpOpcua\Client\Client} from a typed {@see SessionConfig}.
     * Every non-null field on the DTO maps to a dedicated `ClientBuilder` setter; null
     * fields leave the builder at its own default.
     *
     * @param string $endpointUrl
     * @param SessionConfig $config
     * @return \PhpOpcua\Client\Client
     */
    private function buildClientFromConfig(string $endpointUrl, SessionConfig $config): \PhpOpcua\Client\Client
    {
        $builder = ClientBuilder::create(logger: $this->clientLogger);

        if ($this->clientEventDispatcher !== null) {
            $builder->setEventDispatcher($this->clientEventDispatcher);
        }
        if ($this->clientCache !== null) {
            $builder->setCache($this->clientCache);
        }

        if ($config->opcuaTimeout !== null) {
            $builder->setTimeout($config->opcuaTimeout);
        }
        if ($config->autoRetry !== null) {
            $builder->setAutoRetry($config->autoRetry);
        }
        if ($config->batchSize !== null) {
            $builder->setBatchSize($config->batchSize);
        }
        if ($config->defaultBrowseMaxDepth !== null) {
            $builder->setDefaultBrowseMaxDepth($config->defaultBrowseMaxDepth);
        }
        if ($config->securityPolicy !== null) {
            $builder->setSecurityPolicy(SecurityPolicy::from($config->securityPolicy));
        }
        if ($config->securityMode !== null) {
            $builder->setSecurityMode(SecurityMode::from($config->securityMode));
        }
        if ($config->username !== null && $config->password !== null) {
            $builder->setUserCredentials($config->username, $config->password);
        }
        if ($config->clientCertPath !== null && $config->clientKeyPath !== null) {
            $builder->setClientCertificate($config->clientCertPath, $config->clientKeyPath, $config->caCertPath);
        }
        if ($config->userCertPath !== null && $config->userKeyPath !== null) {
            $builder->setUserCertificate($config->userCertPath, $config->userKeyPath);
        }
        if ($config->trustStorePath !== null) {
            $builder->setTrustStore(new FileTrustStore($config->trustStorePath));
        }
        if ($config->trustPolicy !== null) {
            $builder->setTrustPolicy(TrustPolicy::from($config->trustPolicy));
        }
        if ($config->autoAccept !== null) {
            $builder->autoAccept($config->autoAccept, $config->autoAcceptForce ?? false);
        }
        if ($config->autoDetectWriteType !== null) {
            $builder->setAutoDetectWriteType($config->autoDetectWriteType);
        }
        if ($config->readMetadataCache !== null) {
            $builder->setReadMetadataCache($config->readMetadataCache);
        }

        return $builder->connect($endpointUrl);
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

        if ($method === 'publish' && $this->autoPublisher?->isActive($sessionId)) {
            return $this->error(
                'auto_publish_active',
                'Manual publish() is not available while auto-publish is active. '
                . 'Notifications are delivered via the configured EventDispatcher.',
            );
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
     * Attempt to recover a broken session by reconnecting and transferring subscriptions.
     *
     * @param Session $session
     * @return bool True if the session was recovered, false otherwise.
     */
    public function attemptSessionRecovery(Session $session): bool
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
            $hadSubscriptions = $session->hasSubscriptions();
            $session->addSubscription($result->subscriptionId, $result->revisedPublishingInterval);

            if ($this->autoPublisher !== null && !$hadSubscriptions) {
                $this->autoPublisher->startSession($session->id);
            }
        }

        if ($method === 'deleteSubscription' && is_int($result) && $result === 0 && isset($args[0])) {
            $session->removeSubscription((int)$args[0]);

            if ($this->autoPublisher !== null && !$session->hasSubscriptions()) {
                $this->autoPublisher->stopSession($session->id);
            }
        }
    }

    /**
     * Auto-connect a session with pre-configured subscriptions and monitored items.
     *
     * Opens a new session to the given endpoint, creates subscriptions, and registers
     * monitored items and event monitored items as specified. Subscription tracking
     * (and auto-publish, if configured) is wired up automatically.
     *
     * @param string $endpoint The OPC UA endpoint URL.
     * @param array $config Connection configuration in daemon format.
     * @param array $subscriptions Subscription definitions with monitored_items and event_monitored_items.
     * @return string|null The session ID, or null if connection failed.
     */
    public function autoConnectSession(string $endpoint, array $config, array $subscriptions): ?string
    {
        $result = $this->handleOpen(['endpointUrl' => $endpoint, 'config' => $config]);
        if (!$result['success']) {
            return null;
        }

        $sessionId = $result['data']['sessionId'];
        $session = $this->store->get($sessionId);

        foreach ($subscriptions as $subConfig) {
            $subResult = $session->client->createSubscription(
                publishingInterval: (float) ($subConfig['publishing_interval'] ?? 500.0),
                lifetimeCount: (int) ($subConfig['lifetime_count'] ?? 2400),
                maxKeepAliveCount: (int) ($subConfig['max_keep_alive_count'] ?? 10),
                maxNotificationsPerPublish: (int) ($subConfig['max_notifications_per_publish'] ?? 0),
                publishingEnabled: true,
                priority: (int) ($subConfig['priority'] ?? 0),
            );

            $this->trackSubscriptionChanges($session, 'createSubscription', [], $subResult);

            $this->createAutoConnectMonitoredItems($session, $subResult->subscriptionId, $subConfig);
            $this->createAutoConnectEventMonitoredItems($session, $subResult->subscriptionId, $subConfig);
        }

        return $sessionId;
    }

    /**
     * @param Session $session
     * @param int $subscriptionId
     * @param array $subConfig
     * @return void
     */
    private function createAutoConnectMonitoredItems(Session $session, int $subscriptionId, array $subConfig): void
    {
        if (empty($subConfig['monitored_items'])) {
            return;
        }

        $items = array_map(fn(array $item) => [
            'nodeId' => $item['node_id'],
            'attributeId' => $item['attribute_id'] ?? 13,
            'samplingInterval' => $item['sampling_interval'] ?? 250.0,
            'queueSize' => $item['queue_size'] ?? 1,
            'clientHandle' => $item['client_handle'] ?? 0,
        ], $subConfig['monitored_items']);

        $session->client->createMonitoredItems($subscriptionId, $items);
    }

    /**
     * @param Session $session
     * @param int $subscriptionId
     * @param array $subConfig
     * @return void
     */
    private function createAutoConnectEventMonitoredItems(Session $session, int $subscriptionId, array $subConfig): void
    {
        foreach ($subConfig['event_monitored_items'] ?? [] as $eventItem) {
            $session->client->createEventMonitoredItem(
                $subscriptionId,
                $eventItem['node_id'],
                $eventItem['select_fields'] ?? ['EventId', 'EventType', 'SourceName', 'Time', 'Message', 'Severity'],
                $eventItem['client_handle'] ?? 1,
            );
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

    /**
     * Expose the method surface of the session's underlying client along with
     * the module set that defines it and the wire-type FQCNs that may appear on
     * its arguments or return values.
     *
     * The ManagedClient uses this to populate `hasMethod()` / `hasModule()` /
     * `getRegisteredMethods()` / `getLoadedModules()` and to register the same
     * {@see WireTypeRegistry} the daemon uses for {@see WireMessageCodec}. The
     * set of `wireClasses` / `enumClasses` is the authoritative allowlist for
     * typed values exchanged via the `invoke` command: anything outside this
     * set is rejected at decode time on both sides.
     *
     * @param array<string, mixed> $command
     * @return array<string, mixed>
     * @throws SessionNotFoundException
     */
    private function handleDescribe(array $command): array
    {
        $sessionId = $command['sessionId'] ?? '';
        $session = $this->store->get($sessionId);
        $session->touch();

        $registry = $this->buildRegistryForClient($session->client);
        [$wireClasses, $enumClasses] = $this->splitRegisteredClasses($registry);

        return $this->success([
            'methods' => $session->client->getRegisteredMethods(),
            'modules' => $session->client->getLoadedModules(),
            'wireClasses' => $wireClasses,
            'enumClasses' => $enumClasses,
            'wireTypeIds' => $registry->registeredIds(),
        ]);
    }

    /**
     * Generic method-dispatch command. The `args` field is a pre-encoded wire
     * payload (as produced by {@see WireTypeRegistry::encode()} on the
     * ManagedClient side); the handler decodes it with the session's mirrored
     * registry, calls the client method, then returns a wire-encoded result.
     *
     * Unlike {@see self::handleQuery()}, this path is NOT gated by
     * {@see self::ALLOWED_METHODS}: a method is reachable iff
     * `$session->client->hasMethod($method)` is true, which means the method
     * is registered by a loaded module. The wire registry then acts as the
     * type-level allowlist for the payload itself, so arbitrary classes cannot
     * be instantiated over the wire regardless of the method resolved.
     *
     * @param array<string, mixed> $command
     * @return array<string, mixed>
     * @throws SessionNotFoundException
     * @throws Throwable
     */
    private function handleInvoke(array $command): array
    {
        $sessionId = $command['sessionId'] ?? '';
        $method = $command['method'] ?? '';
        $wireArgs = $command['args'] ?? [];

        if (! is_string($method) || $method === '') {
            return $this->error('invalid_argument', 'invoke: "method" must be a non-empty string.');
        }
        if (! is_array($wireArgs)) {
            return $this->error('invalid_argument', 'invoke: "args" must be an array of wire-encoded values.');
        }

        $session = $this->store->get($sessionId);
        $session->touch();

        if (! $session->client->hasMethod($method)) {
            return $this->error(
                'unknown_method',
                sprintf('Method "%s" is not registered on the client for this session.', $method),
            );
        }

        $registry = $this->buildRegistryForClient($session->client);
        $args = array_map(fn ($v) => $registry->decode($v), $wireArgs);

        try {
            $result = $session->client->{$method}(...$args);
        } catch (ConnectionException $e) {
            $recovered = $this->attemptSessionRecovery($session);
            if (! $recovered) {
                throw $e;
            }
            $result = $session->client->{$method}(...$args);
        }

        return $this->success(['data' => $registry->encode($result)]);
    }

    /**
     * Build a {@see WireTypeRegistry} aligned with the wire types the given
     * client's loaded modules emit. Re-used by both {@see self::handleDescribe()}
     * and {@see self::handleInvoke()} so that both sides of a session speak
     * the same allowlist.
     *
     * @param object $client The OPC UA client attached to the session.
     * @return WireTypeRegistry
     */
    private function buildRegistryForClient(object $client): WireTypeRegistry
    {
        if (method_exists($client, 'moduleRegistry')) {
            $registry = $client->moduleRegistry()->buildWireTypeRegistry();

            return $registry;
        }

        $ref = new \ReflectionClass($client);
        if ($ref->hasProperty('moduleRegistry')) {
            $prop = $ref->getProperty('moduleRegistry');
            $prop->setAccessible(true);
            /** @var \PhpOpcua\Client\Module\ModuleRegistry $moduleRegistry */
            $moduleRegistry = $prop->getValue($client);

            return $moduleRegistry->buildWireTypeRegistry();
        }

        $fallback = new WireTypeRegistry();
        \PhpOpcua\Client\Wire\CoreWireTypes::register($fallback);

        return $fallback;
    }

    /**
     * Flatten a registry's bookkeeping into the two FQCN lists that describe
     * exposes to the peer: {@see \PhpOpcua\Client\Wire\WireSerializable} classes
     * vs enum classes.
     *
     * @param WireTypeRegistry $registry
     * @return array{0: string[], 1: string[]} `[wireClasses, enumClasses]`
     */
    private function splitRegisteredClasses(WireTypeRegistry $registry): array
    {
        $ref = new \ReflectionClass($registry);
        $wireProp = $ref->getProperty('wireClasses');
        $wireProp->setAccessible(true);
        $enumProp = $ref->getProperty('enumClasses');
        $enumProp->setAccessible(true);

        /** @var array<string, class-string> $wire */
        $wire = $wireProp->getValue($registry);
        /** @var array<string, class-string> $enums */
        $enums = $enumProp->getValue($registry);

        return [array_values($wire), array_values($enums)];
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
            if ($realDir === false) {
                continue;
            }
            $prefix = rtrim($realDir, '/\\') . DIRECTORY_SEPARATOR;
            if (str_starts_with($realPath, $prefix)) {
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
     * @param string $method
     * @param array $params
     * @return array
     * @throws InvalidArgumentException If no registered deserializer handles `$method`.
     */
    private function deserializeParams(string $method, array $params): array
    {
        return $this->paramRegistry->deserialize($method, $params);
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
