<?php

declare(strict_types=1);

namespace Gianfriaur\OpcuaSessionManager\Serialization;

use DateTimeImmutable;
use Gianfriaur\OpcuaPhpClient\Types\BrowseDirection;
use Gianfriaur\OpcuaPhpClient\Types\BrowseNode;
use Gianfriaur\OpcuaPhpClient\Types\BrowsePathResult;
use Gianfriaur\OpcuaPhpClient\Types\BrowsePathTarget;
use Gianfriaur\OpcuaPhpClient\Types\BrowseResultSet;
use Gianfriaur\OpcuaPhpClient\Types\BuiltinType;
use Gianfriaur\OpcuaPhpClient\Types\CallResult;
use Gianfriaur\OpcuaPhpClient\Types\ConnectionState;
use Gianfriaur\OpcuaPhpClient\Types\DataValue;
use Gianfriaur\OpcuaPhpClient\Types\EndpointDescription;
use Gianfriaur\OpcuaPhpClient\Types\LocalizedText;
use Gianfriaur\OpcuaPhpClient\Types\MonitoredItemResult;
use Gianfriaur\OpcuaPhpClient\Types\NodeClass;
use Gianfriaur\OpcuaPhpClient\Types\NodeId;
use Gianfriaur\OpcuaPhpClient\Types\PublishResult;
use Gianfriaur\OpcuaPhpClient\Types\QualifiedName;
use Gianfriaur\OpcuaPhpClient\Types\ReferenceDescription;
use Gianfriaur\OpcuaPhpClient\Types\SubscriptionResult;
use Gianfriaur\OpcuaPhpClient\Types\TransferResult;
use Gianfriaur\OpcuaPhpClient\Types\UserTokenPolicy;
use Gianfriaur\OpcuaPhpClient\Types\Variant;
use Gianfriaur\OpcuaSessionManager\Exception\SerializationException;

/**
 * Bidirectional JSON serialization and deserialization for all OPC UA types and DTOs transported over IPC.
 */
class TypeSerializer
{
    public function serialize(mixed $value): mixed
    {
        if ($value === null) {
            return null;
        }

        if (is_scalar($value)) {
            return $value;
        }

        if ($value instanceof DateTimeImmutable) {
            return $value->format('c');
        }

        if ($value instanceof NodeId) {
            return $this->serializeNodeId($value);
        }

        if ($value instanceof DataValue) {
            return $this->serializeDataValue($value);
        }

        if ($value instanceof Variant) {
            return $this->serializeVariant($value);
        }

        if ($value instanceof ReferenceDescription) {
            return $this->serializeReferenceDescription($value);
        }

        if ($value instanceof BrowseNode) {
            return $this->serializeBrowseNode($value);
        }

        if ($value instanceof QualifiedName) {
            return $this->serializeQualifiedName($value);
        }

        if ($value instanceof LocalizedText) {
            return $this->serializeLocalizedText($value);
        }

        if ($value instanceof BuiltinType) {
            return $value->value;
        }

        if ($value instanceof NodeClass) {
            return $value->value;
        }

        if ($value instanceof BrowseDirection) {
            return $value->value;
        }

        if ($value instanceof ConnectionState) {
            return $value->name;
        }

        if ($value instanceof EndpointDescription) {
            return $this->serializeEndpointDescription($value);
        }

        if ($value instanceof SubscriptionResult) {
            return $this->serializeSubscriptionResult($value);
        }

        if ($value instanceof MonitoredItemResult) {
            return $this->serializeMonitoredItemResult($value);
        }

        if ($value instanceof CallResult) {
            return $this->serializeCallResult($value);
        }

        if ($value instanceof BrowseResultSet) {
            return $this->serializeBrowseResultSet($value);
        }

        if ($value instanceof PublishResult) {
            return $this->serializePublishResult($value);
        }

        if ($value instanceof BrowsePathResult) {
            return $this->serializeBrowsePathResult($value);
        }

        if ($value instanceof BrowsePathTarget) {
            return $this->serializeBrowsePathTarget($value);
        }

        if ($value instanceof TransferResult) {
            return $this->serializeTransferResult($value);
        }

        if (is_array($value)) {
            return array_map(fn(mixed $item) => $this->serialize($item), $value);
        }

        throw new SerializationException('Cannot serialize value of type ' . get_debug_type($value));
    }

