<?php

declare(strict_types=1);

use PhpOpcua\Client\Client;
use PhpOpcua\SessionManager\Daemon\CommandHandler;
use PhpOpcua\SessionManager\Daemon\Session;
use PhpOpcua\SessionManager\Daemon\SessionStore;

describe('Auto-generated certificate', function () {

    beforeEach(function () {
        $this->store = new SessionStore();
        $this->handler = new CommandHandler($this->store, maxSessions: 10);
    });

    // ── Cert path validation is skipped when no paths are provided ────

    describe('Certificate path validation bypass', function () {

        it('does not reject open when security policy/mode set but no cert paths provided', function () {
            // No cert paths → no path validation → failure comes from connect (no real server)
            $result = $this->handler->handle([
                'command' => 'open',
                'endpointUrl' => 'opc.tcp://localhost:4840',
                'config' => [
                    'securityPolicy' => 'http://opcfoundation.org/UA/SecurityPolicy#Basic256Sha256',
                    'securityMode' => 3, // SignAndEncrypt
                ],
            ]);

            expect($result['success'])->toBeFalse();
            expect($result['error']['message'] ?? '')->not->toContain('does not exist');
            expect($result['error']['message'] ?? '')->not->toContain('not in an allowed directory');
            expect($result['error']['type'] ?? '')->not->toBe('InvalidArgumentException');
        });

        it('does not reject when allowedCertDirs is set but no cert paths are provided', function () {
            $handler = new CommandHandler(
                $this->store,
                maxSessions: 10,
                allowedCertDirs: ['/etc/opcua/certs'],
            );

            $result = $handler->handle([
                'command' => 'open',
                'endpointUrl' => 'opc.tcp://localhost:4840',
                'config' => [
                    'securityPolicy' => 'http://opcfoundation.org/UA/SecurityPolicy#Basic256Sha256',
                    'securityMode' => 3,
                ],
            ]);

            // allowedCertDirs restriction applies only to explicitly provided paths
            expect($result['success'])->toBeFalse();
            expect($result['error']['type'] ?? '')->not->toBe('InvalidArgumentException');
            expect($result['error']['message'] ?? '')->not->toContain('not in an allowed directory');
        });

        it('does not reject SignOnly mode without cert paths', function () {
            $result = $this->handler->handle([
                'command' => 'open',
                'endpointUrl' => 'opc.tcp://localhost:4840',
                'config' => [
                    'securityPolicy' => 'http://opcfoundation.org/UA/SecurityPolicy#Basic256Sha256',
                    'securityMode' => 2, // Sign
                ],
            ]);

            expect($result['success'])->toBeFalse();
            expect($result['error']['type'] ?? '')->not->toBe('InvalidArgumentException');
        });

    });

    // ── Config stored in session for auto-cert connections ────────────

    describe('Session config for auto-cert connections', function () {

        it('preserves security policy and mode in list output for auto-cert session', function () {
            $config = [
                'securityPolicy' => 'http://opcfoundation.org/UA/SecurityPolicy#Basic256Sha256',
                'securityMode' => 3,
            ];
            $client = $this->createStub(Client::class);
            $session = new Session('sess-auto', $client, 'opc.tcp://localhost:4845', $config, microtime(true));
            $this->store->create($session);

            $result = $this->handler->handle(['command' => 'list']);

            expect($result['success'])->toBeTrue();
            $sessionConfig = $result['data']['sessions'][0]['config'];
            expect($sessionConfig)->toHaveKey('securityPolicy');
            expect($sessionConfig)->toHaveKey('securityMode');
            expect($sessionConfig['securityPolicy'])->toBe('http://opcfoundation.org/UA/SecurityPolicy#Basic256Sha256');
            expect($sessionConfig['securityMode'])->toBe(3);
        });

        it('does not expose cert paths in list output for auto-cert session', function () {
            // An auto-cert session has no cert paths in config at all
            $config = [
                'securityPolicy' => 'http://opcfoundation.org/UA/SecurityPolicy#Basic256Sha256',
                'securityMode' => 3,
            ];
            $client = $this->createStub(Client::class);
            $session = new Session('sess-auto', $client, 'opc.tcp://localhost:4845', $config, microtime(true));
            $this->store->create($session);

            $result = $this->handler->handle(['command' => 'list']);

            $sessionConfig = $result['data']['sessions'][0]['config'];
            expect($sessionConfig)->not->toHaveKey('clientCertPath');
            expect($sessionConfig)->not->toHaveKey('clientKeyPath');
            expect($sessionConfig)->not->toHaveKey('caCertPath');
            expect($sessionConfig)->not->toHaveKey('userCertPath');
            expect($sessionConfig)->not->toHaveKey('userKeyPath');
        });

        it('sanitizeConfig does not alter auto-cert config (no sensitive keys present)', function () {
            $config = [
                'securityPolicy' => 'http://opcfoundation.org/UA/SecurityPolicy#Basic256Sha256',
                'securityMode' => 3,
            ];
            $client = $this->createStub(Client::class);
            $session = new Session('sess-auto', $client, 'opc.tcp://localhost:4845', $config, microtime(true));
            $this->store->create($session);

            $result = $this->handler->handle(['command' => 'list']);

            $sessionConfig = $result['data']['sessions'][0]['config'];
            // Only the two keys we set should remain
            expect(array_keys($sessionConfig))->toBe(['securityPolicy', 'securityMode']);
        });

    });

    // ── Interplay: auto-cert does not interfere with explicit cert sessions ──

    describe('Auto-cert and explicit cert sessions coexistence', function () {

        it('explicit cert session still strips private key path from list', function () {
            $explicitConfig = [
                'securityPolicy' => 'http://opcfoundation.org/UA/SecurityPolicy#Basic256Sha256',
                'securityMode' => 3,
                'clientCertPath' => '/etc/opcua/cert.pem',
                'clientKeyPath' => '/etc/opcua/key.pem',
            ];
            $autoConfig = [
                'securityPolicy' => 'http://opcfoundation.org/UA/SecurityPolicy#Basic256Sha256',
                'securityMode' => 3,
            ];

            $client1 = $this->createStub(Client::class);
            $client2 = $this->createStub(Client::class);
            $this->store->create(new Session('sess-explicit', $client1, 'opc.tcp://localhost:4845', $explicitConfig, microtime(true)));
            $this->store->create(new Session('sess-auto', $client2, 'opc.tcp://localhost:4845', $autoConfig, microtime(true)));

            $result = $this->handler->handle(['command' => 'list']);

            expect($result['success'])->toBeTrue();
            expect($result['data']['count'])->toBe(2);

            $configs = array_column($result['data']['sessions'], 'config', 'id');

            // Explicit cert: cert path visible, key path stripped
            expect($configs['sess-explicit'])->toHaveKey('clientCertPath');
            expect($configs['sess-explicit'])->not->toHaveKey('clientKeyPath');

            // Auto-cert: no cert keys at all
            expect($configs['sess-auto'])->not->toHaveKey('clientCertPath');
            expect($configs['sess-auto'])->not->toHaveKey('clientKeyPath');
        });

    });

});
