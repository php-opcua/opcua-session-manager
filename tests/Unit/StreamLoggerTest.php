<?php

declare(strict_types=1);

use PhpOpcua\SessionManager\Logging\StreamLogger;
use Psr\Log\LogLevel;

describe('StreamLogger', function () {

    it('writes info messages to stream', function () {
        $stream = fopen('php://memory', 'r+');
        $logger = new StreamLogger($stream, LogLevel::DEBUG);

        $logger->info('Server started');

        rewind($stream);
        $output = stream_get_contents($stream);
        expect($output)->toContain('[INFO]');
        expect($output)->toContain('Server started');
        fclose($stream);
    });

    it('writes warning messages', function () {
        $stream = fopen('php://memory', 'r+');
        $logger = new StreamLogger($stream, LogLevel::DEBUG);

        $logger->warning('No auth token');

        rewind($stream);
        $output = stream_get_contents($stream);
        expect($output)->toContain('[WARNING]');
        expect($output)->toContain('No auth token');
        fclose($stream);
    });

    it('writes error messages', function () {
        $stream = fopen('php://memory', 'r+');
        $logger = new StreamLogger($stream, LogLevel::DEBUG);

        $logger->error('Connection failed');

        rewind($stream);
        $output = stream_get_contents($stream);
        expect($output)->toContain('[ERROR]');
        fclose($stream);
    });

    it('interpolates context placeholders', function () {
        $stream = fopen('php://memory', 'r+');
        $logger = new StreamLogger($stream, LogLevel::DEBUG);

        $logger->info('Session {id} expired', ['id' => 'abc123']);

        rewind($stream);
        $output = stream_get_contents($stream);
        expect($output)->toContain('Session abc123 expired');
        fclose($stream);
    });

    it('interpolates numeric context values', function () {
        $stream = fopen('php://memory', 'r+');
        $logger = new StreamLogger($stream, LogLevel::DEBUG);

        $logger->info('Count: {count}', ['count' => 42]);

        rewind($stream);
        $output = stream_get_contents($stream);
        expect($output)->toContain('Count: 42');
        fclose($stream);
    });

    it('skips non-stringable context values', function () {
        $stream = fopen('php://memory', 'r+');
        $logger = new StreamLogger($stream, LogLevel::DEBUG);

        $logger->info('Data: {data}', ['data' => ['array']]);

        rewind($stream);
        $output = stream_get_contents($stream);
        expect($output)->toContain('Data: {data}');
        fclose($stream);
    });

    it('filters messages below minimum level', function () {
        $stream = fopen('php://memory', 'r+');
        $logger = new StreamLogger($stream, LogLevel::WARNING);

        $logger->debug('debug message');
        $logger->info('info message');
        $logger->warning('warning message');

        rewind($stream);
        $output = stream_get_contents($stream);
        expect($output)->not->toContain('debug message');
        expect($output)->not->toContain('info message');
        expect($output)->toContain('warning message');
        fclose($stream);
    });

    it('includes timestamp in output', function () {
        $stream = fopen('php://memory', 'r+');
        $logger = new StreamLogger($stream, LogLevel::DEBUG);

        $logger->info('test');

        rewind($stream);
        $output = stream_get_contents($stream);
        expect($output)->toMatch('/\[\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}\]/');
        fclose($stream);
    });

    it('writes to a file path', function () {
        $tmpFile = tempnam(sys_get_temp_dir(), 'opcua_log_');

        try {
            $logger = new StreamLogger($tmpFile, LogLevel::DEBUG);
            $logger->info('file log test');
            unset($logger);

            $content = file_get_contents($tmpFile);
            expect($content)->toContain('file log test');
        } finally {
            if (file_exists($tmpFile)) {
                unlink($tmpFile);
            }
        }
    });

    it('creates parent directory if it does not exist', function () {
        $tmpDir = sys_get_temp_dir() . '/opcua_log_test_' . bin2hex(random_bytes(4));
        $tmpFile = $tmpDir . '/daemon.log';

        try {
            $logger = new StreamLogger($tmpFile, LogLevel::DEBUG);
            $logger->info('nested dir test');
            unset($logger);

            expect(file_exists($tmpFile))->toBeTrue();
            expect(file_get_contents($tmpFile))->toContain('nested dir test');
        } finally {
            if (file_exists($tmpFile)) {
                unlink($tmpFile);
            }
            if (is_dir($tmpDir)) {
                rmdir($tmpDir);
            }
        }
    });

    it('handles all PSR-3 log levels', function () {
        $stream = fopen('php://memory', 'r+');
        $logger = new StreamLogger($stream, LogLevel::DEBUG);

        $logger->debug('d');
        $logger->info('i');
        $logger->notice('n');
        $logger->warning('w');
        $logger->error('e');
        $logger->critical('c');
        $logger->alert('a');
        $logger->emergency('em');

        rewind($stream);
        $output = stream_get_contents($stream);
        expect($output)->toContain('[DEBUG]');
        expect($output)->toContain('[INFO]');
        expect($output)->toContain('[NOTICE]');
        expect($output)->toContain('[WARNING]');
        expect($output)->toContain('[ERROR]');
        expect($output)->toContain('[CRITICAL]');
        expect($output)->toContain('[ALERT]');
        expect($output)->toContain('[EMERGENCY]');
        fclose($stream);
    });

    it('interpolates Stringable objects in context', function () {
        $stream = fopen('php://memory', 'r+');
        $logger = new StreamLogger($stream, LogLevel::DEBUG);

        $obj = new class { public function __toString(): string { return 'stringable'; } };
        $logger->info('Value: {val}', ['val' => $obj]);

        rewind($stream);
        $output = stream_get_contents($stream);
        expect($output)->toContain('Value: stringable');
        fclose($stream);
    });

    it('throws RuntimeException when log file cannot be opened', function () {
        $dir = sys_get_temp_dir() . '/opcua_readonly_' . bin2hex(random_bytes(4));
        mkdir($dir, 0555, true);
        $file = $dir . '/daemon.log';

        try {
            expect(fn() => new StreamLogger($file))->toThrow(RuntimeException::class, 'Cannot open log file');
        } finally {
            if (file_exists($file)) {
                unlink($file);
            }
            chmod($dir, 0755);
            rmdir($dir);
        }
    });

});
