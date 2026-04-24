<?php

declare(strict_types=1);

namespace PhpOpcua\SessionManager\Client;

use BadMethodCallException;
use DateTimeImmutable;
use PhpOpcua\Client\Builder\BrowsePathsBuilder;
use PhpOpcua\Client\Builder\MonitoredItemsBuilder;
use PhpOpcua\Client\Builder\ReadMultiBuilder;
use PhpOpcua\Client\Builder\WriteMultiBuilder;
use PhpOpcua\Client\Event\NullEventDispatcher;
use PhpOpcua\Client\Exception\ConnectionException;
use PhpOpcua\Client\Exception\ServiceException;
use PhpOpcua\Client\Exception\ServiceUnsupportedException;
use PhpOpcua\Client\OpcUaClientInterface;
use PhpOpcua\Client\Repository\ExtensionObjectRepository;
use PhpOpcua\Client\Security\SecurityMode;
use PhpOpcua\Client\Security\SecurityPolicy;
use PhpOpcua\Client\TrustStore\TrustPolicy;
use PhpOpcua\Client\TrustStore\TrustStoreInterface;
use PhpOpcua\Client\Types\BrowseDirection;
use PhpOpcua\Client\Types\BrowseNode;
use PhpOpcua\Client\Module\TranslateBrowsePath\BrowsePathResult;
use PhpOpcua\Client\Module\Browse\BrowseResultSet;
use PhpOpcua\Client\Types\BuiltinType;
use PhpOpcua\Client\Module\NodeManagement\AddNodesResult;
use PhpOpcua\Client\Module\ReadWrite\CallResult;
use PhpOpcua\Client\Module\ServerInfo\BuildInfo;
use PhpOpcua\Client\Types\AttributeId;
use PhpOpcua\Client\Types\ConnectionState;
use PhpOpcua\Client\Types\DataValue;
use PhpOpcua\Client\Types\EndpointDescription;
use PhpOpcua\Client\Module\Subscription\MonitoredItemModifyResult;
use PhpOpcua\Client\Module\Subscription\MonitoredItemResult;
use PhpOpcua\Client\Types\NodeClass;
use PhpOpcua\Client\Types\NodeId;
use PhpOpcua\Client\Module\Subscription\PublishResult;
use PhpOpcua\Client\Module\Subscription\SetTriggeringResult;
use PhpOpcua\Client\Module\Subscription\SubscriptionResult;
use PhpOpcua\Client\Module\Subscription\TransferResult;
use PhpOpcua\Client\Types\Variant;
use PhpOpcua\Client\Wire\WireTypeRegistry;
use PhpOpcua\SessionManager\Exception\DaemonException;
use PhpOpcua\SessionManager\Ipc\WireMessageCodec;
use PhpOpcua\SessionManager\Serialization\TypeSerializer;
use UnitEnum;
use PhpOpcua\Client\Wire\WireSerializable;
use Psr\EventDispatcher\EventDispatcherInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Psr\SimpleCache\CacheInterface;

/**
 * Drop-in replacement for the OPC UA Client that proxies all operations to a long-running daemon over a Unix socket.
 *
 * @see OpcUaClientInterface
 * @see SessionManagerDaemon
 */
class ManagedClient implements OpcUaClientInterface
{
    private ?string $sessionId = null;
    private bool $sessionReused = false;
    private array $config = [];
    private TypeSerializer $serializer;

    /**
     * Cached result of the remote `describe` IPC command. Populated lazily on
     * first call to {@see self::getRegisteredMethods()}, {@see self::hasMethod()},
     * {@see self::hasModule()}, or any third-party method routed through
     * {@see self::__call()}. Invalidated when the session closes.
     *
     * Shape: `{methods: string[], modules: class-string[], wireClasses: class-string[], enumClasses: class-string[]}`.
     *
     * @var ?array{methods: string[], modules: array<class-string>, wireClasses: array<class-string>, enumClasses: array<class-string>}
     */
    private ?array $describe = null;

    /**
     * Codec + registry built from the describe response, so that typed values
     * sent over `invoke` are encoded with the exact same type allowlist the
     * daemon applies on decode. Kept in sync with {@see self::$describe}.
     */
    private ?WireMessageCodec $wireCodec = null;

    private float $opcuaTimeout = 5.0;
    private ?int $autoRetry = null;
    private ?int $batchSize = null;
    private int $defaultBrowseMaxDepth = 10;

    private LoggerInterface $logger;
    private ?CacheInterface $cache = null;
    private ExtensionObjectRepository $extensionObjectRepository;
    private EventDispatcherInterface $eventDispatcher;

    private ?string $trustStorePath = null;
    private ?TrustPolicy $trustPolicy = null;
    private bool $autoAcceptEnabled = false;
    private bool $autoAcceptForce = false;
    private bool $autoDetectWriteType = true;
    private bool $readMetadataCache = false;

    /**
     * @param string $socketPath Path to the daemon's Unix socket.
     * @param float $timeout IPC timeout in seconds.
     * @param ?string $authToken Shared secret for IPC authentication.
     */
    public function __construct(
        private readonly string  $socketPath = '/tmp/opcua-session-manager.sock',
        private readonly float   $timeout = 30.0,
        private readonly ?string $authToken = null,
    )
    {
        $this->serializer = new TypeSerializer();
        $this->logger = new NullLogger();
        $this->extensionObjectRepository = new ExtensionObjectRepository();
        $this->eventDispatcher = new NullEventDispatcher();
    }

    /**
     * @param LoggerInterface $logger
     * @return self
     */
    public function setLogger(LoggerInterface $logger): self
    {
        $this->logger = $logger;

        return $this;
    }

    /**
     * @return LoggerInterface
     */
    public function getLogger(): LoggerInterface
    {
        return $this->logger;
    }

    /**
     * @return ExtensionObjectRepository
     */
    public function getExtensionObjectRepository(): ExtensionObjectRepository
    {
        return $this->extensionObjectRepository;
    }

    /**
     * @param EventDispatcherInterface $eventDispatcher
     * @return self
     */
    public function setEventDispatcher(EventDispatcherInterface $eventDispatcher): self
    {
        $this->eventDispatcher = $eventDispatcher;

        return $this;
    }

    /**
     * @return EventDispatcherInterface
     */
    public function getEventDispatcher(): EventDispatcherInterface
    {
        return $this->eventDispatcher;
    }

