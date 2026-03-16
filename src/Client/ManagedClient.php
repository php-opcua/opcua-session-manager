<?php

declare(strict_types=1);

namespace Gianfriaur\OpcuaSessionManager\Client;

use Gianfriaur\OpcuaPhpClient\Exception\ConnectionException;
use Gianfriaur\OpcuaPhpClient\Exception\OpcUaException;
use Gianfriaur\OpcuaPhpClient\Exception\ServiceException;
use Gianfriaur\OpcuaPhpClient\OpcUaClientInterface;
use Gianfriaur\OpcuaPhpClient\Security\SecurityMode;
use Gianfriaur\OpcuaPhpClient\Security\SecurityPolicy;
use Gianfriaur\OpcuaPhpClient\Types\BuiltinType;
use Gianfriaur\OpcuaPhpClient\Types\DataValue;
use Gianfriaur\OpcuaPhpClient\Types\NodeId;
use Gianfriaur\OpcuaPhpClient\Types\Variant;
use Gianfriaur\OpcuaSessionManager\Exception\DaemonException;
use Gianfriaur\OpcuaSessionManager\Serialization\TypeSerializer;

class ManagedClient implements OpcUaClientInterface
{
    private ?string $sessionId = null;
    private array $config = [];
    private TypeSerializer $serializer;

    public function __construct(
        private readonly string $socketPath = '/tmp/opcua-session-manager.sock',
        private readonly float $timeout = 30.0,
        private readonly ?string $authToken = null,
    ) {
        $this->serializer = new TypeSerializer();
    }

    public function setSecurityPolicy(SecurityPolicy $policy): self
    {
        $this->config['securityPolicy'] = $policy->value;

        return $this;
    }

    public function setSecurityMode(SecurityMode $mode): self
    {
        $this->config['securityMode'] = $mode->value;

        return $this;
    }

    public function setUserCredentials(string $username, string $password): self
    {
        $this->config['username'] = $username;
        $this->config['password'] = $password;

        return $this;
    }

    public function setClientCertificate(string $certPath, string $keyPath, ?string $caCertPath = null): self
    {
        $this->config['clientCertPath'] = $certPath;
        $this->config['clientKeyPath'] = $keyPath;
        if ($caCertPath !== null) {
            $this->config['caCertPath'] = $caCertPath;
        }

        return $this;
    }

    public function setUserCertificate(string $certPath, string $keyPath): self
    {
        $this->config['userCertPath'] = $certPath;
        $this->config['userKeyPath'] = $keyPath;

        return $this;
    }

    public function connect(string $endpointUrl): void
    {
        $response = $this->sendCommand([
            'command' => 'open',
            'endpointUrl' => $endpointUrl,
            'config' => $this->config,
        ]);

        $this->sessionId = $response['sessionId'];
    }

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

    public function getEndpoints(string $endpointUrl): array
    {
        $result = $this->query('getEndpoints', [$endpointUrl]);

        return $result;
    }

    public function browse(
        NodeId $nodeId,
        int $direction = 0,
        ?NodeId $referenceTypeId = null,
        bool $includeSubtypes = true,
        int $nodeClassMask = 0,
    ): array {
        $result = $this->query('browse', [
            $this->serializer->serializeNodeId($nodeId),
            $direction,
            $referenceTypeId !== null ? $this->serializer->serializeNodeId($referenceTypeId) : null,
            $includeSubtypes,
            $nodeClassMask,
        ]);

        return array_map(
            fn(array $ref) => $this->serializer->deserializeReferenceDescription($ref),
            $result,
        );
    }

    public function browseWithContinuation(
        NodeId $nodeId,
        int $direction = 0,
        ?NodeId $referenceTypeId = null,
        bool $includeSubtypes = true,
        int $nodeClassMask = 0,
    ): array {
        $result = $this->query('browseWithContinuation', [
            $this->serializer->serializeNodeId($nodeId),
            $direction,
            $referenceTypeId !== null ? $this->serializer->serializeNodeId($referenceTypeId) : null,
            $includeSubtypes,
            $nodeClassMask,
        ]);

        return [
            'references' => array_map(
                fn(array $ref) => $this->serializer->deserializeReferenceDescription($ref),
                $result['references'] ?? [],
            ),
            'continuationPoint' => $result['continuationPoint'] ?? null,
        ];
    }

    public function browseNext(string $continuationPoint): array
    {
        $result = $this->query('browseNext', [$continuationPoint]);

        return [
            'references' => array_map(
                fn(array $ref) => $this->serializer->deserializeReferenceDescription($ref),
                $result['references'] ?? [],
            ),
            'continuationPoint' => $result['continuationPoint'] ?? null,
        ];
    }