    public function serializeNodeId(NodeId $nodeId): array
    {
        return [
            'ns' => $nodeId->namespaceIndex,
            'id' => $nodeId->identifier,
            'type' => $nodeId->type,
        ];
    }

    public function serializeDataValue(DataValue $dataValue): array
    {
        $variant = $dataValue->getVariant();

        return [
            'value' => $variant !== null ? $this->serialize($variant->value) : null,
            'type' => $variant !== null ? $variant->type->value : null,
            'dimensions' => $variant?->dimensions,
            'statusCode' => $dataValue->statusCode,
            'sourceTimestamp' => $dataValue->sourceTimestamp?->format('c'),
            'serverTimestamp' => $dataValue->serverTimestamp?->format('c'),
        ];
    }

    public function serializeVariant(Variant $variant): array
    {
        return [
            'type' => $variant->type->value,
            'value' => $this->serialize($variant->value),
            'dimensions' => $variant->dimensions,
        ];
    }

    public function serializeReferenceDescription(ReferenceDescription $ref): array
    {
        return [
            'referenceTypeId' => $this->serializeNodeId($ref->referenceTypeId),
            'isForward' => $ref->isForward,
            'nodeId' => $this->serializeNodeId($ref->nodeId),
            'browseName' => $this->serializeQualifiedName($ref->browseName),
            'displayName' => $this->serializeLocalizedText($ref->displayName),
            'nodeClass' => $ref->nodeClass->value,
            'typeDefinition' => $ref->typeDefinition !== null
                ? $this->serializeNodeId($ref->typeDefinition)
                : null,
        ];
    }

    public function serializeBrowseNode(BrowseNode $node): array
    {
        return [
            'reference' => $this->serializeReferenceDescription($node->reference),
            'children' => array_map(
                fn(BrowseNode $child) => $this->serializeBrowseNode($child),
                $node->getChildren(),
            ),
        ];
    }

    public function serializeQualifiedName(QualifiedName $name): array
    {
        return [
            'ns' => $name->namespaceIndex,
            'name' => $name->name,
        ];
    }

    public function serializeLocalizedText(LocalizedText $text): array
    {
        return [
            'locale' => $text->locale,
            'text' => $text->text,
        ];
    }

    public function serializeEndpointDescription(EndpointDescription $endpoint): array
    {
        return [
            'endpointUrl' => $endpoint->endpointUrl,
            'serverCertificate' => $endpoint->serverCertificate,
            'securityMode' => $endpoint->securityMode,
            'securityPolicyUri' => $endpoint->securityPolicyUri,
            'securityLevel' => $endpoint->securityLevel,
            'transportProfileUri' => $endpoint->transportProfileUri,
            'userIdentityTokens' => array_map(fn(UserTokenPolicy $t) => [
                'policyId' => $t->policyId,
                'tokenType' => $t->tokenType,
                'issuedTokenType' => $t->issuedTokenType,
                'issuerEndpointUrl' => $t->issuerEndpointUrl,
                'securityPolicyUri' => $t->securityPolicyUri,
            ], $endpoint->userIdentityTokens),
        ];
    }

    public function serializeSubscriptionResult(SubscriptionResult $result): array
    {
        return [
            'subscriptionId' => $result->subscriptionId,
            'revisedPublishingInterval' => $result->revisedPublishingInterval,
            'revisedLifetimeCount' => $result->revisedLifetimeCount,
            'revisedMaxKeepAliveCount' => $result->revisedMaxKeepAliveCount,
        ];
    }

    public function serializeMonitoredItemResult(MonitoredItemResult $result): array
    {
        return [
            'statusCode' => $result->statusCode,
            'monitoredItemId' => $result->monitoredItemId,
            'revisedSamplingInterval' => $result->revisedSamplingInterval,
            'revisedQueueSize' => $result->revisedQueueSize,
        ];
    }

    public function serializeCallResult(CallResult $result): array
    {
        return [
            'statusCode' => $result->statusCode,
            'inputArgumentResults' => $result->inputArgumentResults,
            'outputArguments' => array_map(
                fn(Variant $v) => $this->serializeVariant($v),
                $result->outputArguments,
            ),
        ];
    }

