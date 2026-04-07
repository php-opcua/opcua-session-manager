# OPC UA Session Manager — AI Skills Reference

> Task-oriented recipes for AI coding assistants. Feed this file to your AI (Claude, Cursor, Copilot, GPT, etc.) so it knows how to use `php-opcua/opcua-session-manager` correctly.

## How to use this file

Add this file to your AI assistant's context:
- **Claude Code**: copy to your project's `CLAUDE.md` or reference via `--add-file`
- **Cursor**: add to `.cursor/rules/` or `.cursorrules`
- **GitHub Copilot**: add to `.github/copilot-instructions.md`
- **Other tools**: paste into system prompt or project context

---

## What This Package Does

PHP destroys all state — including TCP connections — at the end of every request. OPC UA requires a 5-step handshake (TCP → Hello/Ack → OpenSecureChannel → CreateSession → ActivateSession) costing 50–200ms. This package runs a **ReactPHP daemon** that keeps OPC UA sessions alive in memory. PHP applications communicate with the daemon via a lightweight Unix socket IPC protocol.

**Without daemon**: every request pays ~150ms handshake + ~5ms operation = ~155ms
**With daemon**: first request ~155ms, subsequent requests ~5ms

`ManagedClient` implements the same `OpcUaClientInterface` as the direct `Client` from `php-opcua/opcua-client` — it's a **drop-in replacement**.

---

## Skill: Install and Start the Session Manager

### When to use
The user has a PHP web application that makes repeated OPC UA calls and wants to eliminate per-request handshake overhead.

### Prerequisites
```bash
composer require php-opcua/opcua-session-manager
```

### Start the daemon

```bash
# Basic — default socket at /tmp/opcua-session-manager.sock
php vendor/bin/opcua-session-manager

# With options
php vendor/bin/opcua-session-manager \
    --socket /var/run/opcua.sock \
    --timeout 600 \
    --max-sessions 50

# With authentication (recommended for production)
OPCUA_AUTH_TOKEN=my-secret php vendor/bin/opcua-session-manager

# Or read token from file (more secure — not visible in ps)
php vendor/bin/opcua-session-manager --auth-token-file /etc/opcua/daemon.token
```

### Important rules
- The daemon must be running **before** `ManagedClient` tries to connect — there is no fallback
- Use Supervisor or systemd to keep the daemon running in production
- Only ONE daemon instance per socket path (PID lock file prevents duplicates)
- The daemon creates the socket file and cleans it up on graceful shutdown (SIGTERM/SIGINT)

---

## Skill: Use ManagedClient Instead of Direct Client

### When to use
The user already has code using `ClientBuilder::create()->connect()` and wants to switch to persistent sessions.

### Before (direct client)

```php
use PhpOpcua\Client\ClientBuilder;

$client = ClientBuilder::create()
    ->connect('opc.tcp://192.168.1.100:4840');

$value = $client->read('i=2259');
$client->disconnect();
```

### After (managed client)

```php
use PhpOpcua\SessionManager\Client\ManagedClient;

$client = new ManagedClient();
$client->connect('opc.tcp://192.168.1.100:4840');

$value = $client->read('i=2259');
// Do NOT disconnect — session stays alive for next request
```

### Important rules
- `ManagedClient` implements `OpcUaClientInterface` — all the same methods work: `read()`, `write()`, `browse()`, `call()`, `readMulti()`, `writeMulti()`, subscriptions, history, etc.
- String NodeIds work: `'i=2259'`, `'ns=2;s=Temperature'`, etc.
- Fluent builders work: `readMulti()`, `writeMulti()`, `createMonitoredItems()`, `translateBrowsePaths()`
- All result types are the same DTOs: `DataValue`, `SubscriptionResult`, `CallResult`, `BrowseResultSet`, etc.
- The only difference: configuration happens via setter methods on `ManagedClient`, not on `ClientBuilder`

---

## Skill: Configure ManagedClient

### When to use
The user needs security, credentials, timeouts, or other settings on the managed connection.

### Code

