<?php

declare(strict_types=1);

use PhpOpcua\Client\Client;
use PhpOpcua\Client\ClientBuilder;
use PhpOpcua\Client\Types\MonitoredItemResult;
use PhpOpcua\Client\Types\PublishResult;
use PhpOpcua\Client\Types\SubscriptionResult;
use PhpOpcua\SessionManager\Daemon\AutoPublisher;
use PhpOpcua\SessionManager\Daemon\CommandHandler;
use PhpOpcua\SessionManager\Daemon\Session;
use PhpOpcua\SessionManager\Daemon\SessionStore;
use Psr\EventDispatcher\EventDispatcherInterface;
use Psr\Log\NullLogger;

describe('CommandHandler auto-publish', function () {

    beforeEach(function () {
        $this->store = new SessionStore();
        $this->eventDispatcher = $this->createStub(EventDispatcherInterface::class);
        $this->handler = new CommandHandler(
            $this->store,
            100,
            null,
            new NullLogger(),
            null,
            $this->eventDispatcher,
        );
    });

    describe('manual publish blocking', function () {

        it('blocks manual publish when auto-publish is active', function () {
            $autoPublisher = $this->createStub(AutoPublisher::class);
            $autoPublisher->method('isActive')->with('s1')->willReturn(true);
            $this->handler->setAutoPublisher($autoPublisher);

            $client = $this->createStub(Client::class);
            $session = new Session('s1', $client, 'opc.tcp://localhost:4840', [], microtime(true));
            $this->store->create($session);

            $result = $this->handler->handle([
                'command' => 'query',
                'sessionId' => 's1',
                'method' => 'publish',
                'params' => [[]],
            ]);

            expect($result['success'])->toBeFalse();
            expect($result['error']['type'])->toBe('auto_publish_active');
        });

        it('allows manual publish when auto-publish is not active', function () {
            $autoPublisher = $this->createStub(AutoPublisher::class);
            $autoPublisher->method('isActive')->with('s1')->willReturn(false);
            $this->handler->setAutoPublisher($autoPublisher);

            $client = $this->createStub(Client::class);
            $client->method('publish')->willReturn(new PublishResult(1, 1, false, [], []));
            $session = new Session('s1', $client, 'opc.tcp://localhost:4840', [], microtime(true));
            $this->store->create($session);

            $result = $this->handler->handle([
                'command' => 'query',
                'sessionId' => 's1',
                'method' => 'publish',
                'params' => [[]],
            ]);

            expect($result['success'])->toBeTrue();
        });

        it('allows manual publish when no auto-publisher is configured', function () {
            $client = $this->createStub(Client::class);
            $client->method('publish')->willReturn(new PublishResult(1, 1, false, [], []));
            $session = new Session('s1', $client, 'opc.tcp://localhost:4840', [], microtime(true));
            $this->store->create($session);

            $result = $this->handler->handle([
                'command' => 'query',
                'sessionId' => 's1',
                'method' => 'publish',
                'params' => [[]],
            ]);

            expect($result['success'])->toBeTrue();
        });

    });

    describe('subscription tracking with auto-publisher', function () {

        it('starts auto-publish on first subscription creation', function () {
            $autoPublisher = $this->createMock(AutoPublisher::class);
            $autoPublisher->expects($this->once())
                ->method('startSession')
                ->with('s1');
            $this->handler->setAutoPublisher($autoPublisher);

            $client = $this->createStub(Client::class);
            $client->method('createSubscription')->willReturn(
                new SubscriptionResult(1, 500.0, 2400, 10),
            );
            $session = new Session('s1', $client, 'opc.tcp://localhost:4840', [], microtime(true));
            $this->store->create($session);

            $this->handler->handle([
                'command' => 'query',
                'sessionId' => 's1',
                'method' => 'createSubscription',
                'params' => [500.0],
            ]);
        });

        it('does not restart auto-publish on subsequent subscriptions', function () {
            $autoPublisher = $this->createMock(AutoPublisher::class);
            $autoPublisher->expects($this->once())->method('startSession');
            $this->handler->setAutoPublisher($autoPublisher);

            $client = $this->createStub(Client::class);
            $client->method('createSubscription')->willReturnOnConsecutiveCalls(
                new SubscriptionResult(1, 500.0, 2400, 10),
                new SubscriptionResult(2, 1000.0, 2400, 10),
            );
            $session = new Session('s1', $client, 'opc.tcp://localhost:4840', [], microtime(true));
            $this->store->create($session);

            $this->handler->handle([
                'command' => 'query',
                'sessionId' => 's1',
                'method' => 'createSubscription',
                'params' => [500.0],
            ]);
            $this->handler->handle([
                'command' => 'query',
                'sessionId' => 's1',
                'method' => 'createSubscription',
                'params' => [1000.0],
            ]);
        });

        it('stops auto-publish when last subscription is deleted', function () {
            $autoPublisher = $this->createMock(AutoPublisher::class);
            $autoPublisher->expects($this->once())->method('startSession')->with('s1');
            $autoPublisher->expects($this->once())->method('stopSession')->with('s1');
            $this->handler->setAutoPublisher($autoPublisher);

            $client = $this->createStub(Client::class);
            $client->method('createSubscription')->willReturn(
                new SubscriptionResult(1, 500.0, 2400, 10),
            );
            $client->method('deleteSubscription')->willReturn(0);
            $session = new Session('s1', $client, 'opc.tcp://localhost:4840', [], microtime(true));
            $this->store->create($session);

            $this->handler->handle([
                'command' => 'query',
                'sessionId' => 's1',
                'method' => 'createSubscription',
                'params' => [500.0],
            ]);
            $this->handler->handle([
                'command' => 'query',
                'sessionId' => 's1',
                'method' => 'deleteSubscription',
                'params' => [1],
            ]);
        });

        it('does not stop auto-publish when subscriptions remain', function () {
            $autoPublisher = $this->createMock(AutoPublisher::class);
            $autoPublisher->expects($this->once())->method('startSession');
            $autoPublisher->expects($this->never())->method('stopSession');
            $this->handler->setAutoPublisher($autoPublisher);

            $client = $this->createStub(Client::class);
            $client->method('createSubscription')->willReturnOnConsecutiveCalls(
                new SubscriptionResult(1, 500.0, 2400, 10),
                new SubscriptionResult(2, 1000.0, 2400, 10),
            );
            $client->method('deleteSubscription')->willReturn(0);
            $session = new Session('s1', $client, 'opc.tcp://localhost:4840', [], microtime(true));
            $this->store->create($session);

            $this->handler->handle([
                'command' => 'query',
                'sessionId' => 's1',
                'method' => 'createSubscription',
                'params' => [500.0],
            ]);
            $this->handler->handle([
                'command' => 'query',
                'sessionId' => 's1',
                'method' => 'createSubscription',
                'params' => [1000.0],
            ]);
            $this->handler->handle([
                'command' => 'query',
                'sessionId' => 's1',
                'method' => 'deleteSubscription',
                'params' => [1],
            ]);
        });

        it('stores revised publishing interval from subscription result', function () {
            $this->handler->setAutoPublisher($this->createStub(AutoPublisher::class));

            $client = $this->createStub(Client::class);
            $client->method('createSubscription')->willReturn(
                new SubscriptionResult(1, 250.0, 2400, 10),
            );
            $session = new Session('s1', $client, 'opc.tcp://localhost:4840', [], microtime(true));
            $this->store->create($session);

            $this->handler->handle([
                'command' => 'query',
                'sessionId' => 's1',
                'method' => 'createSubscription',
                'params' => [250.0],
            ]);

            expect($session->getMinPublishingInterval())->toBe(0.25);
        });

    });

    describe('autoConnectSession', function () {

        it('returns null when max sessions reached', function () {
            $handler = new CommandHandler(
                $this->store,
                1,
                null,
                new NullLogger(),
                null,
                $this->eventDispatcher,
            );

            $client = $this->createStub(Client::class);
            $existing = new Session('existing', $client, 'opc.tcp://other:4840', [], microtime(true));
            $this->store->create($existing);

            $result = $handler->autoConnectSession(
                'opc.tcp://localhost:4840',
                [],
                [],
            );

            expect($result)->toBeNull();
        });

    });

    describe('attemptSessionRecovery visibility', function () {

        it('is publicly accessible', function () {
            $method = new ReflectionMethod(CommandHandler::class, 'attemptSessionRecovery');

            expect($method->isPublic())->toBeTrue();
        });

    });

});
