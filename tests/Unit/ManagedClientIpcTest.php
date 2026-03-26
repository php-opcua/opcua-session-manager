<?php

declare(strict_types=1);

use PhpOpcua\Client\Exception\ConnectionException;
use PhpOpcua\Client\Exception\ServiceException;
use PhpOpcua\Client\Types\BrowseDirection;
use PhpOpcua\Client\Types\BrowsePathResult;
use PhpOpcua\Client\Types\BrowseResultSet;
use PhpOpcua\Client\Types\BuiltinType;
use PhpOpcua\Client\Types\CallResult;
use PhpOpcua\Client\Types\DataValue;
use PhpOpcua\Client\Types\EndpointDescription;
use PhpOpcua\Client\Types\MonitoredItemResult;
use PhpOpcua\Client\Types\NodeClass;
use PhpOpcua\Client\Types\NodeId;
use PhpOpcua\Client\Types\PublishResult;
use PhpOpcua\Client\Types\SubscriptionResult;
use PhpOpcua\Client\Types\TransferResult;
use PhpOpcua\SessionManager\Client\ManagedClient;
use PhpOpcua\SessionManager\Exception\DaemonException;

function startFakeDaemon(array $responses): array
{
    $socketPath = sys_get_temp_dir() . '/opcua_mc_test_' . bin2hex(random_bytes(4)) . '.sock';

    if (file_exists($socketPath)) {
        unlink($socketPath);
    }

    $server = stream_socket_server("unix://{$socketPath}", $errorCode, $errorMessage);
    if ($server === false) {
        throw new RuntimeException("Cannot create test server: [{$errorCode}] {$errorMessage}");
    }

    $pid = pcntl_fork();
    if ($pid === -1) {
        throw new RuntimeException('Cannot fork');
    }

    if ($pid === 0) {
        foreach ($responses as $responseData) {
            $conn = stream_socket_accept($server, 5);
            if ($conn === false) {
                break;
            }

            $data = '';
            while (!str_contains($data, "\n")) {
                $chunk = fread($conn, 65536);
                if ($chunk === false || $chunk === '') {
                    break;
                }
                $data .= $chunk;
            }

            fwrite($conn, json_encode($responseData) . "\n");
            fclose($conn);
        }
        fclose($server);
        if (file_exists($socketPath)) {
            unlink($socketPath);
        }
        exit(0);
    }

    fclose($server);
    usleep(50_000);

    return ['socketPath' => $socketPath, 'pid' => $pid];
}

function stopFakeDaemon(array $server): void
{
    pcntl_waitpid($server['pid'], $status, WNOHANG);
    posix_kill($server['pid'], SIGTERM);
    pcntl_waitpid($server['pid'], $status);
    if (file_exists($server['socketPath'])) {
        unlink($server['socketPath']);
    }
}

function connectFakeClient(string $socketPath): ManagedClient
{
    $client = new ManagedClient($socketPath, timeout: 2.0);

    $ref = new ReflectionProperty(ManagedClient::class, 'sessionId');
    $ref->setValue($client, 'fake-session-id');

    return $client;
}