```php
use PhpOpcua\SessionManager\Client\ManagedClient;
use PhpOpcua\Client\Security\SecurityPolicy;
use PhpOpcua\Client\Security\SecurityMode;

$client = new ManagedClient(
    socketPath: '/var/run/opcua.sock',   // daemon socket (default: /tmp/opcua-session-manager.sock)
    timeout: 30.0,                        // IPC timeout in seconds (default: 30)
    authToken: 'my-secret',               // must match daemon's token
);

// Security — set BEFORE connect()
$client->setSecurityPolicy(SecurityPolicy::Basic256Sha256);
$client->setSecurityMode(SecurityMode::SignAndEncrypt);
$client->setClientCertificate('/certs/client.pem', '/certs/client.key', '/certs/ca.pem');
$client->setUserCredentials('operator', 'secret');

// Behavior — set BEFORE connect()
$client->setTimeout(10.0);               // OPC UA operation timeout
$client->setAutoRetry(3);                 // retry on connection failure
$client->setBatchSize(100);               // max nodes per read/write batch
$client->setDefaultBrowseMaxDepth(20);    // browseRecursive depth limit

// Trust store
$client->setTrustStorePath('/var/opcua/trust');
$client->setTrustPolicy(\PhpOpcua\Client\TrustStore\TrustPolicy::Fingerprint);
$client->autoAccept(true);

// Write type auto-detection
$client->setAutoDetectWriteType(true);    // default: true
$client->setReadMetadataCache(true);

// Connect
$client->connect('opc.tcp://192.168.1.100:4840');
```

### Important rules
- Constructor parameters (`socketPath`, `timeout`, `authToken`) configure the **IPC channel** to the daemon
- Setter methods (`setSecurityPolicy`, `setTimeout`, etc.) configure the **OPC UA connection** inside the daemon
- All setters must be called **before** `connect()`
- Setters return `$this` for fluent chaining
- Unlike `ClientBuilder`, `ManagedClient` setters remain available after construction (but should still be called before connect)

---

## Skill: Session Persistence Across Requests

### When to use
The user wants to understand how sessions persist and how to control reuse behavior.

### Code

```php
use PhpOpcua\SessionManager\Client\ManagedClient;

// Request 1: first connection — full OPC UA handshake happens
$client = new ManagedClient();
$client->connect('opc.tcp://192.168.1.100:4840');
$value = $client->read('i=2259'); // ~155ms total
// Do NOT call disconnect() — session stays alive

// Request 2: same endpoint + config → session is automatically reused
$client = new ManagedClient();
$client->connect('opc.tcp://192.168.1.100:4840');
echo $client->wasSessionReused(); // true — no handshake
$value = $client->read('i=2259'); // ~5ms total

// Force a NEW parallel session to the same server
$client2 = new ManagedClient();
$client2->connectForceNew('opc.tcp://192.168.1.100:4840');
echo $client2->wasSessionReused(); // false — new session created

// Explicitly close a session
$client->disconnect(); // session removed from daemon
```

### How reuse works
1. `connect()` sends `open` command to daemon with endpoint URL + sanitized config
2. Daemon checks if an existing session matches (same endpoint + same security/auth config)
3. If match found: returns existing session ID, refreshes inactivity timer → `wasSessionReused() = true`
4. If no match: creates new `Client` via `ClientBuilder`, performs OPC UA handshake → `wasSessionReused() = false`

### Important rules
- Session reuse matches on endpoint URL **and** config (security policy, mode, credentials, etc.)
- Different credentials = different session (no reuse)
- `disconnect()` explicitly destroys the session in the daemon
- Not calling `disconnect()` keeps the session alive until it expires (default: 600s inactivity)
- `connectForceNew()` always creates a new session even if a matching one exists

---

## Skill: Persistent Subscriptions

### When to use
The user wants OPC UA subscriptions that survive across HTTP requests — monitoring data changes, collecting events, polling.

### Code

```php
use PhpOpcua\SessionManager\Client\ManagedClient;

// Request 1: create subscription (lives in daemon's memory)
$client = new ManagedClient();
$client->connect('opc.tcp://192.168.1.100:4840');

$sub = $client->createSubscription(publishingInterval: 500.0);
$client->createMonitoredItems($sub->subscriptionId, [
    ['nodeId' => 'ns=2;i=1001', 'clientHandle' => 1],
    ['nodeId' => 'ns=2;i=1002', 'clientHandle' => 2],
]);
// Do NOT disconnect — subscription stays alive in daemon

// Request 2: poll for notifications (reuses same session)
$client = new ManagedClient();
$client->connect('opc.tcp://192.168.1.100:4840');

$response = $client->publish();
foreach ($response->notifications as $notif) {
    echo "Handle {$notif['clientHandle']}: {$notif['dataValue']->getValue()}\n";
}

// Request N: keep polling...
// The daemon accumulates notifications between polls.

// When done: clean up
$client->deleteSubscription($sub->subscriptionId);
$client->disconnect();
```

