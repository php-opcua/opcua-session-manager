<?php

declare(strict_types=1);

use Gianfriaur\OpcuaSessionManager\Client\SocketConnection;
use Gianfriaur\OpcuaSessionManager\Tests\Integration\Helpers\TestHelper;

beforeAll(fn() => TestHelper::startDaemon());
afterAll(fn() => TestHelper::stopDaemon());

describe('Daemon', function () {

    it('responds to ping', function () {
        $response = SocketConnection::send(TestHelper::SOCKET_PATH, ['command' => 'ping']);

        expect($response['success'])->toBeTrue();
        expect($response['data']['status'])->toBe('ok');
        expect($response['data']['sessions'])->toBeInt();
        expect($response['data']['time'])->toBeFloat();
    })->group('integration');

    it('lists sessions when empty', function () {
        $response = SocketConnection::send(TestHelper::SOCKET_PATH, ['command' => 'list']);

        expect($response['success'])->toBeTrue();
        expect($response['data']['count'])->toBe(0);
        expect($response['data']['sessions'])->toBeArray()->toBeEmpty();
    })->group('integration');

    it('returns error for unknown command', function () {
        $response = SocketConnection::send(TestHelper::SOCKET_PATH, ['command' => 'foobar']);

        expect($response['success'])->toBeFalse();
        expect($response['error']['type'])->toBe('unknown_command');
    })->group('integration');

    it('returns error for invalid JSON', function () {
        // Send raw invalid JSON directly via socket
        $socket = stream_socket_client('unix://' . TestHelper::SOCKET_PATH, $errno, $errstr, 5);
        expect($socket)->not->toBeFalse();

        fwrite($socket, "not json\n");
        $response = '';
        while (!feof($socket)) {
            $chunk = fread($socket, 65536);
            if ($chunk === false) {
                break;
            }
            $response .= $chunk;
        }
        fclose($socket);

        $decoded = json_decode(trim($response), true);
        expect($decoded['success'])->toBeFalse();
        expect($decoded['error']['type'])->toBe('invalid_json');
    })->group('integration');

    it('opens a session to no-security server', function () {
        $response = SocketConnection::send(TestHelper::SOCKET_PATH, [
            'command' => 'open',
            'endpointUrl' => TestHelper::ENDPOINT_NO_SECURITY,
            'config' => [],
        ]);

        expect($response['success'])->toBeTrue();
        expect($response['data']['sessionId'])->toBeString()->not->toBeEmpty();

        $sessionId = $response['data']['sessionId'];

        // Verify it appears in list
        $listResponse = SocketConnection::send(TestHelper::SOCKET_PATH, ['command' => 'list']);
        expect($listResponse['data']['count'])->toBe(1);
        expect($listResponse['data']['sessions'][0]['id'])->toBe($sessionId);

        // Close it
        $closeResponse = SocketConnection::send(TestHelper::SOCKET_PATH, [
            'command' => 'close',
            'sessionId' => $sessionId,
        ]);
        expect($closeResponse['success'])->toBeTrue();

        // Verify it's gone
        $listResponse = SocketConnection::send(TestHelper::SOCKET_PATH, ['command' => 'list']);
        expect($listResponse['data']['count'])->toBe(0);
    })->group('integration');

    it('returns error when closing non-existent session', function () {
        $response = SocketConnection::send(TestHelper::SOCKET_PATH, [
            'command' => 'close',
            'sessionId' => 'nonexistent-id',
        ]);

        expect($response['success'])->toBeFalse();
        expect($response['error']['type'])->toBe('session_not_found');
    })->group('integration');

    it('returns error when querying non-existent session', function () {
        $response = SocketConnection::send(TestHelper::SOCKET_PATH, [
            'command' => 'query',
            'sessionId' => 'nonexistent-id',
            'method' => 'browse',
            'params' => [],
        ]);

        expect($response['success'])->toBeFalse();
        expect($response['error']['type'])->toBe('session_not_found');
    })->group('integration');

    it('handles multiple concurrent sessions', function () {
        // Open two sessions
        $response1 = SocketConnection::send(TestHelper::SOCKET_PATH, [
            'command' => 'open',
            'endpointUrl' => TestHelper::ENDPOINT_NO_SECURITY,
            'config' => [],
        ]);
        $response2 = SocketConnection::send(TestHelper::SOCKET_PATH, [
            'command' => 'open',
            'endpointUrl' => TestHelper::ENDPOINT_NO_SECURITY,
            'config' => [],
        ]);

        expect($response1['success'])->toBeTrue();
        expect($response2['success'])->toBeTrue();

        $id1 = $response1['data']['sessionId'];
        $id2 = $response2['data']['sessionId'];
        expect($id1)->not->toBe($id2);

        // Both appear in list
        $listResponse = SocketConnection::send(TestHelper::SOCKET_PATH, ['command' => 'list']);
        expect($listResponse['data']['count'])->toBe(2);

        // Close both
        SocketConnection::send(TestHelper::SOCKET_PATH, ['command' => 'close', 'sessionId' => $id1]);
        SocketConnection::send(TestHelper::SOCKET_PATH, ['command' => 'close', 'sessionId' => $id2]);
    })->group('integration');

})->group('integration');
