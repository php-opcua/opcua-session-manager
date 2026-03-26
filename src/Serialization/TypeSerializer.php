<?php

declare(strict_types=1);

namespace PhpOpcua\SessionManager\Serialization;

use DateTimeImmutable;
use PhpOpcua\Client\Types\BrowseDirection;
use PhpOpcua\Client\Types\BrowseNode;
use PhpOpcua\Client\Types\BrowsePathResult;
use PhpOpcua\Client\Types\BrowsePathTarget;
use PhpOpcua\Client\Types\BrowseResultSet;
use PhpOpcua\Client\Types\BuiltinType;
use PhpOpcua\Client\Types\CallResult;
use PhpOpcua\Client\Types\ConnectionState;
use PhpOpcua\Client\Types\DataValue;
use PhpOpcua\Client\Types\EndpointDescription;
use PhpOpcua\Client\Types\LocalizedText;
use PhpOpcua\Client\Types\MonitoredItemResult;
use PhpOpcua\Client\Types\NodeClass;
use PhpOpcua\Client\Types\NodeId;
use PhpOpcua\Client\Types\PublishResult;
use PhpOpcua\Client\Types\QualifiedName;
use PhpOpcua\Client\Types\ReferenceDescription;
use PhpOpcua\Client\Types\SubscriptionResult;
use PhpOpcua\Client\Types\TransferResult;
use PhpOpcua\Client\Types\UserTokenPolicy;
use PhpOpcua\Client\Types\Variant;
use PhpOpcua\Client\Types\ExtensionObject;
use PhpOpcua\Client\Types\MonitoredItemModifyResult;
use PhpOpcua\Client\Types\SetTriggeringResult;
use PhpOpcua\SessionManager\Exception\SerializationException;

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

        if ($value instanceof MonitoredItemModifyResult) {
            return $this->serializeMonitoredItemModifyResult($value);
        }

        if ($value instanceof SetTriggeringResult) {
            return $this->serializeSetTriggeringResult($value);
        }

        if ($value instanceof ExtensionObject) {
            return $this->serializeExtensionObject($value);
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

    public function serializeMonitoredItemModifyResult(MonitoredItemModifyResult $result): array
    {
        return [
            'statusCode' => $result->statusCode,
            'revisedSamplingInterval' => $result->revisedSamplingInterval,
            'revisedQueueSize' => $result->revisedQueueSize,
        ];
    }

    public function serializeSetTriggeringResult(SetTriggeringResult $result): array
    {
        return [
            'statusCode' => $result->statusCode,
            'addResults' => $result->addResults,
            'removeResults' => $result->removeResults,
        ];
    }

    public function serializeExtensionObject(ExtensionObject $obj): array
    {
        return [
            'typeId' => $this->serializeNodeId($obj->typeId),
            'encoding' => $obj->encoding,
            'body' => $obj->body !== null ? base64_encode($obj->body) : null,
            'value' => $obj->isDecoded() ? $this->serialize($obj->value) : null,
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

    public function deserializeBuiltinType(?int $value): ?BuiltinType
    {
        if ($value === null) {
            return null;
        }

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

    public function deserializeMonitoredItemModifyResult(array $data): MonitoredItemModifyResult
    {
        return new MonitoredItemModifyResult(
            (int)$data['statusCode'],
            (float)$data['revisedSamplingInterval'],
            (int)$data['revisedQueueSize'],
        );
    }

    public function deserializeSetTriggeringResult(array $data): SetTriggeringResult
    {
        return new SetTriggeringResult(
            (int)$data['statusCode'],
            array_map('intval', $data['addResults'] ?? []),
            array_map('intval', $data['removeResults'] ?? []),
        );
    }

    public function deserializeExtensionObject(array $data): ExtensionObject
    {
        return new ExtensionObject(
            $this->deserializeNodeId($data['typeId']),
            (int)($data['encoding'] ?? 0),
            isset($data['body']) ? base64_decode($data['body']) : null,
            $data['value'] ?? null,
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
            BuiltinType::ExtensionObject => is_array($value) && isset($value['typeId']) ? $this->deserializeExtensionObject($value) : $value,
            default => $value,
        };
    }
}