### Important rules
- Subscriptions live in the daemon process — they survive PHP request boundaries
- The daemon tracks subscription IDs per session for auto-recovery
- If the OPC UA connection breaks, the daemon auto-reconnects and transfers subscriptions
- Call `publish()` regularly to consume notifications — they accumulate in the daemon
- Always `deleteSubscription()` when no longer needed to free server resources

---

## Skill: Modify Monitored Items and Set Triggering

### When to use
The user wants to change sampling intervals, queue sizes, or link monitored items so one triggers another.

### Code

```php
// Modify sampling interval on existing monitored items
$results = $client->modifyMonitoredItems($sub->subscriptionId, [
    ['monitoredItemId' => 1, 'samplingInterval' => 200.0],
    ['monitoredItemId' => 2, 'queueSize' => 10],
]);

// Set triggering — when item 1 changes, also report items 2 and 3
$result = $client->setTriggering(
    $sub->subscriptionId,
    triggeringItemId: 1,
    linksToAdd: [2, 3],
    linksToRemove: [],
);
```

### Important rules
- `modifyMonitoredItems()` returns `MonitoredItemModifyResult[]` with `statusCode`, `revisedSamplingInterval`, `revisedQueueSize`
- `setTriggering()` returns `SetTriggeringResult` with `addResults` and `removeResults`
- These methods work identically to the direct `Client` — the daemon proxies them

---

## Skill: Deploy with Supervisor

### When to use
The user needs to run the daemon reliably in production.

### Supervisor config

```ini
[program:opcua-session-manager]
command=php /path/to/project/vendor/bin/opcua-session-manager --socket /var/run/opcua.sock --max-sessions 50
directory=/path/to/project
autostart=true
autorestart=true
user=www-data
environment=OPCUA_AUTH_TOKEN="%(ENV_OPCUA_AUTH_TOKEN)s"
redirect_stderr=true
stdout_logfile=/var/log/opcua-session-manager.log
```

### systemd config

```ini
[Unit]
Description=OPC UA Session Manager
After=network.target

[Service]
User=www-data
WorkingDirectory=/path/to/project
ExecStart=/usr/bin/php vendor/bin/opcua-session-manager --socket /var/run/opcua.sock --max-sessions 50
Environment=OPCUA_AUTH_TOKEN=my-secret
Restart=always
RestartSec=5

[Install]
WantedBy=multi-user.target
```

### Important rules
- Always use `OPCUA_AUTH_TOKEN` env var (not `--auth-token` which is visible in `ps`)
- Use `--socket-mode 0660` if web server and daemon run as different users in the same group
- The daemon handles SIGTERM gracefully — disconnects all sessions, cleans up socket file
- Set `--max-sessions` based on your expected concurrent connections
- The daemon auto-cleans expired sessions every `--cleanup-interval` seconds (default: 30)

---

## Skill: Deploy with Laravel

### When to use
The user has a Laravel app and wants session manager integration with automatic daemon detection.

### Prerequisites
```bash
composer require php-opcua/laravel-opcua
php artisan vendor:publish --tag=opcua-config
```

### Start daemon via Artisan

```bash
php artisan opcua:session
php artisan opcua:session --timeout=600 --max-sessions=100 --log-channel=stack --cache-store=redis
```

### Configuration (.env)

```dotenv
OPCUA_ENDPOINT=opc.tcp://192.168.1.100:4840
OPCUA_SESSION_MANAGER_ENABLED=true
OPCUA_SOCKET_PATH=
OPCUA_AUTH_TOKEN=my-secret
OPCUA_SESSION_TIMEOUT=600
OPCUA_MAX_SESSIONS=100
```

### How it works

```php
use PhpOpcua\LaravelOpcua\Facades\Opcua;

// Automatic detection — zero code changes
$client = Opcua::connect();
// If daemon is running → ManagedClient (persistent session)
// If daemon is NOT running → direct Client (per-request connection)

$value = $client->read('i=2259');
$client->disconnect();

// Check mode
if (Opcua::isSessionManagerRunning()) {
    echo "Using persistent sessions";
}
```

### Important rules
- `laravel-opcua` auto-detects the daemon by checking if the socket file exists
- The switch between ManagedClient and direct Client is **transparent** — no code changes
- The Artisan `opcua:session` command uses Laravel's log channels and cache stores
- Config in `config/opcua.php` section `session_manager`
- Supervisor setup same as standalone, but use `php artisan opcua:session` as the command