    public function serializeBrowseResultSet(BrowseResultSet $result): array
    {
        return [
            'references' => array_map(
                fn(ReferenceDescription $ref) => $this->serializeReferenceDescription($ref),
                $result->references,
            ),
            'continuationPoint' => $result->continuationPoint,
        ];
    }

    public function serializePublishResult(PublishResult $result): array
    {
        return [
            'subscriptionId' => $result->subscriptionId,
            'sequenceNumber' => $result->sequenceNumber,
            'moreNotifications' => $result->moreNotifications,
            'notifications' => $this->serialize($result->notifications),
            'availableSequenceNumbers' => $result->availableSequenceNumbers,
        ];
    }

    public function serializeBrowsePathResult(BrowsePathResult $result): array
    {
        return [
            'statusCode' => $result->statusCode,
            'targets' => array_map(
                fn(BrowsePathTarget $target) => $this->serializeBrowsePathTarget($target),
                $result->targets,
            ),
        ];
    }

    public function serializeBrowsePathTarget(BrowsePathTarget $target): array
    {
        return [
            'targetId' => $this->serializeNodeId($target->targetId),
            'remainingPathIndex' => $target->remainingPathIndex,
        ];
    }

    public function serializeTransferResult(TransferResult $result): array
    {
        return [
            'statusCode' => $result->statusCode,
            'availableSequenceNumbers' => $result->availableSequenceNumbers,
        ];
    }

    public function deserializeNodeId(array $data): NodeId
    {
        $type = $data['type'] ?? NodeId::TYPE_NUMERIC;

        return new NodeId(
            (int)($data['ns'] ?? 0),
            $data['id'] ?? 0,
            $type,
        );
    }

    public function deserializeDataValue(array $data): DataValue
    {
        $variant = null;
        if (isset($data['type']) && array_key_exists('value', $data)) {
            $builtinType = BuiltinType::from((int)$data['type']);
            $dimensions = $data['dimensions'] ?? null;
            $variant = new Variant(
                $builtinType,
                $this->deserializeVariantValue($builtinType, $data['value']),
                $dimensions,
            );
        }

        return new DataValue(
            $variant,
            (int)($data['statusCode'] ?? 0),
            isset($data['sourceTimestamp']) ? new DateTimeImmutable($data['sourceTimestamp']) : null,
            isset($data['serverTimestamp']) ? new DateTimeImmutable($data['serverTimestamp']) : null,
        );
    }

    public function deserializeVariant(array $data): Variant
    {
        $builtinType = BuiltinType::from((int)$data['type']);
        $dimensions = $data['dimensions'] ?? null;

        return new Variant(
            $builtinType,
            $this->deserializeVariantValue($builtinType, $data['value']),
            $dimensions,
        );
    }

    public function deserializeQualifiedName(array $data): QualifiedName
    {
        return new QualifiedName(
            (int)($data['ns'] ?? 0),
            (string)($data['name'] ?? ''),
        );
    }

    public function deserializeLocalizedText(array $data): LocalizedText
    {
        return new LocalizedText(
            $data['locale'] ?? null,
            $data['text'] ?? null,
        );
    }

    public function deserializeReferenceDescription(array $data): ReferenceDescription
    {
        return new ReferenceDescription(
            $this->deserializeNodeId($data['referenceTypeId']),
            (bool)$data['isForward'],
            $this->deserializeNodeId($data['nodeId']),
            $this->deserializeQualifiedName($data['browseName']),
            $this->deserializeLocalizedText($data['displayName']),
            NodeClass::from((int)$data['nodeClass']),
            isset($data['typeDefinition']) ? $this->deserializeNodeId($data['typeDefinition']) : null,
        );
    }

    public function deserializeBrowseNode(array $data): BrowseNode
    {
        $node = new BrowseNode(
            $this->deserializeReferenceDescription($data['reference']),
        );

        foreach ($data['children'] ?? [] as $childData) {
            $node->addChild($this->deserializeBrowseNode($childData));
        }

        return $node;
    }

