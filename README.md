<h1 align="center"><strong>OPC UA PHP Client Session Manager</strong></h1>

<div align="center">
  <picture>
    <source media="(prefers-color-scheme: dark)" srcset="assets/logo-dark.svg">
    <source media="(prefers-color-scheme: light)" srcset="assets/logo-light.svg">
    <img alt="OPC UA Session Manager" src="assets/logo-light.svg" width="435">
  </picture>
</div>

<p align="center">
  <a href="https://github.com/php-opcua/opcua-session-manager/actions/workflows/tests.yml"><img src="https://img.shields.io/github/actions/workflow/status/php-opcua/opcua-session-manager/tests.yml?branch=master&label=tests&style=flat-square" alt="Tests"></a>
  <a href="https://codecov.io/gh/php-opcua/opcua-session-manager"><img src="https://img.shields.io/codecov/c/github/php-opcua/opcua-session-manager?style=flat-square&logo=codecov" alt="Coverage"></a>
  <a href="https://packagist.org/packages/php-opcua/opcua-session-manager"><img src="https://img.shields.io/packagist/v/php-opcua/opcua-session-manager?style=flat-square&label=packagist" alt="Latest Version"></a>
  <a href="https://packagist.org/packages/php-opcua/opcua-session-manager"><img src="https://img.shields.io/packagist/php-v/php-opcua/opcua-session-manager?style=flat-square" alt="PHP Version"></a>
  <a href="LICENSE"><img src="https://img.shields.io/github/license/php-opcua/opcua-session-manager?style=flat-square" alt="License"></a>
</p>

<p align="center">
  <img src="https://custom-icon-badges.demolab.com/badge/Linux-✓-2ea44f?style=flat-square&logo=linux&logoColor=white" alt="Linux">
  <img src="https://custom-icon-badges.demolab.com/badge/macOS-✓-2ea44f?style=flat-square&logo=apple&logoColor=white" alt="macOS">
  <img src="https://custom-icon-badges.demolab.com/badge/Windows-✓-2ea44f?style=flat-square&logo=windows11&logoColor=white" alt="Windows">
</p>

---