    public function read(NodeId $nodeId, int $attributeId = 13): DataValue
    {
        $result = $this->query('read', [
            $this->serializer->serializeNodeId($nodeId),
            $attributeId,
        ]);

        return $this->serializer->deserializeDataValue($result);
    }

    public function readMulti(array $items): array
    {
        $serializedItems = array_map(fn(array $item) => [
            'nodeId' => $this->serializer->serializeNodeId($item['nodeId']),
            'attributeId' => $item['attributeId'] ?? 13,
        ], $items);

        $result = $this->query('readMulti', [$serializedItems]);

        return array_map(
            fn(array $dv) => $this->serializer->deserializeDataValue($dv),
            $result,
        );
    }

    public function write(NodeId $nodeId, mixed $value, BuiltinType $type): int
    {
        return $this->query('write', [
            $this->serializer->serializeNodeId($nodeId),
            $value,
            $type->value,
        ]);
    }

    public function writeMulti(array $items): array
    {
        $serializedItems = array_map(fn(array $item) => [
            'nodeId' => $this->serializer->serializeNodeId($item['nodeId']),
            'value' => $item['value'],
            'type' => $item['type']->value,
            'attributeId' => $item['attributeId'] ?? 13,
        ], $items);

        return $this->query('writeMulti', [$serializedItems]);
    }

    public function call(NodeId $objectId, NodeId $methodId, array $inputArguments = []): array
    {
        $serializedArgs = array_map(
            fn(Variant $v) => $this->serializer->serializeVariant($v),
            $inputArguments,
        );

        return $this->query('call', [
            $this->serializer->serializeNodeId($objectId),
            $this->serializer->serializeNodeId($methodId),
            $serializedArgs,
        ]);
    }

    public function createSubscription(
        float $publishingInterval = 500.0,
        int $lifetimeCount = 2400,
        int $maxKeepAliveCount = 10,
        int $maxNotificationsPerPublish = 0,
        bool $publishingEnabled = true,
        int $priority = 0,
    ): array {
        return $this->query('createSubscription', [
            $publishingInterval,
            $lifetimeCount,
            $maxKeepAliveCount,
            $maxNotificationsPerPublish,
            $publishingEnabled,
            $priority,
        ]);
    }

    public function createMonitoredItems(int $subscriptionId, array $items): array
    {
        $serializedItems = array_map(fn(array $item) => [
            'nodeId' => $this->serializer->serializeNodeId($item['nodeId']),
            'attributeId' => $item['attributeId'] ?? 13,
            'samplingInterval' => $item['samplingInterval'] ?? 250.0,
            'queueSize' => $item['queueSize'] ?? 1,
            'clientHandle' => $item['clientHandle'] ?? 0,
            'monitoringMode' => $item['monitoringMode'] ?? 0,
        ], $items);

        return $this->query('createMonitoredItems', [$subscriptionId, $serializedItems]);
    }

    public function createEventMonitoredItem(
        int $subscriptionId,
        NodeId $nodeId,
        array $selectFields = ['EventId', 'EventType', 'SourceName', 'Time', 'Message', 'Severity'],
        int $clientHandle = 1,
    ): array {
        return $this->query('createEventMonitoredItem', [
            $subscriptionId,
            $this->serializer->serializeNodeId($nodeId),
            $selectFields,
            $clientHandle,
        ]);
    }

    public function deleteMonitoredItems(int $subscriptionId, array $monitoredItemIds): array
    {
        return $this->query('deleteMonitoredItems', [$subscriptionId, $monitoredItemIds]);
    }

    public function deleteSubscription(int $subscriptionId): int
    {
        return $this->query('deleteSubscription', [$subscriptionId]);
    }

    public function publish(array $acknowledgements = []): array
    {
        return $this->query('publish', [$acknowledgements]);
    }

    public function historyReadRaw(
        NodeId $nodeId,
        ?\DateTimeImmutable $startTime = null,
        ?\DateTimeImmutable $endTime = null,
        int $numValuesPerNode = 0,
        bool $returnBounds = false,
    ): array {
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

    public function historyReadProcessed(
        NodeId $nodeId,
        \DateTimeImmutable $startTime,
        \DateTimeImmutable $endTime,
        float $processingInterval,
        NodeId $aggregateType,
    ): array {
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

    public function historyReadAtTime(
        NodeId $nodeId,
        array $timestamps,
    ): array {
        $result = $this->query('historyReadAtTime', [
            $this->serializer->serializeNodeId($nodeId),
            array_map(fn(\DateTimeImmutable $ts) => $ts->format('c'), $timestamps),
        ]);

        return array_map(
            fn(array $dv) => $this->serializer->deserializeDataValue($dv),
            $result,
        );
    }

    public function getSessionId(): ?string
    {
        return $this->sessionId;
    }

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
