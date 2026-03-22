<?php

declare(strict_types=1);

use Gianfriaur\OpcuaPhpClient\Types\NodeId;
use Gianfriaur\OpcuaPhpClient\Types\StatusCode;
use Gianfriaur\OpcuaSessionManager\Tests\Integration\Helpers\TestHelper;

beforeAll(fn() => TestHelper::startDaemon());
afterAll(fn() => TestHelper::stopDaemon());

describe('Timeout configuration via ManagedClient', function () {

    it('connects with custom timeout and reads values', function () {
        $client = null;
        try {
            $client = TestHelper::createManagedClient();
            $client->setTimeout(10.0);
            $client->connect(TestHelper::ENDPOINT_NO_SECURITY);

            $dv = $client->read(NodeId::numeric(0, 2259));
            expect($dv->statusCode)->toBe(StatusCode::Good);
            expect($dv->getValue())->toBe(0);
        } finally {
            TestHelper::safeDisconnect($client);
        }
    })->group('integration');

    it('queries timeout from daemon', function () {
        $client = null;
        try {
            $client = TestHelper::createManagedClient();
            $client->setTimeout(8.5);
            $client->connect(TestHelper::ENDPOINT_NO_SECURITY);

            expect($client->getTimeout())->toBe(8.5);
        } finally {
            TestHelper::safeDisconnect($client);
        }
    })->group('integration');

})->group('integration');

describe('Batching configuration via ManagedClient', function () {

    it('connects with batch size and performs readMulti', function () {
        $client = null;
        try {
            $client = TestHelper::createManagedClient();
            $client->setBatchSize(50);
            $client->connect(TestHelper::ENDPOINT_NO_SECURITY);

            expect($client->getBatchSize())->toBe(50);

            $boolNodeId = TestHelper::browseToNode($client, ['TestServer', 'DataTypes', 'Scalar', 'BooleanValue']);
            $intNodeId = TestHelper::browseToNode($client, ['TestServer', 'DataTypes', 'Scalar', 'Int32Value']);

            $results = $client->readMulti([
                ['nodeId' => $boolNodeId],
                ['nodeId' => $intNodeId],
            ]);

            expect($results)->toHaveCount(2);
            expect($results[0]->statusCode)->toBe(StatusCode::Good);
            expect($results[1]->statusCode)->toBe(StatusCode::Good);
        } finally {
            TestHelper::safeDisconnect($client);
        }
    })->group('integration');

    it('can query server limits after connect', function () {
        $client = null;
        try {
            $client = TestHelper::connectNoSecurity();

            $maxRead = $client->getServerMaxNodesPerRead();
            $maxWrite = $client->getServerMaxNodesPerWrite();

            expect($maxRead === null || is_int($maxRead))->toBeTrue();
            expect($maxWrite === null || is_int($maxWrite))->toBeTrue();
        } finally {
            TestHelper::safeDisconnect($client);
        }
    })->group('integration');

    it('connects with batch size 0 (disabled) and works', function () {
        $client = null;
        try {
            $client = TestHelper::createManagedClient();
            $client->setBatchSize(0);
            $client->connect(TestHelper::ENDPOINT_NO_SECURITY);

            $dv = $client->read(NodeId::numeric(0, 2259));
            expect($dv->statusCode)->toBe(StatusCode::Good);
        } finally {
            TestHelper::safeDisconnect($client);
        }
    })->group('integration');

})->group('integration');

describe('Auto-retry configuration via ManagedClient', function () {

    it('connects with auto-retry and performs operations', function () {
        $client = null;
        try {
            $client = TestHelper::createManagedClient();
            $client->setAutoRetry(2);
            $client->connect(TestHelper::ENDPOINT_NO_SECURITY);

            expect($client->getAutoRetry())->toBe(2);

            $dv = $client->read(NodeId::numeric(0, 2259));
            expect($dv->statusCode)->toBe(StatusCode::Good);
        } finally {
            TestHelper::safeDisconnect($client);
        }
    })->group('integration');

})->group('integration');
