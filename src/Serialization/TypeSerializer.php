<?php

declare(strict_types=1);

namespace Gianfriaur\OpcuaSessionManager\Serialization;

use Gianfriaur\OpcuaPhpClient\Types\BuiltinType;
use Gianfriaur\OpcuaPhpClient\Types\DataValue;
use Gianfriaur\OpcuaPhpClient\Types\EndpointDescription;
use Gianfriaur\OpcuaPhpClient\Types\LocalizedText;
use Gianfriaur\OpcuaPhpClient\Types\NodeClass;
use Gianfriaur\OpcuaPhpClient\Types\NodeId;
use Gianfriaur\OpcuaPhpClient\Types\QualifiedName;
use Gianfriaur\OpcuaPhpClient\Types\ReferenceDescription;
use Gianfriaur\OpcuaPhpClient\Types\UserTokenPolicy;
use Gianfriaur\OpcuaPhpClient\Types\Variant;
use Gianfriaur\OpcuaSessionManager\Exception\SerializationException;

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

        if ($value instanceof \DateTimeImmutable) {
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

        if ($value instanceof EndpointDescription) {
            return $this->serializeEndpointDescription($value);
        }

        if (is_array($value)) {
            return array_map(fn(mixed $item) => $this->serialize($item), $value);
        }

        throw new SerializationException('Cannot serialize value of type ' . get_debug_type($value));
    }

    public function serializeNodeId(NodeId $nodeId): array
    {
        return [
            'ns' => $nodeId->getNamespaceIndex(),
            'id' => $nodeId->getIdentifier(),
            'type' => $nodeId->getType(),
        ];
    }

    public function serializeDataValue(DataValue $dataValue): array
    {
        $variant = $dataValue->getVariant();

        return [
            'value' => $variant !== null ? $this->serialize($variant->getValue()) : null,
            'type' => $variant !== null ? $variant->getType()->value : null,
            'statusCode' => $dataValue->getStatusCode(),
            'sourceTimestamp' => $dataValue->getSourceTimestamp()?->format('c'),
            'serverTimestamp' => $dataValue->getServerTimestamp()?->format('c'),
        ];
    }

    public function serializeVariant(Variant $variant): array
    {
        return [
            'type' => $variant->getType()->value,
            'value' => $this->serialize($variant->getValue()),
        ];
    }

    public function serializeReferenceDescription(ReferenceDescription $ref): array
    {
        return [
            'referenceTypeId' => $this->serializeNodeId($ref->getReferenceTypeId()),
            'isForward' => $ref->isForward(),
            'nodeId' => $this->serializeNodeId($ref->getNodeId()),
            'browseName' => $this->serializeQualifiedName($ref->getBrowseName()),
            'displayName' => $this->serializeLocalizedText($ref->getDisplayName()),
            'nodeClass' => $ref->getNodeClass()->value,
            'typeDefinition' => $ref->getTypeDefinition() !== null
                ? $this->serializeNodeId($ref->getTypeDefinition())
                : null,
        ];
    }

    public function serializeQualifiedName(QualifiedName $name): array
    {
        return [
            'ns' => $name->getNamespaceIndex(),
            'name' => $name->getName(),
        ];
    }

    public function serializeLocalizedText(LocalizedText $text): array
    {
        return [
            'locale' => $text->getLocale(),
            'text' => $text->getText(),
        ];
    }

    public function serializeEndpointDescription(EndpointDescription $endpoint): array
    {
        return [
            'endpointUrl' => $endpoint->getEndpointUrl(),
            'securityMode' => $endpoint->getSecurityMode(),
            'securityPolicyUri' => $endpoint->getSecurityPolicyUri(),
            'securityLevel' => $endpoint->getSecurityLevel(),
            'transportProfileUri' => $endpoint->getTransportProfileUri(),
            'userIdentityTokens' => array_map(fn(UserTokenPolicy $t) => [
                'policyId' => $t->getPolicyId(),
                'tokenType' => $t->getTokenType(),
                'issuedTokenType' => $t->getIssuedTokenType(),
                'issuerEndpointUrl' => $t->getIssuerEndpointUrl(),
                'securityPolicyUri' => $t->getSecurityPolicyUri(),
            ], $endpoint->getUserIdentityTokens()),
        ];
    }

    public function deserializeNodeId(array $data): NodeId
    {
        $type = $data['type'] ?? NodeId::TYPE_NUMERIC;

        return new NodeId(
            (int) ($data['ns'] ?? 0),
            $data['id'] ?? 0,
            $type,
        );
    }

    public function deserializeDataValue(array $data): DataValue
    {
        $variant = null;
        if (isset($data['type']) && array_key_exists('value', $data)) {
            $builtinType = BuiltinType::from((int) $data['type']);
            $variant = new Variant($builtinType, $this->deserializeVariantValue($builtinType, $data['value']));
        }

        return new DataValue(
            $variant,
            (int) ($data['statusCode'] ?? 0),
            isset($data['sourceTimestamp']) ? new \DateTimeImmutable($data['sourceTimestamp']) : null,
            isset($data['serverTimestamp']) ? new \DateTimeImmutable($data['serverTimestamp']) : null,
        );
    }

    public function deserializeVariant(array $data): Variant
    {
        $builtinType = BuiltinType::from((int) $data['type']);

        return new Variant($builtinType, $this->deserializeVariantValue($builtinType, $data['value']));
    }

    public function deserializeQualifiedName(array $data): QualifiedName
    {
        return new QualifiedName(
            (int) ($data['ns'] ?? 0),
            (string) ($data['name'] ?? ''),
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
            (bool) $data['isForward'],
            $this->deserializeNodeId($data['nodeId']),
            $this->deserializeQualifiedName($data['browseName']),
            $this->deserializeLocalizedText($data['displayName']),
            NodeClass::from((int) $data['nodeClass']),
            isset($data['typeDefinition']) ? $this->deserializeNodeId($data['typeDefinition']) : null,
        );
    }

    public function deserializeBuiltinType(int $value): BuiltinType
    {
        return BuiltinType::from($value);
    }

    private function deserializeVariantValue(BuiltinType $type, mixed $value): mixed
    {
        if ($value === null) {
            return null;
        }

        return match ($type) {
            BuiltinType::DateTime => new \DateTimeImmutable($value),
            BuiltinType::NodeId => is_array($value) ? $this->deserializeNodeId($value) : $value,
            BuiltinType::QualifiedName => is_array($value) ? $this->deserializeQualifiedName($value) : $value,
            BuiltinType::LocalizedText => is_array($value) ? $this->deserializeLocalizedText($value) : $value,
            default => $value,
        };
    }
}
