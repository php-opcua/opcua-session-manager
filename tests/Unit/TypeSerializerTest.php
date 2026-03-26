<?php

declare(strict_types=1);

use PhpOpcua\Client\Types\BrowseDirection;
use PhpOpcua\Client\Types\BrowseNode;
use PhpOpcua\Client\Types\BrowsePathResult;
use PhpOpcua\Client\Types\BrowsePathTarget;
use PhpOpcua\Client\Types\BrowseResultSet;
use PhpOpcua\Client\Types\BuiltinType;
use PhpOpcua\Client\Types\CallResult;
use PhpOpcua\Client\Types\ConnectionState;
use PhpOpcua\Client\Types\DataValue;
use PhpOpcua\Client\Types\LocalizedText;
use PhpOpcua\Client\Types\MonitoredItemResult;
use PhpOpcua\Client\Types\NodeClass;
use PhpOpcua\Client\Types\NodeId;
use PhpOpcua\Client\Types\PublishResult;
use PhpOpcua\Client\Types\QualifiedName;
use PhpOpcua\Client\Types\ReferenceDescription;
use PhpOpcua\Client\Types\SubscriptionResult;
use PhpOpcua\Client\Types\TransferResult;
use PhpOpcua\Client\Types\Variant;
use PhpOpcua\SessionManager\Serialization\TypeSerializer;

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

            expect($nodeId->namespaceIndex)->toBe(2);
            expect($nodeId->identifier)->toBe(1234);
            expect($nodeId->type)->toBe(NodeId::TYPE_NUMERIC);
        });

        it('deserializes a string NodeId', function () {
            $data = ['ns' => 1, 'id' => 'MyNode', 'type' => NodeId::TYPE_STRING];
            $nodeId = $this->serializer->deserializeNodeId($data);

            expect($nodeId->namespaceIndex)->toBe(1);
            expect($nodeId->identifier)->toBe('MyNode');
            expect($nodeId->type)->toBe(NodeId::TYPE_STRING);
        });

        it('roundtrips a NodeId', function () {
            $original = NodeId::numeric(0, 85);
            $serialized = $this->serializer->serializeNodeId($original);
            $deserialized = $this->serializer->deserializeNodeId($serialized);

            expect($deserialized->namespaceIndex)->toBe($original->namespaceIndex);
            expect($deserialized->identifier)->toBe($original->identifier);
            expect($deserialized->type)->toBe($original->type);
        });

    });

    // ── Variant ─────────────────────────────────────────────────────

    describe('Variant', function () {

        it('serializes a string Variant', function () {
            $variant = new Variant(BuiltinType::String, 'hello');
            $result = $this->serializer->serializeVariant($variant);

            expect($result['type'])->toBe(BuiltinType::String->value);
            expect($result['value'])->toBe('hello');
            expect($result['dimensions'])->toBeNull();
        });

        it('serializes an Int32 Variant', function () {
            $variant = new Variant(BuiltinType::Int32, 42);
            $result = $this->serializer->serializeVariant($variant);

            expect($result['type'])->toBe(BuiltinType::Int32->value);
            expect($result['value'])->toBe(42);
        });

        it('serializes a Variant with dimensions', function () {
            $variant = new Variant(BuiltinType::Int32, [1, 2, 3, 4], [2, 2]);
            $result = $this->serializer->serializeVariant($variant);

            expect($result['dimensions'])->toBe([2, 2]);
        });

        it('deserializes a Variant', function () {
            $data = ['type' => BuiltinType::Double->value, 'value' => 3.14];
            $variant = $this->serializer->deserializeVariant($data);

            expect($variant->type)->toBe(BuiltinType::Double);
            expect($variant->value)->toBe(3.14);
        });

        it('deserializes a Variant with dimensions', function () {
            $data = ['type' => BuiltinType::Int32->value, 'value' => [1, 2, 3, 4], 'dimensions' => [2, 2]];
            $variant = $this->serializer->deserializeVariant($data);

            expect($variant->dimensions)->toBe([2, 2]);
        });

        it('roundtrips a Boolean Variant', function () {
            $original = new Variant(BuiltinType::Boolean, true);
            $serialized = $this->serializer->serializeVariant($original);
            $deserialized = $this->serializer->deserializeVariant($serialized);

            expect($deserialized->type)->toBe($original->type);
            expect($deserialized->value)->toBe($original->value);
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
            $sourceTs = new DateTimeImmutable('2024-01-15T10:30:00+00:00');
            $serverTs = new DateTimeImmutable('2024-01-15T10:30:01+00:00');
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

            expect($dv->statusCode)->toBe(0);
            expect($dv->getVariant())->not->toBeNull();
            expect($dv->getVariant()->type)->toBe(BuiltinType::Int32);
            expect($dv->getVariant()->value)->toBe(42);
        });

        it('roundtrips a DataValue', function () {
            $original = new DataValue(new Variant(BuiltinType::Double, 3.14), 0);
            $serialized = $this->serializer->serializeDataValue($original);
            $deserialized = $this->serializer->deserializeDataValue($serialized);

            expect($deserialized->statusCode)->toBe($original->statusCode);
            expect($deserialized->getVariant()->value)->toBe($original->getVariant()->value);
        });

        it('preserves Variant dimensions through DataValue roundtrip', function () {
            $original = new DataValue(new Variant(BuiltinType::Int32, [1, 2, 3, 4], [2, 2]), 0);
            $serialized = $this->serializer->serializeDataValue($original);
            $deserialized = $this->serializer->deserializeDataValue($serialized);

            expect($deserialized->getVariant()->dimensions)->toBe([2, 2]);
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

            expect($qn->namespaceIndex)->toBe(2);
            expect($qn->name)->toBe('TestName');
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

            expect($lt->locale)->toBe('de');
            expect($lt->text)->toBe('Hallo');
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

            expect($deserialized->isForward)->toBeTrue();
            expect($deserialized->browseName->name)->toBe('MyNode');
            expect($deserialized->nodeClass)->toBe(NodeClass::Variable);
            expect($deserialized->nodeId->namespaceIndex)->toBe(2);
            expect($deserialized->nodeId->identifier)->toBe(100);
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
            expect($deserialized->typeDefinition)->toBeNull();
        });

    });

    // ── SubscriptionResult ──────────────────────────────────────────

    describe('SubscriptionResult', function () {

        it('serializes and deserializes a SubscriptionResult', function () {
            $original = new SubscriptionResult(42, 500.0, 2400, 10);
            $serialized = $this->serializer->serializeSubscriptionResult($original);

            expect($serialized)->toBe([
                'subscriptionId' => 42,
                'revisedPublishingInterval' => 500.0,
                'revisedLifetimeCount' => 2400,
                'revisedMaxKeepAliveCount' => 10,
            ]);

            $deserialized = $this->serializer->deserializeSubscriptionResult($serialized);
            expect($deserialized->subscriptionId)->toBe(42);
            expect($deserialized->revisedPublishingInterval)->toBe(500.0);
            expect($deserialized->revisedLifetimeCount)->toBe(2400);
            expect($deserialized->revisedMaxKeepAliveCount)->toBe(10);
        });

        it('roundtrips via generic serialize', function () {
            $original = new SubscriptionResult(1, 250.0, 1200, 5);
            $serialized = $this->serializer->serialize($original);
            $deserialized = $this->serializer->deserializeSubscriptionResult($serialized);

            expect($deserialized->subscriptionId)->toBe($original->subscriptionId);
        });

    });

    // ── MonitoredItemResult ─────────────────────────────────────────

    describe('MonitoredItemResult', function () {

        it('serializes and deserializes a MonitoredItemResult', function () {
            $original = new MonitoredItemResult(0, 100, 250.0, 1);
            $serialized = $this->serializer->serializeMonitoredItemResult($original);

            expect($serialized)->toBe([
                'statusCode' => 0,
                'monitoredItemId' => 100,
                'revisedSamplingInterval' => 250.0,
                'revisedQueueSize' => 1,
            ]);

            $deserialized = $this->serializer->deserializeMonitoredItemResult($serialized);
            expect($deserialized->statusCode)->toBe(0);
            expect($deserialized->monitoredItemId)->toBe(100);
            expect($deserialized->revisedSamplingInterval)->toBe(250.0);
            expect($deserialized->revisedQueueSize)->toBe(1);
        });

    });

    // ── CallResult ──────────────────────────────────────────────────

    describe('CallResult', function () {

        it('serializes and deserializes a CallResult', function () {
            $original = new CallResult(
                0,
                [0, 0],
                [new Variant(BuiltinType::Int32, 42), new Variant(BuiltinType::String, 'result')],
            );
            $serialized = $this->serializer->serializeCallResult($original);

            expect($serialized['statusCode'])->toBe(0);
            expect($serialized['inputArgumentResults'])->toBe([0, 0]);
            expect($serialized['outputArguments'])->toHaveCount(2);

            $deserialized = $this->serializer->deserializeCallResult($serialized);
            expect($deserialized->statusCode)->toBe(0);
            expect($deserialized->inputArgumentResults)->toBe([0, 0]);
            expect($deserialized->outputArguments)->toHaveCount(2);
            expect($deserialized->outputArguments[0]->value)->toBe(42);
            expect($deserialized->outputArguments[1]->value)->toBe('result');
        });

    });

    // ── BrowseResultSet ─────────────────────────────────────────────

    describe('BrowseResultSet', function () {

        it('serializes and deserializes a BrowseResultSet', function () {
            $refs = [
                new ReferenceDescription(
                    NodeId::numeric(0, 35),
                    true,
                    NodeId::numeric(0, 85),
                    new QualifiedName(0, 'Objects'),
                    new LocalizedText(null, 'Objects'),
                    NodeClass::Object,
                    null,
                ),
            ];
            $original = new BrowseResultSet($refs, 'abc123');
            $serialized = $this->serializer->serializeBrowseResultSet($original);

            expect($serialized['continuationPoint'])->toBe('abc123');
            expect($serialized['references'])->toHaveCount(1);

            $deserialized = $this->serializer->deserializeBrowseResultSet($serialized);
            expect($deserialized->continuationPoint)->toBe('abc123');
            expect($deserialized->references)->toHaveCount(1);
            expect($deserialized->references[0]->nodeId->identifier)->toBe(85);
        });

        it('handles null continuation point', function () {
            $original = new BrowseResultSet([], null);
            $serialized = $this->serializer->serializeBrowseResultSet($original);
            $deserialized = $this->serializer->deserializeBrowseResultSet($serialized);

            expect($deserialized->continuationPoint)->toBeNull();
            expect($deserialized->references)->toBeEmpty();
        });

    });

    // ── PublishResult ───────────────────────────────────────────────

    describe('PublishResult', function () {

        it('serializes and deserializes a PublishResult', function () {
            $original = new PublishResult(1, 42, false, [], [1, 2, 3]);
            $serialized = $this->serializer->serializePublishResult($original);

            expect($serialized['subscriptionId'])->toBe(1);
            expect($serialized['sequenceNumber'])->toBe(42);
            expect($serialized['moreNotifications'])->toBeFalse();
            expect($serialized['availableSequenceNumbers'])->toBe([1, 2, 3]);

            $deserialized = $this->serializer->deserializePublishResult($serialized);
            expect($deserialized->subscriptionId)->toBe(1);
            expect($deserialized->sequenceNumber)->toBe(42);
            expect($deserialized->moreNotifications)->toBeFalse();
            expect($deserialized->availableSequenceNumbers)->toBe([1, 2, 3]);
        });

    });

    // ── BrowsePathResult ────────────────────────────────────────────

    describe('BrowsePathResult', function () {

        it('serializes and deserializes a BrowsePathResult', function () {
            $original = new BrowsePathResult(0, [
                new BrowsePathTarget(NodeId::numeric(2, 100), 0),
                new BrowsePathTarget(NodeId::numeric(2, 200), 1),
            ]);
            $serialized = $this->serializer->serializeBrowsePathResult($original);

            expect($serialized['statusCode'])->toBe(0);
            expect($serialized['targets'])->toHaveCount(2);
            expect($serialized['targets'][0]['targetId']['id'])->toBe(100);

            $deserialized = $this->serializer->deserializeBrowsePathResult($serialized);
            expect($deserialized->statusCode)->toBe(0);
            expect($deserialized->targets)->toHaveCount(2);
            expect($deserialized->targets[0]->targetId->identifier)->toBe(100);
            expect($deserialized->targets[1]->remainingPathIndex)->toBe(1);
        });

    });

    // ── TransferResult ──────────────────────────────────────────────

    describe('TransferResult', function () {

        it('serializes and deserializes a TransferResult', function () {
            $original = new TransferResult(0, [1, 2, 3]);
            $serialized = $this->serializer->serializeTransferResult($original);

            expect($serialized)->toBe([
                'statusCode' => 0,
                'availableSequenceNumbers' => [1, 2, 3],
            ]);

            $deserialized = $this->serializer->deserializeTransferResult($serialized);
            expect($deserialized->statusCode)->toBe(0);
            expect($deserialized->availableSequenceNumbers)->toBe([1, 2, 3]);
        });

    });

    // ── ConnectionState ─────────────────────────────────────────────

    describe('ConnectionState', function () {

        it('serializes ConnectionState as name', function () {
            expect($this->serializer->serialize(ConnectionState::Connected))->toBe('Connected');
            expect($this->serializer->serialize(ConnectionState::Disconnected))->toBe('Disconnected');
            expect($this->serializer->serialize(ConnectionState::Broken))->toBe('Broken');
        });

        it('deserializes ConnectionState from name', function () {
            expect($this->serializer->deserializeConnectionState('Connected'))->toBe(ConnectionState::Connected);
            expect($this->serializer->deserializeConnectionState('Broken'))->toBe(ConnectionState::Broken);
            expect($this->serializer->deserializeConnectionState('unknown'))->toBe(ConnectionState::Disconnected);
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

    // ── BrowseDirection ─────────────────────────────────────────────

    describe('BrowseDirection', function () {

        it('serializes BrowseDirection as int', function () {
            expect($this->serializer->serialize(BrowseDirection::Forward))->toBe(BrowseDirection::Forward->value);
            expect($this->serializer->serialize(BrowseDirection::Inverse))->toBe(BrowseDirection::Inverse->value);
            expect($this->serializer->serialize(BrowseDirection::Both))->toBe(BrowseDirection::Both->value);
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
            $dt = new DateTimeImmutable('2024-06-15T12:00:00+00:00');
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
