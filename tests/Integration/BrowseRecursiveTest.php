<?php

declare(strict_types=1);

use Gianfriaur\OpcuaPhpClient\Types\BrowseDirection;
use Gianfriaur\OpcuaPhpClient\Types\BrowseNode;
use Gianfriaur\OpcuaPhpClient\Types\NodeId;
use Gianfriaur\OpcuaSessionManager\Tests\Integration\Helpers\TestHelper;

beforeAll(fn() => TestHelper::startDaemon());
afterAll(fn() => TestHelper::stopDaemon());

describe('Browse recursive via ManagedClient', function () {

    it('browseAll returns all references without continuation', function () {
        $client = null;
        try {
            $client = TestHelper::connectNoSecurity();
            $refs = $client->browseAll(NodeId::numeric(0, 85));

            expect($refs)->toBeArray()->not->toBeEmpty();

            $names = array_map(fn($r) => $r->browseName->name, $refs);
            expect($names)->toContain('Server');
        } finally {
            TestHelper::safeDisconnect($client);
        }
    })->group('integration');

    it('browseAll with BrowseDirection::Both returns forward and inverse', function () {
        $client = null;
        try {
            $client = TestHelper::connectNoSecurity();
            $testServerNodeId = TestHelper::browseToNode($client, ['TestServer']);

            $refs = $client->browseAll($testServerNodeId, BrowseDirection::Both);

            expect($refs)->toBeArray()->not->toBeEmpty();

            $hasForward = false;
            $hasInverse = false;
            foreach ($refs as $ref) {
                if ($ref->isForward) {
                    $hasForward = true;
                } else {
                    $hasInverse = true;
                }
            }
            expect($hasForward)->toBeTrue();
            expect($hasInverse)->toBeTrue();
        } finally {
            TestHelper::safeDisconnect($client);
        }
    })->group('integration');

    it('browseRecursive returns BrowseNode tree', function () {
        $client = null;
        try {
            $client = TestHelper::connectNoSecurity();
            $testServerNodeId = TestHelper::browseToNode($client, ['TestServer']);

            $tree = $client->browseRecursive($testServerNodeId, maxDepth: 2);

            expect($tree)->toBeArray()->not->toBeEmpty();
            foreach ($tree as $node) {
                expect($node)->toBeInstanceOf(BrowseNode::class);
                expect($node->reference->nodeId)->toBeInstanceOf(NodeId::class);
                expect($node->reference->browseName->name)->toBeString();
            }
        } finally {
            TestHelper::safeDisconnect($client);
        }
    })->group('integration');

    it('browseRecursive with depth=1 returns only direct children', function () {
        $client = null;
        try {
            $client = TestHelper::connectNoSecurity();
            $testServerNodeId = TestHelper::browseToNode($client, ['TestServer']);

            $tree = $client->browseRecursive($testServerNodeId, maxDepth: 1);

            expect($tree)->toBeArray()->not->toBeEmpty();

            $names = array_map(fn(BrowseNode $n) => $n->reference->browseName->name, $tree);
            expect($names)->toContain('DataTypes');
            expect($names)->toContain('Methods');

            foreach ($tree as $node) {
                foreach ($node->getChildren() as $child) {
                    expect($child->hasChildren())->toBeFalse();
                }
            }
        } finally {
            TestHelper::safeDisconnect($client);
        }
    })->group('integration');

    it('browseRecursive finds nested nodes at deeper depths', function () {
        $client = null;
        try {
            $client = TestHelper::connectNoSecurity();
            $testServerNodeId = TestHelper::browseToNode($client, ['TestServer']);

            $tree = $client->browseRecursive($testServerNodeId, maxDepth: 3);

            expect($tree)->toBeArray()->not->toBeEmpty();

            $dataTypesNode = null;
            foreach ($tree as $node) {
                if ($node->reference->browseName->name === 'DataTypes') {
                    $dataTypesNode = $node;
                    break;
                }
            }

            expect($dataTypesNode)->not->toBeNull();
            expect($dataTypesNode->hasChildren())->toBeTrue();

            $childNames = array_map(
                fn(BrowseNode $n) => $n->reference->browseName->name,
                $dataTypesNode->getChildren(),
            );
            expect($childNames)->toContain('Scalar');
        } finally {
            TestHelper::safeDisconnect($client);
        }
    })->group('integration');

    it('browseRecursive with default depth from setDefaultBrowseMaxDepth', function () {
        $client = null;
        try {
            $client = TestHelper::createManagedClient();
            $client->setDefaultBrowseMaxDepth(2);
            $client->connect(TestHelper::ENDPOINT_NO_SECURITY);

            $testServerNodeId = TestHelper::browseToNode($client, ['TestServer']);
            $tree = $client->browseRecursive($testServerNodeId);

            expect($tree)->toBeArray()->not->toBeEmpty();
        } finally {
            TestHelper::safeDisconnect($client);
        }
    })->group('integration');

})->group('integration');