    /**
     * @return ?TrustStoreInterface
     */
    public function getTrustStore(): ?TrustStoreInterface
    {
        return null;
    }

    /**
     * @return ?TrustPolicy
     */
    public function getTrustPolicy(): ?TrustPolicy
    {
        return $this->trustPolicy;
    }

    /**
     * @param string $trustStorePath Path to the trust store directory.
     * @return self
     */
    public function setTrustStorePath(string $trustStorePath): self
    {
        $this->trustStorePath = $trustStorePath;
        $this->config['trustStorePath'] = $trustStorePath;

        return $this;
    }

    /**
     * @param ?TrustPolicy $policy
     * @return self
     */
    public function setTrustPolicy(?TrustPolicy $policy): self
    {
        $this->trustPolicy = $policy;
        $this->config['trustPolicy'] = $policy?->value;

        return $this;
    }

    /**
     * @param bool $enabled
     * @param bool $force
     * @return self
     */
    public function autoAccept(bool $enabled = true, bool $force = false): self
    {
        $this->autoAcceptEnabled = $enabled;
        $this->autoAcceptForce = $force;
        $this->config['autoAccept'] = $enabled;
        $this->config['autoAcceptForce'] = $force;

        return $this;
    }

    /**
     * @param string $certDer DER-encoded certificate bytes.
     * @return void
     *
     * @throws ConnectionException
     * @throws DaemonException
     */
    public function trustCertificate(string $certDer): void
    {
        $this->query('trustCertificate', [base64_encode($certDer)]);
    }

    /**
     * @param string $fingerprint SHA-1 fingerprint.
     * @return void
     *
     * @throws ConnectionException
     * @throws DaemonException
     */
    public function untrustCertificate(string $fingerprint): void
    {
        $this->query('untrustCertificate', [$fingerprint]);
    }

    /**
     * @param bool $enabled
     * @return self
     */
    public function setAutoDetectWriteType(bool $enabled): self
    {
        $this->autoDetectWriteType = $enabled;
        $this->config['autoDetectWriteType'] = $enabled;

        return $this;
    }

    /**
     * @param bool $enabled
     * @return self
     */
    public function setReadMetadataCache(bool $enabled): self
    {
        $this->readMetadataCache = $enabled;
        $this->config['readMetadataCache'] = $enabled;

        return $this;
    }

    /**
     * @param ?CacheInterface $cache
     * @return self
     */
    public function setCache(?CacheInterface $cache): self
    {
        $this->cache = $cache;

        return $this;
    }

    /**
     * @return ?CacheInterface
     */
    public function getCache(): ?CacheInterface
    {
        return $this->cache;
    }

    /**
     * @param NodeId|string $nodeId
     * @return void
     *
     * @throws ConnectionException
     * @throws DaemonException
     */
    public function invalidateCache(NodeId|string $nodeId): void
    {
        $nodeId = $this->resolveNodeIdParam($nodeId);
        $this->query('invalidateCache', [
            $this->serializer->serializeNodeId($nodeId),
        ]);
    }

    /**
     * @return void
     *
     * @throws ConnectionException
     * @throws DaemonException
     */
    public function flushCache(): void
    {
        $this->query('flushCache', []);
    }

    /**
     * @param float $timeout Timeout in seconds.
     * @return self
     */
    public function setTimeout(float $timeout): self
    {
        $this->opcuaTimeout = $timeout;
        $this->config['opcuaTimeout'] = $timeout;

        return $this;
    }

    /**
     * @return float
     */
    public function getTimeout(): float
    {
        return $this->opcuaTimeout;
    }

    /**
     * @param int $maxRetries
     * @return self
     */
    public function setAutoRetry(int $maxRetries): self
    {
        $this->autoRetry = $maxRetries;
        $this->config['autoRetry'] = $maxRetries;

        return $this;
    }

    /**
     * @return int
     */
    public function getAutoRetry(): int
    {
        return $this->autoRetry ?? ($this->sessionId !== null ? 1 : 0);
    }

    /**
     * @param int $batchSize
     * @return self
     */
    public function setBatchSize(int $batchSize): self
    {
        $this->batchSize = $batchSize;
        $this->config['batchSize'] = $batchSize;

        return $this;
    }

    /**
     * @return ?int
     */
    public function getBatchSize(): ?int
    {
        return $this->batchSize;
    }

    /**
     * @return ?int
     *
     * @throws ConnectionException
     * @throws DaemonException
     */
    public function getServerMaxNodesPerRead(): ?int
    {
        return $this->query('getServerMaxNodesPerRead', []);
    }

    /**
     * @return ?int
     *
     * @throws ConnectionException
     * @throws DaemonException
     */
    public function getServerMaxNodesPerWrite(): ?int
    {
        return $this->query('getServerMaxNodesPerWrite', []);
    }

    /**
     * @param int $maxDepth
     * @return self
     */
    public function setDefaultBrowseMaxDepth(int $maxDepth): self
    {
        $this->defaultBrowseMaxDepth = $maxDepth;
        $this->config['defaultBrowseMaxDepth'] = $maxDepth;

        return $this;
    }

    /**
     * @return int
     */
    public function getDefaultBrowseMaxDepth(): int
    {
        return $this->defaultBrowseMaxDepth;
    }

    /**
     * @param SecurityPolicy $policy
     * @return self
     */
    public function setSecurityPolicy(SecurityPolicy $policy): self
    {
        $this->config['securityPolicy'] = $policy->value;

        return $this;
    }

    /**
     * @param SecurityMode $mode
     * @return self
     */
    public function setSecurityMode(SecurityMode $mode): self
    {
        $this->config['securityMode'] = $mode->value;

        return $this;
    }

    /**
     * @param string $username
     * @param string $password
     * @return self
     */
    public function setUserCredentials(string $username, string $password): self
    {
        $this->config['username'] = $username;
        $this->config['password'] = $password;

        return $this;
    }

    /**
     * @param string $certPath
     * @param string $keyPath
     * @param ?string $caCertPath
     * @return self
     */
    public function setClientCertificate(string $certPath, string $keyPath, ?string $caCertPath = null): self
    {
        $this->config['clientCertPath'] = $certPath;
        $this->config['clientKeyPath'] = $keyPath;
        if ($caCertPath !== null) {
            $this->config['caCertPath'] = $caCertPath;
        }

        return $this;
    }

