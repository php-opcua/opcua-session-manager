<?php

declare(strict_types=1);

use Gianfriaur\OpcuaPhpClient\Types\BuiltinType;
use Gianfriaur\OpcuaPhpClient\Types\NodeId;
use Gianfriaur\OpcuaPhpClient\Types\StatusCode;
use Gianfriaur\OpcuaSessionManager\Client\SocketConnection;
use Gianfriaur\OpcuaSessionManager\Serialization\TypeSerializer;
use Gianfriaur\OpcuaSessionManager\Tests\Integration\Helpers\TestHelper;

beforeAll(fn() => TestHelper::startDaemon());
afterAll(fn() => TestHelper::stopDaemon());

describe('Session Persistence', function () {

    it('session survives across multiple ManagedClient instances', function () {
        // Simulate what happens across PHP requests:
        // 1st "request": open session, get session ID
        $client1 = TestHelper::createManagedClient();
        $client1->connect(TestHelper::ENDPOINT_NO_SECURITY);
        $sessionId = $client1->getSessionId();

        // Read something with client1
        $dv1 = $client1->read(NodeId::numeric(0, 2259));
        expect($dv1->statusCode)->toBe(StatusCode::Good);

        // Don't disconnect — just let $client1 go out of scope (simulating end of request)
        unset($client1);

        // 2nd "request": the session is still alive in the daemon
        $listResponse = SocketConnection::send(TestHelper::SOCKET_PATH, ['command' => 'list']);
        expect($listResponse['data']['count'])->toBeGreaterThanOrEqual(1);

        $found = false;
        foreach ($listResponse['data']['sessions'] as $session) {
            if ($session['id'] === $sessionId) {
                $found = true;
                break;
            }
        }
        expect($found)->toBeTrue();

        // We can still use the session directly via IPC
        $queryResponse = SocketConnection::send(TestHelper::SOCKET_PATH, [
            'command' => 'query',
            'sessionId' => $sessionId,
            'method' => 'read',
            'params' => [
                ['ns' => 0, 'id' => 2259, 'type' => 'numeric'],
                13,
            ],
        ]);
        expect($queryResponse['success'])->toBeTrue();
        expect($queryResponse['data']['statusCode'])->toBe(StatusCode::Good);

        // Cleanup
        SocketConnection::send(TestHelper::SOCKET_PATH, [
            'command' => 'close',
            'sessionId' => $sessionId,
        ]);
    })->group('integration');

    it('session state persists writes across clients', function () {
        // Open session and write
        $client1 = TestHelper::connectNoSecurity();
        $nodeId = TestHelper::browseToNode($client1, ['TestServer', 'DataTypes', 'Scalar', 'StringValue']);
        $sessionId = $client1->getSessionId();

        $testValue = 'persistence-test-' . time();
        $statusCode = $client1->write($nodeId, $testValue, BuiltinType::String);
        expect(StatusCode::isGood($statusCode))->toBeTrue();
        unset($client1);

        // Read back via the same daemon session using raw IPC
        $serializedNodeId = (new TypeSerializer())->serializeNodeId($nodeId);
        $response = SocketConnection::send(TestHelper::SOCKET_PATH, [
            'command' => 'query',
            'sessionId' => $sessionId,
            'method' => 'read',
            'params' => [$serializedNodeId, 13],
        ]);

        expect($response['success'])->toBeTrue();
        expect($response['data']['value'])->toBe($testValue);

        // Cleanup
        SocketConnection::send(TestHelper::SOCKET_PATH, [
            'command' => 'close',
            'sessionId' => $sessionId,
        ]);
    })->group('integration');

    it('multiple independent sessions work simultaneously', function () {
        $client1 = null;
        $client2 = null;
        try {
            $client1 = TestHelper::connectNoSecurity();
            $client2 = TestHelper::connectNoSecurity();

            expect($client1->getSessionId())->not->toBe($client2->getSessionId());

            // Both can independently read
            $dv1 = $client1->read(NodeId::numeric(0, 2259));
            $dv2 = $client2->read(NodeId::numeric(0, 2259));
            expect($dv1->statusCode)->toBe(StatusCode::Good);
            expect($dv2->statusCode)->toBe(StatusCode::Good);

            // Both can independently browse
            $refs1 = $client1->browse(NodeId::numeric(0, 85));
            $refs2 = $client2->browse(NodeId::numeric(0, 85));
            expect($refs1)->toBeArray()->not->toBeEmpty();
            expect($refs2)->toBeArray()->not->toBeEmpty();
        } finally {
            TestHelper::safeDisconnect($client1);
            TestHelper::safeDisconnect($client2);
        }
    })->group('integration');

    it('disconnecting one session does not affect another', function () {
        $client1 = null;
        $client2 = null;
        try {
            $client1 = TestHelper::connectNoSecurity();
            $client2 = TestHelper::connectNoSecurity();

            // Disconnect client1
            $client1->disconnect();
            $client1 = null;

            // Client2 should still work
            $dv = $client2->read(NodeId::numeric(0, 2259));
            expect($dv->statusCode)->toBe(StatusCode::Good);
        } finally {
            TestHelper::safeDisconnect($client1);
            TestHelper::safeDisconnect($client2);
        }
    })->group('integration');

})->group('integration');
