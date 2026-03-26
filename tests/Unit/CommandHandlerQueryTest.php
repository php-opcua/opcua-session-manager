<?php

declare(strict_types=1);

use PhpOpcua\Client\Client;
use PhpOpcua\Client\Exception\ConnectionException;
use PhpOpcua\Client\Types\BrowseResultSet;
use PhpOpcua\Client\Types\CallResult;
use PhpOpcua\Client\Types\ConnectionState;
use PhpOpcua\Client\Types\DataValue;
use PhpOpcua\Client\Types\MonitoredItemResult;
use PhpOpcua\Client\Types\PublishResult;
use PhpOpcua\Client\Types\SubscriptionResult;
use PhpOpcua\Client\Types\TransferResult;
use PhpOpcua\Client\Types\Variant;
use PhpOpcua\Client\Types\BuiltinType;
use PhpOpcua\SessionManager\Daemon\CommandHandler;
use PhpOpcua\SessionManager\Daemon\Session;
use PhpOpcua\SessionManager\Daemon\SessionStore;

describe('CommandHandler Query', function () {

    beforeEach(function () {
        $this->store = new SessionStore();
        $this->handler = new CommandHandler($this->store);
    });

    describe('Query dispatch', function () {

        it('queries isConnected', function () {
            $client = $this->createStub(Client::class);
            $client->method('isConnected')->willReturn(true);
            $session = new Session('s1', $client, 'opc.tcp://localhost:4840', [], microtime(true));
            $this->store->create($session);

            $result = $this->handler->handle([
                'command' => 'query', 'sessionId' => 's1', 'method' => 'isConnected', 'params' => [],
            ]);

            expect($result['success'])->toBeTrue();
            expect($result['data'])->toBeTrue();
        });

        it('queries getConnectionState', function () {
            $client = $this->createStub(Client::class);
            $client->method('getConnectionState')->willReturn(ConnectionState::Connected);
            $session = new Session('s1', $client, 'opc.tcp://localhost:4840', [], microtime(true));
            $this->store->create($session);

            $result = $this->handler->handle([
                'command' => 'query', 'sessionId' => 's1', 'method' => 'getConnectionState', 'params' => [],
            ]);

            expect($result['success'])->toBeTrue();
            expect($result['data'])->toBe('Connected');
        });

        it('queries getTimeout', function () {
            $client = $this->createStub(Client::class);
            $client->method('getTimeout')->willReturn(10.0);
            $session = new Session('s1', $client, 'opc.tcp://localhost:4840', [], microtime(true));
            $this->store->create($session);

            $result = $this->handler->handle([
                'command' => 'query', 'sessionId' => 's1', 'method' => 'getTimeout', 'params' => [],
            ]);

            expect($result['success'])->toBeTrue();
            expect($result['data'])->toBe(10.0);
        });

        it('queries read', function () {
            $client = $this->createStub(Client::class);
            $client->method('read')->willReturn(new DataValue(new Variant(BuiltinType::Int32, 42), 0));
            $session = new Session('s1', $client, 'opc.tcp://localhost:4840', [], microtime(true));
            $this->store->create($session);

            $result = $this->handler->handle([
                'command' => 'query', 'sessionId' => 's1', 'method' => 'read',
                'params' => [['ns' => 0, 'id' => 2259, 'type' => 'numeric'], 13],
            ]);

            expect($result['success'])->toBeTrue();
            expect($result['data']['value'])->toBe(42);
        });

        it('queries write', function () {
            $client = $this->createStub(Client::class);
            $client->method('write')->willReturn(0);
            $session = new Session('s1', $client, 'opc.tcp://localhost:4840', [], microtime(true));
            $this->store->create($session);

            $result = $this->handler->handle([
                'command' => 'query', 'sessionId' => 's1', 'method' => 'write',
                'params' => [['ns' => 2, 'id' => 1001, 'type' => 'numeric'], 42, BuiltinType::Int32->value],
            ]);

            expect($result['success'])->toBeTrue();
            expect($result['data'])->toBe(0);
        });

        it('queries call', function () {
            $client = $this->createStub(Client::class);
            $client->method('call')->willReturn(new CallResult(0, [], [new Variant(BuiltinType::Double, 7.0)]));
            $session = new Session('s1', $client, 'opc.tcp://localhost:4840', [], microtime(true));
            $this->store->create($session);

            $result = $this->handler->handle([
                'command' => 'query', 'sessionId' => 's1', 'method' => 'call',
                'params' => [
                    ['ns' => 2, 'id' => 100, 'type' => 'numeric'],
                    ['ns' => 2, 'id' => 200, 'type' => 'numeric'],
                    [['type' => BuiltinType::Double->value, 'value' => 3.0, 'dimensions' => null]],
                ],
            ]);

            expect($result['success'])->toBeTrue();
            expect($result['data']['statusCode'])->toBe(0);
        });

        it('queries browseNext', function () {
            $client = $this->createStub(Client::class);
            $client->method('browseNext')->willReturn(new BrowseResultSet([], null));
            $session = new Session('s1', $client, 'opc.tcp://localhost:4840', [], microtime(true));
            $this->store->create($session);

            $result = $this->handler->handle([
                'command' => 'query', 'sessionId' => 's1', 'method' => 'browseNext',
                'params' => ['abc123'],
            ]);

            expect($result['success'])->toBeTrue();
            expect($result['data']['references'])->toBe([]);
        });

        it('queries deleteSubscription', function () {
            $client = $this->createStub(Client::class);
            $client->method('deleteSubscription')->willReturn(0);
            $session = new Session('s1', $client, 'opc.tcp://localhost:4840', [], microtime(true));
            $this->store->create($session);

            $result = $this->handler->handle([
                'command' => 'query', 'sessionId' => 's1', 'method' => 'deleteSubscription',
                'params' => [1],
            ]);

            expect($result['success'])->toBeTrue();
            expect($result['data'])->toBe(0);
        });

        it('queries publish', function () {
            $client = $this->createStub(Client::class);
            $client->method('publish')->willReturn(new PublishResult(1, 1, false, [], []));
            $session = new Session('s1', $client, 'opc.tcp://localhost:4840', [], microtime(true));
            $this->store->create($session);

            $result = $this->handler->handle([
                'command' => 'query', 'sessionId' => 's1', 'method' => 'publish',
                'params' => [[]],
            ]);

            expect($result['success'])->toBeTrue();
            expect($result['data']['subscriptionId'])->toBe(1);
        });

        it('queries transferSubscriptions', function () {
            $client = $this->createStub(Client::class);
            $client->method('transferSubscriptions')->willReturn([new TransferResult(0, [1, 2])]);
            $session = new Session('s1', $client, 'opc.tcp://localhost:4840', [], microtime(true));
            $this->store->create($session);

            $result = $this->handler->handle([
                'command' => 'query', 'sessionId' => 's1', 'method' => 'transferSubscriptions',
                'params' => [[1], false],
            ]);

            expect($result['success'])->toBeTrue();
        });

        it('queries republish', function () {
            $client = $this->createStub(Client::class);
            $client->method('republish')->willReturn(['sequenceNumber' => 1, 'publishTime' => null, 'notifications' => []]);
            $session = new Session('s1', $client, 'opc.tcp://localhost:4840', [], microtime(true));
            $this->store->create($session);

            $result = $this->handler->handle([
                'command' => 'query', 'sessionId' => 's1', 'method' => 'republish',
                'params' => [1, 42],
            ]);

            expect($result['success'])->toBeTrue();
            expect($result['data']['sequenceNumber'])->toBe(1);
        });

        it('queries flushCache', function () {
            $client = $this->createStub(Client::class);
            $session = new Session('s1', $client, 'opc.tcp://localhost:4840', [], microtime(true));
            $this->store->create($session);

            $result = $this->handler->handle([
                'command' => 'query', 'sessionId' => 's1', 'method' => 'flushCache',
                'params' => [],
            ]);

            expect($result['success'])->toBeTrue();
        });

        it('queries discoverDataTypes', function () {
            $client = $this->createStub(Client::class);
            $client->method('discoverDataTypes')->willReturn(5);
            $session = new Session('s1', $client, 'opc.tcp://localhost:4840', [], microtime(true));
            $this->store->create($session);

            $result = $this->handler->handle([
                'command' => 'query', 'sessionId' => 's1', 'method' => 'discoverDataTypes',
                'params' => [null, true],
            ]);

            expect($result['success'])->toBeTrue();
            expect($result['data'])->toBe(5);
        });

        it('handles reconnect (void method)', function () {
            $client = $this->createStub(Client::class);
            $session = new Session('s1', $client, 'opc.tcp://localhost:4840', [], microtime(true));
            $this->store->create($session);

            $result = $this->handler->handle([
                'command' => 'query', 'sessionId' => 's1', 'method' => 'reconnect',
                'params' => [],
            ]);

            expect($result['success'])->toBeTrue();
        });

    });

    describe('Subscription tracking', function () {

        it('tracks createSubscription result', function () {
            $client = $this->createStub(Client::class);
            $client->method('createSubscription')->willReturn(new SubscriptionResult(42, 500.0, 2400, 10));
            $session = new Session('s1', $client, 'opc.tcp://localhost:4840', [], microtime(true));
            $this->store->create($session);

            $this->handler->handle([
                'command' => 'query', 'sessionId' => 's1', 'method' => 'createSubscription',
                'params' => [500.0, 2400, 10, 0, true, 0],
            ]);

            expect($session->getSubscriptionIds())->toBe([42]);
        });

        it('untracks subscription on successful deleteSubscription', function () {
            $client = $this->createStub(Client::class);
            $client->method('createSubscription')->willReturn(new SubscriptionResult(42, 500.0, 2400, 10));
            $client->method('deleteSubscription')->willReturn(0);
            $session = new Session('s1', $client, 'opc.tcp://localhost:4840', [], microtime(true));
            $this->store->create($session);

            $this->handler->handle([
                'command' => 'query', 'sessionId' => 's1', 'method' => 'createSubscription',
                'params' => [500.0, 2400, 10, 0, true, 0],
            ]);

            $this->handler->handle([
                'command' => 'query', 'sessionId' => 's1', 'method' => 'deleteSubscription',
                'params' => [42],
            ]);

            expect($session->getSubscriptionIds())->toBe([]);
        });

        it('does not untrack subscription on failed deleteSubscription', function () {
            $client = $this->createStub(Client::class);
            $client->method('createSubscription')->willReturn(new SubscriptionResult(42, 500.0, 2400, 10));
            $client->method('deleteSubscription')->willReturn(0x80000000);
            $session = new Session('s1', $client, 'opc.tcp://localhost:4840', [], microtime(true));
            $this->store->create($session);

            $this->handler->handle([
                'command' => 'query', 'sessionId' => 's1', 'method' => 'createSubscription',
                'params' => [500.0, 2400, 10, 0, true, 0],
            ]);

            $this->handler->handle([
                'command' => 'query', 'sessionId' => 's1', 'method' => 'deleteSubscription',
                'params' => [42],
            ]);

            expect($session->getSubscriptionIds())->toBe([42]);
        });

    });

    describe('Session recovery', function () {

        it('recovers from ConnectionException by reconnecting', function () {
            $callCount = 0;
            $client = $this->createMock(Client::class);
            $client->method('getTimeout')->willReturnCallback(function () use (&$callCount) {
                $callCount++;
                if ($callCount === 1) {
                    throw new ConnectionException('Connection lost');
                }
                return 5.0;
            });
            $client->expects($this->once())->method('reconnect');
            $session = new Session('s1', $client, 'opc.tcp://localhost:4840', [], microtime(true));
            $this->store->create($session);

            $result = $this->handler->handle([
                'command' => 'query', 'sessionId' => 's1', 'method' => 'getTimeout', 'params' => [],
            ]);

            expect($result['success'])->toBeTrue();
            expect($result['data'])->toBe(5.0);
        });

        it('propagates error when reconnect fails', function () {
            $client = $this->createMock(Client::class);
            $client->method('getTimeout')->willThrowException(new ConnectionException('Connection lost'));
            $client->method('reconnect')->willThrowException(new ConnectionException('Reconnect failed'));
            $session = new Session('s1', $client, 'opc.tcp://localhost:4840', [], microtime(true));
            $this->store->create($session);

            $result = $this->handler->handle([
                'command' => 'query', 'sessionId' => 's1', 'method' => 'getTimeout', 'params' => [],
            ]);

            expect($result['success'])->toBeFalse();
            expect($result['error']['type'])->toBe('ConnectionException');
        });

        it('transfers subscriptions on recovery', function () {
            $callCount = 0;
            $client = $this->createMock(Client::class);
            $client->method('createSubscription')->willReturn(new SubscriptionResult(10, 500.0, 2400, 10));
            $client->method('getAutoRetry')->willReturnCallback(function () use (&$callCount) {
                $callCount++;
                if ($callCount === 1) {
                    throw new ConnectionException('Connection lost');
                }
                return 0;
            });
            $client->expects($this->once())->method('reconnect');
            $client->expects($this->once())->method('transferSubscriptions')
                ->with([10], true)
                ->willReturn([new TransferResult(0, [])]);

            $session = new Session('s1', $client, 'opc.tcp://localhost:4840', [], microtime(true));
            $this->store->create($session);

            $this->handler->handle([
                'command' => 'query', 'sessionId' => 's1', 'method' => 'createSubscription',
                'params' => [500.0, 2400, 10, 0, true, 0],
            ]);

            $result = $this->handler->handle([
                'command' => 'query', 'sessionId' => 's1', 'method' => 'getAutoRetry', 'params' => [],
            ]);

            expect($result['success'])->toBeTrue();
        });

        it('removes failed subscriptions from tracking after transfer failure', function () {
            $callCount = 0;
            $client = $this->createMock(Client::class);
            $client->method('createSubscription')->willReturn(new SubscriptionResult(10, 500.0, 2400, 10));
            $client->method('getAutoRetry')->willReturnCallback(function () use (&$callCount) {
                $callCount++;
                if ($callCount === 1) {
                    throw new ConnectionException('Connection lost');
                }
                return 0;
            });
            $client->method('reconnect');
            $client->method('transferSubscriptions')
                ->willReturn([new TransferResult(0x80000000, [])]);

            $session = new Session('s1', $client, 'opc.tcp://localhost:4840', [], microtime(true));
            $this->store->create($session);

            $this->handler->handle([
                'command' => 'query', 'sessionId' => 's1', 'method' => 'createSubscription',
                'params' => [500.0, 2400, 10, 0, true, 0],
            ]);

            $this->handler->handle([
                'command' => 'query', 'sessionId' => 's1', 'method' => 'getAutoRetry', 'params' => [],
            ]);

            expect($session->getSubscriptionIds())->toBe([]);
        });

    });

    describe('Ping and List', function () {

        it('handles ping', function () {
            $result = $this->handler->handle(['command' => 'ping']);

            expect($result['success'])->toBeTrue();
            expect($result['data']['status'])->toBe('ok');
            expect($result['data']['sessions'])->toBe(0);
            expect($result['data'])->toHaveKey('time');
        });

        it('handles list with sessions', function () {
            $client = $this->createStub(Client::class);
            $session = new Session('s1', $client, 'opc.tcp://localhost:4840', ['securityMode' => 1], microtime(true));
            $this->store->create($session);

            $result = $this->handler->handle(['command' => 'list']);

            expect($result['success'])->toBeTrue();
            expect($result['data']['count'])->toBe(1);
            expect($result['data']['sessions'][0]['id'])->toBe('s1');
            expect($result['data']['sessions'][0]['endpointUrl'])->toBe('opc.tcp://localhost:4840');
        });

    });

    describe('Session recovery — transfer exceptions', function () {

        it('recovers even when transferSubscriptions throws', function () {
            $callCount = 0;
            $client = $this->createMock(Client::class);
            $client->method('createSubscription')->willReturn(new SubscriptionResult(10, 500.0, 2400, 10));
            $client->method('getAutoRetry')->willReturnCallback(function () use (&$callCount) {
                $callCount++;
                if ($callCount === 1) {
                    throw new ConnectionException('Connection lost');
                }
                return 0;
            });
            $client->method('reconnect');
            $client->method('transferSubscriptions')
                ->willThrowException(new \RuntimeException('Transfer failed'));

            $session = new Session('s1', $client, 'opc.tcp://localhost:4840', [], microtime(true));
            $this->store->create($session);

            $this->handler->handle([
                'command' => 'query', 'sessionId' => 's1', 'method' => 'createSubscription',
                'params' => [500.0, 2400, 10, 0, true, 0],
            ]);

            $result = $this->handler->handle([
                'command' => 'query', 'sessionId' => 's1', 'method' => 'getAutoRetry', 'params' => [],
            ]);

            expect($result['success'])->toBeTrue();
        });

        it('republishes available sequence numbers on successful transfer', function () {
            $callCount = 0;
            $client = $this->createMock(Client::class);
            $client->method('createSubscription')->willReturn(new SubscriptionResult(10, 500.0, 2400, 10));
            $client->method('getAutoRetry')->willReturnCallback(function () use (&$callCount) {
                $callCount++;
                if ($callCount === 1) {
                    throw new ConnectionException('Connection lost');
                }
                return 0;
            });
            $client->method('reconnect');
            $client->method('transferSubscriptions')
                ->willReturn([new TransferResult(0, [5, 6, 7])]);
            $client->expects($this->exactly(3))->method('republish');

            $session = new Session('s1', $client, 'opc.tcp://localhost:4840', [], microtime(true));
            $this->store->create($session);

            $this->handler->handle([
                'command' => 'query', 'sessionId' => 's1', 'method' => 'createSubscription',
                'params' => [500.0, 2400, 10, 0, true, 0],
            ]);

            $result = $this->handler->handle([
                'command' => 'query', 'sessionId' => 's1', 'method' => 'getAutoRetry', 'params' => [],
            ]);

            expect($result['success'])->toBeTrue();
        });

    });

    describe('Session recovery — republish failure', function () {

        it('continues when republish throws for individual sequence numbers', function () {
            $callCount = 0;
            $client = $this->createMock(Client::class);
            $client->method('createSubscription')->willReturn(new SubscriptionResult(10, 500.0, 2400, 10));
            $client->method('getAutoRetry')->willReturnCallback(function () use (&$callCount) {
                $callCount++;
                if ($callCount === 1) {
                    throw new ConnectionException('Connection lost');
                }
                return 0;
            });
            $client->method('reconnect');
            $client->method('transferSubscriptions')
                ->willReturn([new TransferResult(0, [5])]);
            $client->method('republish')
                ->willThrowException(new \RuntimeException('Republish failed'));

            $session = new Session('s1', $client, 'opc.tcp://localhost:4840', [], microtime(true));
            $this->store->create($session);

            $this->handler->handle([
                'command' => 'query', 'sessionId' => 's1', 'method' => 'createSubscription',
                'params' => [500.0, 2400, 10, 0, true, 0],
            ]);

            $result = $this->handler->handle([
                'command' => 'query', 'sessionId' => 's1', 'method' => 'getAutoRetry', 'params' => [],
            ]);

            expect($result['success'])->toBeTrue();
            expect($session->getSubscriptionIds())->toBe([10]);
        });

    });

    describe('Edge cases', function () {

        it('skips transfer results with no matching subscription ID', function () {
            $callCount = 0;
            $client = $this->createMock(Client::class);
            $client->method('createSubscription')->willReturn(new SubscriptionResult(10, 500.0, 2400, 10));
            $client->method('getAutoRetry')->willReturnCallback(function () use (&$callCount) {
                $callCount++;
                if ($callCount === 1) {
                    throw new ConnectionException('Connection lost');
                }
                return 0;
            });
            $client->method('reconnect');
            $client->method('transferSubscriptions')
                ->willReturn([
                    new TransferResult(0, []),
                    new TransferResult(0, []),
                ]);

            $session = new Session('s1', $client, 'opc.tcp://localhost:4840', [], microtime(true));
            $this->store->create($session);

            $this->handler->handle([
                'command' => 'query', 'sessionId' => 's1', 'method' => 'createSubscription',
                'params' => [500.0, 2400, 10, 0, true, 0],
            ]);

            $result = $this->handler->handle([
                'command' => 'query', 'sessionId' => 's1', 'method' => 'getAutoRetry', 'params' => [],
            ]);

            expect($result['success'])->toBeTrue();
        });

    });

});