    /**
     * @param string $certPath
     * @param string $keyPath
     * @return self
     */
    public function setUserCertificate(string $certPath, string $keyPath): self
    {
        $this->config['userCertPath'] = $certPath;
        $this->config['userKeyPath'] = $keyPath;

        return $this;
    }

    /**
     * @param string $endpointUrl
     * @return void
     *
     * @throws ConnectionException
     * @throws ServiceException
     * @throws DaemonException
     */
    public function connect(string $endpointUrl): void
    {
        $response = $this->sendCommand([
            'command' => 'open',
            'endpointUrl' => $endpointUrl,
            'config' => $this->config,
        ]);

        $this->sessionId = $response['sessionId'];
        $this->sessionReused = !empty($response['reused']);
    }

    /**
     * Connect forcing a new session, even if one already exists for the same endpoint and config.
     *
     * @param string $endpointUrl
     * @return void
     *
     * @throws ConnectionException
     * @throws ServiceException
     * @throws DaemonException
     */
    public function connectForceNew(string $endpointUrl): void
    {
        $response = $this->sendCommand([
            'command' => 'open',
            'endpointUrl' => $endpointUrl,
            'config' => $this->config,
            'forceNew' => true,
        ]);

        $this->sessionId = $response['sessionId'];
    }

    /**
     * Whether the last connect() call reused an existing daemon session.
     *
     * @return bool
     */
    public function wasSessionReused(): bool
    {
        return $this->sessionReused;
    }

    /**
     * @return void
     *
     * @throws ConnectionException
     * @throws DaemonException
     */
    public function reconnect(): void
    {
        if ($this->sessionId === null) {
            throw new ConnectionException('Not connected. Call connect() first.');
        }

        $this->query('reconnect', []);
    }

    /**
     * @return void
     */
    public function disconnect(): void
    {
        if ($this->sessionId === null) {
            return;
        }

        try {
            $this->sendCommand([
                'command' => 'close',
                'sessionId' => $this->sessionId,
            ]);
        } finally {
            $this->sessionId = null;
            $this->describe = null;
            $this->wireCodec = null;
        }
    }

    /**
     * @return bool
     */
    public function isConnected(): bool
    {
        if ($this->sessionId === null) {
            return false;
        }

        return (bool)$this->query('isConnected', []);
    }

    /**
     * @return ConnectionState
     */
    public function getConnectionState(): ConnectionState
    {
        if ($this->sessionId === null) {
            return ConnectionState::Disconnected;
        }

        $state = $this->query('getConnectionState', []);

        return $this->serializer->deserializeConnectionState($state);
    }

    /**
     * @param ?int $namespaceIndex
     * @param bool $useCache
     * @return int
     *
     * @throws ConnectionException
     * @throws DaemonException
     */
    public function discoverDataTypes(?int $namespaceIndex = null, bool $useCache = true): int
    {
        return $this->query('discoverDataTypes', [$namespaceIndex, $useCache]);
    }

    /**
     * @param string $endpointUrl
     * @param bool $useCache
     * @return EndpointDescription[]
     *
     * @throws ConnectionException
     * @throws DaemonException
     */
    public function getEndpoints(string $endpointUrl, bool $useCache = true): array
    {
        $result = $this->query('getEndpoints', [$endpointUrl, $useCache]);

        return array_map(
            fn(array $ep) => $this->serializer->deserializeEndpointDescription($ep),
            $result,
        );
    }

    /**
     * @param NodeId|string $nodeId
     * @param BrowseDirection $direction
     * @param ?NodeId $referenceTypeId
     * @param bool $includeSubtypes
     * @param NodeClass[] $nodeClasses
     * @param bool $useCache
     * @return ReferenceDescription[]
     *
     * @throws ConnectionException
     * @throws ServiceException
     * @throws DaemonException
     */
    public function browse(
        NodeId|string   $nodeId,
        BrowseDirection $direction = BrowseDirection::Forward,
        ?NodeId         $referenceTypeId = null,
        bool            $includeSubtypes = true,
        array           $nodeClasses = [],
        bool            $useCache = true,
    ): array
    {
        $nodeId = $this->resolveNodeIdParam($nodeId);

        $result = $this->query('browse', [
            $this->serializer->serializeNodeId($nodeId),
            $direction->value,
            $referenceTypeId !== null ? $this->serializer->serializeNodeId($referenceTypeId) : null,
            $includeSubtypes,
            $this->serializeNodeClasses($nodeClasses),
            $useCache,
        ]);

        return array_map(
            fn(array $ref) => $this->serializer->deserializeReferenceDescription($ref),
            $result,
        );
    }

    /**
     * @param NodeId|string $nodeId
     * @param BrowseDirection $direction
     * @param ?NodeId $referenceTypeId
     * @param bool $includeSubtypes
     * @param NodeClass[] $nodeClasses
     * @return BrowseResultSet
     *
     * @throws ConnectionException
     * @throws ServiceException
     * @throws DaemonException
     */
    public function browseWithContinuation(
        NodeId|string   $nodeId,
        BrowseDirection $direction = BrowseDirection::Forward,
        ?NodeId         $referenceTypeId = null,
        bool            $includeSubtypes = true,
        array           $nodeClasses = [],
    ): BrowseResultSet
    {
        $nodeId = $this->resolveNodeIdParam($nodeId);

        $result = $this->query('browseWithContinuation', [
            $this->serializer->serializeNodeId($nodeId),
            $direction->value,
            $referenceTypeId !== null ? $this->serializer->serializeNodeId($referenceTypeId) : null,
            $includeSubtypes,
            $this->serializeNodeClasses($nodeClasses),
        ]);

        return $this->serializer->deserializeBrowseResultSet($result);
    }

    /**
     * @param string $continuationPoint
     * @return BrowseResultSet
     *
     * @throws ConnectionException
     * @throws ServiceException
     * @throws DaemonException
     */
    public function browseNext(string $continuationPoint): BrowseResultSet
    {
        $result = $this->query('browseNext', [$continuationPoint]);

        return $this->serializer->deserializeBrowseResultSet($result);
    }

