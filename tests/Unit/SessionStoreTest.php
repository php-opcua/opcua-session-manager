<?php

declare(strict_types=1);

use Gianfriaur\OpcuaPhpClient\Client;
use Gianfriaur\OpcuaSessionManager\Daemon\Session;
use Gianfriaur\OpcuaSessionManager\Daemon\SessionStore;
use Gianfriaur\OpcuaSessionManager\Exception\SessionNotFoundException;

describe('SessionStore', function () {

    beforeEach(function () {
        $this->store = new SessionStore();
    });

    it('starts empty', function () {
        expect($this->store->count())->toBe(0);
        expect($this->store->all())->toBeEmpty();
    });

    it('creates and retrieves a session', function () {
        $client = $this->createStub(Client::class);
        $session = new Session('abc123', $client, 'opc.tcp://localhost:4840', [], microtime(true));
        $this->store->create($session);

        expect($this->store->count())->toBe(1);
        expect($this->store->get('abc123'))->toBe($session);
    });

    it('throws when getting a non-existent session', function () {
        expect(fn() => $this->store->get('nonexistent'))
            ->toThrow(SessionNotFoundException::class);
    });

    it('removes a session', function () {
        $client = $this->createStub(Client::class);
        $session = new Session('abc123', $client, 'opc.tcp://localhost:4840', [], microtime(true));
        $this->store->create($session);

        $this->store->remove('abc123');
        expect($this->store->count())->toBe(0);
    });

    it('touches a session to update lastUsed', function () {
        $client = $this->createStub(Client::class);
        $oldTime = microtime(true) - 100;
        $session = new Session('abc123', $client, 'opc.tcp://localhost:4840', [], $oldTime);
        $this->store->create($session);

        $this->store->touch('abc123');
        expect($this->store->get('abc123')->lastUsed)->toBeGreaterThan($oldTime);
    });

    it('finds expired sessions', function () {
        $client = $this->createStub(Client::class);

        // Create an expired session (lastUsed 200 seconds ago)
        $expired = new Session('expired', $client, 'opc.tcp://localhost:4840', [], microtime(true) - 200);
        $this->store->create($expired);

        // Create a fresh session
        $fresh = new Session('fresh', $client, 'opc.tcp://localhost:4840', [], microtime(true));
        $this->store->create($fresh);

        $expiredSessions = $this->store->getExpired(100);

        expect($expiredSessions)->toHaveCount(1);
        expect($expiredSessions[0]->id)->toBe('expired');
    });

    it('returns all sessions', function () {
        $client = $this->createStub(Client::class);
        $this->store->create(new Session('a', $client, 'url-a', [], microtime(true)));
        $this->store->create(new Session('b', $client, 'url-b', [], microtime(true)));
        $this->store->create(new Session('c', $client, 'url-c', [], microtime(true)));

        $all = $this->store->all();
        expect($all)->toHaveCount(3);
        $ids = array_map(fn(Session $s) => $s->id, $all);
        expect($ids)->toContain('a', 'b', 'c');
    });

    it('handles remove of non-existent session silently', function () {
        $this->store->remove('nonexistent');
        expect($this->store->count())->toBe(0);
    });

});
