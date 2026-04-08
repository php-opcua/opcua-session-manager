<?php

declare(strict_types=1);

use PhpOpcua\Client\Client;
use PhpOpcua\SessionManager\Daemon\Session;

describe('Session', function () {

    it('tracks subscription IDs', function () {
        $client = $this->createStub(Client::class);
        $session = new Session('s1', $client, 'opc.tcp://localhost:4840', [], microtime(true));

        expect($session->hasSubscriptions())->toBeFalse();
        expect($session->getSubscriptionIds())->toBe([]);

        $session->addSubscription(1);
        $session->addSubscription(2);

        expect($session->hasSubscriptions())->toBeTrue();
        expect($session->getSubscriptionIds())->toBe([1, 2]);
    });

    it('removes subscription IDs', function () {
        $client = $this->createStub(Client::class);
        $session = new Session('s1', $client, 'opc.tcp://localhost:4840', [], microtime(true));

        $session->addSubscription(1);
        $session->addSubscription(2);
        $session->addSubscription(3);
        $session->removeSubscription(2);

        expect($session->getSubscriptionIds())->toBe([1, 3]);
        expect($session->hasSubscriptions())->toBeTrue();
    });

    it('removing non-existent subscription is safe', function () {
        $client = $this->createStub(Client::class);
        $session = new Session('s1', $client, 'opc.tcp://localhost:4840', [], microtime(true));

        $session->removeSubscription(999);

        expect($session->getSubscriptionIds())->toBe([]);
    });

    it('does not duplicate subscription IDs', function () {
        $client = $this->createStub(Client::class);
        $session = new Session('s1', $client, 'opc.tcp://localhost:4840', [], microtime(true));

        $session->addSubscription(1);
        $session->addSubscription(1);

        expect($session->getSubscriptionIds())->toBe([1]);
    });

    it('removing all subscriptions makes hasSubscriptions false', function () {
        $client = $this->createStub(Client::class);
        $session = new Session('s1', $client, 'opc.tcp://localhost:4840', [], microtime(true));

        $session->addSubscription(1);
        $session->removeSubscription(1);

        expect($session->hasSubscriptions())->toBeFalse();
    });

    it('touch updates lastUsed', function () {
        $client = $this->createStub(Client::class);
        $before = microtime(true);
        $session = new Session('s1', $client, 'opc.tcp://localhost:4840', [], $before);

        usleep(10000);
        $session->touch();

        expect($session->lastUsed)->toBeGreaterThan($before);
    });

    it('isExpired returns true after timeout', function () {
        $client = $this->createStub(Client::class);
        $session = new Session('s1', $client, 'opc.tcp://localhost:4840', [], microtime(true) - 100);

        expect($session->isExpired(50))->toBeTrue();
        expect($session->isExpired(200))->toBeFalse();
    });

    it('stores publishing interval with subscription', function () {
        $client = $this->createStub(Client::class);
        $session = new Session('s1', $client, 'opc.tcp://localhost:4840', [], microtime(true));

        $session->addSubscription(1, 500.0);
        $session->addSubscription(2, 1000.0);

        expect($session->getMinPublishingInterval())->toBe(0.5);
    });

    it('returns minimum publishing interval across subscriptions', function () {
        $client = $this->createStub(Client::class);
        $session = new Session('s1', $client, 'opc.tcp://localhost:4840', [], microtime(true));

        $session->addSubscription(1, 1000.0);
        $session->addSubscription(2, 250.0);
        $session->addSubscription(3, 500.0);

        expect($session->getMinPublishingInterval())->toBe(0.25);
    });

    it('returns fallback interval when no subscriptions exist', function () {
        $client = $this->createStub(Client::class);
        $session = new Session('s1', $client, 'opc.tcp://localhost:4840', [], microtime(true));

        expect($session->getMinPublishingInterval())->toBe(0.5);
    });

    it('updates min interval when subscription is removed', function () {
        $client = $this->createStub(Client::class);
        $session = new Session('s1', $client, 'opc.tcp://localhost:4840', [], microtime(true));

        $session->addSubscription(1, 250.0);
        $session->addSubscription(2, 1000.0);

        expect($session->getMinPublishingInterval())->toBe(0.25);

        $session->removeSubscription(1);

        expect($session->getMinPublishingInterval())->toBe(1.0);
    });

    it('uses default publishing interval when none specified', function () {
        $client = $this->createStub(Client::class);
        $session = new Session('s1', $client, 'opc.tcp://localhost:4840', [], microtime(true));

        $session->addSubscription(1);

        expect($session->getMinPublishingInterval())->toBe(0.5);
    });

    it('returns fallback after removing all subscriptions', function () {
        $client = $this->createStub(Client::class);
        $session = new Session('s1', $client, 'opc.tcp://localhost:4840', [], microtime(true));

        $session->addSubscription(1, 250.0);
        $session->removeSubscription(1);

        expect($session->getMinPublishingInterval())->toBe(0.5);
    });

});