    /**
     * @param NodeId|string $nodeId
     * @param BrowseDirection $direction
     * @param ?NodeId $referenceTypeId
     * @param bool $includeSubtypes
     * @param NodeClass[] $nodeClasses
     * @param bool $useCache
     * @return ReferenceDescription[]
     *
     * @throws ConnectionException
     * @throws ServiceException
     * @throws DaemonException
     */
    public function browseAll(
        NodeId|string   $nodeId,
        BrowseDirection $direction = BrowseDirection::Forward,
        ?NodeId         $referenceTypeId = null,
        bool            $includeSubtypes = true,
        array           $nodeClasses = [],
        bool            $useCache = true,
    ): array
    {
        $nodeId = $this->resolveNodeIdParam($nodeId);

        $result = $this->query('browseAll', [
            $this->serializer->serializeNodeId($nodeId),
            $direction->value,
            $referenceTypeId !== null ? $this->serializer->serializeNodeId($referenceTypeId) : null,
            $includeSubtypes,
            $this->serializeNodeClasses($nodeClasses),
            $useCache,
        ]);

        return array_map(
            fn(array $ref) => $this->serializer->deserializeReferenceDescription($ref),
            $result,
        );
    }

    /**
     * @param NodeId|string $nodeId
     * @param BrowseDirection $direction
     * @param ?int $maxDepth
     * @param ?NodeId $referenceTypeId
     * @param bool $includeSubtypes
     * @param NodeClass[] $nodeClasses
     * @return BrowseNode[]
     *
     * @throws ConnectionException
     * @throws ServiceException
     * @throws DaemonException
     */
    public function browseRecursive(
        NodeId|string   $nodeId,
        BrowseDirection $direction = BrowseDirection::Forward,
        ?int            $maxDepth = null,
        ?NodeId         $referenceTypeId = null,
        bool            $includeSubtypes = true,
        array           $nodeClasses = [],
    ): array
    {
        $nodeId = $this->resolveNodeIdParam($nodeId);

        $result = $this->query('browseRecursive', [
            $this->serializer->serializeNodeId($nodeId),
            $direction->value,
            $maxDepth,
            $referenceTypeId !== null ? $this->serializer->serializeNodeId($referenceTypeId) : null,
            $includeSubtypes,
            $this->serializeNodeClasses($nodeClasses),
        ]);

        return array_map(
            fn(array $node) => $this->serializer->deserializeBrowseNode($node),
            $result,
        );
    }

    /**
     * @param ?array $browsePaths
     * @return BrowsePathResult[]|BrowsePathsBuilder
     *
     * @throws ConnectionException
     * @throws ServiceException
     * @throws DaemonException
     */
    public function translateBrowsePaths(?array $browsePaths = null): array|BrowsePathsBuilder
    {
        if ($browsePaths === null) {
            return new BrowsePathsBuilder($this);
        }

        $serializedPaths = array_map(fn(array $bp) => [
            'startingNodeId' => $this->serializer->serializeNodeId(
                $this->resolveNodeIdParam($bp['startingNodeId']),
            ),
            'relativePath' => array_map(fn(array $elem) => [
                'referenceTypeId' => isset($elem['referenceTypeId'])
                    ? $this->serializer->serializeNodeId($elem['referenceTypeId'])
                    : null,
                'isInverse' => $elem['isInverse'] ?? false,
                'includeSubtypes' => $elem['includeSubtypes'] ?? true,
                'targetName' => $this->serializer->serializeQualifiedName($elem['targetName']),
            ], $bp['relativePath'] ?? []),
        ], $browsePaths);

        $result = $this->query('translateBrowsePaths', [$serializedPaths]);

        return array_map(
            fn(array $pathResult) => $this->serializer->deserializeBrowsePathResult($pathResult),
            $result,
        );
    }

    /**
     * @param string $path
     * @param NodeId|string|null $startingNodeId
     * @param bool $useCache
     * @return NodeId
     *
     * @throws ConnectionException
     * @throws ServiceException
     * @throws DaemonException
     */
    public function resolveNodeId(string $path, NodeId|string|null $startingNodeId = null, bool $useCache = true): NodeId
    {
        $serializedStartingNodeId = null;
        if ($startingNodeId !== null) {
            $startingNodeId = $this->resolveNodeIdParam($startingNodeId);
            $serializedStartingNodeId = $this->serializer->serializeNodeId($startingNodeId);
        }

        $result = $this->query('resolveNodeId', [
            $path,
            $serializedStartingNodeId,
            $useCache,
        ]);

        return $this->serializer->deserializeNodeId($result);
    }

    /**
     * @param NodeId|string $nodeId
     * @param int $attributeId
     * @param bool $refresh Force a server read even if the result is cached.
     * @return DataValue
     *
     * @throws ConnectionException
     * @throws ServiceException
     * @throws DaemonException
     */
    public function read(NodeId|string $nodeId, int $attributeId = 13, bool $refresh = false): DataValue
    {
        $nodeId = $this->resolveNodeIdParam($nodeId);

        $result = $this->query('read', [
            $this->serializer->serializeNodeId($nodeId),
            $attributeId,
            $refresh,
        ]);

        return $this->serializer->deserializeDataValue($result);
    }

    /**
     * @param ?array $readItems
     * @return DataValue[]|ReadMultiBuilder
     *
     * @throws ConnectionException
     * @throws ServiceException
     * @throws DaemonException
     */
    public function readMulti(?array $readItems = null): array|ReadMultiBuilder
    {
        if ($readItems === null) {
            return new ReadMultiBuilder($this);
        }

        $serializedItems = array_map(fn(array $item) => [
            'nodeId' => $this->serializer->serializeNodeId(
                $this->resolveNodeIdParam($item['nodeId']),
            ),
            'attributeId' => $item['attributeId'] ?? 13,
        ], $readItems);

        $result = $this->query('readMulti', [$serializedItems]);

        return array_map(
            fn(array $dv) => $this->serializer->deserializeDataValue($dv),
            $result,
        );
    }

    /**
     * @param NodeId|string $nodeId
     * @param mixed $value
     * @param ?BuiltinType $type The OPC UA built-in type, or null for auto-detection.
     * @return int
     *
     * @throws ConnectionException
     * @throws ServiceException
     * @throws DaemonException
     */
    public function write(NodeId|string $nodeId, mixed $value, ?BuiltinType $type = null): int
    {
        $nodeId = $this->resolveNodeIdParam($nodeId);

        return $this->query('write', [
            $this->serializer->serializeNodeId($nodeId),
            $value,
            $type?->value,
        ]);
    }

