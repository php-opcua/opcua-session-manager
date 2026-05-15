---
eyebrow: 'Docs · Daemon'
lede:    'Pre-register endpoints and subscriptions in the daemon so they are ready before the first IPC request lands. Useful for boot-time discovery and for keeping subscriptions alive across full daemon restarts.'

see_also:
  - { href: './auto-publish.md',                meta: '6 min' }
  - { href: '../managed-client/session-reuse.md', meta: '5 min' }
  - { href: '../recipes/recovery-and-reconnect.md', meta: '6 min' }

prev: { label: 'Logging and cache', href: './logging-and-cache.md' }
next: { label: 'Auto-publish',      href: './auto-publish.md' }
---

# Auto-connect

`SessionManagerDaemon::autoConnect()` lets you register a set of
`(endpoint, config, subscriptions)` triples that the daemon opens on
the first event-loop tick after startup. By the time the first
`ManagedClient` calls `connect()` with matching parameters, the
session is already there — no handshake on the critical path, no
"cold cache" window for subscriptions.

The feature is **embedded-only**: the CLI binary does not expose it.
Use `autoConnect()` when you construct the daemon programmatically.

## When to use it

- **Application boot warmup.** The first request after a daemon
  restart finds the OPC UA session ready instead of paying the
  ~1-second handshake.
- **Always-on subscriptions.** Data-change monitoring that must
  start as soon as the daemon is up, before any application code
  has run.
- **Predictable inventory.** Production where the list of OPC UA
  endpoints is fixed and known at deploy time — register them at
  the daemon, do not rely on every worker to open them on demand.

If your endpoint list is dynamic — discovered at request time,
varies per tenant, comes from a database — use the regular
`open` IPC command from `ManagedClient::connect()` instead.

## Registering connections

<!-- @code-block language="php" label="examples/auto-connect.php" -->
```php
use PhpOpcua\SessionManager\Daemon\SessionManagerDaemon;
use Psr\Log\NullLogger;

$daemon = new SessionManagerDaemon(
    socketPath: '/var/run/opcua/sessions.sock',
    logger: new NullLogger(),
);

$daemon->autoConnect([
    'plc1' => [
        'endpoint'      => 'opc.tcp://plc-1.plant.local:4840',
        'config'        => [
            'securityPolicy' => 'http://opcfoundation.org/UA/SecurityPolicy#Basic256Sha256',
            'securityMode'   => 3,
            'username'       => 'integrations',
            'password'       => getenv('PLC1_PASSWORD'),
            'autoRetry'      => 3,
        ],
        'subscriptions' => [
            [
                'publishing_interval' => 500.0,
                'monitored_items'     => [
                    ['node_id' => 'ns=2;s=Devices/PLC1/Speed', 'sampling_interval' => 500.0],
                    ['node_id' => 'ns=2;s=Devices/PLC1/Mode',  'sampling_interval' => 1000.0],
                ],
            ],
        ],
    ],
    'plc2' => [
        'endpoint'      => 'opc.tcp://plc-2.plant.local:4840',
        'config'        => [/* … */],
        'subscriptions' => [],
    ],
]);

$daemon->run();
```
<!-- @endcode-block -->

Each entry is a `[label => [endpoint, config, subscriptions]]` map.
The labels are diagnostic only — sessions are keyed by `(endpoint,
sanitized config)`, the same as on-demand opens. See
[ManagedClient · Session reuse](../managed-client/session-reuse.md).

## When connections happen

On the first event-loop tick after `run()` enters the loop, the
daemon iterates the auto-connect list and calls into the same
internal path the `open` IPC command uses. From the OPC UA server's
perspective there is no difference between an auto-connect session
and an on-demand one.

If a particular auto-connect entry **fails** (server unreachable,
bad credentials, certificate rejected):

- The failure is logged at `error` level.
- The daemon **continues startup** — failures in auto-connect do
  not abort the daemon, since other endpoints may still be
  reachable.
