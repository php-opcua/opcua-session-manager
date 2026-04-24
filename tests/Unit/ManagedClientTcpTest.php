<?php

declare(strict_types=1);

/**
 * Cross-OS unit test for the ManagedClient IPC error-mapping path.
 *
 * `ManagedClientIpcTest` uses `pcntl_fork()` + `unix://` sockets and is therefore
 * marked `->skipOnWindows()`. This file covers the same critical path — the
 * exception mapping in `sendCommand()` — over the loopback TCP transport, using
 * `proc_open()` to spawn a fake daemon in a child PHP process. Runs on Linux,
 * macOS, and Windows.
 */

use PhpOpcua\Client\Exception\ConnectionException;
use PhpOpcua\Client\Exception\ServiceException;
use PhpOpcua\Client\Exception\ServiceUnsupportedException;
use PhpOpcua\SessionManager\Client\ManagedClient;
use PhpOpcua\SessionManager\Exception\DaemonException;

/**
 * @param array<int, array<string, mixed>> $responses
 * @return array{endpoint: string, process: resource, pipes: array}
 */
function startTcpFakeDaemon(array $responses): array
{
    $listener = @stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
    if ($listener === false) {
        throw new RuntimeException("Cannot open listener: [{$errno}] {$errstr}");
    }
    $name = stream_socket_get_name($listener, false);
    fclose($listener);
    if ($name === false) {
        throw new RuntimeException('Cannot resolve listener endpoint');
    }
    [$host, $port] = explode(':', $name);

    $responsesJson = base64_encode(serialize($responses));

    $script = <<<'PHP'
<?php
$responses = unserialize(base64_decode($argv[1]));
$host = $argv[2];
$port = (int) $argv[3];

$server = stream_socket_server("tcp://{$host}:{$port}");
if ($server === false) {
    exit(1);
}

// Ready handshake: first connection is the parent's readiness probe, accept
// and close it without consuming an entry from $responses.
$probe = @stream_socket_accept($server, 10);
if ($probe !== false) {
    fclose($probe);
}

foreach ($responses as $responseData) {
    $conn = @stream_socket_accept($server, 10);
    if ($conn === false) {
        break;
    }

    $data = '';
    while (!str_contains($data, "\n")) {
        $chunk = fread($conn, 65536);
        if ($chunk === false || $chunk === '') {
            break;
        }
        $data .= $chunk;
    }

    fwrite($conn, json_encode($responseData) . "\n");
    fclose($conn);
}

fclose($server);
exit(0);
PHP;

    $scriptFile = tempnam(sys_get_temp_dir(), 'opcua_mc_tcp_') . '.php';
    file_put_contents($scriptFile, $script);

    $descriptors = [
        0 => ['pipe', 'r'],
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w'],
    ];
    $process = proc_open(
        [PHP_BINARY, $scriptFile, $responsesJson, $host, $port],
        $descriptors,
        $pipes,
    );
    if (! is_resource($process)) {
        @unlink($scriptFile);

        throw new RuntimeException('Cannot spawn fake daemon subprocess');
    }

    // Ready probe: wait until the subprocess has bound its listener, then
    // close the probe connection. The fake-daemon script drains exactly one
    // such probe before entering the response loop, so this does not steal a
    // slot from $responses. Wrap the retry loop with a silent error handler so
    // Pest does not report early "connection refused" warnings while the
    // subprocess is still spinning up.
    set_error_handler(static fn (): bool => true);
    try {
        $probeConnected = false;
        $deadline = microtime(true) + 3.0;
        while (microtime(true) < $deadline) {
            $probe = @stream_socket_client("tcp://{$host}:{$port}", $err, $errstr, 0.2);
            if ($probe !== false) {
                fclose($probe);
                $probeConnected = true;
                break;
            }
            usleep(50_000);
        }
    } finally {
        restore_error_handler();
    }
    if (! $probeConnected) {
        @unlink($scriptFile);
        proc_terminate($process);
        proc_close($process);

        throw new RuntimeException("Fake daemon did not bind tcp://{$host}:{$port} within 3s");
    }

    return [
        'endpoint' => "tcp://{$host}:{$port}",
        'process' => $process,
        'pipes' => $pipes,
        'scriptFile' => $scriptFile,
    ];
}

/**
 * @param array{process: resource, pipes: array, scriptFile: string} $daemon
 * @return void
 */
function stopTcpFakeDaemon(array $daemon): void
{
    foreach ($daemon['pipes'] as $pipe) {
        if (is_resource($pipe)) {
            @fclose($pipe);
        }
    }
    if (is_resource($daemon['process'])) {
        $status = proc_get_status($daemon['process']);
        if ($status['running']) {
            proc_terminate($daemon['process']);
            usleep(200_000);
            $status = proc_get_status($daemon['process']);
            if ($status['running']) {
                proc_terminate($daemon['process'], 9);
            }
        }
        proc_close($daemon['process']);
    }
    if (isset($daemon['scriptFile']) && file_exists($daemon['scriptFile'])) {
        @unlink($daemon['scriptFile']);
    }
}

function connectFakeTcpClient(string $endpoint): ManagedClient
{
    $client = new ManagedClient($endpoint, timeout: 2.0);

    $ref = new ReflectionProperty(ManagedClient::class, 'sessionId');
    $ref->setValue($client, 'fake-session-id');

    return $client;
}

describe('ManagedClient IPC (TCP loopback, cross-OS)', function () {

    it('maps ConnectionException from daemon', function () {
        $daemon = startTcpFakeDaemon([
            ['success' => false, 'error' => ['type' => 'ConnectionException', 'message' => 'Connection lost']],
        ]);

        try {
            $client = connectFakeTcpClient($daemon['endpoint']);
            expect(fn () => $client->read('i=2259'))->toThrow(ConnectionException::class, 'Connection lost');
        } finally {
            stopTcpFakeDaemon($daemon);
        }
    });

    it('maps ServiceException from daemon', function () {
        $daemon = startTcpFakeDaemon([
            ['success' => false, 'error' => ['type' => 'ServiceException', 'message' => 'Bad node']],
        ]);

        try {
            $client = connectFakeTcpClient($daemon['endpoint']);
            expect(fn () => $client->read('i=2259'))->toThrow(ServiceException::class, 'Bad node');
        } finally {
            stopTcpFakeDaemon($daemon);
        }
    });

    it('maps ServiceUnsupportedException from daemon preserving the subclass', function () {
        $daemon = startTcpFakeDaemon([
            ['success' => false, 'error' => ['type' => 'ServiceUnsupportedException', 'message' => 'BadServiceUnsupported']],
        ]);

        try {
            $client = connectFakeTcpClient($daemon['endpoint']);
            expect(fn () => $client->addNodes([]))->toThrow(ServiceUnsupportedException::class, 'BadServiceUnsupported');
        } finally {
            stopTcpFakeDaemon($daemon);
        }
    });

    it('maps unknown error types to DaemonException', function () {
        $daemon = startTcpFakeDaemon([
            ['success' => false, 'error' => ['type' => 'SomeNovelError', 'message' => 'boom']],
        ]);

        try {
            $client = connectFakeTcpClient($daemon['endpoint']);
            expect(fn () => $client->read('i=2259'))->toThrow(DaemonException::class, '[SomeNovelError] boom');
        } finally {
            stopTcpFakeDaemon($daemon);
        }
    });
});