    /**
     * @param ?array $writeItems
     * @return int[]|WriteMultiBuilder
     *
     * @throws ConnectionException
     * @throws ServiceException
     * @throws DaemonException
     */
    public function writeMulti(?array $writeItems = null): array|WriteMultiBuilder
    {
        if ($writeItems === null) {
            return new WriteMultiBuilder($this);
        }

        $serializedItems = array_map(fn(array $item) => [
            'nodeId' => $this->serializer->serializeNodeId(
                $this->resolveNodeIdParam($item['nodeId']),
            ),
            'value' => $item['value'],
            'type' => isset($item['type']) ? $item['type']->value : null,
            'attributeId' => $item['attributeId'] ?? 13,
        ], $writeItems);

        return $this->query('writeMulti', [$serializedItems]);
    }

    /**
     * @param NodeId|string $objectId
     * @param NodeId|string $methodId
     * @param Variant[] $inputArguments
     * @return CallResult
     *
     * @throws ConnectionException
     * @throws ServiceException
     * @throws DaemonException
     */
    public function call(NodeId|string $objectId, NodeId|string $methodId, array $inputArguments = []): CallResult
    {
        $objectId = $this->resolveNodeIdParam($objectId);
        $methodId = $this->resolveNodeIdParam($methodId);

        $serializedArgs = array_map(
            fn(Variant $v) => $this->serializer->serializeVariant($v),
            $inputArguments,
        );

        $result = $this->query('call', [
            $this->serializer->serializeNodeId($objectId),
            $this->serializer->serializeNodeId($methodId),
            $serializedArgs,
        ]);

        return $this->serializer->deserializeCallResult($result);
    }

    /**
     * @param float $publishingInterval
     * @param int $lifetimeCount
     * @param int $maxKeepAliveCount
     * @param int $maxNotificationsPerPublish
     * @param bool $publishingEnabled
     * @param int $priority
     * @return SubscriptionResult
     *
     * @throws ConnectionException
     * @throws ServiceException
     * @throws DaemonException
     */
    public function createSubscription(
        float $publishingInterval = 500.0,
        int   $lifetimeCount = 2400,
        int   $maxKeepAliveCount = 10,
        int   $maxNotificationsPerPublish = 0,
        bool  $publishingEnabled = true,
        int   $priority = 0,
    ): SubscriptionResult
    {
        $result = $this->query('createSubscription', [
            $publishingInterval,
            $lifetimeCount,
            $maxKeepAliveCount,
            $maxNotificationsPerPublish,
            $publishingEnabled,
            $priority,
        ]);

        return $this->serializer->deserializeSubscriptionResult($result);
    }

    /**
     * @param int $subscriptionId
     * @param ?array $monitoredItems
     * @return MonitoredItemResult[]|MonitoredItemsBuilder
     *
     * @throws ConnectionException
     * @throws ServiceException
     * @throws DaemonException
     */
    public function createMonitoredItems(
        int    $subscriptionId,
        ?array $monitoredItems = null,
    ): array|MonitoredItemsBuilder
    {
        if ($monitoredItems === null) {
            return new MonitoredItemsBuilder($this, $subscriptionId);
        }

        $serializedItems = array_map(fn(array $item) => [
            'nodeId' => $this->serializer->serializeNodeId(
                $this->resolveNodeIdParam($item['nodeId']),
            ),
            'attributeId' => $item['attributeId'] ?? 13,
            'samplingInterval' => $item['samplingInterval'] ?? 250.0,
            'queueSize' => $item['queueSize'] ?? 1,
            'clientHandle' => $item['clientHandle'] ?? 0,
            'monitoringMode' => $item['monitoringMode'] ?? 0,
        ], $monitoredItems);

        $result = $this->query('createMonitoredItems', [$subscriptionId, $serializedItems]);

        return array_map(
            fn(array $item) => $this->serializer->deserializeMonitoredItemResult($item),
            $result,
        );
    }

    /**
     * @param int $subscriptionId
     * @param NodeId|string $nodeId
     * @param string[] $selectFields
     * @param int $clientHandle
     * @return MonitoredItemResult
     *
     * @throws ConnectionException
     * @throws ServiceException
     * @throws DaemonException
     */
    public function createEventMonitoredItem(
        int           $subscriptionId,
        NodeId|string $nodeId,
        array         $selectFields = ['EventId', 'EventType', 'SourceName', 'Time', 'Message', 'Severity'],
        int           $clientHandle = 1,
    ): MonitoredItemResult
    {
        $nodeId = $this->resolveNodeIdParam($nodeId);

        $result = $this->query('createEventMonitoredItem', [
            $subscriptionId,
            $this->serializer->serializeNodeId($nodeId),
            $selectFields,
            $clientHandle,
        ]);

        return $this->serializer->deserializeMonitoredItemResult($result);
    }

    /**
     * @param int $subscriptionId
     * @param int[] $monitoredItemIds
     * @return int[]
     *
     * @throws ConnectionException
     * @throws ServiceException
     * @throws DaemonException
     */
    public function deleteMonitoredItems(int $subscriptionId, array $monitoredItemIds): array
    {
        return $this->query('deleteMonitoredItems', [$subscriptionId, $monitoredItemIds]);
    }

    /**
     * @param int $subscriptionId
     * @param array<array{monitoredItemId: int, samplingInterval?: float, queueSize?: int, clientHandle?: int, discardOldest?: bool}> $itemsToModify
     * @return MonitoredItemModifyResult[]
     *
     * @throws ConnectionException
     * @throws ServiceException
     * @throws DaemonException
     */
    public function modifyMonitoredItems(int $subscriptionId, array $itemsToModify): array
    {
        $result = $this->query('modifyMonitoredItems', [$subscriptionId, $itemsToModify]);

        return array_map(
            fn(array $item) => $this->serializer->deserializeMonitoredItemModifyResult($item),
            $result,
        );
    }

    /**
     * @param int $subscriptionId
     * @param int $triggeringItemId
     * @param int[] $linksToAdd
     * @param int[] $linksToRemove
     * @return SetTriggeringResult
     *
     * @throws ConnectionException
     * @throws ServiceException
     * @throws DaemonException
     */
    public function setTriggering(int $subscriptionId, int $triggeringItemId, array $linksToAdd = [], array $linksToRemove = []): SetTriggeringResult
    {
        $result = $this->query('setTriggering', [$subscriptionId, $triggeringItemId, $linksToAdd, $linksToRemove]);

        return $this->serializer->deserializeSetTriggeringResult($result);
    }

