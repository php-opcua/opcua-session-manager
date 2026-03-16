<?php

declare(strict_types=1);

use Gianfriaur\OpcuaPhpClient\Exception\ConnectionException;
use Gianfriaur\OpcuaPhpClient\Security\SecurityMode;
use Gianfriaur\OpcuaPhpClient\Security\SecurityPolicy;
use Gianfriaur\OpcuaPhpClient\Types\NodeId;
use Gianfriaur\OpcuaPhpClient\Types\StatusCode;
use Gianfriaur\OpcuaSessionManager\Client\ManagedClient;
use Gianfriaur\OpcuaSessionManager\Tests\Integration\Helpers\TestHelper;

beforeAll(fn() => TestHelper::startDaemon());
afterAll(fn() => TestHelper::stopDaemon());

describe('Connection via ManagedClient', function () {

    it('connects to opcua-no-security with anonymous auth', function () {
        $client = null;
        try {
            $client = TestHelper::connectNoSecurity();
            expect($client)->toBeInstanceOf(ManagedClient::class);
            expect($client->getSessionId())->toBeString()->not->toBeEmpty();
        } finally {
            TestHelper::safeDisconnect($client);
        }
    })->group('integration');

    it('connects and browses root Objects folder', function () {
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

    it('reads Server/ServerStatus/State variable', function () {
        $client = null;
        try {
            $client = TestHelper::connectNoSecurity();
            $dataValue = $client->read(NodeId::numeric(0, 2259));
            expect($dataValue->getStatusCode())->toBe(StatusCode::Good);
            expect($dataValue->getValue())->toBeInt()->toBe(0);
        } finally {
            TestHelper::safeDisconnect($client);
        }
    })->group('integration');

    it('disconnects cleanly', function () {
        $client = TestHelper::connectNoSecurity();
        $sessionId = $client->getSessionId();
        $client->disconnect();

        expect($client->getSessionId())->toBeNull();

        // After disconnect, operations should fail
        expect(fn() => $client->browse(NodeId::numeric(0, 85)))
            ->toThrow(ConnectionException::class);
    })->group('integration');

    it('throws on connection to invalid host', function () {
        $client = TestHelper::createManagedClient();
        expect(fn() => $client->connect('opc.tcp://invalid.host.that.does.not.exist:4840/UA/TestServer'))
            ->toThrow(ConnectionException::class);
    })->group('integration');

    it('throws on connection to invalid port', function () {
        $client = TestHelper::createManagedClient();
        expect(fn() => $client->connect('opc.tcp://localhost:59999/UA/TestServer'))
            ->toThrow(ConnectionException::class);
    })->group('integration');

    it('connects with username/password to userpass server', function () {
        $client = null;
        try {
            $client = TestHelper::createManagedClient();
            $client->setSecurityPolicy(SecurityPolicy::Basic256Sha256);
            $client->setSecurityMode(SecurityMode::SignAndEncrypt);
            $client->setClientCertificate(
                TestHelper::getClientCertPath(),
                TestHelper::getClientKeyPath(),
                TestHelper::getCaCertPath(),
            );
            $client->setUserCredentials(
                TestHelper::USER_ADMIN['username'],
                TestHelper::USER_ADMIN['password'],
            );
            $client->connect(TestHelper::ENDPOINT_USERPASS);

            expect($client->getSessionId())->toBeString()->not->toBeEmpty();

            $refs = $client->browse(NodeId::numeric(0, 85));
            expect($refs)->toBeArray()->not->toBeEmpty();
        } finally {
            TestHelper::safeDisconnect($client);
        }
    })->group('integration');

    it('connects with certificate to auto-accept server', function () {
        $client = null;
        try {
            $client = TestHelper::createManagedClient();
            $client->setSecurityPolicy(SecurityPolicy::Basic256Sha256);
            $client->setSecurityMode(SecurityMode::SignAndEncrypt);
            $client->setClientCertificate(
                TestHelper::getClientCertPath(),
                TestHelper::getClientKeyPath(),
                TestHelper::getCaCertPath(),
            );
            $client->setUserCertificate(
                TestHelper::getClientCertPath(),
                TestHelper::getClientKeyPath(),
            );
            $client->connect(TestHelper::ENDPOINT_AUTO_ACCEPT);

            expect($client->getSessionId())->toBeString()->not->toBeEmpty();

            $dataValue = $client->read(NodeId::numeric(0, 2259));
            expect($dataValue->getStatusCode())->toBe(StatusCode::Good);
        } finally {
            TestHelper::safeDisconnect($client);
        }
    })->group('integration');

    it('can reconnect after disconnect', function () {
        $client = TestHelper::createManagedClient();

        $client->connect(TestHelper::ENDPOINT_NO_SECURITY);
        $firstSession = $client->getSessionId();
        $client->disconnect();

        $client->connect(TestHelper::ENDPOINT_NO_SECURITY);
        $secondSession = $client->getSessionId();

        expect($secondSession)->not->toBe($firstSession);

        $refs = $client->browse(NodeId::numeric(0, 85));
        expect($refs)->toBeArray()->not->toBeEmpty();

        $client->disconnect();
    })->group('integration');

})->group('integration');
