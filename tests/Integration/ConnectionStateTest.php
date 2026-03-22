<?php

declare(strict_types=1);

use Gianfriaur\OpcuaPhpClient\Exception\ConnectionException;
use Gianfriaur\OpcuaPhpClient\Types\ConnectionState;
use Gianfriaur\OpcuaPhpClient\Types\NodeId;
use Gianfriaur\OpcuaSessionManager\Tests\Integration\Helpers\TestHelper;

beforeAll(fn() => TestHelper::startDaemon());
afterAll(fn() => TestHelper::stopDaemon());

describe('Connection state via ManagedClient', function () {

    it('reports Disconnected before connect', function () {
        $client = TestHelper::createManagedClient();

        expect($client->isConnected())->toBeFalse();
        expect($client->getConnectionState())->toBe(ConnectionState::Disconnected);
    })->group('integration');

    it('reports Connected after connect', function () {
        $client = null;
        try {
            $client = TestHelper::connectNoSecurity();

            expect($client->isConnected())->toBeTrue();
            expect($client->getConnectionState())->toBe(ConnectionState::Connected);
        } finally {
            TestHelper::safeDisconnect($client);
        }
    })->group('integration');

    it('reports Disconnected after disconnect', function () {
        $client = TestHelper::connectNoSecurity();
        $client->disconnect();

        expect($client->isConnected())->toBeFalse();
        expect($client->getConnectionState())->toBe(ConnectionState::Disconnected);
    })->group('integration');

    it('throws on reconnect when not connected', function () {
        $client = TestHelper::createManagedClient();

        expect(fn() => $client->reconnect())
            ->toThrow(ConnectionException::class, 'Not connected');
    })->group('integration');

    it('can reconnect and perform operations', function () {
        $client = null;
        try {
            $client = TestHelper::connectNoSecurity();

            $client->reconnect();

            expect($client->isConnected())->toBeTrue();

            $dv = $client->read(NodeId::numeric(0, 2259));
            expect($dv->getValue())->toBe(0);
        } finally {
            TestHelper::safeDisconnect($client);
        }
    })->group('integration');

})->group('integration');
