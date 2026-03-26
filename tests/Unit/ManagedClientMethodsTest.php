<?php

declare(strict_types=1);

use PhpOpcua\Client\Exception\ConnectionException;
use PhpOpcua\Client\Security\SecurityMode;
use PhpOpcua\Client\Security\SecurityPolicy;
use PhpOpcua\Client\Types\NodeId;
use PhpOpcua\SessionManager\Client\ManagedClient;
use PhpOpcua\SessionManager\Exception\DaemonException;

describe('ManagedClient Methods', function () {

    describe('Methods that throw when not connected', function () {

        it('throws ConnectionException on read when not connected', function () {
            $client = new ManagedClient();
            expect(fn() => $client->read('i=2259'))->toThrow(ConnectionException::class);
        });

        it('throws ConnectionException on write when not connected', function () {
            $client = new ManagedClient();
            expect(fn() => $client->write('i=2259', 42, \PhpOpcua\Client\Types\BuiltinType::Int32))
                ->toThrow(ConnectionException::class);
        });

        it('throws ConnectionException on browse when not connected', function () {
            $client = new ManagedClient();
            expect(fn() => $client->browse('i=85'))->toThrow(ConnectionException::class);
        });

        it('throws ConnectionException on browseAll when not connected', function () {
            $client = new ManagedClient();
            expect(fn() => $client->browseAll('i=85'))->toThrow(ConnectionException::class);
        });

        it('throws ConnectionException on browseWithContinuation when not connected', function () {
            $client = new ManagedClient();
            expect(fn() => $client->browseWithContinuation('i=85'))->toThrow(ConnectionException::class);
        });

        it('throws ConnectionException on browseNext when not connected', function () {
            $client = new ManagedClient();
            expect(fn() => $client->browseNext('abc'))->toThrow(ConnectionException::class);
        });

        it('throws ConnectionException on browseRecursive when not connected', function () {
            $client = new ManagedClient();
            expect(fn() => $client->browseRecursive('i=85'))->toThrow(ConnectionException::class);
        });

        it('throws ConnectionException on call when not connected', function () {
            $client = new ManagedClient();
            expect(fn() => $client->call('i=2253', 'i=11492'))->toThrow(ConnectionException::class);
        });

        it('throws ConnectionException on createSubscription when not connected', function () {
            $client = new ManagedClient();
            expect(fn() => $client->createSubscription())->toThrow(ConnectionException::class);
        });

        it('throws ConnectionException on publish when not connected', function () {
            $client = new ManagedClient();
            expect(fn() => $client->publish())->toThrow(ConnectionException::class);
        });

        it('throws ConnectionException on deleteSubscription when not connected', function () {
            $client = new ManagedClient();
            expect(fn() => $client->deleteSubscription(1))->toThrow(ConnectionException::class);
        });

        it('throws ConnectionException on deleteMonitoredItems when not connected', function () {
            $client = new ManagedClient();
            expect(fn() => $client->deleteMonitoredItems(1, [1]))->toThrow(ConnectionException::class);
        });

        it('throws ConnectionException on transferSubscriptions when not connected', function () {
            $client = new ManagedClient();
            expect(fn() => $client->transferSubscriptions([1]))->toThrow(ConnectionException::class);
        });

        it('throws ConnectionException on republish when not connected', function () {
            $client = new ManagedClient();
            expect(fn() => $client->republish(1, 1))->toThrow(ConnectionException::class);
        });

        it('throws ConnectionException on historyReadRaw when not connected', function () {
            $client = new ManagedClient();
            expect(fn() => $client->historyReadRaw('i=2259'))->toThrow(ConnectionException::class);
        });

        it('throws ConnectionException on historyReadProcessed when not connected', function () {
            $client = new ManagedClient();
            expect(fn() => $client->historyReadProcessed('i=2259', new DateTimeImmutable(), new DateTimeImmutable(), 1000.0, NodeId::numeric(0, 2342)))
                ->toThrow(ConnectionException::class);
        });

        it('throws ConnectionException on historyReadAtTime when not connected', function () {
            $client = new ManagedClient();
            expect(fn() => $client->historyReadAtTime('i=2259', [new DateTimeImmutable()]))
                ->toThrow(ConnectionException::class);
        });

        it('throws ConnectionException on getEndpoints when not connected', function () {
            $client = new ManagedClient();
            expect(fn() => $client->getEndpoints('opc.tcp://localhost:4840'))
                ->toThrow(ConnectionException::class);
        });

        it('throws ConnectionException on resolveNodeId when not connected', function () {
            $client = new ManagedClient();
            expect(fn() => $client->resolveNodeId('/Objects/Server'))
                ->toThrow(ConnectionException::class);
        });

        it('throws ConnectionException on discoverDataTypes when not connected', function () {
            $client = new ManagedClient();
            expect(fn() => $client->discoverDataTypes())->toThrow(ConnectionException::class);
        });

        it('throws ConnectionException on invalidateCache when not connected', function () {
            $client = new ManagedClient();
            expect(fn() => $client->invalidateCache('i=85'))->toThrow(ConnectionException::class);
        });

        it('throws ConnectionException on flushCache when not connected', function () {
            $client = new ManagedClient();
            expect(fn() => $client->flushCache())->toThrow(ConnectionException::class);
        });

        it('throws ConnectionException on getServerMaxNodesPerRead when not connected', function () {
            $client = new ManagedClient();
            expect(fn() => $client->getServerMaxNodesPerRead())->toThrow(ConnectionException::class);
        });

        it('throws ConnectionException on getServerMaxNodesPerWrite when not connected', function () {
            $client = new ManagedClient();
            expect(fn() => $client->getServerMaxNodesPerWrite())->toThrow(ConnectionException::class);
        });

    });

    describe('Security configuration', function () {

        it('sets security policy', function () {
            $client = new ManagedClient();
            $result = $client->setSecurityPolicy(SecurityPolicy::Basic256Sha256);
            expect($result)->toBe($client);
        });

        it('sets security mode', function () {
            $client = new ManagedClient();
            $result = $client->setSecurityMode(SecurityMode::SignAndEncrypt);
            expect($result)->toBe($client);
        });

        it('sets user credentials', function () {
            $client = new ManagedClient();
            $result = $client->setUserCredentials('admin', 'secret');
            expect($result)->toBe($client);
        });

        it('sets client certificate', function () {
            $client = new ManagedClient();
            $result = $client->setClientCertificate('/cert.pem', '/key.pem', '/ca.pem');
            expect($result)->toBe($client);
        });

        it('sets client certificate without CA', function () {
            $client = new ManagedClient();
            $result = $client->setClientCertificate('/cert.pem', '/key.pem');
            expect($result)->toBe($client);
        });

        it('sets user certificate', function () {
            $client = new ManagedClient();
            $result = $client->setUserCertificate('/user-cert.pem', '/user-key.pem');
            expect($result)->toBe($client);
        });

    });

    describe('Builder pattern', function () {

        it('readMulti returns builder when called without args', function () {
            $client = new ManagedClient();
            $builder = $client->readMulti();
            expect($builder)->toBeInstanceOf(\PhpOpcua\Client\Builder\ReadMultiBuilder::class);
        });

        it('writeMulti returns builder when called without args', function () {
            $client = new ManagedClient();
            $builder = $client->writeMulti();
            expect($builder)->toBeInstanceOf(\PhpOpcua\Client\Builder\WriteMultiBuilder::class);
        });

        it('createMonitoredItems returns builder when called without items', function () {
            $client = new ManagedClient();
            $builder = $client->createMonitoredItems(1);
            expect($builder)->toBeInstanceOf(\PhpOpcua\Client\Builder\MonitoredItemsBuilder::class);
        });

        it('translateBrowsePaths returns builder when called without args', function () {
            $client = new ManagedClient();
            $builder = $client->translateBrowsePaths();
            expect($builder)->toBeInstanceOf(\PhpOpcua\Client\Builder\BrowsePathsBuilder::class);
        });

    });

    describe('Disconnect behavior', function () {

        it('disconnect does nothing when not connected', function () {
            $client = new ManagedClient();
            $client->disconnect();
            expect($client->getSessionId())->toBeNull();
        });

        it('reconnect throws when not connected', function () {
            $client = new ManagedClient();
            expect(fn() => $client->reconnect())->toThrow(ConnectionException::class);
        });

        it('connect throws DaemonException when socket missing', function () {
            $client = new ManagedClient('/nonexistent/sock');
            expect(fn() => $client->connect('opc.tcp://localhost:4840'))->toThrow(DaemonException::class);
        });

    });

});
