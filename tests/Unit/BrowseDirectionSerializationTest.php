<?php

declare(strict_types=1);

use PhpOpcua\Client\Types\BrowseDirection;
use PhpOpcua\Client\Types\BrowseNode;
use PhpOpcua\Client\Types\ConnectionState;
use PhpOpcua\Client\Types\LocalizedText;
use PhpOpcua\Client\Types\NodeClass;
use PhpOpcua\Client\Types\NodeId;
use PhpOpcua\Client\Types\QualifiedName;
use PhpOpcua\Client\Types\ReferenceDescription;
use PhpOpcua\SessionManager\Serialization\TypeSerializer;

describe('TypeSerializer — v2.0.0 types', function () {

    beforeEach(function () {
        $this->serializer = new TypeSerializer();
    });

    describe('BrowseDirection', function () {

        it('serializes BrowseDirection::Forward as int', function () {
            $result = $this->serializer->serialize(BrowseDirection::Forward);
            expect($result)->toBe(0);
        });

        it('serializes BrowseDirection::Inverse as int', function () {
            $result = $this->serializer->serialize(BrowseDirection::Inverse);
            expect($result)->toBe(1);
        });

        it('serializes BrowseDirection::Both as int', function () {
            $result = $this->serializer->serialize(BrowseDirection::Both);
            expect($result)->toBe(2);
        });

        it('deserializes BrowseDirection from int', function () {
            expect($this->serializer->deserializeBrowseDirection(0))->toBe(BrowseDirection::Forward);
            expect($this->serializer->deserializeBrowseDirection(1))->toBe(BrowseDirection::Inverse);
            expect($this->serializer->deserializeBrowseDirection(2))->toBe(BrowseDirection::Both);
        });

    });

    describe('ConnectionState', function () {

        it('serializes ConnectionState as name', function () {
            expect($this->serializer->serialize(ConnectionState::Disconnected))->toBe('Disconnected');
            expect($this->serializer->serialize(ConnectionState::Connected))->toBe('Connected');
            expect($this->serializer->serialize(ConnectionState::Broken))->toBe('Broken');
        });

        it('deserializes ConnectionState from name', function () {
            expect($this->serializer->deserializeConnectionState('Disconnected'))->toBe(ConnectionState::Disconnected);
            expect($this->serializer->deserializeConnectionState('Connected'))->toBe(ConnectionState::Connected);
            expect($this->serializer->deserializeConnectionState('Broken'))->toBe(ConnectionState::Broken);
        });

        it('defaults to Disconnected for unknown name', function () {
            expect($this->serializer->deserializeConnectionState('invalid'))->toBe(ConnectionState::Disconnected);
        });

    });

    describe('BrowseNode', function () {

        it('serializes a leaf BrowseNode', function () {
            $ref = new ReferenceDescription(
                NodeId::numeric(0, 35),
                true,
                NodeId::numeric(2, 100),
                new QualifiedName(2, 'MyNode'),
                new LocalizedText('en', 'My Node'),
                NodeClass::Variable,
                NodeId::numeric(0, 63),
            );
            $node = new BrowseNode($ref);

            $result = $this->serializer->serializeBrowseNode($node);

            expect($result)->toHaveKey('reference');
            expect($result)->toHaveKey('children');
            expect($result['children'])->toBeEmpty();
            expect($result['reference']['browseName']['name'])->toBe('MyNode');
        });

        it('serializes a BrowseNode with children', function () {
            $parentRef = new ReferenceDescription(
                NodeId::numeric(0, 35),
                true,
                NodeId::numeric(0, 85),
                new QualifiedName(0, 'Objects'),
                new LocalizedText(null, 'Objects'),
                NodeClass::Object,
                null,
            );
            $childRef = new ReferenceDescription(
                NodeId::numeric(0, 35),
                true,
                NodeId::numeric(0, 2253),
                new QualifiedName(0, 'Server'),
                new LocalizedText(null, 'Server'),
                NodeClass::Object,
                null,
            );

            $parent = new BrowseNode($parentRef);
            $child = new BrowseNode($childRef);
            $parent->addChild($child);

            $result = $this->serializer->serializeBrowseNode($parent);

            expect($result['children'])->toHaveCount(1);
            expect($result['children'][0]['reference']['browseName']['name'])->toBe('Server');
            expect($result['children'][0]['children'])->toBeEmpty();
        });

        it('roundtrips a BrowseNode tree', function () {
            $parentRef = new ReferenceDescription(
                NodeId::numeric(0, 35),
                true,
                NodeId::numeric(0, 85),
                new QualifiedName(0, 'Objects'),
                new LocalizedText(null, 'Objects'),
                NodeClass::Object,
                null,
            );
            $childRef = new ReferenceDescription(
                NodeId::numeric(0, 35),
                true,
                NodeId::numeric(0, 2253),
                new QualifiedName(0, 'Server'),
                new LocalizedText(null, 'Server'),
                NodeClass::Object,
                null,
            );

            $parent = new BrowseNode($parentRef);
            $parent->addChild(new BrowseNode($childRef));

            $serialized = $this->serializer->serializeBrowseNode($parent);
            $deserialized = $this->serializer->deserializeBrowseNode($serialized);

            expect($deserialized->reference->browseName->name)->toBe('Objects');
            expect($deserialized->hasChildren())->toBeTrue();
            expect($deserialized->getChildren())->toHaveCount(1);
            expect($deserialized->getChildren()[0]->reference->browseName->name)->toBe('Server');
            expect($deserialized->getChildren()[0]->hasChildren())->toBeFalse();
        });

        it('deserializes a deeply nested BrowseNode tree', function () {
            $data = [
                'reference' => [
                    'referenceTypeId' => ['ns' => 0, 'id' => 35, 'type' => 'numeric'],
                    'isForward' => true,
                    'nodeId' => ['ns' => 0, 'id' => 85, 'type' => 'numeric'],
                    'browseName' => ['ns' => 0, 'name' => 'Root'],
                    'displayName' => ['locale' => null, 'text' => 'Root'],
                    'nodeClass' => 1,
                    'typeDefinition' => null,
                ],
                'children' => [
                    [
                        'reference' => [
                            'referenceTypeId' => ['ns' => 0, 'id' => 35, 'type' => 'numeric'],
                            'isForward' => true,
                            'nodeId' => ['ns' => 0, 'id' => 2253, 'type' => 'numeric'],
                            'browseName' => ['ns' => 0, 'name' => 'Server'],
                            'displayName' => ['locale' => null, 'text' => 'Server'],
                            'nodeClass' => 1,
                            'typeDefinition' => null,
                        ],
                        'children' => [
                            [
                                'reference' => [
                                    'referenceTypeId' => ['ns' => 0, 'id' => 47, 'type' => 'numeric'],
                                    'isForward' => true,
                                    'nodeId' => ['ns' => 0, 'id' => 2256, 'type' => 'numeric'],
                                    'browseName' => ['ns' => 0, 'name' => 'ServerStatus'],
                                    'displayName' => ['locale' => null, 'text' => 'ServerStatus'],
                                    'nodeClass' => 2,
                                    'typeDefinition' => null,
                                ],
                                'children' => [],
                            ],
                        ],
                    ],
                ],
            ];

            $node = $this->serializer->deserializeBrowseNode($data);

            expect($node->reference->browseName->name)->toBe('Root');
            expect($node->getChildren())->toHaveCount(1);

            $server = $node->getChildren()[0];
            expect($server->reference->browseName->name)->toBe('Server');
            expect($server->getChildren())->toHaveCount(1);

            $serverStatus = $server->getChildren()[0];
            expect($serverStatus->reference->browseName->name)->toBe('ServerStatus');
            expect($serverStatus->hasChildren())->toBeFalse();
        });

    });

});