---

## Skill: Secure the IPC Channel

### When to use
The user wants to harden the daemon for production deployments.

### Code

```bash
# 1. Generate a strong auth token
openssl rand -hex 32 > /etc/opcua/daemon.token
chmod 600 /etc/opcua/daemon.token

# 2. Start daemon with security options
OPCUA_AUTH_TOKEN=$(cat /etc/opcua/daemon.token) php vendor/bin/opcua-session-manager \
    --socket /var/run/opcua.sock \
    --socket-mode 0660 \
    --max-sessions 50 \
    --allowed-cert-dirs /etc/opcua/certs
```

```php
// Client must provide the same token
$client = new ManagedClient(
    socketPath: '/var/run/opcua.sock',
    authToken: trim(file_get_contents('/etc/opcua/daemon.token')),
);
```

### Security layers

| Layer | What it does |
|-------|-------------|
| **IPC auth token** | Shared secret validated with timing-safe `hash_equals()` |
| **Socket permissions** | `0600` by default (owner-only). Use `0660` for group access |
| **Method whitelist** | Only 37 OPC UA operations allowed — setters and internal methods blocked |
| **Credential stripping** | Passwords and key paths removed from session metadata after connection |
| **Certificate path restriction** | `--allowed-cert-dirs` constrains which directories can be accessed |
| **Input limits** | 1MB max request, 30s timeout, 50 max concurrent IPC connections |
| **Error sanitization** | Error messages truncated, file paths replaced with `[path]` |
| **PID lock** | Prevents multiple daemon instances on same socket |

### Important rules
- Auth token priority: `OPCUA_AUTH_TOKEN` env var > `--auth-token-file` > `--auth-token`
- Never use `--auth-token` in production (visible in `ps`) — use env var or file
- The method whitelist blocks: `setTimeout`, `setSecurityPolicy`, `setCache`, `setLogger`, `connect`, `disconnect`, and all PHP magic methods
- Connection/disconnection is exclusively via IPC `open`/`close` commands

---

## Skill: Monitor Daemon Health

### When to use
The user wants to check if the daemon is running, how many sessions are active, or build monitoring dashboards.

### Code

```php
use PhpOpcua\SessionManager\Client\SocketConnection;

// Ping the daemon
$response = SocketConnection::send('/tmp/opcua-session-manager.sock', [
    'command' => 'ping',
]);
// {"success": true, "data": {"status": "ok", "sessions": 3, "time": 1712345678.5}}

// List active sessions
$response = SocketConnection::send('/tmp/opcua-session-manager.sock', [
    'command' => 'list',
    'authToken' => 'my-secret',  // required if auth is enabled
]);
// {"success": true, "data": {"count": 2, "sessions": [
//   {"id": "a1b2c3...", "endpointUrl": "opc.tcp://...", "lastUsed": 1712345670.0, "config": {...}},
//   ...
// ]}}
```

### Important rules
- `ping` does not require auth token — use it for health checks
- `list` requires auth token if configured — it returns session metadata (credentials are stripped)
- `lastUsed` is a Unix timestamp — compare with `time()` to check inactivity
- The daemon logs session creation, expiration, and errors to its configured log output

---

## Skill: Handle Connection Recovery

### When to use
The user needs to understand how the daemon handles OPC UA connection failures.

### How auto-recovery works

1. A `query` command fails with a `ConnectionException`
2. The daemon calls `$client->reconnect()` on the session's Client
3. If the session had active subscriptions, the daemon calls `transferSubscriptions()` to restore them
4. For each subscription with unacknowledged notifications, `republish()` is called
5. The original failed query is retried
6. If recovery fails, the error is returned to the caller

### Manual recovery from ManagedClient

```php
use PhpOpcua\Client\Exception\ConnectionException;

$client = new ManagedClient();
$client->connect('opc.tcp://192.168.1.100:4840');

try {
    $value = $client->read('i=2259');
} catch (ConnectionException $e) {
    // The daemon already tried auto-recovery and it failed.
    // The session may be broken — reconnect explicitly.
    $client->reconnect();
    $value = $client->read('i=2259');
}
```

### Subscription transfer after reconnection

```php
// Manually transfer subscriptions to a new session
$results = $client->transferSubscriptions([$subscriptionId]);

if (\PhpOpcua\Client\Types\StatusCode::isGood($results[0]->statusCode)) {
    echo "Subscription transferred\n";
    
    // Republish missed notifications
    $republished = $client->republish($subscriptionId, $lastSequenceNumber);
}
```

