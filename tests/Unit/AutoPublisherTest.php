<?php

declare(strict_types=1);

use PhpOpcua\Client\Client;
use PhpOpcua\Client\Exception\ConnectionException;
use PhpOpcua\Client\Types\PublishResult;
use PhpOpcua\SessionManager\Daemon\AutoPublisher;
use PhpOpcua\SessionManager\Daemon\Session;
use PhpOpcua\SessionManager\Daemon\SessionStore;
use Psr\Log\NullLogger;
use React\EventLoop\LoopInterface;
use React\EventLoop\TimerInterface;

function drainAutoPublishCallbacks(array &$callbacks, int $maxIterations = 50): void
{
    $i = 0;
    while (!empty($callbacks) && $i < $maxIterations) {
        $cb = array_shift($callbacks);
        ($cb['callback'])();
        $i++;
    }
}

describe('AutoPublisher', function () {

    beforeEach(function () {
        $this->store = new SessionStore();
        $this->callbacks = [];
        $this->timer = $this->createStub(TimerInterface::class);
        $callbacks = &$this->callbacks;
        $timer = $this->timer;

        $this->loop = $this->createMock(LoopInterface::class);
        $this->loop->method('addTimer')->willReturnCallback(function ($delay, $callback) use (&$callbacks, $timer) {
            $callbacks[] = ['delay' => $delay, 'callback' => $callback];
            return $timer;
        });

        $this->recoveryResult = true;
        $this->recoveryCalled = false;
        $recoveryResult = &$this->recoveryResult;
        $recoveryCalled = &$this->recoveryCalled;

        $this->publisher = new AutoPublisher(
            $this->store,
            $this->loop,
            new NullLogger(),
            function (Session $s) use (&$recoveryResult, &$recoveryCalled) {
                $recoveryCalled = true;
                return $recoveryResult;
            },
        );
    });

    it('is not active for unknown sessions', function () {
        expect($this->publisher->isActive('nonexistent'))->toBeFalse();
    });

    it('starts auto-publish and schedules a timer', function () {
        $this->publisher->startSession('s1');

        expect($this->publisher->isActive('s1'))->toBeTrue();
        expect($this->callbacks)->toHaveCount(1);
        expect($this->callbacks[0]['delay'])->toBe(0.0);
    });

    it('is idempotent on repeated startSession calls', function () {
        $this->publisher->startSession('s1');
        $this->publisher->startSession('s1');

        expect($this->callbacks)->toHaveCount(1);
    });

    it('stops auto-publish and cancels the timer', function () {
        $this->loop->expects($this->once())->method('cancelTimer')->with($this->timer);

        $this->publisher->startSession('s1');
        $this->publisher->stopSession('s1');

        expect($this->publisher->isActive('s1'))->toBeFalse();
    });

    it('stopSession is safe for inactive sessions', function () {
        $this->publisher->stopSession('nonexistent');

        expect($this->publisher->isActive('nonexistent'))->toBeFalse();
    });

    it('stops all active sessions', function () {
        $this->publisher->startSession('s1');
        $this->publisher->startSession('s2');

        $this->publisher->stopAll();

        expect($this->publisher->isActive('s1'))->toBeFalse();
        expect($this->publisher->isActive('s2'))->toBeFalse();
    });

    it('calls publish and schedules next cycle on success', function () {
        $client = $this->createStub(Client::class);
        $client->method('publish')->willReturn(new PublishResult(1, 10, false, [], []));

        $session = new Session('s1', $client, 'opc.tcp://localhost:4840', [], microtime(true));
        $session->addSubscription(1, 500.0);
        $this->store->create($session);

        $this->publisher->startSession('s1');
        drainAutoPublishCallbacks($this->callbacks, 1);

        expect($this->publisher->isActive('s1'))->toBeTrue();
        expect($this->callbacks)->toHaveCount(1);
        expect($this->callbacks[0]['delay'])->toBe(0.5 * 0.75);
    });

    it('drains quickly when moreNotifications is true', function () {
        $callCount = 0;
        $client = $this->createStub(Client::class);
        $client->method('publish')->willReturnCallback(function () use (&$callCount) {
            $callCount++;
            return new PublishResult(1, $callCount, $callCount < 3, [], []);
        });

        $session = new Session('s1', $client, 'opc.tcp://localhost:4840', [], microtime(true));
        $session->addSubscription(1, 500.0);
        $this->store->create($session);

        $this->publisher->startSession('s1');
        drainAutoPublishCallbacks($this->callbacks, 5);

        expect($callCount)->toBeGreaterThanOrEqual(3);
    });

    it('uses short delay for moreNotifications drain', function () {
        $client = $this->createStub(Client::class);
        $client->method('publish')->willReturn(new PublishResult(1, 1, true, [], []));

        $session = new Session('s1', $client, 'opc.tcp://localhost:4840', [], microtime(true));
        $session->addSubscription(1, 500.0);
        $this->store->create($session);

        $this->publisher->startSession('s1');
        drainAutoPublishCallbacks($this->callbacks, 1);

        expect($this->callbacks[0]['delay'])->toBe(0.01);
    });

    it('stops when session has no subscriptions', function () {
        $client = $this->createStub(Client::class);
        $session = new Session('s1', $client, 'opc.tcp://localhost:4840', [], microtime(true));
        $this->store->create($session);

        $this->publisher->startSession('s1');
        drainAutoPublishCallbacks($this->callbacks, 1);

        expect($this->publisher->isActive('s1'))->toBeFalse();
    });

    it('stops when session is removed from store', function () {
        $client = $this->createStub(Client::class);
        $session = new Session('s1', $client, 'opc.tcp://localhost:4840', [], microtime(true));
        $session->addSubscription(1, 500.0);
        $this->store->create($session);

        $this->publisher->startSession('s1');
        $this->store->remove('s1');
        drainAutoPublishCallbacks($this->callbacks, 1);

        expect($this->publisher->isActive('s1'))->toBeFalse();
    });

    it('attempts recovery on ConnectionException', function () {
        $client = $this->createStub(Client::class);
        $client->method('publish')->willThrowException(new ConnectionException('Connection lost'));

        $session = new Session('s1', $client, 'opc.tcp://localhost:4840', [], microtime(true));
        $session->addSubscription(1, 500.0);
        $this->store->create($session);

        $this->publisher->startSession('s1');
        drainAutoPublishCallbacks($this->callbacks, 1);

        expect($this->recoveryCalled)->toBeTrue();
        expect($this->publisher->isActive('s1'))->toBeTrue();
        expect($this->callbacks[0]['delay'])->toBe(1.0);
    });

    it('stops after failed recovery', function () {
        $this->recoveryResult = false;

        $client = $this->createStub(Client::class);
        $client->method('publish')->willThrowException(new ConnectionException('Connection lost'));

        $session = new Session('s1', $client, 'opc.tcp://localhost:4840', [], microtime(true));
        $session->addSubscription(1, 500.0);
        $this->store->create($session);

        $this->publisher->startSession('s1');
        drainAutoPublishCallbacks($this->callbacks, 1);

        expect($this->recoveryCalled)->toBeTrue();
        expect($this->publisher->isActive('s1'))->toBeFalse();
    });

    it('stops after max consecutive generic errors', function () {
        $callCount = 0;
        $client = $this->createStub(Client::class);
        $client->method('publish')->willReturnCallback(function () use (&$callCount) {
            $callCount++;
            throw new RuntimeException('Generic error');
        });

        $session = new Session('s1', $client, 'opc.tcp://localhost:4840', [], microtime(true));
        $session->addSubscription(1, 500.0);
        $this->store->create($session);

        $this->publisher->startSession('s1');
        drainAutoPublishCallbacks($this->callbacks, 10);

        expect($callCount)->toBe(5);
        expect($this->publisher->isActive('s1'))->toBeFalse();
    });

    it('uses backoff delay on generic errors', function () {
        $client = $this->createStub(Client::class);
        $client->method('publish')->willThrowException(new RuntimeException('Error'));

        $session = new Session('s1', $client, 'opc.tcp://localhost:4840', [], microtime(true));
        $session->addSubscription(1, 500.0);
        $this->store->create($session);

        $this->publisher->startSession('s1');
        drainAutoPublishCallbacks($this->callbacks, 1);

        expect($this->callbacks[0]['delay'])->toBe(5.0);
    });

    it('resets error count on successful publish', function () {
        $callCount = 0;
        $client = $this->createStub(Client::class);
        $client->method('publish')->willReturnCallback(function () use (&$callCount) {
            $callCount++;
            if ($callCount <= 3) {
                throw new RuntimeException('Transient error');
            }
            return new PublishResult(1, $callCount, false, [], []);
        });

        $session = new Session('s1', $client, 'opc.tcp://localhost:4840', [], microtime(true));
        $session->addSubscription(1, 500.0);
        $this->store->create($session);

        $this->publisher->startSession('s1');
        drainAutoPublishCallbacks($this->callbacks, 10);

        expect($callCount)->toBeGreaterThan(3);
        expect($this->publisher->isActive('s1'))->toBeTrue();
    });

    it('passes acknowledgements from previous publish', function () {
        $receivedAcks = [];
        $callCount = 0;
        $client = $this->createStub(Client::class);
        $client->method('publish')->willReturnCallback(function (array $acks) use (&$receivedAcks, &$callCount) {
            $callCount++;
            $receivedAcks[] = $acks;
            if ($callCount >= 3) {
                throw new RuntimeException('Stop');
            }
            return new PublishResult(1, $callCount * 10, false, [], []);
        });

        $session = new Session('s1', $client, 'opc.tcp://localhost:4840', [], microtime(true));
        $session->addSubscription(1, 100.0);
        $this->store->create($session);

        $this->publisher->startSession('s1');
        drainAutoPublishCallbacks($this->callbacks, 5);

        expect($receivedAcks[0])->toBe([]);
        expect($receivedAcks[1])->toBe([['subscriptionId' => 1, 'sequenceNumber' => 10]]);
        expect($receivedAcks[2])->toBe([['subscriptionId' => 1, 'sequenceNumber' => 20]]);
    });

    it('touches the session on successful publish', function () {
        $client = $this->createStub(Client::class);
        $client->method('publish')->willReturn(new PublishResult(1, 10, false, [], []));

        $oldTime = microtime(true) - 100;
        $session = new Session('s1', $client, 'opc.tcp://localhost:4840', [], $oldTime);
        $session->addSubscription(1, 500.0);
        $this->store->create($session);

        $this->publisher->startSession('s1');
        drainAutoPublishCallbacks($this->callbacks, 1);

        expect($session->lastUsed)->toBeGreaterThan($oldTime);
    });

    it('clears pending acks on connection error', function () {
        $callCount = 0;
        $receivedAcks = [];
        $client = $this->createStub(Client::class);
        $client->method('publish')->willReturnCallback(function (array $acks) use (&$callCount, &$receivedAcks) {
            $callCount++;
            $receivedAcks[] = $acks;
            if ($callCount === 2) {
                throw new ConnectionException('Lost');
            }
            return new PublishResult(1, $callCount * 10, false, [], []);
        });

        $session = new Session('s1', $client, 'opc.tcp://localhost:4840', [], microtime(true));
        $session->addSubscription(1, 500.0);
        $this->store->create($session);

        $this->publisher->startSession('s1');
        drainAutoPublishCallbacks($this->callbacks, 3);

        expect($receivedAcks[2])->toBe([]);
    });

});
