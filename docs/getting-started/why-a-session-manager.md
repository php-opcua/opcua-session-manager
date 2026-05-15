---
eyebrow: 'Docs · Getting started'
lede:    'PHP processes die at the end of every request. OPC UA sessions need to live longer than that. The session manager is the bridge — built as a separate process so the trade-offs do not bleed into the client library.'

see_also:
  - { href: '../managed-client/session-reuse.md',          meta: '5 min' }
  - { href: '../daemon/auto-publish.md',                   meta: '6 min' }
  - { href: 'https://github.com/php-opcua/opcua-client/blob/master/ROADMAP.md#session-manager-integration-here', meta: 'external', label: 'opcua-client ROADMAP — Session Manager rationale' }

prev: { label: 'Quick start',           href: './quick-start.md' }
next: { label: 'Starting the daemon',   href: '../daemon/starting.md' }
---

# Why a session manager

OPC UA is a stateful protocol. PHP is a stateless runtime. The two
do not naturally meet — and the cost of bridging them in the wrong
layer is paid in either bad ergonomics or bad performance. This page
explains why this package exists as a separate process, not as a
piece of `opcua-client`.

## The mismatch

OPC UA, simplified:

- A **session** is a stateful, authenticated bind to a server. It
  carries identity, the secure channel, sequence numbers, and any
  subscriptions you create.
- The **handshake** to open a session takes one or two seconds: TCP
  handshake, OPN secure-channel setup, CreateSession, ActivateSession.
- Subscriptions live for the session's lifetime. They require an
  application to drive a publish loop to retrieve notifications.

PHP-FPM, simplified:

- A web request spawns a worker, runs your code, dies.
- The worker has no memory of any previous request. Globals reset,
  connections close, file descriptors are released.
- A "session" — in the HTTP sense — is a serialised blob on disk,
  not a live object.

If you call `opcua-client`'s `ClientBuilder::create()->connect()` in
a Laravel controller, **every request** pays the OPC UA handshake.
Subscriptions are impossible — they last only for the request
duration. You end up redesigning the integration around this
constraint, which usually means polling instead of subscribing.

## Two approaches that do not work

**1. Singleton in the framework's container.**

You bind a `Client` to the container so it is reused. It is — for
the duration of the request. The framework tears down the container
when the request ends; the `Client` is garbage-collected; the OPC UA
session goes with it.

**2. APCu / shared memory.**

You try to share a `Client` object across requests via APCu or a
similar shared cache. `Client` holds a socket, which is per-process.
Serialising it across processes is meaningless.

The fundamental constraint: **the live socket cannot leave the
process that opened it.** Any approach that does not introduce a
second process is doomed to either re-open the socket per request or
lie about reusing it.

## The third approach — a separate process

A long-running process can hold the socket. PHP requests talk to
that process over local IPC; the process is the source of truth for
the OPC UA session.

That is what `opcua-session-manager` is: a ReactPHP daemon that
keeps OPC UA sessions alive and exposes them over a Unix domain
socket (or TCP loopback on Windows). Your application code uses
`ManagedClient`, which speaks the IPC protocol and exposes
`OpcUaClientInterface` — the same interface as the direct client.

<!-- @code-block language="text" label="three layers" -->
```text
PHP-FPM worker 1 ─┐
                  │
PHP-FPM worker 2 ─┼── ManagedClient ──IPC──→ Session Manager Daemon ──OPC UA──→ Server
                  │                                  │
PHP-FPM worker N ─┘                                  └── holds the session
                                                         drives the publish loop
                                                         keeps the channel open
```
<!-- @endcode-block -->

The request cost is now the IPC round-trip — a few milliseconds —
instead of the OPC UA handshake.

## Why not bake this into opcua-client?

The session manager is intentionally a separate package. Four
reasons:

<!-- @do-dont -->
<!-- @do -->
**Separate concerns.** `opcua-client` is a synchronous, zero-runtime-
dependency library. The session manager runs an async event loop and
depends on ReactPHP. Different ergonomics, different runtime
requirements, different test surfaces.
<!-- @enddo -->
<!-- @dont -->
**Don't bundle ReactPHP into every user.** A CLI script that does
one `read()` and exits has no reason to install
`react/event-loop` + `react/socket`. Bundling would force the
dependency on every user.
<!-- @enddont -->
<!-- @enddo-dont -->

Also:

- **Cross-platform discipline.** `opcua-client` targets Linux,
  macOS, Windows. The daemon's preferred transport (Unix socket) is
  POSIX-only — the TCP loopback fallback exists for Windows. Keeping
  the transport layer in a separate package lets `opcua-client` stay
  Unix-clean and Windows-clean.
- **Operational separation.** A daemon is infrastructure; you
  deploy it, monitor it, restart it. A library is a Composer
  dependency you upgrade. Mixing the two clouds the responsibility
  boundary.

See the [opcua-client ROADMAP — Session Manager
rationale](https://github.com/php-opcua/opcua-client/blob/master/ROADMAP.md#session-manager-integration-here)
for the longer write-up.

## What you give up

This is not free.

- **An extra process to operate.** Your deployment story now
  includes the daemon: starting it, watching it, restarting it.
- **An extra failure mode.** The daemon can be down even when the
  OPC UA server is up. `ManagedClient` raises `DaemonException` when
  the IPC channel is unreachable.
- **A per-call IPC round-trip.** Tens of microseconds on a Unix
  socket; the OPC UA round-trip dominates the call cost regardless,
  but the IPC layer is real.
- **A serialisation layer.** Every typed argument and return value
  crosses the JSON boundary through `TypeSerializer`. The library
  manages this for you, but exotic types (custom ExtensionObject
  bodies, third-party module DTOs) need an explicit
  `ParamDeserializerInterface` — see [Extensibility · Custom param
  deserializer](../extensibility/custom-param-deserializer.md).

The trade-off is worth it for any application that issues more than
one OPC UA call per request. For a one-shot CLI script, use
`opcua-client` directly.

## When to skip it

- **Single long-running CLI script.** No request boundary to amortise
  across; just hold a `Client` in memory.
- **Once-a-minute polling from cron.** The handshake cost is paid
  rarely; the daemon's operational overhead exceeds its benefit.
- **Managed hosts that disallow daemons.** Shared PHP hosting,
  serverless PHP runtimes. The session manager needs a real process
  host.

For everything else — Laravel apps reading PLCs on every request,
Symfony consoles writing setpoints, queue workers reacting to live
subscriptions — the daemon earns its keep.
