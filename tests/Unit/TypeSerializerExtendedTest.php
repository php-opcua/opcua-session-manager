<?php

declare(strict_types=1);

use PhpOpcua\Client\Types\BrowseDirection;
use PhpOpcua\Client\Types\BrowsePathResult;
use PhpOpcua\Client\Types\BrowsePathTarget;
use PhpOpcua\Client\Types\BuiltinType;
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
use PhpOpcua\SessionManager\Exception\SerializationException;
use PhpOpcua\SessionManager\Serialization\TypeSerializer;

describe('TypeSerializer — Extended', function () {

    beforeEach(function () {
        $this->serializer = new TypeSerializer();
    });

    describe('serialize dispatch', function () {

        it('serializes NodeClass enum', function () {
            expect($this->serializer->serialize(NodeClass::Variable))->toBe(2);
            expect($this->serializer->serialize(NodeClass::Object))->toBe(1);
        });

        it('serializes BrowseDirection enum', function () {
            expect($this->serializer->serialize(BrowseDirection::Forward))->toBe(0);
            expect($this->serializer->serialize(BrowseDirection::Both))->toBe(2);
        });

        it('serializes ConnectionState enum', function () {
            expect($this->serializer->serialize(ConnectionState::Connected))->toBe('Connected');
            expect($this->serializer->serialize(ConnectionState::Broken))->toBe('Broken');
        });

        it('serializes SubscriptionResult via generic serialize', function () {
            $result = new SubscriptionResult(1, 500.0, 2400, 10);
            $serialized = $this->serializer->serialize($result);

            expect($serialized)->toBeArray();
            expect($serialized['subscriptionId'])->toBe(1);
        });

        it('serializes MonitoredItemResult via generic serialize', function () {
            $result = new MonitoredItemResult(0, 100, 250.0, 1);
            $serialized = $this->serializer->serialize($result);

            expect($serialized['monitoredItemId'])->toBe(100);
        });

        it('serializes CallResult via generic serialize', function () {
            $result = new \PhpOpcua\Client\Types\CallResult(0, [], []);
            $serialized = $this->serializer->serialize($result);

            expect($serialized['statusCode'])->toBe(0);
        });

        it('serializes BrowseResultSet via generic serialize', function () {
            $result = new \PhpOpcua\Client\Types\BrowseResultSet([], null);
            $serialized = $this->serializer->serialize($result);

            expect($serialized['references'])->toBe([]);
            expect($serialized['continuationPoint'])->toBeNull();
        });

        it('serializes PublishResult via generic serialize', function () {
            $result = new PublishResult(1, 1, false, [], []);
            $serialized = $this->serializer->serialize($result);

            expect($serialized['subscriptionId'])->toBe(1);
        });

        it('serializes BrowsePathResult via generic serialize', function () {
            $result = new BrowsePathResult(0, []);
            $serialized = $this->serializer->serialize($result);

            expect($serialized['statusCode'])->toBe(0);
        });

        it('serializes BrowsePathTarget via generic serialize', function () {
            $target = new BrowsePathTarget(NodeId::numeric(0, 85), 0);
            $serialized = $this->serializer->serialize($target);

            expect($serialized['targetId']['id'])->toBe(85);
        });

        it('serializes TransferResult via generic serialize', function () {
            $result = new TransferResult(0, [1, 2]);
            $serialized = $this->serializer->serialize($result);

            expect($serialized['availableSequenceNumbers'])->toBe([1, 2]);
        });

        it('throws on unsupported type', function () {
            expect(fn() => $this->serializer->serialize(new stdClass()))->toThrow(SerializationException::class);
        });

    });

    describe('EndpointDescription roundtrip', function () {

        it('serializes and deserializes an EndpointDescription', function () {
            $ep = new EndpointDescription(
                'opc.tcp://localhost:4840',
                'certbytes',
                3,
                'http://opcfoundation.org/UA/SecurityPolicy#Basic256Sha256',
                [
                    new UserTokenPolicy('anon', 0, null, null, null),
                    new UserTokenPolicy('user', 1, null, null, 'http://opcfoundation.org/UA/SecurityPolicy#None'),
                ],
                'http://opcfoundation.org/UA-Profile/Transport/uatcp-uasc-uabinary',
                2,
            );

            $serialized = $this->serializer->serializeEndpointDescription($ep);

            expect($serialized['endpointUrl'])->toBe('opc.tcp://localhost:4840');
            expect($serialized['serverCertificate'])->toBe('certbytes');
            expect($serialized['securityMode'])->toBe(3);
            expect($serialized['securityLevel'])->toBe(2);
            expect($serialized['userIdentityTokens'])->toHaveCount(2);
            expect($serialized['userIdentityTokens'][0]['policyId'])->toBe('anon');
            expect($serialized['userIdentityTokens'][1]['tokenType'])->toBe(1);

            $deserialized = $this->serializer->deserializeEndpointDescription($serialized);

            expect($deserialized)->toBeInstanceOf(EndpointDescription::class);
            expect($deserialized->endpointUrl)->toBe('opc.tcp://localhost:4840');
            expect($deserialized->serverCertificate)->toBe('certbytes');
            expect($deserialized->securityMode)->toBe(3);
            expect($deserialized->securityLevel)->toBe(2);
            expect($deserialized->userIdentityTokens)->toHaveCount(2);
            expect($deserialized->userIdentityTokens[0]->policyId)->toBe('anon');
            expect($deserialized->userIdentityTokens[1]->securityPolicyUri)->toBe('http://opcfoundation.org/UA/SecurityPolicy#None');
        });

        it('handles EndpointDescription with null certificate', function () {
            $ep = new EndpointDescription(
                'opc.tcp://localhost:4840',
                null,
                1,
                'http://opcfoundation.org/UA/SecurityPolicy#None',
                [],
                '',
                0,
            );

            $serialized = $this->serializer->serializeEndpointDescription($ep);
            $deserialized = $this->serializer->deserializeEndpointDescription($serialized);

            expect($deserialized->serverCertificate)->toBeNull();
            expect($deserialized->userIdentityTokens)->toBe([]);
        });

        it('serializes EndpointDescription via generic serialize', function () {
            $ep = new EndpointDescription('url', null, 1, 'policy', [], 'transport', 0);
            $serialized = $this->serializer->serialize($ep);

            expect($serialized['endpointUrl'])->toBe('url');
        });

    });

    describe('Variant special value types', function () {

        it('deserializes DateTime Variant value', function () {
            $data = ['type' => BuiltinType::DateTime->value, 'value' => '2024-06-15T12:00:00+00:00'];
            $variant = $this->serializer->deserializeVariant($data);

            expect($variant->value)->toBeInstanceOf(DateTimeImmutable::class);
        });

        it('deserializes NodeId Variant value', function () {
            $data = ['type' => BuiltinType::NodeId->value, 'value' => ['ns' => 0, 'id' => 85, 'type' => 'numeric']];
            $variant = $this->serializer->deserializeVariant($data);

            expect($variant->value)->toBeInstanceOf(NodeId::class);
            expect($variant->value->identifier)->toBe(85);
        });

        it('deserializes QualifiedName Variant value', function () {
            $data = ['type' => BuiltinType::QualifiedName->value, 'value' => ['ns' => 1, 'name' => 'Test']];
            $variant = $this->serializer->deserializeVariant($data);

            expect($variant->value)->toBeInstanceOf(QualifiedName::class);
            expect($variant->value->name)->toBe('Test');
        });

        it('deserializes LocalizedText Variant value', function () {
            $data = ['type' => BuiltinType::LocalizedText->value, 'value' => ['locale' => 'en', 'text' => 'Hello']];
            $variant = $this->serializer->deserializeVariant($data);

            expect($variant->value)->toBeInstanceOf(LocalizedText::class);
            expect($variant->value->text)->toBe('Hello');
        });

        it('handles null Variant value', function () {
            $data = ['type' => BuiltinType::String->value, 'value' => null];
            $variant = $this->serializer->deserializeVariant($data);

            expect($variant->value)->toBeNull();
        });

        it('passes scalar Variant value through unchanged', function () {
            $data = ['type' => BuiltinType::Int32->value, 'value' => 42];
            $variant = $this->serializer->deserializeVariant($data);

            expect($variant->value)->toBe(42);
        });

    });

    describe('MonitoredItemResult array deserialization', function () {

        it('deserializes an array of MonitoredItemResults', function () {
            $data = [
                ['statusCode' => 0, 'monitoredItemId' => 1, 'revisedSamplingInterval' => 250.0, 'revisedQueueSize' => 1],
                ['statusCode' => 0, 'monitoredItemId' => 2, 'revisedSamplingInterval' => 500.0, 'revisedQueueSize' => 5],
            ];

            $results = array_map(fn(array $d) => $this->serializer->deserializeMonitoredItemResult($d), $data);

            expect($results)->toHaveCount(2);
            expect($results[0]->monitoredItemId)->toBe(1);
            expect($results[1]->revisedSamplingInterval)->toBe(500.0);
        });

    });

    describe('Generic serialize dispatch for core types', function () {

        it('dispatches Variant through generic serialize', function () {
            $variant = new Variant(BuiltinType::Int32, 42);
            $result = $this->serializer->serialize($variant);

            expect($result)->toBeArray();
            expect($result['type'])->toBe(BuiltinType::Int32->value);
            expect($result['value'])->toBe(42);
        });

        it('dispatches ReferenceDescription through generic serialize', function () {
            $ref = new ReferenceDescription(
                NodeId::numeric(0, 35),
                true,
                NodeId::numeric(0, 85),
                new QualifiedName(0, 'Objects'),
                new LocalizedText(null, 'Objects'),
                NodeClass::Object,
                null,
            );
            $result = $this->serializer->serialize($ref);

            expect($result)->toBeArray();
            expect($result['isForward'])->toBeTrue();
            expect($result['browseName']['name'])->toBe('Objects');
        });

        it('dispatches BrowseNode through generic serialize', function () {
            $node = new \PhpOpcua\Client\Types\BrowseNode(
                new ReferenceDescription(
                    NodeId::numeric(0, 35),
                    true,
                    NodeId::numeric(0, 85),
                    new QualifiedName(0, 'Objects'),
                    new LocalizedText(null, 'Objects'),
                    NodeClass::Object,
                    null,
                ),
            );
            $result = $this->serializer->serialize($node);

            expect($result)->toBeArray();
            expect($result['reference']['browseName']['name'])->toBe('Objects');
            expect($result['children'])->toBe([]);
        });

        it('dispatches QualifiedName through generic serialize', function () {
            $qn = new QualifiedName(2, 'Temperature');
            $result = $this->serializer->serialize($qn);

            expect($result)->toBe(['ns' => 2, 'name' => 'Temperature']);
        });

        it('dispatches LocalizedText through generic serialize', function () {
            $lt = new LocalizedText('en', 'Hello');
            $result = $this->serializer->serialize($lt);

            expect($result)->toBe(['locale' => 'en', 'text' => 'Hello']);
        });

        it('dispatches DataValue through generic serialize', function () {
            $dv = new DataValue(new Variant(BuiltinType::Double, 3.14), 0);
            $result = $this->serializer->serialize($dv);

            expect($result)->toBeArray();
            expect($result['value'])->toBe(3.14);
            expect($result['statusCode'])->toBe(0);
        });

    });

});
