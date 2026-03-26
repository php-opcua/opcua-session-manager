<?php

declare(strict_types=1);

use PhpOpcua\Client\Client;
use PhpOpcua\Client\Exception\ConnectionException;
use PhpOpcua\Client\Types\DataValue;
use PhpOpcua\Client\Types\BuiltinType;
use PhpOpcua\Client\Types\Variant;
use PhpOpcua\SessionManager\Daemon\CommandHandler;
use PhpOpcua\SessionManager\Daemon\Session;
use PhpOpcua\SessionManager\Daemon\SessionStore;

describe('CommandHandler Extended', function () {

    beforeEach(function () {
        $this->store = new SessionStore();
        $this->handler = new CommandHandler($this->store);
    });

    describe('handleClose', function () {

        it('closes an existing session', function () {
            $client = $this->createStub(Client::class);
            $session = new Session('s1', $client, 'opc.tcp://localhost:4840', [], microtime(true));
            $this->store->create($session);

            $result = $this->handler->handle(['command' => 'close', 'sessionId' => 's1']);

            expect($result['success'])->toBeTrue();
            expect($result['data'])->toBeNull();
            expect($this->store->count())->toBe(0);
        });

        it('closes session even when disconnect throws', function () {
            $client = $this->createStub(Client::class);
            $client->method('disconnect')->willThrowException(new RuntimeException('disconnect failed'));
            $session = new Session('s1', $client, 'opc.tcp://localhost:4840', [], microtime(true));
            $this->store->create($session);

            $result = $this->handler->handle(['command' => 'close', 'sessionId' => 's1']);

            expect($result['success'])->toBeTrue();
            expect($this->store->count())->toBe(0);
        });

        it('returns session_not_found for non-existent session', function () {
            $result = $this->handler->handle(['command' => 'close', 'sessionId' => 'nonexistent']);

            expect($result['success'])->toBeFalse();
            expect($result['error']['type'])->toBe('session_not_found');
        });

    });

    describe('handleOpen error paths', function () {

        it('returns error on connect failure', function () {
            $result = $this->handler->handle([
                'command' => 'open',
                'endpointUrl' => 'opc.tcp://nonexistent-host:99999',
                'config' => [],
            ]);

            expect($result['success'])->toBeFalse();
        });

        it('applies opcuaTimeout config', function () {
            $result = $this->handler->handle([
                'command' => 'open',
                'endpointUrl' => 'opc.tcp://nonexistent-host:99999',
                'config' => ['opcuaTimeout' => 0.1],
            ]);

            expect($result['success'])->toBeFalse();
        });

        it('applies autoRetry config', function () {
            $result = $this->handler->handle([
                'command' => 'open',
                'endpointUrl' => 'opc.tcp://nonexistent-host:99999',
                'config' => ['autoRetry' => 0, 'opcuaTimeout' => 0.1],
            ]);

            expect($result['success'])->toBeFalse();
        });

        it('applies batchSize config', function () {
            $result = $this->handler->handle([
                'command' => 'open',
                'endpointUrl' => 'opc.tcp://nonexistent-host:99999',
                'config' => ['batchSize' => 50, 'opcuaTimeout' => 0.1],
            ]);

            expect($result['success'])->toBeFalse();
        });

        it('applies defaultBrowseMaxDepth config', function () {
            $result = $this->handler->handle([
                'command' => 'open',
                'endpointUrl' => 'opc.tcp://nonexistent-host:99999',
                'config' => ['defaultBrowseMaxDepth' => 20, 'opcuaTimeout' => 0.1],
            ]);

            expect($result['success'])->toBeFalse();
        });

        it('applies securityPolicy and securityMode config', function () {
            $result = $this->handler->handle([
                'command' => 'open',
                'endpointUrl' => 'opc.tcp://nonexistent-host:99999',
                'config' => [
                    'securityPolicy' => 'http://opcfoundation.org/UA/SecurityPolicy#None',
                    'securityMode' => 1,
                    'opcuaTimeout' => 0.1,
                ],
            ]);

            expect($result['success'])->toBeFalse();
        });

        it('applies username/password config', function () {
            $result = $this->handler->handle([
                'command' => 'open',
                'endpointUrl' => 'opc.tcp://nonexistent-host:99999',
                'config' => [
                    'username' => 'admin',
                    'password' => 'secret',
                    'opcuaTimeout' => 0.1,
                ],
            ]);

            expect($result['success'])->toBeFalse();
        });

        it('applies clientCache when configured', function () {
            $cache = $this->createStub(\Psr\SimpleCache\CacheInterface::class);
            $handler = new CommandHandler($this->store, clientCache: $cache);

            $result = $handler->handle([
                'command' => 'open',
                'endpointUrl' => 'opc.tcp://nonexistent-host:99999',
                'config' => ['opcuaTimeout' => 0.1],
            ]);

            expect($result['success'])->toBeFalse();
        });

    });

    describe('Error sanitization', function () {

        it('sanitizes error messages from generic Throwable', function () {
            $client = $this->createStub(Client::class);
            $client->method('read')->willThrowException(
                new RuntimeException('Error at /home/user/secret/path.php: failed')
            );
            $session = new Session('s1', $client, 'opc.tcp://localhost:4840', [], microtime(true));
            $this->store->create($session);

            $result = $this->handler->handle([
                'command' => 'query', 'sessionId' => 's1', 'method' => 'read',
                'params' => [['ns' => 0, 'id' => 2259, 'type' => 'numeric'], 13],
            ]);

            expect($result['success'])->toBeFalse();
            expect($result['error']['message'])->toContain('[path]');
            expect($result['error']['message'])->not->toContain('/home/user/secret');
        });

    });

    describe('Certificate validation in handleOpen', function () {

        it('rejects userCertPath that does not exist', function () {
            $result = $this->handler->handle([
                'command' => 'open',
                'endpointUrl' => 'opc.tcp://localhost:4840',
                'config' => [
                    'userCertPath' => '/nonexistent/user-cert.pem',
                    'userKeyPath' => '/nonexistent/user-key.pem',
                ],
            ]);

            expect($result['success'])->toBeFalse();
            expect($result['error']['message'])->toContain('does not exist');
        });

        it('validates allowedCertDirs path resolution failure', function () {
            $tmpFile = tempnam(sys_get_temp_dir(), 'opcua_test_');
            file_put_contents($tmpFile, 'fake cert');

            try {
                $handler = new CommandHandler($this->store, allowedCertDirs: ['/nonexistent/allowed']);

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

    });

    describe('handleOpen config branches', function () {

        it('applies userCertPath/userKeyPath config', function () {
            $tmpCert = tempnam(sys_get_temp_dir(), 'opcua_ucert_');
            $tmpKey = tempnam(sys_get_temp_dir(), 'opcua_ukey_');
            file_put_contents($tmpCert, 'fake user cert');
            file_put_contents($tmpKey, 'fake user key');

            try {
                $result = $this->handler->handle([
                    'command' => 'open',
                    'endpointUrl' => 'opc.tcp://nonexistent-host:99999',
                    'config' => [
                        'userCertPath' => $tmpCert,
                        'userKeyPath' => $tmpKey,
                        'opcuaTimeout' => 0.1,
                    ],
                ]);

                expect($result['success'])->toBeFalse();
                expect($result['error']['type'])->not->toBe('InvalidArgumentException');
            } finally {
                unlink($tmpCert);
                unlink($tmpKey);
            }
        });

        it('applies clientCertPath with caCertPath config', function () {
            $tmpCert = tempnam(sys_get_temp_dir(), 'opcua_cert_');
            $tmpKey = tempnam(sys_get_temp_dir(), 'opcua_key_');
            $tmpCa = tempnam(sys_get_temp_dir(), 'opcua_ca_');
            file_put_contents($tmpCert, 'fake cert');
            file_put_contents($tmpKey, 'fake key');
            file_put_contents($tmpCa, 'fake ca');

            try {
                $result = $this->handler->handle([
                    'command' => 'open',
                    'endpointUrl' => 'opc.tcp://nonexistent-host:99999',
                    'config' => [
                        'clientCertPath' => $tmpCert,
                        'clientKeyPath' => $tmpKey,
                        'caCertPath' => $tmpCa,
                        'opcuaTimeout' => 0.1,
                    ],
                ]);

                expect($result['success'])->toBeFalse();
                expect($result['error']['type'])->not->toBe('InvalidArgumentException');
            } finally {
                unlink($tmpCert);
                unlink($tmpKey);
                unlink($tmpCa);
            }
        });

    });

    describe('handleQuery — result instanceof Client', function () {

        it('returns null data when method returns void (Client-like)', function () {
            $client = $this->createStub(Client::class);
            $session = new Session('s1', $client, 'opc.tcp://localhost:4840', [], microtime(true));
            $this->store->create($session);

            $result = $this->handler->handle([
                'command' => 'query', 'sessionId' => 's1', 'method' => 'flushCache', 'params' => [],
            ]);

            expect($result['success'])->toBeTrue();
            expect($result['data'])->toBeNull();
        });

    });

    describe('Certificate path cannot be resolved', function () {

        it('rejects cert path that realpath cannot resolve', function () {
            $tmpDir = sys_get_temp_dir();
            $handler = new CommandHandler($this->store, allowedCertDirs: [$tmpDir]);

            $symlinkPath = $tmpDir . '/opcua_broken_link_' . bin2hex(random_bytes(4));
            symlink('/nonexistent/target', $symlinkPath);

            try {
                $result = $handler->handle([
                    'command' => 'open',
                    'endpointUrl' => 'opc.tcp://localhost:4840',
                    'config' => [
                        'clientCertPath' => $symlinkPath,
                        'clientKeyPath' => $symlinkPath,
                    ],
                ]);

                expect($result['success'])->toBeFalse();
                expect($result['error']['message'])->toContain('does not exist');
            } finally {
                if (is_link($symlinkPath)) {
                    unlink($symlinkPath);
                }
            }
        });

    });

    describe('throwInvalidArgumentIf', function () {

        it('throws when condition is true', function () {
            $method = new ReflectionMethod(CommandHandler::class, 'throwInvalidArgumentIf');
            $method->setAccessible(true);

            expect(fn() => $method->invoke(null, true, 'Path cannot be resolved'))
                ->toThrow(InvalidArgumentException::class, 'Path cannot be resolved');
        });

        it('does nothing when condition is false', function () {
            $method = new ReflectionMethod(CommandHandler::class, 'throwInvalidArgumentIf');
            $method->setAccessible(true);

            $method->invoke(null, false, 'Should not throw');
            expect(true)->toBeTrue();
        });

    });

});
