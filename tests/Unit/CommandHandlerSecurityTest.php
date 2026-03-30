<?php

declare(strict_types=1);

use PhpOpcua\Client\Client;
use PhpOpcua\SessionManager\Daemon\CommandHandler;
use PhpOpcua\SessionManager\Daemon\Session;
use PhpOpcua\SessionManager\Daemon\SessionStore;

describe('CommandHandler Security', function () {

    beforeEach(function () {
        $this->store = new SessionStore();
        $this->handler = new CommandHandler($this->store, maxSessions: 3);
    });

    // ── Method whitelist ──────────────────────────────────────────────

    describe('Method whitelist', function () {

        it('rejects calls to non-whitelisted methods', function () {
            $client = $this->createStub(Client::class);
            $session = new Session('sess1', $client, 'opc.tcp://localhost:4840', [], microtime(true));
            $this->store->create($session);

            $result = $this->handler->handle([
                'command' => 'query',
                'sessionId' => 'sess1',
                'method' => '__destruct',
                'params' => [],
            ]);

            expect($result['success'])->toBeFalse();
            expect($result['error']['type'])->toBe('forbidden_method');
            expect($result['error']['message'])->toContain('__destruct');
        });

        it('rejects setter methods (setTimeout, setAutoRetry, etc.) via query', function () {
            $client = $this->createStub(Client::class);
            $session = new Session('sess1', $client, 'opc.tcp://localhost:4840', [], microtime(true));
            $this->store->create($session);

            foreach (['setTimeout', 'setAutoRetry', 'setBatchSize', 'setDefaultBrowseMaxDepth', 'setLogger', 'setCache'] as $method) {
                $result = $this->handler->handle([
                    'command' => 'query',
                    'sessionId' => 'sess1',
                    'method' => $method,
                    'params' => [1],
                ]);

                expect($result['success'])->toBeFalse();
                expect($result['error']['type'])->toBe('forbidden_method');
            }
        });

        it('rejects calls to setSecurityPolicy via query', function () {
            $client = $this->createStub(Client::class);
            $session = new Session('sess1', $client, 'opc.tcp://localhost:4840', [], microtime(true));
            $this->store->create($session);

            $result = $this->handler->handle([
                'command' => 'query',
                'sessionId' => 'sess1',
                'method' => 'setSecurityPolicy',
                'params' => ['http://opcfoundation.org/UA/SecurityPolicy#None'],
            ]);

            expect($result['success'])->toBeFalse();
            expect($result['error']['type'])->toBe('forbidden_method');
        });

        it('rejects calls to setUserCredentials via query', function () {
            $client = $this->createStub(Client::class);
            $session = new Session('sess1', $client, 'opc.tcp://localhost:4840', [], microtime(true));
            $this->store->create($session);

            $result = $this->handler->handle([
                'command' => 'query',
                'sessionId' => 'sess1',
                'method' => 'setUserCredentials',
                'params' => ['admin', 'password'],
            ]);

            expect($result['success'])->toBeFalse();
            expect($result['error']['type'])->toBe('forbidden_method');
        });

        it('rejects arbitrary PHP magic methods', function () {
            $client = $this->createStub(Client::class);
            $session = new Session('sess1', $client, 'opc.tcp://localhost:4840', [], microtime(true));
            $this->store->create($session);

            foreach (['__toString', '__clone', '__sleep', '__wakeup', '__invoke'] as $method) {
                $result = $this->handler->handle([
                    'command' => 'query',
                    'sessionId' => 'sess1',
                    'method' => $method,
                    'params' => [],
                ]);

                expect($result['success'])->toBeFalse();
                expect($result['error']['type'])->toBe('forbidden_method');
            }
        });

        it('rejects empty method name', function () {
            $client = $this->createStub(Client::class);
            $session = new Session('sess1', $client, 'opc.tcp://localhost:4840', [], microtime(true));
            $this->store->create($session);

            $result = $this->handler->handle([
                'command' => 'query',
                'sessionId' => 'sess1',
                'method' => '',
                'params' => [],
            ]);

            expect($result['success'])->toBeFalse();
            expect($result['error']['type'])->toBe('forbidden_method');
        });

    });

    // ── Credential stripping from list ────────────────────────────────

    describe('Credential stripping', function () {

        it('strips password from list output', function () {
            $config = [
                'username' => 'admin',
                'password' => 'secret123',
                'securityPolicy' => 'http://opcfoundation.org/UA/SecurityPolicy#None',
            ];
            $client = $this->createStub(Client::class);
            $session = new Session('sess1', $client, 'opc.tcp://localhost:4840', $config, microtime(true));
            $this->store->create($session);

            $result = $this->handler->handle(['command' => 'list']);

            expect($result['success'])->toBeTrue();
            $sessionConfig = $result['data']['sessions'][0]['config'];
            expect($sessionConfig)->not->toHaveKey('password');
            expect($sessionConfig)->toHaveKey('username');
            expect($sessionConfig['username'])->toBe('admin');
            expect($sessionConfig)->toHaveKey('securityPolicy');
        });

        it('strips private key paths from list output', function () {
            $config = [
                'clientCertPath' => '/etc/opcua/cert.pem',
                'clientKeyPath' => '/etc/opcua/key.pem',
                'caCertPath' => '/etc/opcua/ca.pem',
                'userCertPath' => '/etc/opcua/user-cert.pem',
                'userKeyPath' => '/etc/opcua/user-key.pem',
            ];
            $client = $this->createStub(Client::class);
            $session = new Session('sess1', $client, 'opc.tcp://localhost:4840', $config, microtime(true));
            $this->store->create($session);

            $result = $this->handler->handle(['command' => 'list']);

            $sessionConfig = $result['data']['sessions'][0]['config'];
            expect($sessionConfig)->not->toHaveKey('password');
            expect($sessionConfig)->not->toHaveKey('clientKeyPath');
            expect($sessionConfig)->not->toHaveKey('userKeyPath');
            expect($sessionConfig)->not->toHaveKey('caCertPath');
            expect($sessionConfig)->toHaveKey('clientCertPath');
            expect($sessionConfig)->toHaveKey('userCertPath');
        });

        it('handles config with no sensitive keys', function () {
            $config = ['securityMode' => 1];
            $client = $this->createStub(Client::class);
            $session = new Session('sess1', $client, 'opc.tcp://localhost:4840', $config, microtime(true));
            $this->store->create($session);

            $result = $this->handler->handle(['command' => 'list']);

            $sessionConfig = $result['data']['sessions'][0]['config'];
            expect($sessionConfig)->toBe(['securityMode' => 1]);
        });

    });

    // ── Max sessions ──────────────────────────────────────────────────

    describe('Max sessions limit', function () {

        it('rejects open when max sessions reached', function () {
            // Fill up the store to max (3)
            for ($i = 0; $i < 3; $i++) {
                $client = $this->createStub(Client::class);
                $session = new Session("sess{$i}", $client, "opc.tcp://host{$i}:4840", [], microtime(true));
                $this->store->create($session);
            }

            $result = $this->handler->handle([
                'command' => 'open',
                'endpointUrl' => 'opc.tcp://new-host:4840',
                'config' => [],
            ]);

            expect($result['success'])->toBeFalse();
            expect($result['error']['type'])->toBe('max_sessions_reached');
            expect($result['error']['message'])->toContain('3');
        });

        it('allows open after closing a session', function () {
            for ($i = 0; $i < 3; $i++) {
                $client = $this->createStub(Client::class);
                $session = new Session("sess{$i}", $client, "opc.tcp://host{$i}:4840", [], microtime(true));
                $this->store->create($session);
            }

            // Remove one session
            $this->store->remove('sess0');

            // Now a new open should at least get past the max_sessions check
            // (it will fail at connect since we have no real server, but not with max_sessions_reached)
            $result = $this->handler->handle([
                'command' => 'open',
                'endpointUrl' => 'opc.tcp://new-host:4840',
                'config' => [],
            ]);

            expect($result['error']['type'] ?? '')->not->toBe('max_sessions_reached');
        });

    });

    // ── Certificate path validation ───────────────────────────────────

    describe('Certificate path validation', function () {

        it('rejects non-existent certificate paths', function () {
            $result = $this->handler->handle([
                'command' => 'open',
                'endpointUrl' => 'opc.tcp://localhost:4840',
                'config' => [
                    'clientCertPath' => '/nonexistent/path/cert.pem',
                    'clientKeyPath' => '/nonexistent/path/key.pem',
                ],
            ]);

            expect($result['success'])->toBeFalse();
            expect($result['error']['message'])->toContain('does not exist');
        });

        it('rejects certificate paths pointing to directories', function () {
            $result = $this->handler->handle([
                'command' => 'open',
                'endpointUrl' => 'opc.tcp://localhost:4840',
                'config' => [
                    'clientCertPath' => '/tmp',
                    'clientKeyPath' => '/tmp',
                ],
            ]);

            expect($result['success'])->toBeFalse();
            expect($result['error']['message'])->toContain('does not exist or is not a file');
        });

        it('rejects paths outside allowed cert dirs', function () {
            $handler = new CommandHandler(
                $this->store,
                maxSessions: 100,
                allowedCertDirs: ['/etc/opcua/certs'],
            );

            // Create a real temp file to test with
            $tmpFile = tempnam(sys_get_temp_dir(), 'opcua_test_');
            file_put_contents($tmpFile, 'fake cert');

            try {
                $result = $handler->handle([
                    'command' => 'open',
                    'endpointUrl' => 'opc.tcp://localhost:4840',
                    'config' => [
                        'clientCertPath' => $tmpFile,
                        'clientKeyPath' => $tmpFile,
                    ],
                ]);

                expect($result['success'])->toBeFalse();
                expect($result['error']['message'])->toContain('not in an allowed directory');
            } finally {
                unlink($tmpFile);
            }
        });

        it('allows paths inside allowed cert dirs', function () {
            // Use a dir that exists on the system
            $tmpDir = sys_get_temp_dir();
            $handler = new CommandHandler(
                $this->store,
                maxSessions: 100,
                allowedCertDirs: [$tmpDir],
            );

            $tmpFile = tempnam($tmpDir, 'opcua_test_');
            file_put_contents($tmpFile, 'fake cert');

            try {
                $result = $handler->handle([
                    'command' => 'open',
                    'endpointUrl' => 'opc.tcp://localhost:4840',
                    'config' => [
                        'clientCertPath' => $tmpFile,
                        'clientKeyPath' => $tmpFile,
                    ],
                ]);

                // It will fail at connect (no real server), but NOT at cert validation
                expect($result['error']['type'] ?? '')->not->toBe('InvalidArgumentException');
            } finally {
                unlink($tmpFile);
            }
        });

        it('passes when no allowed dirs configured and file exists', function () {
            $tmpFile = tempnam(sys_get_temp_dir(), 'opcua_test_');
            file_put_contents($tmpFile, 'fake cert');

            try {
                $result = $this->handler->handle([
                    'command' => 'open',
                    'endpointUrl' => 'opc.tcp://localhost:4840',
                    'config' => [
                        'clientCertPath' => $tmpFile,
                        'clientKeyPath' => $tmpFile,
                    ],
                ]);

                // Should fail at connect, not cert validation
                expect($result['error']['message'] ?? '')->not->toContain('does not exist');
                expect($result['error']['message'] ?? '')->not->toContain('not in an allowed directory');
            } finally {
                unlink($tmpFile);
            }
        });

    });

    // ── connect/disconnect removed from whitelist ──────────────────────

    describe('connect/disconnect not in whitelist', function () {

        it('rejects connect via query', function () {
            $client = $this->createStub(Client::class);
            $session = new Session('sess1', $client, 'opc.tcp://localhost:4840', [], microtime(true));
            $this->store->create($session);

            $result = $this->handler->handle([
                'command' => 'query',
                'sessionId' => 'sess1',
                'method' => 'connect',
                'params' => ['opc.tcp://evil:4840'],
            ]);

            expect($result['success'])->toBeFalse();
            expect($result['error']['type'])->toBe('forbidden_method');
        });

        it('rejects disconnect via query', function () {
            $client = $this->createStub(Client::class);
            $session = new Session('sess1', $client, 'opc.tcp://localhost:4840', [], microtime(true));
            $this->store->create($session);

            $result = $this->handler->handle([
                'command' => 'query',
                'sessionId' => 'sess1',
                'method' => 'disconnect',
                'params' => [],
            ]);

            expect($result['success'])->toBeFalse();
            expect($result['error']['type'])->toBe('forbidden_method');
        });

    });

    // ── Error message sanitization ──────────────────────────────────

    describe('Error message sanitization', function () {

        it('truncates long error messages', function () {
            $client = $this->createStub(Client::class);
            $session = new Session('sess1', $client, 'opc.tcp://localhost:4840', [], microtime(true));
            $this->store->create($session);

            // Stub browse to throw an exception with a very long message
            $client->method('browse')->willThrowException(
                new RuntimeException(str_repeat('X', 1000))
            );

            $result = $this->handler->handle([
                'command' => 'query',
                'sessionId' => 'sess1',
                'method' => 'browse',
                'params' => [['ns' => 0, 'id' => 85, 'type' => 'numeric']],
            ]);

            expect($result['success'])->toBeFalse();
            expect(strlen($result['error']['message']))->toBeLessThanOrEqual(503); // 500 + "..."
        });

        it('strips file paths from error messages', function () {
            $client = $this->createStub(Client::class);
            $session = new Session('sess1', $client, 'opc.tcp://localhost:4840', [], microtime(true));
            $this->store->create($session);

            $client->method('browse')->willThrowException(
                new RuntimeException('Error in /home/user/secret/file.php: something broke')
            );

            $result = $this->handler->handle([
                'command' => 'query',
                'sessionId' => 'sess1',
                'method' => 'browse',
                'params' => [['ns' => 0, 'id' => 85, 'type' => 'numeric']],
            ]);

            expect($result['success'])->toBeFalse();
            expect($result['error']['message'])->not->toContain('/home/user/secret');
            expect($result['error']['message'])->toContain('[path]');
        });

    });

    // ── Unknown command ───────────────────────────────────────────────

    describe('Unknown commands', function () {

        it('rejects unknown commands', function () {
            $result = $this->handler->handle(['command' => 'evil_command']);

            expect($result['success'])->toBeFalse();
            expect($result['error']['type'])->toBe('unknown_command');
        });

        it('handles missing command field', function () {
            $result = $this->handler->handle([]);

            expect($result['success'])->toBeFalse();
            expect($result['error']['type'])->toBe('unknown_command');
        });

    });

});
