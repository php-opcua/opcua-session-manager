<?php

declare(strict_types=1);

use Gianfriaur\OpcuaPhpClient\Types\NodeId;
use Gianfriaur\OpcuaPhpClient\Types\QualifiedName;
use Gianfriaur\OpcuaPhpClient\Types\StatusCode;
use Gianfriaur\OpcuaSessionManager\Tests\Integration\Helpers\TestHelper;

beforeAll(fn() => TestHelper::startDaemon());
afterAll(fn() => TestHelper::stopDaemon());

describe('Translate browse path via ManagedClient', function () {

    it('resolves /Objects/Server/ServerStatus path', function () {
        $client = null;
        try {
            $client = TestHelper::connectNoSecurity();

            $nodeId = $client->resolveNodeId('/Objects/Server/ServerStatus');

            expect($nodeId)->toBeInstanceOf(NodeId::class);
            expect($nodeId->namespaceIndex)->toBe(0);
            expect($nodeId->identifier)->toBe(2256);
        } finally {
            TestHelper::safeDisconnect($client);
        }
    })->group('integration');

    it('resolves /Objects/Server path', function () {
        $client = null;
        try {
            $client = TestHelper::connectNoSecurity();

            $nodeId = $client->resolveNodeId('/Objects/Server');

            expect($nodeId)->toBeInstanceOf(NodeId::class);
            expect($nodeId->namespaceIndex)->toBe(0);
            expect($nodeId->identifier)->toBe(2253);
        } finally {
            TestHelper::safeDisconnect($client);
        }
    })->group('integration');

    it('resolves a path from custom starting node', function () {
        $client = null;
        try {
            $client = TestHelper::connectNoSecurity();

            $nodeId = $client->resolveNodeId('Server', NodeId::numeric(0, 85));

            expect($nodeId)->toBeInstanceOf(NodeId::class);
            expect($nodeId->identifier)->toBe(2253);
        } finally {
            TestHelper::safeDisconnect($client);
        }
    })->group('integration');

    it('translates multiple browse paths at once', function () {
        $client = null;
        try {
            $client = TestHelper::connectNoSecurity();

            $results = $client->translateBrowsePaths([
                [
                    'startingNodeId' => NodeId::numeric(0, 84),
                    'relativePath' => [
                        ['targetName' => new QualifiedName(0, 'Objects')],
                        ['targetName' => new QualifiedName(0, 'Server')],
                    ],
                ],
            ]);

            expect($results)->toBeArray()->toHaveCount(1);
            expect($results[0]->statusCode)->toBe(StatusCode::Good);
            expect($results[0]->targets)->toBeArray()->not->toBeEmpty();
            expect($results[0]->targets[0]->targetId)->toBeInstanceOf(NodeId::class);
            expect($results[0]->targets[0]->targetId->identifier)->toBe(2253);
        } finally {
            TestHelper::safeDisconnect($client);
        }
    })->group('integration');

})->group('integration');