### Important rules
- The daemon attempts auto-recovery transparently — most connection drops are handled without the caller knowing
- If auto-recovery fails, the original exception is propagated to the ManagedClient caller
- Subscription IDs are tracked per session — the daemon knows which subscriptions to transfer
- `setAutoRetry(n)` on ManagedClient is forwarded to the daemon's Client — the daemon retries at the OPC UA level

---

## Skill: Use Logging and Caching with the Daemon

### When to use
The user wants structured logs from the daemon or wants to configure cache behavior.

### Daemon logging

```bash
# Log to file
php vendor/bin/opcua-session-manager --log-file /var/log/opcua.log --log-level debug

# Log to stderr (default)
php vendor/bin/opcua-session-manager --log-level info
```

### Client-side logging

```php
use Monolog\Logger;
use Monolog\Handler\StreamHandler;

$logger = new Logger('opcua');
$logger->pushHandler(new StreamHandler('php://stderr', Logger::DEBUG));

$client = new ManagedClient();
$client->setLogger($logger);  // logs IPC communication
$client->connect('opc.tcp://192.168.1.100:4840');
```

### Cache configuration

```bash
# In-memory cache (default — per-daemon-process, fastest)
php vendor/bin/opcua-session-manager --cache-driver memory --cache-ttl 300

# File-based cache (persists across daemon restarts)
php vendor/bin/opcua-session-manager --cache-driver file --cache-path /tmp/opcua-cache --cache-ttl 600

# No cache
php vendor/bin/opcua-session-manager --cache-driver none
```

### Cache operations from ManagedClient

```php
// These are forwarded to the daemon's Client
$client->invalidateCache('i=85');  // clear one node's cached browse results
$client->flushCache();             // clear all cached data
```

### Important rules
- Daemon log levels: `debug`, `info`, `notice`, `warning`, `error`, `critical`, `alert`, `emergency`
- The daemon uses `StreamLogger` (PSR-3) — not Monolog. For richer logging, use Laravel's `opcua:session` command
- Cache operations (`invalidateCache`, `flushCache`) are proxied via IPC to the daemon — they affect the daemon's cache, not the client's
- Browse, resolve, endpoint, and discovery results are cached. Read values are NEVER cached.

---

## Daemon CLI Options Reference

| Option | Default | Description |
|--------|---------|-------------|
| `--socket <path>` | `/tmp/opcua-session-manager.sock` | Unix socket path |
| `--timeout <sec>` | `600` | Session inactivity timeout (seconds) |
| `--cleanup-interval <sec>` | `30` | How often to check for expired sessions |
| `--auth-token <token>` | *(none)* | Shared secret for IPC (visible in ps — avoid in production) |
| `--auth-token-file <path>` | *(none)* | Read auth token from file (recommended) |
| `--max-sessions <n>` | `100` | Maximum concurrent OPC UA sessions |
| `--socket-mode <octal>` | `0600` | Socket file permissions |
| `--allowed-cert-dirs <dirs>` | *(none)* | Comma-separated allowed certificate directories |
| `--log-file <path>` | *(stderr)* | Log output file |
| `--log-level <level>` | `info` | Minimum log level |
| `--cache-driver <driver>` | `memory` | Cache driver: `memory`, `file`, `none` |
| `--cache-path <path>` | *(required for file)* | Cache directory for file driver |
| `--cache-ttl <sec>` | `300` | Cache TTL in seconds |

Auth token priority: `OPCUA_AUTH_TOKEN` env var > `--auth-token-file` > `--auth-token`.

---

## ManagedClient vs Direct Client

| Aspect | Direct `Client` | `ManagedClient` |
|--------|-----------------|-----------------|
| **Entry point** | `ClientBuilder::create()->connect()` | `new ManagedClient()` then `->connect()` |
| **Connection** | Direct TCP to OPC UA server | Via daemon (Unix socket IPC → TCP) |
| **Session lifetime** | Dies with PHP process | Persists across requests |
| **Configuration** | On `ClientBuilder` before `connect()` | Setter methods on `ManagedClient` before `connect()` |
| **Config immutability** | Immutable after `connect()` | Setters available, but should be called before `connect()` |
| **Per-operation latency** | ~1–5ms | ~5–15ms (IPC overhead) |
| **Connection latency** | ~50–200ms every request | ~50–200ms first time only |
| **Subscriptions** | Lost when PHP process ends | Maintained by daemon |
| **Certificate paths** | Relative or absolute | **Absolute only** |
| **Dependency** | `php-opcua/opcua-client` | `php-opcua/opcua-session-manager` (includes client) |

