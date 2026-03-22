<?php

declare(strict_types=1);

namespace Gianfriaur\OpcuaSessionManager\Client;

use DateTimeImmutable;
use Gianfriaur\OpcuaPhpClient\Builder\BrowsePathsBuilder;
use Gianfriaur\OpcuaPhpClient\Builder\MonitoredItemsBuilder;
use Gianfriaur\OpcuaPhpClient\Builder\ReadMultiBuilder;
use Gianfriaur\OpcuaPhpClient\Builder\WriteMultiBuilder;
use Gianfriaur\OpcuaPhpClient\Exception\ConnectionException;
use Gianfriaur\OpcuaPhpClient\Exception\ServiceException;
use Gianfriaur\OpcuaPhpClient\OpcUaClientInterface;
use Gianfriaur\OpcuaPhpClient\Repository\ExtensionObjectRepository;
use Gianfriaur\OpcuaPhpClient\Security\SecurityMode;
use Gianfriaur\OpcuaPhpClient\Security\SecurityPolicy;
use Gianfriaur\OpcuaPhpClient\Types\BrowseDirection;
use Gianfriaur\OpcuaPhpClient\Types\BrowseNode;
use Gianfriaur\OpcuaPhpClient\Types\BrowsePathResult;
use Gianfriaur\OpcuaPhpClient\Types\BrowseResultSet;
use Gianfriaur\OpcuaPhpClient\Types\BuiltinType;
use Gianfriaur\OpcuaPhpClient\Types\CallResult;
use Gianfriaur\OpcuaPhpClient\Types\ConnectionState;
use Gianfriaur\OpcuaPhpClient\Types\DataValue;
use Gianfriaur\OpcuaPhpClient\Types\EndpointDescription;
use Gianfriaur\OpcuaPhpClient\Types\MonitoredItemResult;
use Gianfriaur\OpcuaPhpClient\Types\NodeClass;
use Gianfriaur\OpcuaPhpClient\Types\NodeId;
use Gianfriaur\OpcuaPhpClient\Types\PublishResult;
use Gianfriaur\OpcuaPhpClient\Types\SubscriptionResult;
use Gianfriaur\OpcuaPhpClient\Types\TransferResult;
use Gianfriaur\OpcuaPhpClient\Types\Variant;
use Gianfriaur\OpcuaSessionManager\Exception\DaemonException;
use Gianfriaur\OpcuaSessionManager\Serialization\TypeSerializer;
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
    private array $config = [];
    private TypeSerializer $serializer;

    private float $opcuaTimeout = 5.0;
    private ?int $autoRetry = null;
    private ?int $batchSize = null;
    private int $defaultBrowseMaxDepth = 10;

    private LoggerInterface $logger;
    private ?CacheInterface $cache = null;
    private ExtensionObjectRepository $extensionObjectRepository;

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
     * @return DataValue
     *
     * @throws ConnectionException
     * @throws ServiceException
     * @throws DaemonException
     */
    public function read(NodeId|string $nodeId, int $attributeId = 13): DataValue
    {
        $nodeId = $this->resolveNodeIdParam($nodeId);

        $result = $this->query('read', [
            $this->serializer->serializeNodeId($nodeId),
            $attributeId,
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
     * @param BuiltinType $type
     * @return int
     *
     * @throws ConnectionException
     * @throws ServiceException
     * @throws DaemonException
     */
    public function write(NodeId|string $nodeId, mixed $value, BuiltinType $type): int
    {
        $nodeId = $this->resolveNodeIdParam($nodeId);

        return $this->query('write', [
            $this->serializer->serializeNodeId($nodeId),
            $value,
            $type->value,
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
            'type' => $item['type']->value,
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
                'ServiceException' => new ServiceException($message),
                'session_not_found' => new ConnectionException("Session expired or not found: {$message}"),
                default => new DaemonException("[{$type}] {$message}"),
            };
        }

        return $response['data'];
    }
}