describe('ManagedClient IPC', function () {

    describe('Error mapping in sendCommand', function () {

        it('maps ConnectionException from daemon', function () {
            $daemon = startFakeDaemon([
                ['success' => false, 'error' => ['type' => 'ConnectionException', 'message' => 'Connection lost']],
            ]);

            try {
                $client = connectFakeClient($daemon['socketPath']);
                expect(fn() => $client->read('i=2259'))->toThrow(ConnectionException::class, 'Connection lost');
            } finally {
                stopFakeDaemon($daemon);
            }
        });

        it('maps ServiceException from daemon', function () {
            $daemon = startFakeDaemon([
                ['success' => false, 'error' => ['type' => 'ServiceException', 'message' => 'Bad node']],
            ]);

            try {
                $client = connectFakeClient($daemon['socketPath']);
                expect(fn() => $client->read('i=2259'))->toThrow(ServiceException::class, 'Bad node');
            } finally {
                stopFakeDaemon($daemon);
            }
        });

        it('maps session_not_found from daemon', function () {
            $daemon = startFakeDaemon([
                ['success' => false, 'error' => ['type' => 'session_not_found', 'message' => 'abc123']],
            ]);

            try {
                $client = connectFakeClient($daemon['socketPath']);
                expect(fn() => $client->read('i=2259'))->toThrow(ConnectionException::class, 'Session expired');
            } finally {
                stopFakeDaemon($daemon);
            }
        });

        it('maps unknown error type as DaemonException', function () {
            $daemon = startFakeDaemon([
                ['success' => false, 'error' => ['type' => 'WeirdError', 'message' => 'something']],
            ]);

            try {
                $client = connectFakeClient($daemon['socketPath']);
                expect(fn() => $client->read('i=2259'))->toThrow(DaemonException::class, '[WeirdError]');
            } finally {
                stopFakeDaemon($daemon);
            }
        });

        it('includes auth token when configured', function () {
            $daemon = startFakeDaemon([
                ['success' => true, 'data' => ['value' => 0, 'type' => 6, 'statusCode' => 0, 'sourceTimestamp' => null, 'serverTimestamp' => null, 'dimensions' => null]],
            ]);

            try {
                $client = new ManagedClient($daemon['socketPath'], timeout: 2.0, authToken: 'my-secret');
                $ref = new ReflectionProperty(ManagedClient::class, 'sessionId');
                $ref->setValue($client, 'fake-session-id');

                $dv = $client->read('i=2259');
                expect($dv->statusCode)->toBe(0);
            } finally {
                stopFakeDaemon($daemon);
            }
        });

    });

    describe('Methods with deserialization', function () {

        it('getEndpoints deserializes EndpointDescription[]', function () {
            $daemon = startFakeDaemon([
                ['success' => true, 'data' => [
                    ['endpointUrl' => 'opc.tcp://localhost:4840', 'serverCertificate' => null, 'securityMode' => 1, 'securityPolicyUri' => 'None', 'userIdentityTokens' => [], 'transportProfileUri' => '', 'securityLevel' => 0],
                ]],
            ]);

            try {
                $client = connectFakeClient($daemon['socketPath']);
                $endpoints = $client->getEndpoints('opc.tcp://localhost:4840');

                expect($endpoints)->toHaveCount(1);
                expect($endpoints[0])->toBeInstanceOf(EndpointDescription::class);
                expect($endpoints[0]->endpointUrl)->toBe('opc.tcp://localhost:4840');
            } finally {
                stopFakeDaemon($daemon);
            }
        });

        it('browseNext deserializes BrowseResultSet', function () {
            $daemon = startFakeDaemon([
                ['success' => true, 'data' => ['references' => [], 'continuationPoint' => null]],
            ]);

            try {
                $client = connectFakeClient($daemon['socketPath']);
                $result = $client->browseNext('abc123');

                expect($result)->toBeInstanceOf(BrowseResultSet::class);
            } finally {
                stopFakeDaemon($daemon);
            }
        });

        it('createSubscription deserializes SubscriptionResult', function () {
            $daemon = startFakeDaemon([
                ['success' => true, 'data' => ['subscriptionId' => 1, 'revisedPublishingInterval' => 500.0, 'revisedLifetimeCount' => 2400, 'revisedMaxKeepAliveCount' => 10]],
            ]);

            try {
                $client = connectFakeClient($daemon['socketPath']);
                $result = $client->createSubscription();

                expect($result)->toBeInstanceOf(SubscriptionResult::class);
                expect($result->subscriptionId)->toBe(1);
            } finally {
                stopFakeDaemon($daemon);
            }
        });

        it('createMonitoredItems deserializes MonitoredItemResult[]', function () {
            $daemon = startFakeDaemon([
                ['success' => true, 'data' => [
                    ['statusCode' => 0, 'monitoredItemId' => 1, 'revisedSamplingInterval' => 250.0, 'revisedQueueSize' => 1],
                ]],
            ]);

            try {
                $client = connectFakeClient($daemon['socketPath']);
                $results = $client->createMonitoredItems(1, [['nodeId' => 'i=2259']]);

                expect($results)->toHaveCount(1);
                expect($results[0])->toBeInstanceOf(MonitoredItemResult::class);
            } finally {
                stopFakeDaemon($daemon);
            }
        });

        it('createEventMonitoredItem deserializes MonitoredItemResult', function () {
            $daemon = startFakeDaemon([
                ['success' => true, 'data' => ['statusCode' => 0, 'monitoredItemId' => 1, 'revisedSamplingInterval' => 250.0, 'revisedQueueSize' => 1]],
            ]);

            try {
                $client = connectFakeClient($daemon['socketPath']);
                $result = $client->createEventMonitoredItem(1, 'i=2253');

                expect($result)->toBeInstanceOf(MonitoredItemResult::class);
            } finally {
                stopFakeDaemon($daemon);
            }
        });

        it('publish deserializes PublishResult', function () {
            $daemon = startFakeDaemon([
                ['success' => true, 'data' => ['subscriptionId' => 1, 'sequenceNumber' => 1, 'moreNotifications' => false, 'notifications' => [], 'availableSequenceNumbers' => []]],
            ]);

            try {
                $client = connectFakeClient($daemon['socketPath']);
                $result = $client->publish();

                expect($result)->toBeInstanceOf(PublishResult::class);
            } finally {
                stopFakeDaemon($daemon);
            }
        });

        it('transferSubscriptions deserializes TransferResult[]', function () {
            $daemon = startFakeDaemon([
                ['success' => true, 'data' => [
                    ['statusCode' => 0, 'availableSequenceNumbers' => [1, 2]],
                ]],
            ]);

            try {
                $client = connectFakeClient($daemon['socketPath']);
                $results = $client->transferSubscriptions([1]);

                expect($results)->toHaveCount(1);
                expect($results[0])->toBeInstanceOf(TransferResult::class);
            } finally {
                stopFakeDaemon($daemon);
            }
        });

        it('republish deserializes publishTime', function () {
            $daemon = startFakeDaemon([
                ['success' => true, 'data' => ['sequenceNumber' => 1, 'publishTime' => '2024-06-15T12:00:00+00:00', 'notifications' => []]],
            ]);

            try {
                $client = connectFakeClient($daemon['socketPath']);
                $result = $client->republish(1, 42);

                expect($result['publishTime'])->toBeInstanceOf(DateTimeImmutable::class);
            } finally {
                stopFakeDaemon($daemon);
            }
        });

        it('republish handles null publishTime', function () {
            $daemon = startFakeDaemon([
                ['success' => true, 'data' => ['sequenceNumber' => 1, 'publishTime' => null, 'notifications' => []]],
            ]);

            try {
                $client = connectFakeClient($daemon['socketPath']);
                $result = $client->republish(1, 42);

                expect($result['publishTime'])->toBeNull();
            } finally {
                stopFakeDaemon($daemon);
            }
        });

        it('call deserializes CallResult', function () {
            $daemon = startFakeDaemon([
                ['success' => true, 'data' => ['statusCode' => 0, 'inputArgumentResults' => [], 'outputArguments' => [['type' => 11, 'value' => 7.0, 'dimensions' => null]]]],
            ]);

            try {
                $client = connectFakeClient($daemon['socketPath']);
                $result = $client->call('i=2253', 'i=11492');

                expect($result)->toBeInstanceOf(CallResult::class);
                expect($result->outputArguments[0]->value)->toBe(7);
            } finally {
                stopFakeDaemon($daemon);
            }
        });

        it('translateBrowsePaths deserializes BrowsePathResult[]', function () {
            $daemon = startFakeDaemon([
                ['success' => true, 'data' => [
                    ['statusCode' => 0, 'targets' => [['targetId' => ['ns' => 0, 'id' => 2253, 'type' => 'numeric'], 'remainingPathIndex' => 0]]],
                ]],
            ]);

            try {
                $client = connectFakeClient($daemon['socketPath']);
                $results = $client->translateBrowsePaths([
                    ['startingNodeId' => NodeId::numeric(0, 84), 'relativePath' => [
                        ['targetName' => new \PhpOpcua\Client\Types\QualifiedName(0, 'Objects')],
                    ]],
                ]);

                expect($results)->toHaveCount(1);
                expect($results[0])->toBeInstanceOf(BrowsePathResult::class);
            } finally {
                stopFakeDaemon($daemon);
            }
        });

        it('historyReadRaw deserializes DataValue[]', function () {
            $daemon = startFakeDaemon([
                ['success' => true, 'data' => [
                    ['value' => 42, 'type' => 6, 'statusCode' => 0, 'sourceTimestamp' => '2024-01-01T00:00:00+00:00', 'serverTimestamp' => null, 'dimensions' => null],
                ]],
            ]);

            try {
                $client = connectFakeClient($daemon['socketPath']);
                $results = $client->historyReadRaw('ns=2;i=1001', new DateTimeImmutable('-1 hour'), new DateTimeImmutable());

                expect($results)->toHaveCount(1);
                expect($results[0])->toBeInstanceOf(DataValue::class);
            } finally {
                stopFakeDaemon($daemon);
            }
        });

        it('historyReadProcessed deserializes DataValue[]', function () {
            $daemon = startFakeDaemon([
                ['success' => true, 'data' => [
                    ['value' => 3.14, 'type' => 11, 'statusCode' => 0, 'sourceTimestamp' => null, 'serverTimestamp' => null, 'dimensions' => null],
                ]],
            ]);

            try {
                $client = connectFakeClient($daemon['socketPath']);
                $results = $client->historyReadProcessed('ns=2;i=1001', new DateTimeImmutable('-1 hour'), new DateTimeImmutable(), 3600000.0, NodeId::numeric(0, 2342));

                expect($results)->toHaveCount(1);
                expect($results[0])->toBeInstanceOf(DataValue::class);
            } finally {
                stopFakeDaemon($daemon);
            }
        });

        it('historyReadAtTime deserializes DataValue[]', function () {
            $daemon = startFakeDaemon([
                ['success' => true, 'data' => [
                    ['value' => 100, 'type' => 6, 'statusCode' => 0, 'sourceTimestamp' => null, 'serverTimestamp' => null, 'dimensions' => null],
                ]],
            ]);

            try {
                $client = connectFakeClient($daemon['socketPath']);
                $results = $client->historyReadAtTime('ns=2;i=1001', [new DateTimeImmutable()]);

                expect($results)->toHaveCount(1);
                expect($results[0])->toBeInstanceOf(DataValue::class);
            } finally {
                stopFakeDaemon($daemon);
            }
        });

        it('discoverDataTypes returns int', function () {
            $daemon = startFakeDaemon([
                ['success' => true, 'data' => 5],
            ]);

            try {
                $client = connectFakeClient($daemon['socketPath']);
                $count = $client->discoverDataTypes();

                expect($count)->toBe(5);
            } finally {
                stopFakeDaemon($daemon);
            }
        });

        it('invalidateCache forwards to daemon', function () {
            $daemon = startFakeDaemon([
                ['success' => true, 'data' => null],
            ]);

            try {
                $client = connectFakeClient($daemon['socketPath']);
                $client->invalidateCache('i=85');
                expect(true)->toBeTrue();
            } finally {
                stopFakeDaemon($daemon);
            }
        });

        it('flushCache forwards to daemon', function () {
            $daemon = startFakeDaemon([
                ['success' => true, 'data' => null],
            ]);

            try {
                $client = connectFakeClient($daemon['socketPath']);
                $client->flushCache();
                expect(true)->toBeTrue();
            } finally {
                stopFakeDaemon($daemon);
            }
        });

        it('connect stores sessionId from daemon response', function () {
            $daemon = startFakeDaemon([
                ['success' => true, 'data' => ['sessionId' => 'test-session-123']],
            ]);

            try {
                $client = new ManagedClient($daemon['socketPath'], timeout: 2.0);
                $client->connect('opc.tcp://localhost:4840');

                expect($client->getSessionId())->toBe('test-session-123');
            } finally {
                stopFakeDaemon($daemon);
            }
        });

        it('disconnect clears sessionId', function () {
            $daemon = startFakeDaemon([
                ['success' => true, 'data' => null],
            ]);

            try {
                $client = connectFakeClient($daemon['socketPath']);
                $client->disconnect();

                expect($client->getSessionId())->toBeNull();
            } finally {
                stopFakeDaemon($daemon);
            }
        });

        it('writeMulti returns status codes', function () {
            $daemon = startFakeDaemon([
                ['success' => true, 'data' => [0, 0]],
            ]);

            try {
                $client = connectFakeClient($daemon['socketPath']);
                $results = $client->writeMulti([
                    ['nodeId' => 'ns=2;i=1001', 'value' => 42, 'type' => BuiltinType::Int32],
                    ['nodeId' => 'ns=2;i=1002', 'value' => 3.14, 'type' => BuiltinType::Double],
                ]);

                expect($results)->toBe([0, 0]);
            } finally {
                stopFakeDaemon($daemon);
            }
        });

        it('deleteMonitoredItems returns status codes', function () {
            $daemon = startFakeDaemon([
                ['success' => true, 'data' => [0]],
            ]);

            try {
                $client = connectFakeClient($daemon['socketPath']);
                $results = $client->deleteMonitoredItems(1, [1]);

                expect($results)->toBe([0]);
            } finally {
                stopFakeDaemon($daemon);
            }
        });

        it('deleteSubscription returns status code', function () {
            $daemon = startFakeDaemon([
                ['success' => true, 'data' => 0],
            ]);

            try {
                $client = connectFakeClient($daemon['socketPath']);
                $result = $client->deleteSubscription(1);

                expect($result)->toBe(0);
            } finally {
                stopFakeDaemon($daemon);
            }
        });

        it('getServerMaxNodesPerRead returns value', function () {
            $daemon = startFakeDaemon([
                ['success' => true, 'data' => 1000],
            ]);

            try {
                $client = connectFakeClient($daemon['socketPath']);
                $result = $client->getServerMaxNodesPerRead();

                expect($result)->toBe(1000);
            } finally {
                stopFakeDaemon($daemon);
            }
        });

        it('getServerMaxNodesPerWrite returns value', function () {
            $daemon = startFakeDaemon([
                ['success' => true, 'data' => 500],
            ]);

            try {
                $client = connectFakeClient($daemon['socketPath']);
                $result = $client->getServerMaxNodesPerWrite();

                expect($result)->toBe(500);
            } finally {
                stopFakeDaemon($daemon);
            }
        });

    });

    describe('Edge case branches', function () {

        it('translateBrowsePaths serializes referenceTypeId when present', function () {
            $daemon = startFakeDaemon([
                ['success' => true, 'data' => [
                    ['statusCode' => 0, 'targets' => [['targetId' => ['ns' => 0, 'id' => 2253, 'type' => 'numeric'], 'remainingPathIndex' => 0]]],
                ]],
            ]);

            try {
                $client = connectFakeClient($daemon['socketPath']);
                $results = $client->translateBrowsePaths([
                    ['startingNodeId' => NodeId::numeric(0, 84), 'relativePath' => [
                        [
                            'referenceTypeId' => NodeId::numeric(0, 35),
                            'isInverse' => false,
                            'includeSubtypes' => true,
                            'targetName' => new \PhpOpcua\Client\Types\QualifiedName(0, 'Objects'),
                        ],
                    ]],
                ]);

                expect($results)->toHaveCount(1);
                expect($results[0])->toBeInstanceOf(BrowsePathResult::class);
            } finally {
                stopFakeDaemon($daemon);
            }
        });

        it('resolveNodeId with string startingNodeId', function () {
            $daemon = startFakeDaemon([
                ['success' => true, 'data' => ['ns' => 0, 'id' => 2253, 'type' => 'numeric']],
            ]);

            try {
                $client = connectFakeClient($daemon['socketPath']);
                $nodeId = $client->resolveNodeId('/Objects/Server', 'i=85');

                expect($nodeId)->toBeInstanceOf(NodeId::class);
                expect($nodeId->identifier)->toBe(2253);
            } finally {
                stopFakeDaemon($daemon);
            }
        });

    });

});
