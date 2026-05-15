---
eyebrow: 'Docs · Recipes'
lede:    'The canonical Laravel pattern: bind ManagedClient as a service-container singleton, configure once, inject everywhere. Sessions persist across PHP-FPM requests because the daemon outlives them.'

see_also:
  - { href: '../managed-client/session-reuse.md',  meta: '5 min' }
  - { href: '../daemon/running-as-a-service.md',   meta: '7 min' }
  - { href: 'https://github.com/php-opcua/laravel-opcua', meta: 'external', label: 'php-opcua/laravel-opcua' }

prev: { label: 'Upgrading to v4.3',  href: './upgrading-to-v4.3.md' }
next: { label: 'Auto-publish pattern', href: './auto-publish-pattern.md' }
---

# Persistent sessions in Laravel

The session manager exists exactly for this pattern: every Laravel
HTTP request, every console command, every queue job talks to the
daemon over IPC, the daemon holds the OPC UA session, the request
budget pays an IPC round-trip instead of a full OPC UA handshake.

This recipe shows the manual integration. For a turnkey package,
use [`php-opcua/laravel-opcua`](https://github.com/php-opcua/laravel-opcua)
— it wraps everything here in a service provider + facade.

## 1 — Daemon as a service

Run the daemon as a system service. See
[Daemon · Running as a service](../daemon/running-as-a-service.md)
for the systemd / supervisor / Docker recipes. The endpoint and
auth token need to be reachable from your PHP-FPM workers.

`.env`:

<!-- @code-block language="text" label=".env" -->
```text
OPCUA_SOCKET_PATH=/var/run/opcua/sessions.sock
OPCUA_AUTH_TOKEN=long-random-string-here
OPCUA_ENDPOINT=opc.tcp://plc.local:4840
OPCUA_SECURITY_POLICY=http://opcfoundation.org/UA/SecurityPolicy#Basic256Sha256
OPCUA_SECURITY_MODE=3
OPCUA_USERNAME=integrations
OPCUA_PASSWORD=secret
OPCUA_CLIENT_CERT=/etc/opcua/client.pem
OPCUA_CLIENT_KEY=/etc/opcua/client.key
```
<!-- @endcode-block -->

## 2 — Service provider binding

Bind `OpcUaClientInterface` to the container as a singleton — one
client per request, configured exactly once, injected through DI.

<!-- @code-block language="php" label="app/Providers/OpcUaServiceProvider.php" -->
```php
namespace App\Providers;

use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\ServiceProvider;
use PhpOpcua\Client\OpcUaClientInterface;
use PhpOpcua\Client\Security\SecurityMode;
use PhpOpcua\Client\Security\SecurityPolicy;
use PhpOpcua\SessionManager\Client\ManagedClient;

class OpcUaServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(OpcUaClientInterface::class, function (Application $app) {
            $client = (new ManagedClient(
                socketPath: config('opcua.socket_path'),
                timeout:    (float) config('opcua.timeout', 30.0),
                authToken:  config('opcua.auth_token'),
            ))
                ->setLogger($app['log']->channel('opcua'))
                ->setSecurityPolicy(SecurityPolicy::from(config('opcua.security_policy')))
                ->setSecurityMode(SecurityMode::from((int) config('opcua.security_mode')))
                ->setUserCredentials(config('opcua.username'), config('opcua.password'))
                ->setClientCertificate(
                    config('opcua.client_cert'),
                    config('opcua.client_key'),
                )
                ->setTimeout((float) config('opcua.opcua_timeout', 10.0))
                ->setAutoRetry((int) config('opcua.auto_retry', 3));

            $client->connect(config('opcua.endpoint'));

            return $client;
        });
    }
}
```
<!-- @endcode-block -->

Register the provider in `config/app.php`. Add the config file:

<!-- @code-block language="php" label="config/opcua.php" -->
```php
return [
    'socket_path'     => env('OPCUA_SOCKET_PATH', '/tmp/opcua-session-manager.sock'),
    'auth_token'      => env('OPCUA_AUTH_TOKEN'),
    'timeout'         => env('OPCUA_IPC_TIMEOUT', 30.0),
    'endpoint'        => env('OPCUA_ENDPOINT'),
    'security_policy' => env('OPCUA_SECURITY_POLICY', 'http://opcfoundation.org/UA/SecurityPolicy#None'),
    'security_mode'   => env('OPCUA_SECURITY_MODE', 1),
    'username'        => env('OPCUA_USERNAME'),
    'password'        => env('OPCUA_PASSWORD'),
    'client_cert'     => env('OPCUA_CLIENT_CERT'),
    'client_key'      => env('OPCUA_CLIENT_KEY'),
    'opcua_timeout'   => env('OPCUA_TIMEOUT', 10.0),
    'auto_retry'      => env('OPCUA_AUTO_RETRY', 3),
];
```
<!-- @endcode-block -->

## 3 — Inject and use

Anywhere in your application:

<!-- @code-block language="php" label="app/Services/PlcSpeedReader.php" -->
```php
namespace App\Services;

use PhpOpcua\Client\OpcUaClientInterface;
use PhpOpcua\Client\Types\StatusCode;

readonly class PlcSpeedReader
{
    public function __construct(
        private OpcUaClientInterface $opcua,
    ) {}

    public function currentSpeed(): float
    {
        $dv = $this->opcua->read('ns=2;s=Devices/PLC/Speed');

        if (! StatusCode::isGood($dv->statusCode)) {
            throw new RuntimeException('PLC speed read failed: ' . StatusCode::getName($dv->statusCode));
        }

        return $dv->getValue();
    }
}
```
<!-- @endcode-block -->

Laravel auto-resolves the constructor argument from the container.
The first request after a daemon restart pays the OPC UA handshake;
every subsequent request — within or across PHP-FPM workers —
reuses the daemon-held session.

## 4 — Verify the reuse

In a debug controller:

<!-- @code-block language="php" label="routes/web.php" -->
```php
Route::get('/opcua/debug', function (OpcUaClientInterface $client) {
    return [
        'sessionId'  => $client instanceof \PhpOpcua\SessionManager\Client\ManagedClient
            ? $client->getSessionId()
            : null,
        'reused'     => $client instanceof \PhpOpcua\SessionManager\Client\ManagedClient
            ? $client->wasSessionReused()
            : null,
        'connected'  => $client->isConnected(),
    ];
});
```
<!-- @endcode-block -->

Hit the route once: `reused: false`. Hit it again: `reused: true`,
same `sessionId`. That is the daemon working as designed.

## Queue workers

The same binding works for queue workers (`php artisan queue:work`)
— each worker process gets the singleton, every job in the worker
talks through the cached `ManagedClient`. Per-job IPC cost is one
round-trip per OPC UA operation; session reuse across jobs is
automatic.

For long-running workers (Horizon supervisors), the worker process
itself is long-lived; the singleton survives between jobs.

## Console commands

For Artisan commands:

<!-- @code-block language="php" label="app/Console/Commands/ReadSpeed.php" -->
```php
namespace App\Console\Commands;

use App\Services\PlcSpeedReader;
use Illuminate\Console\Command;

class ReadSpeed extends Command
{
    protected $signature = 'opcua:read-speed';

    public function handle(PlcSpeedReader $reader): int
    {
        $this->line("Speed: " . $reader->currentSpeed());
        return self::SUCCESS;
    }
}
```
<!-- @endcode-block -->

The command boots the container, the singleton constructor calls
`connect()`, the read returns, the process exits. The OPC UA
session persists on the daemon side for the next request.

## Reconnect when the session goes stale

If the daemon was restarted between two Laravel requests, the
next call raises a `ConnectionException` whose message begins
with `"Session expired or not found"` (the client-side wrapper
around the daemon's `session_not_found` wire token). Wrap calls
with a middleware-style reconnect:

<!-- @code-block language="php" label="app/Services/ResilientOpcUa.php" -->
```php
namespace App\Services;

use PhpOpcua\Client\Exception\ConnectionException;
use PhpOpcua\Client\OpcUaClientInterface;
use PhpOpcua\SessionManager\Client\ManagedClient;

readonly class ResilientOpcUa
{
    public function __construct(
        private OpcUaClientInterface $client,
        private string $endpoint,
    ) {}

    public function call(callable $fn): mixed
    {
        try {
            return $fn($this->client);
        } catch (ConnectionException $e) {
            if (! str_starts_with($e->getMessage(), 'Session expired or not found')) {
                throw $e;
            }
            if ($this->client instanceof ManagedClient) {
                $this->client->connect($this->endpoint);
            }
            return $fn($this->client);
        }
    }
}
```
<!-- @endcode-block -->

Use it for any call that crosses a worker / daemon restart
boundary. For broader recovery (channel breaks, OPC UA
disconnects), see
[Recipes · Recovery and reconnect](./recovery-and-reconnect.md).

## When this pattern is the wrong fit

- **Multi-tenant apps with per-tenant credentials.** Each tenant
  needs its own `ManagedClient` configured with its own
  credentials. Bind as a factory keyed by tenant ID, not as a
  singleton.
- **CLI scripts that exit immediately.** The singleton-per-request
  model has no value when the process is also the request. Use
  the direct `opcua-client` instead.
- **Strict per-request isolation requirements.** The session
  singleton may carry state across requests (subscriptions, cache).
  When that's a problem, use `connectForceNew()` per request — at
  the cost of the handshake every time.
