<?php

declare(strict_types=1);

use Gianfriaur\OpcuaPhpClient\Types\NodeClass;
use Gianfriaur\OpcuaPhpClient\Types\NodeId;
use Gianfriaur\OpcuaSessionManager\Tests\Integration\Helpers\TestHelper;

beforeAll(fn() => TestHelper::startDaemon());
afterAll(fn() => TestHelper::stopDaemon());

describe('Browse via ManagedClient', function () {

    it('browses the root Objects folder', function () {
        $client = null;
        try {
            $client = TestHelper::connectNoSecurity();
            $refs = $client->browse(NodeId::numeric(0, 85));

            expect($refs)->toBeArray()->not->toBeEmpty();

            $names = array_map(fn($r) => $r->getBrowseName()->getName(), $refs);
            expect($names)->toContain('Server');
        } finally {
            TestHelper::safeDisconnect($client);
        }
    })->group('integration');

    it('browses the TestServer folder', function () {
        $client = null;
        try {
            $client = TestHelper::connectNoSecurity();
            $testServerNodeId = TestHelper::browseToNode($client, ['TestServer']);
            $refs = $client->browse($testServerNodeId);

            expect($refs)->toBeArray()->not->toBeEmpty();

            $names = array_map(fn($r) => $r->getBrowseName()->getName(), $refs);
            expect($names)->toContain('DataTypes');
            expect($names)->toContain('Methods');
            expect($names)->toContain('Dynamic');
        } finally {
            TestHelper::safeDisconnect($client);
        }
    })->group('integration');

    it('browses the DataTypes folder', function () {
        $client = null;
        try {
            $client = TestHelper::connectNoSecurity();
            $nodeId = TestHelper::browseToNode($client, ['TestServer', 'DataTypes']);
            $refs = $client->browse($nodeId);

            expect($refs)->toBeArray()->not->toBeEmpty();

            $names = array_map(fn($r) => $r->getBrowseName()->getName(), $refs);
            expect($names)->toContain('Scalar');
        } finally {
            TestHelper::safeDisconnect($client);
        }
    })->group('integration');

    it('browses the Methods folder', function () {
        $client = null;
        try {
            $client = TestHelper::connectNoSecurity();
            $nodeId = TestHelper::browseToNode($client, ['TestServer', 'Methods']);
            $refs = $client->browse($nodeId);

            expect($refs)->toBeArray()->not->toBeEmpty();

            $names = array_map(fn($r) => $r->getBrowseName()->getName(), $refs);
            expect($names)->toContain('Add');
            expect($names)->toContain('Multiply');
        } finally {
            TestHelper::safeDisconnect($client);
        }
    })->group('integration');

    it('verifies node classes are correct', function () {
        $client = null;
        try {
            $client = TestHelper::connectNoSecurity();
            $testServerNodeId = TestHelper::browseToNode($client, ['TestServer']);
            $refs = $client->browse($testServerNodeId);

            foreach ($refs as $ref) {
                $name = $ref->getBrowseName()->getName();
                if (in_array($name, ['DataTypes', 'Methods', 'Dynamic'], true)) {
                    expect($ref->getNodeClass())->toBe(NodeClass::Object);
                }
            }
        } finally {
            TestHelper::safeDisconnect($client);
        }
    })->group('integration');

    it('browses with direction=Inverse', function () {
        $client = null;
        try {
            $client = TestHelper::connectNoSecurity();
            $testServerNodeId = TestHelper::browseToNode($client, ['TestServer']);

            $refs = $client->browse($testServerNodeId, direction: 1);

            expect($refs)->toBeArray()->not->toBeEmpty();
            foreach ($refs as $ref) {
                expect($ref->isForward())->toBeFalse();
            }
        } finally {
            TestHelper::safeDisconnect($client);
        }
    })->group('integration');

    it('browses with specific reference type (Organizes)', function () {
        $client = null;
        try {
            $client = TestHelper::connectNoSecurity();

            $refs = $client->browse(
                NodeId::numeric(0, 85),
                direction: 0,
                referenceTypeId: NodeId::numeric(0, 35),
                includeSubtypes: true,
            );

            expect($refs)->toBeArray()->not->toBeEmpty();
            foreach ($refs as $ref) {
                expect($ref->isForward())->toBeTrue();
            }
        } finally {
            TestHelper::safeDisconnect($client);
        }
    })->group('integration');

    it('browses with continuation', function () {
        $client = null;
        try {
            $client = TestHelper::connectNoSecurity();

            $result = $client->browseWithContinuation(NodeId::numeric(0, 85));

            expect($result)->toBeArray();
            expect($result)->toHaveKey('references');
            expect($result)->toHaveKey('continuationPoint');
            expect($result['references'])->toBeArray()->not->toBeEmpty();
        } finally {
            TestHelper::safeDisconnect($client);
        }
    })->group('integration');

    it('deeply browses to a scalar node', function () {
        $client = null;
        try {
            $client = TestHelper::connectNoSecurity();
            $nodeId = TestHelper::browseToNode($client, ['TestServer', 'DataTypes', 'Scalar', 'BooleanValue']);

            expect($nodeId)->toBeInstanceOf(NodeId::class);
            expect($nodeId->getNamespaceIndex())->toBeGreaterThanOrEqual(0);
        } finally {
            TestHelper::safeDisconnect($client);
        }
    })->group('integration');

})->group('integration');
