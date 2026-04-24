<?php

declare(strict_types=1);

use PhpOpcua\SessionManager\Cli\ArgvParser;

$defaults = [
    'socket_path' => '/tmp/sm.sock',
    'timeout' => 600,
    'cleanup_interval' => 30,
    'auth_token' => null,
    'auth_token_file' => null,
    'max_sessions' => 100,
    'socket_mode' => 0600,
    'allowed_cert_dirs' => null,
    'log_file' => null,
    'log_level' => 'info',
    'cache_driver' => 'memory',
    'cache_path' => null,
    'cache_ttl' => 300,
];

describe('ArgvParser', function () use ($defaults) {

    it('returns defaults when no flags are passed', function () use ($defaults) {
        $result = ArgvParser::parse(['opcua-session-manager'], $defaults);

        expect($result['action'])->toBe('run');
        expect($result['options'])->toBe($defaults);
        expect($result['errors'])->toBe([]);
        expect($result['authTokenFromCli'])->toBeFalse();
    });

    it('detects --version flag', function () use ($defaults) {
        $result = ArgvParser::parse(['opcua-session-manager', '--version'], $defaults);

        expect($result['action'])->toBe('version');
    });

    it('detects -v short flag', function () use ($defaults) {
        $result = ArgvParser::parse(['opcua-session-manager', '-v'], $defaults);

        expect($result['action'])->toBe('version');
    });

    it('detects --help flag', function () use ($defaults) {
        $result = ArgvParser::parse(['opcua-session-manager', '--help'], $defaults);

        expect($result['action'])->toBe('help');
    });

    it('detects -h short flag', function () use ($defaults) {
        $result = ArgvParser::parse(['opcua-session-manager', '-h'], $defaults);

        expect($result['action'])->toBe('help');
    });

    it('parses string options', function () use ($defaults) {
        $result = ArgvParser::parse([
            'opcua-session-manager',
            '--socket', '/var/run/opcua.sock',
            '--log-file', '/var/log/opcua.log',
            '--log-level', 'debug',
            '--cache-driver', 'file',
            '--cache-path', '/var/cache/opcua',
        ], $defaults);

        expect($result['options']['socket_path'])->toBe('/var/run/opcua.sock');
        expect($result['options']['log_file'])->toBe('/var/log/opcua.log');
        expect($result['options']['log_level'])->toBe('debug');
        expect($result['options']['cache_driver'])->toBe('file');
        expect($result['options']['cache_path'])->toBe('/var/cache/opcua');
    });

    it('parses integer options with type coercion', function () use ($defaults) {
        $result = ArgvParser::parse([
            'opcua-session-manager',
            '--timeout', '1200',
            '--cleanup-interval', '60',
            '--max-sessions', '50',
            '--cache-ttl', '900',
        ], $defaults);

        expect($result['options']['timeout'])->toBe(1200);
        expect($result['options']['cleanup_interval'])->toBe(60);
        expect($result['options']['max_sessions'])->toBe(50);
        expect($result['options']['cache_ttl'])->toBe(900);
    });

    it('parses --socket-mode as octal', function () use ($defaults) {
        $result = ArgvParser::parse([
            'opcua-session-manager',
            '--socket-mode', '0644',
        ], $defaults);

        expect($result['options']['socket_mode'])->toBe(0644);
    });

    it('splits --allowed-cert-dirs on commas', function () use ($defaults) {
        $result = ArgvParser::parse([
            'opcua-session-manager',
            '--allowed-cert-dirs', '/etc/ssl,/home/user/certs',
        ], $defaults);

        expect($result['options']['allowed_cert_dirs'])->toBe(['/etc/ssl', '/home/user/certs']);
    });

    it('flags --auth-token as coming from CLI', function () use ($defaults) {
        $result = ArgvParser::parse([
            'opcua-session-manager',
            '--auth-token', 'secret',
        ], $defaults);

        expect($result['options']['auth_token'])->toBe('secret');
        expect($result['authTokenFromCli'])->toBeTrue();
    });

    it('does not flag --auth-token-file as coming from CLI', function () use ($defaults) {
        $result = ArgvParser::parse([
            'opcua-session-manager',
            '--auth-token-file', '/etc/opcua/token',
        ], $defaults);

        expect($result['options']['auth_token_file'])->toBe('/etc/opcua/token');
        expect($result['authTokenFromCli'])->toBeFalse();
    });

    it('reports an error when a value flag has no value', function () use ($defaults) {
        $result = ArgvParser::parse([
            'opcua-session-manager',
            '--socket',
        ], $defaults);

        expect($result['errors'])->toHaveCount(1);
        expect($result['errors'][0])->toContain('--socket');
        expect($result['errors'][0])->toContain('Missing value');
    });

    it('silently ignores unknown flags (backwards-compat with the previous inline parser)', function () use ($defaults) {
        $result = ArgvParser::parse([
            'opcua-session-manager',
            '--unknown-flag',
            '--timeout', '900',
        ], $defaults);

        expect($result['action'])->toBe('run');
        expect($result['errors'])->toBe([]);
        expect($result['options']['timeout'])->toBe(900);
    });

    it('stops parsing at --version even if other flags follow', function () use ($defaults) {
        $result = ArgvParser::parse([
            'opcua-session-manager',
            '--timeout', '900',
            '--version',
            '--socket', '/should/be/ignored',
        ], $defaults);

        expect($result['action'])->toBe('version');
    });
});