---

## Common Mistakes to Avoid

### 1. Disconnecting when you want persistence
```php
// WRONG — defeats the purpose of session manager
$client = new ManagedClient();
$client->connect('opc.tcp://...');
$value = $client->read('i=2259');
$client->disconnect(); // session destroyed!

// CORRECT — let the session persist
$client = new ManagedClient();
$client->connect('opc.tcp://...');
$value = $client->read('i=2259');
// just let $client go out of scope — session stays alive in daemon
```

### 2. Using relative certificate paths
```php
// WRONG — daemon can't resolve relative paths
$client->setClientCertificate('certs/client.pem', 'certs/client.key');

// CORRECT — always use absolute paths
$client->setClientCertificate('/etc/opcua/certs/client.pem', '/etc/opcua/certs/client.key');
```

### 3. Forgetting auth token on client side
```php
// WRONG — daemon has auth enabled but client doesn't provide token
$client = new ManagedClient();
$client->connect('opc.tcp://...'); // DaemonException: auth_failed

// CORRECT
$client = new ManagedClient(authToken: 'my-secret');
$client->connect('opc.tcp://...');
```

### 4. Expecting fallback to direct connection
```php
// WRONG assumption — ManagedClient does NOT fall back
$client = new ManagedClient();
$client->connect('opc.tcp://...'); // DaemonException if daemon not running

// For automatic fallback, use laravel-opcua instead:
use PhpOpcua\LaravelOpcua\Facades\Opcua;
$client = Opcua::connect(); // auto-detects daemon presence
```

### 5. Using --auth-token in production
```bash
# WRONG — token visible in ps output
php vendor/bin/opcua-session-manager --auth-token my-secret

# CORRECT — use env var
OPCUA_AUTH_TOKEN=my-secret php vendor/bin/opcua-session-manager

# CORRECT — use file
php vendor/bin/opcua-session-manager --auth-token-file /etc/opcua/daemon.token
```

---

## IPC Protocol Reference (for advanced use)

Commands sent as newline-delimited JSON over Unix socket.

### ping
```json
→ {"command": "ping"}
← {"success": true, "data": {"status": "ok", "sessions": 3, "time": 1712345678.5}}
```

### list
```json
→ {"command": "list", "authToken": "..."}
← {"success": true, "data": {"count": 2, "sessions": [{"id": "...", "endpointUrl": "...", "lastUsed": 1712345670.0}]}}
```

### open
```json
→ {"command": "open", "endpointUrl": "opc.tcp://...", "config": {"securityPolicy": "None"}, "authToken": "..."}
← {"success": true, "data": {"sessionId": "a1b2c3...", "reused": false}}
```

### close
```json
→ {"command": "close", "sessionId": "a1b2c3...", "authToken": "..."}
← {"success": true, "data": null}
```

### query
```json
→ {"command": "query", "sessionId": "a1b2c3...", "method": "read", "params": [{"ns": 0, "id": 2259, "type": "numeric"}], "authToken": "..."}
← {"success": true, "data": {"value": 0, "type": 6, "statusCode": 0, "sourceTimestamp": "2026-04-07T12:00:00Z"}}
```

### Error response format
```json
← {"success": false, "error": {"type": "session_not_found", "message": "Session abc123 not found"}}
```

Error types: `unknown_command`, `session_not_found`, `forbidden_method`, `max_sessions_reached`, `too_many_connections`, `connection_timeout`, `payload_too_large`, `invalid_json`, `auth_failed`, `ConnectionException`, `ServiceException`.

---

## Exception Reference

| Exception | When | Package |
|-----------|------|---------|
| `DaemonException` | Socket missing, connection failed, IPC timeout, invalid response, auth failed | opcua-session-manager |
| `SessionNotFoundException` | Session ID not found in daemon's store | opcua-session-manager |
| `SerializationException` | Type cannot be serialized/deserialized for IPC | opcua-session-manager |
| `ConnectionException` | OPC UA TCP connection failed or broken | opcua-client |
| `ServiceException` | OPC UA server rejected the request | opcua-client |
| `UntrustedCertificateException` | Server certificate not in trust store | opcua-client |
| `InvalidNodeIdException` | Invalid NodeId string format | opcua-client |