    public function deserializeEndpointDescription(array $data): EndpointDescription
    {
        return new EndpointDescription(
            (string)($data['endpointUrl'] ?? ''),
            $data['serverCertificate'] ?? null,
            (int)($data['securityMode'] ?? 1),
            (string)($data['securityPolicyUri'] ?? ''),
            array_map(fn(array $t) => new UserTokenPolicy(
                $t['policyId'] ?? null,
                (int)($t['tokenType'] ?? 0),
                $t['issuedTokenType'] ?? null,
                $t['issuerEndpointUrl'] ?? null,
                $t['securityPolicyUri'] ?? null,
            ), $data['userIdentityTokens'] ?? []),
            (string)($data['transportProfileUri'] ?? ''),
            (int)($data['securityLevel'] ?? 0),
        );
    }

    public function deserializeBrowseDirection(int $value): BrowseDirection
    {
        return BrowseDirection::from($value);
    }

    public function deserializeConnectionState(string $name): ConnectionState
    {
        return match ($name) {
            'Connected' => ConnectionState::Connected,
            'Broken' => ConnectionState::Broken,
            default => ConnectionState::Disconnected,
        };
    }

    public function deserializeBuiltinType(int $value): BuiltinType
    {
        return BuiltinType::from($value);
    }

    public function deserializeSubscriptionResult(array $data): SubscriptionResult
    {
        return new SubscriptionResult(
            (int)$data['subscriptionId'],
            (float)$data['revisedPublishingInterval'],
            (int)$data['revisedLifetimeCount'],
            (int)$data['revisedMaxKeepAliveCount'],
        );
    }

    public function deserializeMonitoredItemResult(array $data): MonitoredItemResult
    {
        return new MonitoredItemResult(
            (int)$data['statusCode'],
            (int)$data['monitoredItemId'],
            (float)$data['revisedSamplingInterval'],
            (int)$data['revisedQueueSize'],
        );
    }

    public function deserializeCallResult(array $data): CallResult
    {
        return new CallResult(
            (int)$data['statusCode'],
            array_map('intval', $data['inputArgumentResults'] ?? []),
            array_map(
                fn(array $v) => $this->deserializeVariant($v),
                $data['outputArguments'] ?? [],
            ),
        );
    }

    public function deserializeBrowseResultSet(array $data): BrowseResultSet
    {
        return new BrowseResultSet(
            array_map(
                fn(array $ref) => $this->deserializeReferenceDescription($ref),
                $data['references'] ?? [],
            ),
            $data['continuationPoint'] ?? null,
        );
    }

    public function deserializePublishResult(array $data): PublishResult
    {
        return new PublishResult(
            (int)$data['subscriptionId'],
            (int)$data['sequenceNumber'],
            (bool)$data['moreNotifications'],
            $data['notifications'] ?? [],
            array_map('intval', $data['availableSequenceNumbers'] ?? []),
        );
    }

    public function deserializeBrowsePathResult(array $data): BrowsePathResult
    {
        return new BrowsePathResult(
            (int)$data['statusCode'],
            array_map(
                fn(array $target) => $this->deserializeBrowsePathTarget($target),
                $data['targets'] ?? [],
            ),
        );
    }

    public function deserializeBrowsePathTarget(array $data): BrowsePathTarget
    {
        return new BrowsePathTarget(
            $this->deserializeNodeId($data['targetId']),
            (int)$data['remainingPathIndex'],
        );
    }

    public function deserializeTransferResult(array $data): TransferResult
    {
        return new TransferResult(
            (int)$data['statusCode'],
            array_map('intval', $data['availableSequenceNumbers'] ?? []),
        );
    }

    private function deserializeVariantValue(BuiltinType $type, mixed $value): mixed
    {
        if ($value === null) {
            return null;
        }

        return match ($type) {
            BuiltinType::DateTime => new DateTimeImmutable($value),
            BuiltinType::NodeId => is_array($value) ? $this->deserializeNodeId($value) : $value,
            BuiltinType::QualifiedName => is_array($value) ? $this->deserializeQualifiedName($value) : $value,
            BuiltinType::LocalizedText => is_array($value) ? $this->deserializeLocalizedText($value) : $value,
            default => $value,
        };
    }
}