    /**
     * @param int $subscriptionId
     * @return int
     *
     * @throws ConnectionException
     * @throws ServiceException
     * @throws DaemonException
     */
    public function deleteSubscription(int $subscriptionId): int
    {
        return $this->query('deleteSubscription', [$subscriptionId]);
    }

    /**
     * @param array $acknowledgements
     * @return PublishResult
     *
     * @throws ConnectionException
     * @throws ServiceException
     * @throws DaemonException
     */
    public function publish(array $acknowledgements = []): PublishResult
    {
        $result = $this->query('publish', [$acknowledgements]);

        return $this->serializer->deserializePublishResult($result);
    }

    /**
     * @param int[] $subscriptionIds
     * @param bool $sendInitialValues
     * @return TransferResult[]
     *
     * @throws ConnectionException
     * @throws ServiceException
     * @throws DaemonException
     */
    public function transferSubscriptions(array $subscriptionIds, bool $sendInitialValues = false): array
    {
        $result = $this->query('transferSubscriptions', [$subscriptionIds, $sendInitialValues]);

        return array_map(
            fn(array $item) => $this->serializer->deserializeTransferResult($item),
            $result,
        );
    }

    /**
     * @param int $subscriptionId
     * @param int $retransmitSequenceNumber
     * @return array{sequenceNumber: int, publishTime: ?DateTimeImmutable, notifications: array}
     *
     * @throws ConnectionException
     * @throws ServiceException
     * @throws DaemonException
     */
    public function republish(int $subscriptionId, int $retransmitSequenceNumber): array
    {
        $result = $this->query('republish', [$subscriptionId, $retransmitSequenceNumber]);

        if (isset($result['publishTime']) && is_string($result['publishTime'])) {
            $result['publishTime'] = new DateTimeImmutable($result['publishTime']);
        }

        return $result;
    }

    /**
     * @param NodeId|string $nodeId
     * @param ?DateTimeImmutable $startTime
     * @param ?DateTimeImmutable $endTime
     * @param int $numValuesPerNode
     * @param bool $returnBounds
     * @return DataValue[]
     *
     * @throws ConnectionException
     * @throws ServiceException
     * @throws DaemonException
     */
    public function historyReadRaw(
        NodeId|string      $nodeId,
        ?DateTimeImmutable $startTime = null,
        ?DateTimeImmutable $endTime = null,
        int                $numValuesPerNode = 0,
        bool               $returnBounds = false,
    ): array
    {
        $nodeId = $this->resolveNodeIdParam($nodeId);

        $result = $this->query('historyReadRaw', [
            $this->serializer->serializeNodeId($nodeId),
            $startTime?->format('c'),
            $endTime?->format('c'),
            $numValuesPerNode,
            $returnBounds,
        ]);

        return array_map(
            fn(array $dv) => $this->serializer->deserializeDataValue($dv),
            $result,
        );
    }

    /**
     * @param NodeId|string $nodeId
     * @param DateTimeImmutable $startTime
     * @param DateTimeImmutable $endTime
     * @param float $processingInterval
     * @param NodeId $aggregateType
     * @return DataValue[]
     *
     * @throws ConnectionException
     * @throws ServiceException
     * @throws DaemonException
     */
    public function historyReadProcessed(
        NodeId|string     $nodeId,
        DateTimeImmutable $startTime,
        DateTimeImmutable $endTime,
        float             $processingInterval,
        NodeId            $aggregateType,
    ): array
    {
        $nodeId = $this->resolveNodeIdParam($nodeId);

        $result = $this->query('historyReadProcessed', [
            $this->serializer->serializeNodeId($nodeId),
            $startTime->format('c'),
            $endTime->format('c'),
            $processingInterval,
            $this->serializer->serializeNodeId($aggregateType),
        ]);

        return array_map(
            fn(array $dv) => $this->serializer->deserializeDataValue($dv),
            $result,
        );
    }

    /**
     * @param NodeId|string $nodeId
     * @param DateTimeImmutable[] $timestamps
     * @return DataValue[]
     *
     * @throws ConnectionException
     * @throws ServiceException
     * @throws DaemonException
     */
    public function historyReadAtTime(
        NodeId|string $nodeId,
        array         $timestamps,
    ): array
    {
        $nodeId = $this->resolveNodeIdParam($nodeId);

        $result = $this->query('historyReadAtTime', [
            $this->serializer->serializeNodeId($nodeId),
            array_map(fn(DateTimeImmutable $ts) => $ts->format('c'), $timestamps),
        ]);

        return array_map(
            fn(array $dv) => $this->serializer->deserializeDataValue($dv),
            $result,
        );
    }

    /**
     * @return ?string
     */
    public function getSessionId(): ?string
    {
        return $this->sessionId;
    }

    /**
     * @param NodeId|string $nodeId
     * @return NodeId
     */
    private function resolveNodeIdParam(NodeId|string $nodeId): NodeId
    {
        return is_string($nodeId) ? NodeId::parse($nodeId) : $nodeId;
    }

    /**
     * @param NodeClass[] $nodeClasses
     * @return int[]
     */
    private function serializeNodeClasses(array $nodeClasses): array
    {
        return array_map(fn(NodeClass $nc) => $nc->value, $nodeClasses);
    }

    /**
     * @param string $method
     * @param array $params
     * @return mixed
     *
     * @throws ConnectionException
     * @throws DaemonException
     */
    private function query(string $method, array $params): mixed
    {
        if ($this->sessionId === null) {
            throw new ConnectionException('Not connected. Call connect() first.');
        }

        return $this->sendCommand([
            'command' => 'query',
            'sessionId' => $this->sessionId,
            'method' => $method,
            'params' => $params,
        ]);
    }

    /**
     * @param array $command
     * @return mixed
     *
     * @throws ConnectionException
     * @throws ServiceException
     * @throws DaemonException
     */
    private function sendCommand(array $command): mixed
    {
        if ($this->authToken !== null) {
            $command['authToken'] = $this->authToken;
        }

        $response = SocketConnection::send($this->socketPath, $command, $this->timeout);

        if (!($response['success'] ?? false)) {
            $error = $response['error'] ?? [];
            $type = $error['type'] ?? 'unknown';
            $message = $error['message'] ?? 'Unknown error';

            throw match ($type) {
                'ConnectionException' => new ConnectionException($message),
                'ServiceUnsupportedException' => new ServiceUnsupportedException($message),
                'ServiceException' => new ServiceException($message),
                'session_not_found' => new ConnectionException("Session expired or not found: {$message}"),
                default => new DaemonException("[{$type}] {$message}"),
            };
        }

        return $response['data'];
    }

