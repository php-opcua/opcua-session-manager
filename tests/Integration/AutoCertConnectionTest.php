<?php

declare(strict_types=1);

use PhpOpcua\Client\Security\SecurityMode;
use PhpOpcua\Client\Security\SecurityPolicy;
use PhpOpcua\Client\Types\NodeId;
use PhpOpcua\Client\Types\StatusCode;
use PhpOpcua\SessionManager\Client\SocketConnection;
use PhpOpcua\SessionManager\Tests\Integration\Helpers\TestHelper;

// Tests for auto-generated client certificate connections (opcua-client v1.1+).
// The daemon's Client auto-generates a self-signed certificate when security
// policy/mode are configured but no explicit clientCertPath/clientKeyPath are provided.

beforeAll(fn() => TestHelper::startDaemon());
afterAll(fn() => TestHelper::stopDaemon());

describe('Auto-generated certificate: connection', function () {

    it('connects to auto-accept server with security but no explicit cert paths', function () {
        $client = null;
        try {
            $client = TestHelper::createManagedClient();
            $client->setSecurityPolicy(SecurityPolicy::Basic256Sha256);
            $client->setSecurityMode(SecurityMode::SignAndEncrypt);
            // No setClientCertificate() — daemon auto-generates the cert
            $client->connect(TestHelper::ENDPOINT_AUTO_ACCEPT);

            expect($client->getSessionId())->toBeString()->not->toBeEmpty();
        } finally {
            TestHelper::safeDisconnect($client);
        }
    })->group('integration');

    it('reads ServerState after auto-cert connection', function () {
        $client = null;
        try {
            $client = TestHelper::createManagedClient();
            $client->setSecurityPolicy(SecurityPolicy::Basic256Sha256);
            $client->setSecurityMode(SecurityMode::SignAndEncrypt);
            $client->connect(TestHelper::ENDPOINT_AUTO_ACCEPT);

            $dataValue = $client->read(NodeId::numeric(0, 2259));
            expect($dataValue->statusCode)->toBe(StatusCode::Good);
            expect($dataValue->getValue())->toBeInt()->toBe(0);
        } finally {
            TestHelper::safeDisconnect($client);
        }
    })->group('integration');

    it('browses Objects folder after auto-cert connection', function () {
        $client = null;
        try {
            $client = TestHelper::createManagedClient();
            $client->setSecurityPolicy(SecurityPolicy::Basic256Sha256);
            $client->setSecurityMode(SecurityMode::SignAndEncrypt);
            $client->connect(TestHelper::ENDPOINT_AUTO_ACCEPT);

            $refs = $client->browse(NodeId::numeric(0, 85));
            expect($refs)->toBeArray()->not->toBeEmpty();

            $names = array_map(fn($r) => $r->browseName->name, $refs);
            expect($names)->toContain('Server');
        } finally {
            TestHelper::safeDisconnect($client);
        }
    })->group('integration');

    it('disconnects auto-cert session cleanly', function () {
        $client = TestHelper::createManagedClient();
        $client->setSecurityPolicy(SecurityPolicy::Basic256Sha256);
        $client->setSecurityMode(SecurityMode::SignAndEncrypt);
        $client->connect(TestHelper::ENDPOINT_AUTO_ACCEPT);

        $sessionId = $client->getSessionId();
        expect($sessionId)->toBeString()->not->toBeEmpty();

        $client->disconnect();
        expect($client->getSessionId())->toBeNull();
    })->group('integration');

    it('can reconnect with auto-cert after disconnect', function () {
        $client = TestHelper::createManagedClient();
        $client->setSecurityPolicy(SecurityPolicy::Basic256Sha256);
        $client->setSecurityMode(SecurityMode::SignAndEncrypt);

        $client->connect(TestHelper::ENDPOINT_AUTO_ACCEPT);
        $firstSession = $client->getSessionId();
        $client->disconnect();

        $client->connect(TestHelper::ENDPOINT_AUTO_ACCEPT);
        $secondSession = $client->getSessionId();

        expect($secondSession)->not->toBe($firstSession);

        $dataValue = $client->read(NodeId::numeric(0, 2259));
        expect($dataValue->getValue())->toBeInt();

        $client->disconnect();
    })->group('integration');

})->group('integration');

describe('Auto-generated certificate: daemon list output', function () {

    it('does not expose cert paths in list for auto-cert session', function () {
        $client = null;
        try {
            $client = TestHelper::createManagedClient();
            $client->setSecurityPolicy(SecurityPolicy::Basic256Sha256);
            $client->setSecurityMode(SecurityMode::SignAndEncrypt);
            $client->connect(TestHelper::ENDPOINT_AUTO_ACCEPT);

            $response = SocketConnection::send(TestHelper::SOCKET_PATH, ['command' => 'list']);
            expect($response['success'])->toBeTrue();

            $found = false;
            foreach ($response['data']['sessions'] as $session) {
                if ($session['id'] === $client->getSessionId()) {
                    $found = true;
                    expect($session['config'])->not->toHaveKey('clientCertPath');
                    expect($session['config'])->not->toHaveKey('clientKeyPath');
                    expect($session['config'])->not->toHaveKey('caCertPath');
                    expect($session['config'])->toHaveKey('securityPolicy');
                    expect($session['config'])->toHaveKey('securityMode');
                    break;
                }
            }
            expect($found)->toBeTrue('Auto-cert session not found in daemon list');
        } finally {
            TestHelper::safeDisconnect($client);
        }
    })->group('integration');

    it('explicit-cert and auto-cert sessions coexist in daemon', function () {
        $autoClient = null;
        $explicitClient = null;
        try {
            $autoClient = TestHelper::createManagedClient();
            $autoClient->setSecurityPolicy(SecurityPolicy::Basic256Sha256);
            $autoClient->setSecurityMode(SecurityMode::SignAndEncrypt);
            $autoClient->connect(TestHelper::ENDPOINT_AUTO_ACCEPT);

            $explicitClient = TestHelper::createManagedClient();
            $explicitClient->setSecurityPolicy(SecurityPolicy::Basic256Sha256);
            $explicitClient->setSecurityMode(SecurityMode::SignAndEncrypt);
            $explicitClient->setClientCertificate(
                TestHelper::getClientCertPath(),
                TestHelper::getClientKeyPath(),
                TestHelper::getCaCertPath(),
            );
            $explicitClient->connect(TestHelper::ENDPOINT_AUTO_ACCEPT);

            expect($autoClient->getSessionId())->not->toBe($explicitClient->getSessionId());

            // Both sessions should return valid data
            $v1 = $autoClient->read(NodeId::numeric(0, 2259))->getValue();
            $v2 = $explicitClient->read(NodeId::numeric(0, 2259))->getValue();
            expect($v1)->toBeInt();
            expect($v2)->toBeInt();

            // Check list: key presence differs per session type
            $response = SocketConnection::send(TestHelper::SOCKET_PATH, ['command' => 'list']);
            $configs = array_column($response['data']['sessions'], 'config', 'id');

            expect($configs[$autoClient->getSessionId()])->not->toHaveKey('clientCertPath');
            expect($configs[$explicitClient->getSessionId()])->toHaveKey('clientCertPath');
            expect($configs[$explicitClient->getSessionId()])->not->toHaveKey('clientKeyPath');
        } finally {
            TestHelper::safeDisconnect($autoClient);
            TestHelper::safeDisconnect($explicitClient);
        }
    })->group('integration');

})->group('integration');
