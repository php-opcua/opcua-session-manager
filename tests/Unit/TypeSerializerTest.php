<?php

declare(strict_types=1);

use Gianfriaur\OpcuaPhpClient\Types\BuiltinType;
use Gianfriaur\OpcuaPhpClient\Types\DataValue;
use Gianfriaur\OpcuaPhpClient\Types\LocalizedText;
use Gianfriaur\OpcuaPhpClient\Types\NodeClass;
use Gianfriaur\OpcuaPhpClient\Types\NodeId;
use Gianfriaur\OpcuaPhpClient\Types\QualifiedName;
use Gianfriaur\OpcuaPhpClient\Types\ReferenceDescription;
use Gianfriaur\OpcuaPhpClient\Types\Variant;
use Gianfriaur\OpcuaSessionManager\Serialization\TypeSerializer;

describe('TypeSerializer', function () {

    beforeEach(function () {
        $this->serializer = new TypeSerializer();
    });

    // ── NodeId ───────────────────────────────────────────────────────

    describe('NodeId', function () {

        it('serializes a numeric NodeId', function () {
            $nodeId = NodeId::numeric(2, 1234);
            $result = $this->serializer->serializeNodeId($nodeId);

            expect($result)->toBe([
                'ns' => 2,
                'id' => 1234,
                'type' => NodeId::TYPE_NUMERIC,
            ]);
        });

        it('serializes a string NodeId', function () {
            $nodeId = NodeId::string(1, 'MyNode');
            $result = $this->serializer->serializeNodeId($nodeId);

            expect($result)->toBe([
                'ns' => 1,
                'id' => 'MyNode',
                'type' => NodeId::TYPE_STRING,
            ]);
        });

        it('deserializes a numeric NodeId', function () {
            $data = ['ns' => 2, 'id' => 1234, 'type' => NodeId::TYPE_NUMERIC];
            $nodeId = $this->serializer->deserializeNodeId($data);

            expect($nodeId->getNamespaceIndex())->toBe(2);
            expect($nodeId->getIdentifier())->toBe(1234);
            expect($nodeId->getType())->toBe(NodeId::TYPE_NUMERIC);
        });

        it('deserializes a string NodeId', function () {
            $data = ['ns' => 1, 'id' => 'MyNode', 'type' => NodeId::TYPE_STRING];
            $nodeId = $this->serializer->deserializeNodeId($data);

            expect($nodeId->getNamespaceIndex())->toBe(1);
            expect($nodeId->getIdentifier())->toBe('MyNode');
            expect($nodeId->getType())->toBe(NodeId::TYPE_STRING);
        });

        it('roundtrips a NodeId', function () {
            $original = NodeId::numeric(0, 85);
            $serialized = $this->serializer->serializeNodeId($original);
            $deserialized = $this->serializer->deserializeNodeId($serialized);

            expect($deserialized->getNamespaceIndex())->toBe($original->getNamespaceIndex());
            expect($deserialized->getIdentifier())->toBe($original->getIdentifier());
            expect($deserialized->getType())->toBe($original->getType());
        });

    });

    // ── Variant ─────────────────────────────────────────────────────

    describe('Variant', function () {

        it('serializes a string Variant', function () {
            $variant = new Variant(BuiltinType::String, 'hello');
            $result = $this->serializer->serializeVariant($variant);

            expect($result)->toBe([
                'type' => BuiltinType::String->value,
                'value' => 'hello',
            ]);
        });

        it('serializes an Int32 Variant', function () {
            $variant = new Variant(BuiltinType::Int32, 42);
            $result = $this->serializer->serializeVariant($variant);

            expect($result)->toBe([
                'type' => BuiltinType::Int32->value,
                'value' => 42,
            ]);
        });

        it('deserializes a Variant', function () {
            $data = ['type' => BuiltinType::Double->value, 'value' => 3.14];
            $variant = $this->serializer->deserializeVariant($data);

            expect($variant->getType())->toBe(BuiltinType::Double);
            expect($variant->getValue())->toBe(3.14);
        });

        it('roundtrips a Boolean Variant', function () {
            $original = new Variant(BuiltinType::Boolean, true);
            $serialized = $this->serializer->serializeVariant($original);
            $deserialized = $this->serializer->deserializeVariant($serialized);

            expect($deserialized->getType())->toBe($original->getType());
            expect($deserialized->getValue())->toBe($original->getValue());
        });

    });

    // ── DataValue ───────────────────────────────────────────────────

    describe('DataValue', function () {

        it('serializes a DataValue with Variant', function () {
            $variant = new Variant(BuiltinType::Int32, 100);
            $dv = new DataValue($variant, 0);
            $result = $this->serializer->serializeDataValue($dv);

            expect($result['value'])->toBe(100);
            expect($result['type'])->toBe(BuiltinType::Int32->value);
            expect($result['statusCode'])->toBe(0);
            expect($result['sourceTimestamp'])->toBeNull();
            expect($result['serverTimestamp'])->toBeNull();
        });

        it('serializes a DataValue with timestamps', function () {
            $variant = new Variant(BuiltinType::String, 'test');
            $sourceTs = new \DateTimeImmutable('2024-01-15T10:30:00+00:00');
            $serverTs = new \DateTimeImmutable('2024-01-15T10:30:01+00:00');
            $dv = new DataValue($variant, 0, $sourceTs, $serverTs);
            $result = $this->serializer->serializeDataValue($dv);

            expect($result['sourceTimestamp'])->toBeString();
            expect($result['serverTimestamp'])->toBeString();
        });

        it('serializes a null DataValue', function () {
            $dv = new DataValue(null, 0);
            $result = $this->serializer->serializeDataValue($dv);

            expect($result['value'])->toBeNull();
            expect($result['type'])->toBeNull();
        });

        it('deserializes a DataValue', function () {
            $data = [
                'value' => 42,
                'type' => BuiltinType::Int32->value,
                'statusCode' => 0,
                'sourceTimestamp' => null,
                'serverTimestamp' => null,
            ];
            $dv = $this->serializer->deserializeDataValue($data);

            expect($dv->getStatusCode())->toBe(0);
            expect($dv->getVariant())->not->toBeNull();
            expect($dv->getVariant()->getType())->toBe(BuiltinType::Int32);
            expect($dv->getVariant()->getValue())->toBe(42);
        });

        it('roundtrips a DataValue', function () {
            $original = new DataValue(new Variant(BuiltinType::Double, 3.14), 0);
            $serialized = $this->serializer->serializeDataValue($original);
            $deserialized = $this->serializer->deserializeDataValue($serialized);

            expect($deserialized->getStatusCode())->toBe($original->getStatusCode());
            expect($deserialized->getVariant()->getValue())->toBe($original->getVariant()->getValue());
        });

    });

    // ── QualifiedName ───────────────────────────────────────────────

    describe('QualifiedName', function () {

        it('serializes a QualifiedName', function () {
            $qn = new QualifiedName(1, 'MyName');
            $result = $this->serializer->serializeQualifiedName($qn);

            expect($result)->toBe(['ns' => 1, 'name' => 'MyName']);
        });

        it('deserializes a QualifiedName', function () {
            $data = ['ns' => 2, 'name' => 'TestName'];
            $qn = $this->serializer->deserializeQualifiedName($data);

            expect($qn->getNamespaceIndex())->toBe(2);
            expect($qn->getName())->toBe('TestName');
        });

    });

    // ── LocalizedText ───────────────────────────────────────────────

    describe('LocalizedText', function () {

        it('serializes a LocalizedText', function () {
            $lt = new LocalizedText('en', 'Hello');
            $result = $this->serializer->serializeLocalizedText($lt);

            expect($result)->toBe(['locale' => 'en', 'text' => 'Hello']);
        });

        it('serializes a LocalizedText with null locale', function () {
            $lt = new LocalizedText(null, 'Hello');
            $result = $this->serializer->serializeLocalizedText($lt);

            expect($result)->toBe(['locale' => null, 'text' => 'Hello']);
        });

        it('deserializes a LocalizedText', function () {
            $data = ['locale' => 'de', 'text' => 'Hallo'];
            $lt = $this->serializer->deserializeLocalizedText($data);

            expect($lt->getLocale())->toBe('de');
            expect($lt->getText())->toBe('Hallo');
        });

    });

    // ── ReferenceDescription ────────────────────────────────────────

    describe('ReferenceDescription', function () {

        it('serializes and deserializes a ReferenceDescription', function () {
            $ref = new ReferenceDescription(
                NodeId::numeric(0, 35),
                true,
                NodeId::numeric(2, 100),
                new QualifiedName(2, 'MyNode'),
                new LocalizedText('en', 'My Node'),
                NodeClass::Variable,
                NodeId::numeric(0, 63),
            );

            $serialized = $this->serializer->serializeReferenceDescription($ref);

            expect($serialized['isForward'])->toBeTrue();
            expect($serialized['browseName'])->toBe(['ns' => 2, 'name' => 'MyNode']);
            expect($serialized['displayName'])->toBe(['locale' => 'en', 'text' => 'My Node']);
            expect($serialized['nodeClass'])->toBe(NodeClass::Variable->value);

            $deserialized = $this->serializer->deserializeReferenceDescription($serialized);

            expect($deserialized->isForward())->toBeTrue();
            expect($deserialized->getBrowseName()->getName())->toBe('MyNode');
            expect($deserialized->getNodeClass())->toBe(NodeClass::Variable);
            expect($deserialized->getNodeId()->getNamespaceIndex())->toBe(2);
            expect($deserialized->getNodeId()->getIdentifier())->toBe(100);
        });

        it('handles null typeDefinition', function () {
            $ref = new ReferenceDescription(
                NodeId::numeric(0, 35),
                true,
                NodeId::numeric(0, 85),
                new QualifiedName(0, 'Objects'),
                new LocalizedText(null, 'Objects'),
                NodeClass::Object,
                null,
            );

            $serialized = $this->serializer->serializeReferenceDescription($ref);
            expect($serialized['typeDefinition'])->toBeNull();

            $deserialized = $this->serializer->deserializeReferenceDescription($serialized);
            expect($deserialized->getTypeDefinition())->toBeNull();
        });

    });

    // ── BuiltinType ─────────────────────────────────────────────────

    describe('BuiltinType', function () {

        it('serializes BuiltinType as int', function () {
            $result = $this->serializer->serialize(BuiltinType::Double);
            expect($result)->toBe(BuiltinType::Double->value);
        });

        it('deserializes BuiltinType from int', function () {
            $result = $this->serializer->deserializeBuiltinType(12);
            expect($result)->toBe(BuiltinType::String);
        });

    });

    // ── Scalars and nulls ───────────────────────────────────────────

    describe('Scalars', function () {

        it('serializes null as null', function () {
            expect($this->serializer->serialize(null))->toBeNull();
        });

        it('serializes int as int', function () {
            expect($this->serializer->serialize(42))->toBe(42);
        });

        it('serializes string as string', function () {
            expect($this->serializer->serialize('hello'))->toBe('hello');
        });

        it('serializes bool as bool', function () {
            expect($this->serializer->serialize(true))->toBeTrue();
        });

        it('serializes float as float', function () {
            expect($this->serializer->serialize(3.14))->toBe(3.14);
        });

    });

    // ── DateTime ────────────────────────────────────────────────────

    describe('DateTime', function () {

        it('serializes DateTimeImmutable to ISO 8601', function () {
            $dt = new \DateTimeImmutable('2024-06-15T12:00:00+00:00');
            $result = $this->serializer->serialize($dt);

            expect($result)->toBeString();
            expect($result)->toContain('2024-06-15');
        });

    });

    // ── Arrays ──────────────────────────────────────────────────────

    describe('Arrays', function () {

        it('serializes an array of NodeIds', function () {
            $nodeIds = [NodeId::numeric(0, 1), NodeId::numeric(0, 2)];
            $result = $this->serializer->serialize($nodeIds);

            expect($result)->toBeArray()->toHaveCount(2);
            expect($result[0]['ns'])->toBe(0);
            expect($result[0]['id'])->toBe(1);
            expect($result[1]['id'])->toBe(2);
        });

        it('serializes an array of scalars', function () {
            $result = $this->serializer->serialize([1, 2, 3]);
            expect($result)->toBe([1, 2, 3]);
        });

    });

});