    /**
     * @return ?string Server ProductName (`ns=0;i=2262`), or null if the server does not expose it.
     *
     * @throws ConnectionException
     * @throws ServiceException
     * @throws DaemonException
     */
    public function getServerProductName(): ?string
    {
        $value = $this->read(NodeId::numeric(0, 2262), AttributeId::Value)->getValue();

        return is_string($value) ? $value : null;
    }

    /**
     * @return ?string Server ManufacturerName (`ns=0;i=2263`), or null if the server does not expose it.
     *
     * @throws ConnectionException
     * @throws ServiceException
     * @throws DaemonException
     */
    public function getServerManufacturerName(): ?string
    {
        $value = $this->read(NodeId::numeric(0, 2263), AttributeId::Value)->getValue();

        return is_string($value) ? $value : null;
    }

    /**
     * @return ?string Server SoftwareVersion (`ns=0;i=2264`), or null if the server does not expose it.
     *
     * @throws ConnectionException
     * @throws ServiceException
     * @throws DaemonException
     */
    public function getServerSoftwareVersion(): ?string
    {
        $value = $this->read(NodeId::numeric(0, 2264), AttributeId::Value)->getValue();

        return is_string($value) ? $value : null;
    }

    /**
     * @return ?string Server BuildNumber (`ns=0;i=2265`), or null if the server does not expose it.
     *
     * @throws ConnectionException
     * @throws ServiceException
     * @throws DaemonException
     */
    public function getServerBuildNumber(): ?string
    {
        $value = $this->read(NodeId::numeric(0, 2265), AttributeId::Value)->getValue();

        return is_string($value) ? $value : null;
    }

    /**
     * @return ?DateTimeImmutable Server BuildDate (`ns=0;i=2266`), or null if the server does not expose it.
     *
     * @throws ConnectionException
     * @throws ServiceException
     * @throws DaemonException
     */
    public function getServerBuildDate(): ?DateTimeImmutable
    {
        $value = $this->read(NodeId::numeric(0, 2266), AttributeId::Value)->getValue();

        return $value instanceof DateTimeImmutable ? $value : null;
    }

    /**
     * Fetch the full BuildInfo structure in a single `readMulti()` IPC round-trip.
     *
     * @return BuildInfo Populated with whichever of the 5 BuildInfo fields the server exposes;
     *                   missing or typo-typed fields are left as `null` on the DTO.
     *
     * @throws ConnectionException
     * @throws ServiceException
     * @throws DaemonException
     */
    public function getServerBuildInfo(): BuildInfo
    {
        $results = $this->readMulti([
            ['nodeId' => NodeId::numeric(0, 2262)],
            ['nodeId' => NodeId::numeric(0, 2263)],
            ['nodeId' => NodeId::numeric(0, 2264)],
            ['nodeId' => NodeId::numeric(0, 2265)],
            ['nodeId' => NodeId::numeric(0, 2266)],
        ]);

        $productName = $results[0]->getValue();
        $manufacturerName = $results[1]->getValue();
        $softwareVersion = $results[2]->getValue();
        $buildNumber = $results[3]->getValue();
        $buildDate = $results[4]->getValue();

        return new BuildInfo(
            productName: is_string($productName) ? $productName : null,
            manufacturerName: is_string($manufacturerName) ? $manufacturerName : null,
            softwareVersion: is_string($softwareVersion) ? $softwareVersion : null,
            buildNumber: is_string($buildNumber) ? $buildNumber : null,
            buildDate: $buildDate instanceof DateTimeImmutable ? $buildDate : null,
        );
    }

    /**
     * Add nodes to the server's address space. Delegated to the daemon via the
     * generic `invoke` IPC path — functional only when the daemon's underlying
     * client has the `NodeManagementModule` loaded (opt-in in opcua-client
     * v4.2.0).
     *
     * @param array $nodesToAdd
     * @return AddNodesResult[]
     *
     * @throws BadMethodCallException If the daemon's client does not have `addNodes` registered.
     * @throws ConnectionException
     * @throws ServiceException
     * @throws ServiceUnsupportedException When the server does not implement the NodeManagement service set (`BadServiceUnsupported`).
     * @throws DaemonException
     */
    public function addNodes(array $nodesToAdd): array
    {
        /** @var AddNodesResult[] $result */
        $result = $this->invokeRemote('addNodes', [$nodesToAdd]);

        return $result;
    }

    /**
     * Delete nodes. Delegated via `invoke`. See {@see self::addNodes()}.
     *
     * @param array $nodesToDelete
     * @return int[]
     *
     * @throws BadMethodCallException
     * @throws ConnectionException
     * @throws ServiceException
     * @throws ServiceUnsupportedException
     * @throws DaemonException
     */
    public function deleteNodes(array $nodesToDelete): array
    {
        /** @var int[] $result */
        $result = $this->invokeRemote('deleteNodes', [$nodesToDelete]);

        return $result;
    }

    /**
     * Add references between nodes. Delegated via `invoke`.
     *
     * @param array $referencesToAdd
     * @return int[]
     *
     * @throws BadMethodCallException
     * @throws ConnectionException
     * @throws ServiceException
     * @throws ServiceUnsupportedException
     * @throws DaemonException
     */
    public function addReferences(array $referencesToAdd): array
    {
        /** @var int[] $result */
        $result = $this->invokeRemote('addReferences', [$referencesToAdd]);

        return $result;
    }

    /**
     * Delete references. Delegated via `invoke`.
     *
     * @param array $referencesToDelete
     * @return int[]
     *
     * @throws BadMethodCallException
     * @throws ConnectionException
     * @throws ServiceException
     * @throws ServiceUnsupportedException
     * @throws DaemonException
     */
    public function deleteReferences(array $referencesToDelete): array
    {
        /** @var int[] $result */
        $result = $this->invokeRemote('deleteReferences', [$referencesToDelete]);

        return $result;
    }

