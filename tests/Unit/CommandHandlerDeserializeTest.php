<?php

declare(strict_types=1);

use PhpOpcua\Client\Client;
use PhpOpcua\Client\Types\BrowseResultSet;
use PhpOpcua\Client\Types\BuiltinType;
use PhpOpcua\Client\Types\DataValue;
use PhpOpcua\Client\Types\EndpointDescription;
use PhpOpcua\Client\Types\MonitoredItemResult;
use PhpOpcua\Client\Types\NodeId;
use PhpOpcua\Client\Types\PublishResult;
use PhpOpcua\Client\Types\SubscriptionResult;
use PhpOpcua\Client\Types\Variant;
use PhpOpcua\SessionManager\Daemon\CommandHandler;
use PhpOpcua\SessionManager\Daemon\Session;
use PhpOpcua\SessionManager\Daemon\SessionStore;

describe('CommandHandler deserializeParams', function () {

    beforeEach(function () {
        $this->store = new SessionStore();
        $this->handler = new CommandHandler($this->store);
    });

    it('deserializes getEndpoints params', function () {
        $client = $this->createStub(Client::class);
        $client->method('getEndpoints')->willReturn([]);
        $session = new Session('s1', $client, 'opc.tcp://localhost:4840', [], microtime(true));
        $this->store->create($session);

        $result = $this->handler->handle([
            'command' => 'query', 'sessionId' => 's1', 'method' => 'getEndpoints',
            'params' => ['opc.tcp://localhost:4840', true],
        ]);

        expect($result['success'])->toBeTrue();
    });

    it('deserializes browse params with nodeClasses', function () {
        $client = $this->createStub(Client::class);
        $client->method('browse')->willReturn([]);
        $session = new Session('s1', $client, 'opc.tcp://localhost:4840', [], microtime(true));
        $this->store->create($session);

        $result = $this->handler->handle([
            'command' => 'query', 'sessionId' => 's1', 'method' => 'browse',
            'params' => [
                ['ns' => 0, 'id' => 85, 'type' => 'numeric'],
                0,
                ['ns' => 0, 'id' => 35, 'type' => 'numeric'],
                true,
                [1, 2],
                true,
            ],
        ]);

        expect($result['success'])->toBeTrue();
    });

    it('deserializes browseAll params', function () {
        $client = $this->createStub(Client::class);
        $client->method('browseAll')->willReturn([]);
        $session = new Session('s1', $client, 'opc.tcp://localhost:4840', [], microtime(true));
        $this->store->create($session);

        $result = $this->handler->handle([
            'command' => 'query', 'sessionId' => 's1', 'method' => 'browseAll',
            'params' => [['ns' => 0, 'id' => 85, 'type' => 'numeric'], 0, null, true, [], false],
        ]);

        expect($result['success'])->toBeTrue();
    });

    it('deserializes browseWithContinuation params', function () {
        $client = $this->createStub(Client::class);
        $client->method('browseWithContinuation')->willReturn(new BrowseResultSet([], null));
        $session = new Session('s1', $client, 'opc.tcp://localhost:4840', [], microtime(true));
        $this->store->create($session);

        $result = $this->handler->handle([
            'command' => 'query', 'sessionId' => 's1', 'method' => 'browseWithContinuation',
            'params' => [['ns' => 0, 'id' => 85, 'type' => 'numeric'], 0, null, true, []],
        ]);

        expect($result['success'])->toBeTrue();
    });

    it('deserializes browseRecursive params', function () {
        $client = $this->createStub(Client::class);
        $client->method('browseRecursive')->willReturn([]);
        $session = new Session('s1', $client, 'opc.tcp://localhost:4840', [], microtime(true));
        $this->store->create($session);

        $result = $this->handler->handle([
            'command' => 'query', 'sessionId' => 's1', 'method' => 'browseRecursive',
            'params' => [
                ['ns' => 0, 'id' => 85, 'type' => 'numeric'],
                0,
                3,
                ['ns' => 0, 'id' => 35, 'type' => 'numeric'],
                true,
                [1],
            ],
        ]);

        expect($result['success'])->toBeTrue();
    });

    it('deserializes translateBrowsePaths params', function () {
        $client = $this->createStub(Client::class);
        $client->method('translateBrowsePaths')->willReturn([]);
        $session = new Session('s1', $client, 'opc.tcp://localhost:4840', [], microtime(true));
        $this->store->create($session);

        $result = $this->handler->handle([
            'command' => 'query', 'sessionId' => 's1', 'method' => 'translateBrowsePaths',
            'params' => [[
                [
                    'startingNodeId' => ['ns' => 0, 'id' => 84, 'type' => 'numeric'],
                    'relativePath' => [
                        [
                            'referenceTypeId' => ['ns' => 0, 'id' => 35, 'type' => 'numeric'],
                            'isInverse' => false,
                            'includeSubtypes' => true,
                            'targetName' => ['ns' => 0, 'name' => 'Objects'],
                        ],
                    ],
                ],
            ]],
        ]);

        expect($result['success'])->toBeTrue();
    });

    it('deserializes resolveNodeId params', function () {
        $client = $this->createStub(Client::class);
        $client->method('resolveNodeId')->willReturn(NodeId::numeric(0, 2253));
        $session = new Session('s1', $client, 'opc.tcp://localhost:4840', [], microtime(true));
        $this->store->create($session);

        $result = $this->handler->handle([
            'command' => 'query', 'sessionId' => 's1', 'method' => 'resolveNodeId',
            'params' => ['/Objects/Server', ['ns' => 0, 'id' => 84, 'type' => 'numeric'], true],
        ]);

        expect($result['success'])->toBeTrue();
    });

    it('deserializes readMulti params', function () {
        $client = $this->createStub(Client::class);
        $client->method('readMulti')->willReturn([new DataValue(new Variant(BuiltinType::Int32, 42), 0)]);
        $session = new Session('s1', $client, 'opc.tcp://localhost:4840', [], microtime(true));
        $this->store->create($session);

        $result = $this->handler->handle([
            'command' => 'query', 'sessionId' => 's1', 'method' => 'readMulti',
            'params' => [[
                ['nodeId' => ['ns' => 0, 'id' => 2259, 'type' => 'numeric'], 'attributeId' => 13],
            ]],
        ]);

        expect($result['success'])->toBeTrue();
    });

    it('deserializes writeMulti params', function () {
        $client = $this->createStub(Client::class);
        $client->method('writeMulti')->willReturn([0]);
        $session = new Session('s1', $client, 'opc.tcp://localhost:4840', [], microtime(true));
        $this->store->create($session);

        $result = $this->handler->handle([
            'command' => 'query', 'sessionId' => 's1', 'method' => 'writeMulti',
            'params' => [[
                ['nodeId' => ['ns' => 2, 'id' => 1001, 'type' => 'numeric'], 'value' => 42, 'type' => BuiltinType::Int32->value, 'attributeId' => 13],
            ]],
        ]);

        expect($result['success'])->toBeTrue();
    });

    it('deserializes createMonitoredItems params', function () {
        $client = $this->createStub(Client::class);
        $client->method('createMonitoredItems')->willReturn([new MonitoredItemResult(0, 1, 250.0, 1)]);
        $session = new Session('s1', $client, 'opc.tcp://localhost:4840', [], microtime(true));
        $this->store->create($session);

        $result = $this->handler->handle([
            'command' => 'query', 'sessionId' => 's1', 'method' => 'createMonitoredItems',
            'params' => [1, [
                ['nodeId' => ['ns' => 2, 'id' => 1001, 'type' => 'numeric'], 'samplingInterval' => 500.0, 'queueSize' => 10],
            ]],
        ]);

        expect($result['success'])->toBeTrue();
    });

    it('deserializes createEventMonitoredItem params', function () {
        $client = $this->createStub(Client::class);
        $client->method('createEventMonitoredItem')->willReturn(new MonitoredItemResult(0, 1, 250.0, 1));
        $session = new Session('s1', $client, 'opc.tcp://localhost:4840', [], microtime(true));
        $this->store->create($session);

        $result = $this->handler->handle([
            'command' => 'query', 'sessionId' => 's1', 'method' => 'createEventMonitoredItem',
            'params' => [1, ['ns' => 0, 'id' => 2253, 'type' => 'numeric'], ['EventId', 'EventType'], 1],
        ]);

        expect($result['success'])->toBeTrue();
    });

    it('deserializes deleteMonitoredItems params', function () {
        $client = $this->createStub(Client::class);
        $client->method('deleteMonitoredItems')->willReturn([0]);
        $session = new Session('s1', $client, 'opc.tcp://localhost:4840', [], microtime(true));
        $this->store->create($session);

        $result = $this->handler->handle([
            'command' => 'query', 'sessionId' => 's1', 'method' => 'deleteMonitoredItems',
            'params' => [1, [1, 2]],
        ]);

        expect($result['success'])->toBeTrue();
    });

    it('deserializes historyReadRaw params', function () {
        $client = $this->createStub(Client::class);
        $client->method('historyReadRaw')->willReturn([]);
        $session = new Session('s1', $client, 'opc.tcp://localhost:4840', [], microtime(true));
        $this->store->create($session);

        $result = $this->handler->handle([
            'command' => 'query', 'sessionId' => 's1', 'method' => 'historyReadRaw',
            'params' => [
                ['ns' => 2, 'id' => 1001, 'type' => 'numeric'],
                '2024-01-01T00:00:00+00:00',
                '2024-01-02T00:00:00+00:00',
                100,
                false,
            ],
        ]);

        expect($result['success'])->toBeTrue();
    });

    it('deserializes historyReadProcessed params', function () {
        $client = $this->createStub(Client::class);
        $client->method('historyReadProcessed')->willReturn([]);
        $session = new Session('s1', $client, 'opc.tcp://localhost:4840', [], microtime(true));
        $this->store->create($session);

        $result = $this->handler->handle([
            'command' => 'query', 'sessionId' => 's1', 'method' => 'historyReadProcessed',
            'params' => [
                ['ns' => 2, 'id' => 1001, 'type' => 'numeric'],
                '2024-01-01T00:00:00+00:00',
                '2024-01-02T00:00:00+00:00',
                3600000.0,
                ['ns' => 0, 'id' => 2342, 'type' => 'numeric'],
            ],
        ]);

        expect($result['success'])->toBeTrue();
    });

    it('deserializes historyReadAtTime params', function () {
        $client = $this->createStub(Client::class);
        $client->method('historyReadAtTime')->willReturn([]);
        $session = new Session('s1', $client, 'opc.tcp://localhost:4840', [], microtime(true));
        $this->store->create($session);

        $result = $this->handler->handle([
            'command' => 'query', 'sessionId' => 's1', 'method' => 'historyReadAtTime',
            'params' => [
                ['ns' => 2, 'id' => 1001, 'type' => 'numeric'],
                ['2024-01-01T12:00:00+00:00', '2024-01-01T13:00:00+00:00'],
            ],
        ]);

        expect($result['success'])->toBeTrue();
    });

    it('deserializes invalidateCache params', function () {
        $client = $this->createStub(Client::class);
        $session = new Session('s1', $client, 'opc.tcp://localhost:4840', [], microtime(true));
        $this->store->create($session);

        $result = $this->handler->handle([
            'command' => 'query', 'sessionId' => 's1', 'method' => 'invalidateCache',
            'params' => [['ns' => 0, 'id' => 85, 'type' => 'numeric']],
        ]);

        expect($result['success'])->toBeTrue();
    });

    it('deserializes getAutoRetry params', function () {
        $client = $this->createStub(Client::class);
        $client->method('getAutoRetry')->willReturn(3);
        $session = new Session('s1', $client, 'opc.tcp://localhost:4840', [], microtime(true));
        $this->store->create($session);

        $result = $this->handler->handle([
            'command' => 'query', 'sessionId' => 's1', 'method' => 'getAutoRetry',
            'params' => [],
        ]);

        expect($result['success'])->toBeTrue();
        expect($result['data'])->toBe(3);
    });

    it('deserializes getBatchSize params', function () {
        $client = $this->createStub(Client::class);
        $client->method('getBatchSize')->willReturn(50);
        $session = new Session('s1', $client, 'opc.tcp://localhost:4840', [], microtime(true));
        $this->store->create($session);

        $result = $this->handler->handle([
            'command' => 'query', 'sessionId' => 's1', 'method' => 'getBatchSize',
            'params' => [],
        ]);

        expect($result['success'])->toBeTrue();
        expect($result['data'])->toBe(50);
    });

    it('deserializes getDefaultBrowseMaxDepth params', function () {
        $client = $this->createStub(Client::class);
        $client->method('getDefaultBrowseMaxDepth')->willReturn(10);
        $session = new Session('s1', $client, 'opc.tcp://localhost:4840', [], microtime(true));
        $this->store->create($session);

        $result = $this->handler->handle([
            'command' => 'query', 'sessionId' => 's1', 'method' => 'getDefaultBrowseMaxDepth',
            'params' => [],
        ]);

        expect($result['success'])->toBeTrue();
        expect($result['data'])->toBe(10);
    });

    it('deserializes getServerMaxNodesPerRead params', function () {
        $client = $this->createStub(Client::class);
        $client->method('getServerMaxNodesPerRead')->willReturn(null);
        $session = new Session('s1', $client, 'opc.tcp://localhost:4840', [], microtime(true));
        $this->store->create($session);

        $result = $this->handler->handle([
            'command' => 'query', 'sessionId' => 's1', 'method' => 'getServerMaxNodesPerRead',
            'params' => [],
        ]);

        expect($result['success'])->toBeTrue();
    });

    it('deserializes getServerMaxNodesPerWrite params', function () {
        $client = $this->createStub(Client::class);
        $client->method('getServerMaxNodesPerWrite')->willReturn(null);
        $session = new Session('s1', $client, 'opc.tcp://localhost:4840', [], microtime(true));
        $this->store->create($session);

        $result = $this->handler->handle([
            'command' => 'query', 'sessionId' => 's1', 'method' => 'getServerMaxNodesPerWrite',
            'params' => [],
        ]);

        expect($result['success'])->toBeTrue();
    });

});