- The failed entry is **not retried automatically**. A subsequent
  `open` from `ManagedClient` will retry with the same parameters.

## Subscriptions in the auto-connect payload

The `subscriptions` array is processed after the session activates:
the daemon issues `createSubscription` + `createMonitoredItems` per
entry, then walks away. The subscriptions are live; if you also
wired auto-publish (see [Auto-publish](./auto-publish.md)),
notifications start flowing immediately.

| Subscription field    | Required | Notes                                                 |
| --------------------- | -------- | ----------------------------------------------------- |
| `publishing_interval` | no       | Milliseconds. Default `500.0`. Server may revise.     |
| `lifetime_count`      | no       | Default `2400`.                                        |
| `max_keep_alive_count`| no       | Default `10`.                                          |
| `max_notifications_per_publish` | no | Default `0` (unbounded).                            |
| `priority`            | no       | Default `0`.                                           |
| `monitored_items`     | no       | Array of `{node_id, attribute_id?, sampling_interval?, queue_size?, client_handle?}` |
| `event_monitored_items` | no     | Array of `{node_id, select_fields?, client_handle?}`  |

> Auto-connect uses **snake_case** keys throughout — they are
> processed by `CommandHandler::autoConnectSession()` /
> `createAutoConnectMonitoredItems()` /
> `createAutoConnectEventMonitoredItems()` and **not** by the
> regular IPC path. Mixing camelCase (`publishingInterval`,
> `monitoredItems`, `nodeId`) silently falls through to defaults
> (`publishing_interval = 500.0`, no monitored items, undefined
> indexes inside each item).

The shape mirrors `createMonitoredItems()` on the OPC UA client — see
[`opcua-client` — monitored items](https://github.com/php-opcua/opcua-client/blob/master/docs/operations/subscriptions.md).

## Interaction with on-demand opens

A `ManagedClient::connect()` from an application **matches** an
auto-connect session as long as the `(endpoint, config)` tuple
matches (after sanitisation). The application call:

- Returns immediately without re-opening the session.
- `wasSessionReused()` returns `true`.
- Has access to the pre-registered subscriptions and any cached
  values the auto-connect path populated.

If the application's config differs from the auto-connect config —
even in a single sanitised field — the application opens a fresh
session against the same endpoint. The two sessions coexist; the
server sees two distinct activations. Avoid the duplication by
agreeing on a single config schema across boot-time and runtime.

## Restart behaviour

When the daemon restarts:

- All sessions terminate (the daemon's process is the holder).
- Auto-connect re-runs on the next startup — sessions reappear.
- Subscriptions are recreated from scratch — server-side state is
  gone, no `transferSubscriptions` magic at the daemon layer.

The recovery time is roughly `handshake + createSubscription
round-trips` per entry. For latency-sensitive deployments, this is
why auto-connect exists: amortise the cold start once, at daemon
boot, rather than per-request.

## What the bin script does instead

The packaged binary does not currently expose an auto-connect file
or CLI flag — the only way to register auto-connect entries is
through embedded use. If you need auto-connect with the standard bin
script, the recommended path is to write a small launcher that
constructs the daemon programmatically:

<!-- @code-block language="php" label="bin/my-opcua-daemon" -->
```php
#!/usr/bin/env php
<?php
require __DIR__ . '/../vendor/autoload.php';

use PhpOpcua\SessionManager\Daemon\SessionManagerDaemon;

$daemon = new SessionManagerDaemon(
    socketPath: getenv('OPCUA_SOCKET_PATH') ?: '/tmp/opcua-session-manager.sock',
    authToken:  getenv('OPCUA_AUTH_TOKEN')  ?: null,
);

$daemon->autoConnect(require __DIR__ . '/../config/opcua-endpoints.php');
$daemon->run();
```
<!-- @endcode-block -->

The configuration file (`config/opcua-endpoints.php`) returns the
auto-connect array. Wire your secrets in the same file.
