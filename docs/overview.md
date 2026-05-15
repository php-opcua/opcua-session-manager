---
eyebrow: 'Docs · Overview'
lede:    'A long-running daemon that holds OPC UA sessions on behalf of short-lived PHP processes. Drop in ManagedClient for OpcUaClientInterface and your application reads, writes, and subscribes through a session that survives the request.'

see_also:
  - { href: './getting-started/installation.md',          meta: '2 min' }
  - { href: './getting-started/why-a-session-manager.md', meta: '7 min' }
  - { href: 'https://github.com/php-opcua/opcua-client',  meta: 'external', label: 'php-opcua/opcua-client' }

prev: { label: 'No previous page', href: '#' }
next: { label: 'Installation',     href: './getting-started/installation.md' }
---

# Overview

`php-opcua/opcua-session-manager` is the persistence layer for
`php-opcua/opcua-client`. It runs as a separate process (a ReactPHP
daemon) and keeps OPC UA sessions alive across short-lived PHP
requests. Your application talks to the daemon over a local IPC
channel through `ManagedClient`, which implements the same
`OpcUaClientInterface` as the direct client — drop in, no API
change.

## What problem it solves

OPC UA sessions are **stateful**. A session takes one to two seconds
to negotiate (TCP handshake + OPN + CreateSession + ActivateSession),
holds a secure channel, and may carry subscriptions with their own
sequence-number bookkeeping. PHP processes, on the other hand, are
**short-lived** — a single web request, a single CLI invocation. The
two models are mismatched.

Without the session manager, a Laravel controller that needs to read
a tag has two bad options:

- Open a new session on every request. Spend most of the request
  budget on the handshake.
- Keep the connection alive somehow. PHP gives you no straightforward
  way to do that — no thread-local globals across requests, no
  long-lived daemons in the runtime.

`opcua-session-manager` is the long-lived daemon PHP does not give
you out of the box.

## What is in the box

<!-- @code-block language="text" label="components" -->
```text
SessionManagerDaemon         long-running ReactPHP daemon
   │
   ├── SessionStore          dictionary of active sessions keyed by (endpoint, config)
   ├── CommandHandler        IPC command dispatcher with method whitelist
   ├── AutoPublisher         optional event-driven publish loop
   └── ParamDeserializerRegistry  pluggable typed-arg decoding

ManagedClient                drop-in OpcUaClientInterface
   │
   └── implements every method of the direct Client, routed through IPC

IPC layer
   ├── UnixSocketTransport   Linux / macOS default
   ├── TcpLoopbackTransport  Windows / portable fallback (loopback only)
   ├── SocketConnection      flat JSON envelope + NDJSON framing
   └── TypeSerializer        JSON shapes for every OPC UA value
```
<!-- @endcode-block -->

## When to reach for it

Use the session manager when:

- **Your application is request-driven** — Laravel / Symfony / plain
  PHP-FPM, where every request spawns a fresh PHP process.
- **You make repeated OPC UA calls per request** — the handshake
  amortises across requests instead of being paid each time.
- **You need durable subscriptions** — keep monitored items alive
  across the publish loop, which a single request cannot drive.

Skip it when:

- **Your application is a single long-running CLI script** —
  `opcua-client` directly is simpler and one fewer moving part.
- **You only make occasional calls** — once a minute is too rare to
  justify a daemon.
- **You cannot run a daemon** — managed PHP hosts that disallow
  background processes leave you with only the direct client.

## Reading order

This documentation is organised by who you are right now:

- **Getting started** — installation, first read, the mental model
  that makes the daemon make sense.
- **Daemon** — operational pages for the long-running side: starting,
  configuring, securing, supervising.
- **ManagedClient** — the client-side surface, with focus on what
  *differs* from the direct client (session reuse, IPC cost). For OPC
  UA operations themselves, see the
  [opcua-client docs](https://github.com/php-opcua/opcua-client/tree/master/docs).
- **IPC** — the wire protocol between the two. Useful for debugging
  and for non-PHP consumers.
- **Extensibility** — wiring custom modules and param deserializers.
- **Reference** — flat catalogues of every CLI option, public method,
  command, and exception.
- **Recipes** — short walkthroughs for the patterns that come up most
  often.

## Relationship to other packages

| Package                                                      | Role                                                          |
| ------------------------------------------------------------ | ------------------------------------------------------------- |
| [`php-opcua/opcua-client`](https://github.com/php-opcua/opcua-client)   | The actual OPC UA client. This daemon embeds it.    |
| **`php-opcua/opcua-session-manager`** (this package)         | Daemon + ManagedClient for persistent sessions                |
| [`php-opcua/laravel-opcua`](https://github.com/php-opcua/laravel-opcua) | Laravel integration that wires ManagedClient into the service container |
| [`php-opcua/opcua-cli`](https://github.com/php-opcua/opcua-cli)         | Command-line tooling (browse, read, write, trust). Standalone — does not need the daemon. |

The session manager is intentionally a separate package — see
[Why a session manager](./getting-started/why-a-session-manager.md)
for the architectural rationale.
