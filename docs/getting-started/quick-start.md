---
eyebrow: 'Docs · Getting started'
lede:    'Start the daemon, build a ManagedClient, read a value — five minutes from install to a request that survives PHP-FPM.'

see_also:
  - { href: './why-a-session-manager.md',                  meta: '7 min' }
  - { href: '../managed-client/overview.md',               meta: '5 min' }
  - { href: '../recipes/persistent-sessions-laravel.md',   meta: '6 min' }

prev: { label: 'Installation',            href: './installation.md' }
next: { label: 'Why a session manager',   href: './why-a-session-manager.md' }
---

# Quick start

This page wires the smallest end-to-end integration: daemon up,
client connects to an OPC UA server through the daemon, reads one
value, exits. Subsequent runs reuse the same session — that is the
whole point.

## 1 — Start the daemon

<!-- @code-block language="bash" label="terminal" -->
```bash
vendor/bin/opcua-session-manager
```
<!-- @endcode-block -->

Leave it running in the foreground for now. In production you wrap
it in systemd — see
[Daemon · Running as a service](../daemon/running-as-a-service.md).

## 2 — Connect and read

<!-- @code-block language="php" label="examples/read-one.php" -->
```php
<?php

require __DIR__ . '/vendor/autoload.php';

use PhpOpcua\SessionManager\Client\ManagedClient;
use PhpOpcua\Client\Types\NodeId;
use PhpOpcua\Client\Types\StatusCode;

$client = new ManagedClient(
    socketPath: '/tmp/opcua-session-manager.sock',
    timeout: 30.0,
);

$client->connect('opc.tcp://localhost:4840');

$dv = $client->read(NodeId::numeric(0, 2261));   // ProductName

if (StatusCode::isGood($dv->statusCode)) {
    echo "Connected to: " . $dv->getValue() . "\n";
}

$client->disconnect();
```
<!-- @endcode-block -->

Run it twice. The **first** run pays the OPC UA handshake cost; the
**second** run reuses the same daemon-held session and the read is
~10× faster.

## 3 — Verify the reuse

<!-- @code-block language="php" label="examples/check-reuse.php" -->
```php
$client->connect('opc.tcp://localhost:4840');

if ($client->wasSessionReused()) {
    echo "Reusing existing session — no handshake.\n";
} else {
    echo "Fresh session — first connect from this configuration.\n";
}
```
<!-- @endcode-block -->

`wasSessionReused()` is the ManagedClient-specific accessor that
tells you whether the last `connect()` matched an existing session
keyed by the same `(endpointUrl, sanitized config)` pair. See
[ManagedClient · Session reuse](../managed-client/session-reuse.md).

## 4 — What disconnect does (and does not)

<!-- @code-block language="php" label="examples/disconnect.php" -->
```php
$client->disconnect();
```
<!-- @endcode-block -->

`ManagedClient::disconnect()` sends a `close` IPC command to the
daemon. The daemon performs `CloseSession` against the OPC UA
server and removes the entry from its session store. The
daemon-side session is **gone** when `disconnect()` returns; the
next `connect()` with the same config pays the OPC UA handshake
again.

To keep the daemon session alive across multiple requests so the
reuse machinery can do its job, **do not call `disconnect()`**
between requests — keep the `ManagedClient` instance alive
(singleton bind in Laravel, long-running worker) and let the
daemon's inactivity timeout (`--timeout`, default `600` s)
reclaim idle sessions when nothing references them.

To bypass reuse on a particular connect call (force a fresh
session even if one matches):

<!-- @code-block language="php" label="examples/force-new.php" -->
```php
$client->connectForceNew('opc.tcp://localhost:4840');
```
<!-- @endcode-block -->

`connectForceNew()` is the escape hatch for session isolation
(per-test scenarios, manual session resets). See
[ManagedClient · Opening and closing](../managed-client/opening-and-closing.md).

## 5 — Where to go next

The `ManagedClient` reads, writes, browses, subscribes — every
method on `OpcUaClientInterface` works. For the operation pages
themselves (`read`, `browse`, `subscribe`, …), the documentation
lives in `opcua-client`:

- [`opcua-client/doc/04-reading-writing.md`](https://github.com/php-opcua/opcua-client/blob/master/docs/operations/reading-attributes.md)
- [`opcua-client/doc/03-browsing.md`](https://github.com/php-opcua/opcua-client/blob/master/docs/operations/browsing.md)
- [`opcua-client/doc/06-subscriptions.md`](https://github.com/php-opcua/opcua-client/blob/master/docs/operations/subscriptions.md)

In this documentation the focus is on **what changes** when you go
through the daemon:

- [Why a session manager](./why-a-session-manager.md) — the mental
  model.
- [ManagedClient · Overview](../managed-client/overview.md) — what
  the wrapper does and what it does not.
- [ManagedClient · Differences from the direct
  client](../managed-client/differences-from-direct-client.md) —
  side-by-side comparison.

<!-- @callout variant="tip" -->
For a quick smoke-test of the daemon without any PHP, use
[`netcat`](../recipes/debugging-with-netcat.md) to send a `ping`
command directly to the socket. It is the fastest way to confirm the
daemon is alive and accepting requests.
<!-- @endcallout -->