Keep OPC UA sessions alive across PHP requests. A daemon-based session manager for [`opcua-client`](https://github.com/php-opcua/opcua-client) that eliminates the 50–200ms connection handshake overhead on every HTTP request.

PHP's request/response model destroys all state — including network connections — at the end of every request. OPC UA requires a 5-step handshake (TCP → Hello/Ack → OpenSecureChannel → CreateSession → ActivateSession) that must be repeated every single time. This package solves the problem with a long-running [ReactPHP](https://reactphp.org/) daemon that holds sessions in memory, communicating with PHP applications via a lightweight local IPC protocol (Unix-domain socket on Linux/macOS, TCP loopback on Windows — auto-selected).

**What you get:**

- **Session persistence** — OPC UA connections survive across HTTP requests. Pay the handshake cost once, reuse forever
- **Automatic session reuse** — reconnecting to the same endpoint returns the existing session automatically, no manual session ID tracking needed
- **Drop-in replacement** — `ManagedClient` implements the same `OpcUaClientInterface` as the direct `Client`. Swap one line, keep all your code
- **All OPC UA operations** — browse, read, write, method calls, subscriptions, history, path resolution, type discovery
- **Security hardening** — method whitelist, IPC authentication, credential stripping, error sanitization, connection limits
- **Auto-publish** — daemon automatically publishes for sessions with active subscriptions and dispatches PSR-14 events (`DataChangeReceived`, `AlarmActivated`, etc.) — no manual publish loop needed
- **Auto-connect** — daemon can auto-connect and register subscriptions at startup from pre-configured connection definitions
- **Automatic cleanup** — expired sessions are disconnected after configurable inactivity timeout
- **Graceful shutdown** — SIGTERM/SIGINT cleanly disconnect all active sessions

<table>
<tr>
<td>

### Tested against the OPC UA reference implementation

The underlying [opcua-client](https://github.com/php-opcua/opcua-client) is integration-tested against **[UA-.NETStandard](https://github.com/OPCFoundation/UA-.NETStandard)** — the **reference implementation** maintained by the OPC Foundation, the organization that defines the OPC UA specification. This is the same stack used by major industrial vendors to certify their products.

This session manager is additionally integration-tested via [uanetstandard-test-suite](https://github.com/php-opcua/uanetstandard-test-suite), verifying that all OPC UA operations work correctly when proxied through the daemon's IPC layer. Like [opcua-client](https://github.com/php-opcua/opcua-client), unit tests run cross-OS — **Linux, macOS, and Windows** across PHP 8.2–8.5 — on every push. Integration tests stay on Linux (Docker-hosted OPC UA servers).

</td>
</tr>
</table>

<table>
<tr>
<td>

### Runs on Linux, macOS, and Windows

The daemon and `ManagedClient` pick the right local IPC transport automatically — no platform-specific code in your application.

| Platform | Default transport | Endpoint URI |
|---|---|---|
| Linux / macOS | Unix-domain socket | `unix:///tmp/opcua-session-manager.sock` |
| Windows | TCP loopback | `tcp://127.0.0.1:9990` |

`PhpOpcua\SessionManager\Ipc\TransportFactory::defaultEndpoint()` reads `PHP_OS_FAMILY` at startup and returns the platform-appropriate URI; both sides agree without any config. You can override explicitly with `--socket tcp://127.0.0.1:8900` on the daemon or by passing the same URI to `new ManagedClient(...)`.

**Security posture is identical on every OS:**

- **Local origin only.** On Linux/macOS the Unix socket relies on filesystem permissions (`0600` by default). On Windows the TCP path is **loopback-only, enforced on both sides**: `TcpLoopbackTransport` (client) and `SessionManagerDaemon` (daemon) both refuse any bind/connect to a non-loopback host at construction time.
- **Authenticated every request.** The shared `authToken` is compared with `hash_equals()` on every IPC frame, regardless of transport.
- **Same wire format.** NDJSON-framed JSON, 16 MiB frame cap, 32-level JSON nesting cap, binary-mode streams (no `\n` ↔ `\r\n` translation on Windows).

Named pipes on Windows were evaluated and intentionally skipped — see the [ROADMAP](ROADMAP.md#windows-native-named-pipe-transport) for the full cost/benefit analysis.

</td>
</tr>
</table>

## Quick Start

```bash
composer require php-opcua/opcua-session-manager
```

### 1. Start the daemon

```bash
php bin/opcua-session-manager
```

### 2. Use ManagedClient in your PHP code

```php
use PhpOpcua\SessionManager\Client\ManagedClient;

$client = new ManagedClient();
$client->connect('opc.tcp://localhost:4840');

$value = $client->read('i=2259');
echo $value->getValue(); // 0 = Running

$client->disconnect();
```

That's it. Same API as the direct `Client`, but the session stays alive between requests.

## See It in Action

### Session persistence across requests

```php
// Request 1: open session — handshake happens once
$client = new ManagedClient();
$client->connect('opc.tcp://localhost:4840');
// Do NOT call disconnect() — session stays alive in daemon

// Request 2: same endpoint → reuses existing session automatically
$client = new ManagedClient();
$client->connect('opc.tcp://localhost:4840');
$client->wasSessionReused(); // true — no handshake needed
$value = $client->read('i=2259'); // ~5ms instead of ~155ms

// If you need a separate parallel session to the same server:
$client2 = new ManagedClient();
$client2->connectForceNew('opc.tcp://localhost:4840');
$client2->wasSessionReused(); // false — new session created
```

### Browse and read

```php
$refs = $client->browse('i=85');
foreach ($refs as $ref) {
    echo "{$ref->displayName} ({$ref->nodeId})\n";
}

$nodeId = $client->resolveNodeId('/Objects/Server/ServerStatus');
$status = $client->read($nodeId);
```

### Read multiple values with fluent builder

```php
$results = $client->readMulti()
    ->node('i=2259')->value()
    ->node('ns=2;i=1001')->displayName()
    ->execute();
```

### Write to a PLC

```php
// Auto-detection (v4) — type inferred automatically
$client->write('ns=2;i=1001', 42);

// Explicit type (still supported)
use PhpOpcua\Client\Types\BuiltinType;
$client->write('ns=2;i=1001', 42, BuiltinType::Int32);
```

### Subscribe to data changes

```php
$sub = $client->createSubscription(publishingInterval: 500.0);

$client->createMonitoredItems($sub->subscriptionId, [
    ['nodeId' => 'ns=2;i=1001'],
]);

$response = $client->publish();
foreach ($response->notifications as $notif) {
    echo $notif['dataValue']->getValue() . "\n";
}
```

### Auto-publish (no manual publish loop)

When the daemon is started with an `EventDispatcherInterface` and `autoPublish: true`, it automatically calls `publish()` for sessions that have subscriptions. The client's PSR-14 events are dispatched to your listeners:

```php
use PhpOpcua\Client\Event\DataChangeReceived;
use PhpOpcua\Client\Event\AlarmActivated;
use Psr\EventDispatcher\EventDispatcherInterface;

// 1. Start daemon with auto-publish
$daemon = new SessionManagerDaemon(
    socketPath: '/tmp/opcua.sock',
    clientEventDispatcher: $yourPsr14Dispatcher,
    autoPublish: true,
);

// 2. Pre-configure connections to auto-connect on startup
$daemon->autoConnect([
    'plc-1' => [
        'endpoint' => 'opc.tcp://192.168.1.10:4840',
        'config' => [],
        'subscriptions' => [
            [
                'publishing_interval' => 500.0,
                'max_keep_alive_count' => 5,
                'monitored_items' => [
                    ['node_id' => 'ns=2;s=Temperature', 'client_handle' => 1],
                    ['node_id' => 'ns=2;s=Pressure', 'client_handle' => 2],
                ],
                'event_monitored_items' => [
                    ['node_id' => 'i=2253', 'client_handle' => 10],
                ],
            ],
        ],
    ],
]);

$daemon->run();
// DataChangeReceived, EventNotificationReceived, AlarmActivated events
// are dispatched to your PSR-14 listeners automatically.
```

### Secure connection with authentication

```php
use PhpOpcua\Client\Security\SecurityPolicy;
use PhpOpcua\Client\Security\SecurityMode;

$client = new ManagedClient(
    socketPath: '/var/run/opcua-session-manager.sock',
    authToken: trim(file_get_contents('/etc/opcua/daemon.token')),
);

// RSA security
$client->setSecurityPolicy(SecurityPolicy::Basic256Sha256);
$client->setSecurityMode(SecurityMode::SignAndEncrypt);
$client->setClientCertificate('/certs/client.pem', '/certs/client.key');
$client->setUserCredentials('operator', 'secret');
$client->connect('opc.tcp://192.168.1.100:4840');
```

```php
// ECC security (auto-generated ECC certificate)
$client = new ManagedClient();
$client->setSecurityPolicy(SecurityPolicy::EccNistP256);
$client->setSecurityMode(SecurityMode::SignAndEncrypt);
$client->setUserCredentials('operator', 'secret');
$client->connect('opc.tcp://192.168.1.100:4840');
```

> **Tip:** Skip `setClientCertificate()` and a self-signed cert gets auto-generated in memory (RSA for RSA policies, ECC for ECC policies) — perfect for quick tests or servers with auto-accept.

> **ECC disclaimer:** ECC security policies (`EccNistP256`, `EccNistP384`, `EccBrainpoolP256r1`, `EccBrainpoolP384r1`) are fully implemented and tested against the OPC Foundation's UA-.NETStandard reference stack. However, no commercial OPC UA vendor supports ECC endpoints yet.

## How It Works

```
┌──────────────┐         ┌──────────────────────────────┐         ┌──────────────┐
│  PHP Request │ ──IPC──►│  Session Manager Daemon      │ ──TCP──►│  OPC UA      │
│  (short-     │◄──IPC── │                              │◄──TCP── │  Server      │
│   lived)     │         │  ● ReactPHP event loop       │         │              │
└──────────────┘         │  ● Sessions in memory        │         └──────────────┘
                         │  ● Periodic cleanup timer    │
┌──────────────┐         │  ● Signal handlers           │
│  PHP Request │ ──IPC──►│                              │
│  (reuses     │◄──IPC── │  Sessions:                   │
│   session)   │         │   [sess-a1b2] → Client (TCP) │
└──────────────┘         │   [sess-c3d4] → Client (TCP) │
                         └──────────────────────────────┘
```

Without the session manager:
```
Request 1:  [connect 150ms] [read 5ms] [disconnect]  → total ~155ms
Request 2:  [connect 150ms] [read 5ms] [disconnect]  → total ~155ms
```

With the session manager:
```
Request 1:  [open session 150ms] [read 5ms]           → total ~155ms  (first time only)
Request 2:                       [read 5ms]           → total ~5ms
Request N:                       [read 5ms]           → total ~5ms
```

## Features

| Feature | What it does |
|---|---|
| **Drop-in Replacement** | `ManagedClient` implements the same `OpcUaClientInterface` as the direct `Client` |
| **Session Persistence** | OPC UA sessions survive across PHP requests via the daemon |
| **Automatic Session Reuse** | Reconnecting to the same endpoint returns the existing session instead of creating a new one |
| **All OPC UA Operations** | Browse, read, write, method calls, subscriptions, history, path resolution |
| **String NodeIds** | All methods accept `'i=2259'` or `'ns=2;s=MyNode'` in addition to `NodeId` objects |
| **Fluent Builder API** | `readMulti()`, `writeMulti()`, `createMonitoredItems()`, `translateBrowsePaths()` support chainable builders |
| **Typed Returns** | All service responses return `public readonly` DTOs — `SubscriptionResult`, `CallResult`, `BrowseResultSet`, etc. |
| **Type Discovery** | `discoverDataTypes()` auto-detects custom server structures |
| **Transfer & Recovery** | `transferSubscriptions()` and `republish()` for session migration |
| **PSR-3 Logging** | Optional structured logging via any PSR-3 logger |
| **PSR-16 Cache** | Cache management forwarded to daemon — `invalidateCache()`, `flushCache()` |
| **Security** | 10 policies (RSA + ECC), 3 auth modes, IPC authentication, method whitelist |
| **Third-party modules** | Any method registered by a custom `ServiceModule` on the daemon is callable via `ManagedClient::$method(...)` — typed args/results travel through a JSON wire codec with an explicit type allowlist |
| **Cross-platform IPC** | Auto-selects Unix-domain sockets on Linux/macOS and TCP loopback on Windows via `TransportFactory`. Endpoints accept `unix://<path>`, `tcp://127.0.0.1:<port>`, or a scheme-less Unix path (backwards-compatible). Loopback-only guard on both client and daemon sides |
| **Auto-Retry** | Automatic reconnect on connection failures |
| **Auto-Batching** | Transparent batching for `readMulti()`/`writeMulti()` |
| **Auto-Publish** | Daemon automatically calls `publish()` for sessions with subscriptions and dispatches PSR-14 events |
| **Auto-Connect** | Daemon connects and registers subscriptions at startup from pre-configured definitions |
| **Automatic Cleanup** | Expired sessions closed after inactivity timeout |
| **Graceful Shutdown** | SIGTERM/SIGINT disconnect all sessions cleanly |

## Daemon Options

```bash
php bin/opcua-session-manager [options]
```

| Option | Default | Description |
|--------|---------|-------------|
| `--socket <uri>` | per-OS (`unix:///tmp/opcua-session-manager.sock` on Linux/macOS, `tcp://127.0.0.1:9990` on Windows) | IPC endpoint URI. Accepts `unix://<path>`, `tcp://127.0.0.1:<port>`, or a scheme-less Unix path. TCP binds are loopback-only (construction-time guard refuses non-loopback hosts). |
| `--timeout <sec>` | `600` | Session inactivity timeout |
| `--cleanup-interval <sec>` | `30` | Expired session cleanup interval |
| `--auth-token <token>` | *(none)* | Shared secret for IPC authentication |
| `--auth-token-file <path>` | *(none)* | Read auth token from file (recommended) |
| `--max-sessions <n>` | `100` | Maximum concurrent sessions |
| `--socket-mode <octal>` | `0600` | Socket file permissions (applied only to Unix-socket endpoints) |
| `--allowed-cert-dirs <dirs>` | *(none)* | Comma-separated allowed certificate directories |

Auth token priority: `OPCUA_AUTH_TOKEN` env var > `--auth-token-file` > `--auth-token`.

## Security

The daemon implements multiple layers of security hardening:

- **IPC authentication** — shared-secret token validated with timing-safe `hash_equals()`
- **Socket permissions** — `0600` by default (owner-only)
- **Method whitelist** — only 45 documented OPC UA operations allowed via `query`
- **Credential protection** — passwords and private key paths stripped immediately after connection
- **Session limits** — configurable maximum to prevent resource exhaustion
- **Certificate path restrictions** — `--allowed-cert-dirs` constrains certificate directories
- **Input size limit** — IPC requests capped at 1MB
- **Connection protection** — 30s per-connection timeout, max 50 concurrent IPC connections
- **Error sanitization** — messages truncated, file paths stripped
- **PID file lock** — prevents multiple daemon instances

### Recommended production setup

```bash
openssl rand -hex 32 > /etc/opcua/daemon.token
chmod 600 /etc/opcua/daemon.token

OPCUA_AUTH_TOKEN=$(cat /etc/opcua/daemon.token) php bin/opcua-session-manager \
    --socket /var/run/opcua-session-manager.sock \
    --socket-mode 0660 \
    --max-sessions 50 \
    --allowed-cert-dirs /etc/opcua/certs
```

## Comparison

| | Direct `Client` | `ManagedClient` |
|-|-----------------|-----------------|
| Connection | Direct TCP | Via daemon (Unix socket) |
| Session lifetime | Dies with PHP process | Persists across requests |
| Per-operation overhead | ~1–5ms | ~5–15ms |
| Connection overhead | ~50–200ms every request | ~50–200ms first time only |
| Subscriptions | Lost between requests | Maintained by daemon |
| Certificate paths | Relative or absolute | Absolute only |

## Documentation

| # | Document | Covers |
|---|----------|--------|
| 01 | [Introduction](doc/01-introduction.md) | Overview, requirements, quick start |
| 02 | [Overview & Architecture](doc/02-overview.md) | Problem, solution, components |
| 03 | [Installation](doc/03-installation.md) | Requirements, Composer setup, project structure |
| 04 | [Daemon](doc/04-daemon.md) | CLI options, security, systemd/Supervisor, internals |
| 05 | [ManagedClient API](doc/05-managed-client.md) | Full API reference, configuration, session persistence |
| 06 | [IPC Protocol](doc/06-ipc-protocol.md) | Transport, commands, authentication, wire format |
| 07 | [Type Serialization](doc/07-type-serialization.md) | JSON conversion for all OPC UA types and DTOs |
| 08 | [Testing](doc/08-testing.md) | Test infrastructure, helper class, running tests |
| 09 | [Examples](doc/09-examples.md) | Complete code examples for all features |

## Testing

```bash
./vendor/bin/pest                                          # everything
./vendor/bin/pest tests/Unit/                              # unit only
./vendor/bin/pest tests/Integration/ --group=integration   # integration only
```

456+ tests (unit + integration). Integration tests run against [uanetstandard-test-suite](https://github.com/php-opcua/uanetstandard-test-suite) — a Docker-based OPC UA environment built on the OPC Foundation's UA-.NETStandard reference implementation — covering browse, read/write, subscriptions, method calls, path resolution, connection state, security, type serialization, session persistence, session recovery, and all module DTOs.

> **Note on coverage:** `SessionManagerDaemon` is excluded from coverage reports because it runs as a separate long-lived process (ReactPHP event loop). PHP coverage tools (pcov, xdebug) only instrument the test runner process — they cannot track code executing inside a subprocess started via `proc_open()`. The daemon is fully tested by the integration suite, which starts a real daemon, sends IPC commands, and verifies responses. This is a known limitation shared by other daemon-based PHP packages (Laravel Horizon, Symfony Messenger, RoadRunner workers).

## Ecosystem

| Package | Description |
|---------|-------------|
| [opcua-client](https://github.com/php-opcua/opcua-client) | Pure PHP OPC UA client |
| [opcua-cli](https://github.com/php-opcua/opcua-cli) | CLI tool — browse, read, write, watch, discover endpoints, manage certificates, generate code from NodeSet2.xml |
| [opcua-session-manager](https://github.com/php-opcua/opcua-session-manager) | Daemon-based session persistence across PHP requests (this package) |
| [opcua-client-nodeset](https://github.com/php-opcua/opcua-client-nodeset) | Pre-generated PHP types from 51 OPC Foundation companion specifications (DI, Robotics, Machinery, MachineTool, ISA-95, CNC, MTConnect, and more). 807 PHP files — NodeId constants, enums, typed DTOs, codecs, registrars with automatic dependency resolution. Just `composer require` and `loadGeneratedTypes()`. |
| [laravel-opcua](https://github.com/php-opcua/laravel-opcua) | Laravel integration — service provider, facade, config |
| [uanetstandard-test-suite](https://github.com/php-opcua/uanetstandard-test-suite) | Docker-based OPC UA test servers (UA-.NETStandard) for integration testing |

## AI-Ready

This package ships with machine-readable documentation designed for AI coding assistants (Claude, Cursor, Copilot, ChatGPT, and others). Feed these files to your AI so it knows how to use the library correctly:

| File | Purpose |
|------|---------|
| [`llms.txt`](llms.txt) | Compact project summary — architecture, key classes, API signatures, and configuration. Optimized for LLM context windows with minimal token usage. |
| [`llms-full.txt`](llms-full.txt) | Comprehensive technical reference — every class, method, DTO, serialization format, IPC protocol, and daemon internal. For deep dives and complex questions. |
| [`llms-skills.md`](llms-skills.md) | Task-oriented recipes — step-by-step instructions for common tasks (install, configure, deploy, persist sessions, subscriptions, security, monitoring). Written so an AI can generate correct, production-ready code from a user's intent. |

**How to use:** copy the files you need into your project's AI configuration directory. The files are located in `vendor/php-opcua/opcua-session-manager/` after `composer install`.

- **Claude Code**: reference per-session with `--add-file vendor/php-opcua/opcua-session-manager/llms-skills.md`
- **Cursor**: copy into your project's rules directory — `cp vendor/php-opcua/opcua-session-manager/llms-skills.md .cursor/rules/opcua-session-manager.md`
- **GitHub Copilot**: copy or append the content into your project's `.github/copilot-instructions.md` file (create the file and directory if they don't exist). Copilot reads this file automatically for project-specific context
- **Other tools**: paste the content into your system prompt, project knowledge base, or context configuration

## Roadmap

See [ROADMAP.md](ROADMAP.md) for what's coming next.

## Contributing

Contributions welcome — see [CONTRIBUTING.md](CONTRIBUTING.md).

## Versioning

This package follows the same version numbering as [`php-opcua/opcua-client`](https://github.com/php-opcua/opcua-client). Each release of `opcua-session-manager` is aligned with the corresponding release of the client library to ensure full compatibility.

## Changelog

See [CHANGELOG.md](CHANGELOG.md).

## License

[MIT](LICENSE)