    /**
     * Check whether a method of the given name is reachable on this client.
     *
     * Answers truthfully for any method registered on the daemon's underlying
     * `PhpOpcua\Client\Client` — including method names contributed by third-
     * party modules the daemon has loaded via `ClientBuilder::addModule()`.
     * The list is fetched lazily the first time the client queries it and
     * cached until {@see self::disconnect()}.
     *
     * @param string $name The method name to probe.
     * @return bool
     */
    public function hasMethod(string $name): bool
    {
        if ($this->sessionId === null) {
            return method_exists($this, $name);
        }
        try {
            return in_array($name, $this->ensureDescribe()['methods'], true);
        } catch (DaemonException|ConnectionException) {
            return method_exists($this, $name);
        }
    }

    /**
     * Check whether a module of the given fully-qualified class name is loaded
     * on the daemon's underlying client.
     *
     * Resolved through the same `describe` round-trip used by {@see self::hasMethod()};
     * no hardcoded module list — the truth of record is the daemon's
     * `moduleRegistry->getModuleClasses()`.
     *
     * @param string $moduleClass Fully-qualified module class name.
     * @return bool
     */
    public function hasModule(string $moduleClass): bool
    {
        if ($this->sessionId === null) {
            return false;
        }
        try {
            return in_array($moduleClass, $this->ensureDescribe()['modules'], true);
        } catch (DaemonException|ConnectionException) {
            return false;
        }
    }

    /**
     * {@inheritDoc}
     *
     * @throws DaemonException
     * @throws ConnectionException
     */
    public function getRegisteredMethods(): array
    {
        if ($this->sessionId === null) {
            $names = [];
            foreach ((new \ReflectionClass(OpcUaClientInterface::class))->getMethods() as $m) {
                $names[] = $m->getName();
            }

            return array_values(array_unique($names));
        }

        return $this->ensureDescribe()['methods'];
    }

    /**
     * {@inheritDoc}
     *
     * @throws DaemonException
     * @throws ConnectionException
     */
    public function getLoadedModules(): array
    {
        if ($this->sessionId === null) {
            return [];
        }

        return $this->ensureDescribe()['modules'];
    }

    /**
     * Proxy any method name that is registered on the daemon's client but is
     * not declared as a concrete method on {@see ManagedClient}. Covers third-
     * party modules transparently: if the daemon loaded a custom
     * `AcmeModule::queryFirst(...)`, the caller can do `$managed->queryFirst(...)`
     * and the args / return value flow through {@see WireMessageCodec}.
     *
     * @param string $method
     * @param array<int, mixed> $args
     * @return mixed
     *
     * @throws BadMethodCallException If the daemon has no such method.
     * @throws ConnectionException
     * @throws ServiceException
     * @throws DaemonException
     */
    public function __call(string $method, array $args): mixed
    {
        return $this->invokeRemote($method, $args);
    }

    /**
     * Fetch (and cache) the remote `describe` metadata, invalidated on disconnect.
     *
     * @return array{methods: string[], modules: array<class-string>, wireClasses: array<class-string>, enumClasses: array<class-string>}
     * @throws DaemonException
     * @throws ConnectionException
     */
    private function ensureDescribe(): array
    {
        if ($this->describe !== null) {
            return $this->describe;
        }
        if ($this->sessionId === null) {
            throw new ConnectionException('Not connected. Call connect() first.');
        }

        $data = $this->sendCommand([
            'command' => 'describe',
            'sessionId' => $this->sessionId,
        ]);

        if (! is_array($data)
            || ! isset($data['methods'], $data['modules'], $data['wireClasses'], $data['enumClasses'])
            || ! is_array($data['methods']) || ! is_array($data['modules'])
            || ! is_array($data['wireClasses']) || ! is_array($data['enumClasses'])
        ) {
            throw new DaemonException('Malformed describe response: missing or non-array fields.');
        }

        $this->describe = [
            'methods' => array_values($data['methods']),
            'modules' => array_values($data['modules']),
            'wireClasses' => array_values($data['wireClasses']),
            'enumClasses' => array_values($data['enumClasses']),
        ];
        $this->wireCodec = $this->buildCodecFromDescribe($this->describe);

        return $this->describe;
    }

    /**
     * Build a codec whose registry mirrors the daemon's type allowlist.
     * Classes not loadable on this side are skipped — decode fails loudly later if needed.
     *
     * @param array{wireClasses: array<class-string>, enumClasses: array<class-string>} $describe
     * @return WireMessageCodec
     */
    private function buildCodecFromDescribe(array $describe): WireMessageCodec
    {
        $registry = new WireTypeRegistry();
        foreach ($describe['wireClasses'] as $class) {
            if (class_exists($class) && is_subclass_of($class, WireSerializable::class)) {
                $registry->register($class);
            }
        }
        foreach ($describe['enumClasses'] as $class) {
            if (class_exists($class) && is_subclass_of($class, UnitEnum::class)) {
                $registry->registerEnum($class);
            }
        }

        return new WireMessageCodec($registry);
    }

    /**
     * Send an `invoke` request for `$method` with positional `$args`.
     *
     * @param string $method Target method name on the daemon's client.
     * @param array<int, mixed> $args
     * @return mixed The wire-decoded return value.
     * @throws BadMethodCallException If the daemon's client does not expose `$method`.
     * @throws ConnectionException
     * @throws ServiceException
     * @throws DaemonException
     */
    private function invokeRemote(string $method, array $args): mixed
    {
        $describe = $this->ensureDescribe();
        if (! in_array($method, $describe['methods'], true)) {
            throw new BadMethodCallException(sprintf(
                'Method "%s" is not available on the session-manager daemon (describe reported %d method(s)). '
                . 'If a third-party module adds this method, make sure the module is loaded on the daemon side.',
                $method,
                count($describe['methods']),
            ));
        }

        $codec = $this->wireCodec;
        if ($codec === null) {
            throw new DaemonException('invoke: wire codec is not initialised (describe returned but codec missing).');
        }

        $wireArgs = array_map(fn ($v) => $codec->registry()->encode($v), $args);

        $data = $this->sendCommand([
            'command' => 'invoke',
            'sessionId' => $this->sessionId,
            'method' => $method,
            'args' => $wireArgs,
        ]);

        if (! is_array($data) || ! array_key_exists('data', $data)) {
            throw new DaemonException('Malformed invoke response: missing "data" field.');
        }

        return $codec->registry()->decode($data['data']);
    }
}
