---
eyebrow: 'Docs · ManagedClient'
lede:    'Same interface, different costs, slightly different lifecycle. Five things change between the direct Client and ManagedClient — knowing them up front avoids surprises in production.'

see_also:
  - { href: './overview.md',                  meta: '5 min' }
  - { href: './opening-and-closing.md',       meta: '6 min' }
  - { href: '../getting-started/why-a-session-manager.md', meta: '7 min' }

prev: { label: 'Session reuse',     href: './session-reuse.md' }
next: { label: 'IPC overview',      href: '../ipc/overview.md' }
---

# Differences from the direct client

`ManagedClient` implements `OpcUaClientInterface` and looks
identical to the direct `Client` at the call site. The differences
are below the call site — in cost, in lifecycle, in what is and
isn't observable. Five differences worth internalising before
production.

## 1 — Every call pays IPC

`Client` operations are local to the PHP process. `ManagedClient`
operations cross a Unix socket (or TCP loopback) for every method —
the daemon executes the actual OPC UA call.

| Cost                   | Direct `Client`             | `ManagedClient`                  |
| ---------------------- | --------------------------- | -------------------------------- |
| `read()` single value  | ~1-5 ms (OPC UA round-trip) | ~1-5 ms + ~0.5-2 ms IPC          |
| `connect()` first time | ~1 s (OPC UA handshake)     | ~1 s (handshake on daemon) + IPC |
| `connect()` reused     | n/a — every connect is fresh| ~0.5-2 ms (no handshake)         |
| `disconnect()`         | ~50 ms (CloseSession)       | ~50 ms + IPC (daemon performs CloseSession) |

The IPC cost is dominated by the OPC UA round-trip for any call
that talks to the server. It is **not** dominated for local-only
operations like `wasSessionReused()` or `getConnectionState()` —
those are an IPC round-trip on `ManagedClient`, free on direct
`Client`.

The asymmetry of `connect()` is the whole point: pay the handshake
once on the daemon, every subsequent client `connect()` reuses.

## 2 — Lifecycle semantics

Same method names, different meaning. Internalise the table:

| Method                | Direct `Client`                                  | `ManagedClient`                                                    |
| --------------------- | ------------------------------------------------ | ------------------------------------------------------------------ |
| `connect($url)`       | Opens TCP + secure channel + session, every time | Reuses daemon session when keys match; otherwise opens a fresh one  |
| `disconnect()`        | Closes session, channel, TCP socket              | **Sends `close` IPC; daemon tears the session down on the server too** |
| `reconnect()`         | Rebuilds channel + session                       | Sends `query method=reconnect` — daemon rebuilds the channel for the current session |
| `isConnected()`       | In-process boolean                               | IPC round-trip to query the daemon                                  |
| `getConnectionState()`| In-process enum                                  | IPC round-trip to query the daemon                                  |

The biggest pitfall: `disconnect()` **does** close the OPC UA
session. Callers that "tidy up" by disconnecting at the end of a
request **defeat reuse** — the next request from a fresh
`ManagedClient` pays the handshake again because the daemon's
session-store entry is gone. To benefit from reuse, keep the
`ManagedClient` instance alive across requests (singleton bind in
Laravel, long-running worker) and let the inactivity timeout
(`--timeout`, default 600 s) reclaim idle sessions instead.

## 3 — Events are dispatched in the daemon, not in your process

`getEventDispatcher()` is part of `OpcUaClientInterface`. On
`ManagedClient` it returns the **client-side** dispatcher (a
`NullEventDispatcher` by default), not the daemon's. Setting a
dispatcher on the managed client has **no effect** on the events
the daemon dispatches.

| Want to listen for events in     | Set the dispatcher on                                         |
| -------------------------------- | ------------------------------------------------------------- |
| The application process          | Wire your own — but the daemon won't fire into it             |
| The daemon process               | Pass `clientEventDispatcher` to the daemon constructor; embed the daemon |

The auto-publish feature is the canonical bridge — the daemon
dispatches events in its own process, and a queue / message bus
carries them to application listeners. See [Daemon ·
Auto-publish](../daemon/auto-publish.md) and [Recipes ·
Auto-publish pattern](../recipes/auto-publish-pattern.md).

## 4 — Configuration is frozen at `connect()`

On the direct `Client`, the configuration is owned by the
`ClientBuilder` and frozen once `connect()` returns — same as
`ManagedClient`. The difference: on the direct client there is no
way to "re-configure" without building a new client. On
`ManagedClient`, setting a different value and calling `connect()`
again opens a **different daemon-side session** (the keys do not
match) — your `getSessionId()` changes, `wasSessionReused()` is
false.

This is the "fragmentation" trap warned in
[Session reuse](./session-reuse.md). Build one canonical client
per `(endpoint, config)` pair, share it, do not vary the setters
per call site.

## 5 — Some surfaces are inherently different

A handful of `OpcUaClientInterface` methods cannot fully cross the
IPC boundary. They work — they just have caveats.

### `getExtensionObjectRepository()`

Returns the **client-side** ExtensionObject repository. Codecs
registered on it apply to **client-side decoding** of responses —
typically the path where the daemon returns raw bytes that the
client decodes. For codecs that need to run on the daemon side
(decoding ExtensionObjects on `Read` responses that the daemon
already auto-decodes), register them on the daemon-side client
through a custom param deserializer or a third-party module. See
[Extensibility · Third-party modules](../extensibility/third-party-modules.md).

### `getCache()` and the cache methods

`setCache()` configures a **client-side** cache. The daemon uses
its own cache (the `--cache-driver` configured at startup); the
client-side cache layered on top is useful for caching responses
the application receives, separate from what the daemon has
cached.

In practice, do not bother setting a client-side cache on
`ManagedClient` unless you have a measured need. The daemon's
cache is shared across all clients; the client-side cache is
process-local and adds little.

### `getLogger()`

Returns the client-side logger. Logs from inside the OPC UA stack
land in the **daemon's** logger, not this one. The client-side
logger captures `ManagedClient`'s own diagnostics — IPC retries,
serialisation traces — and nothing more.

### `hasMethod()`, `hasModule()`, `getRegisteredMethods()`, `getLoadedModules()`

These v4.2.0 introspection methods reach the daemon via the
`describe` IPC command on first call and **cache the response for
the client's lifetime**. The cached response is invalidated when
the IPC connection closes (`disconnect()`).

Two consequences:

- The first call pays an IPC round-trip; subsequent calls are
  free.
- If the daemon's module set changes mid-session (extremely rare —
  the daemon would have to be restarted with new modules), the
  client sees the old set until it reconnects.

## When to keep using the direct client

For services where every operation is well below request latency
and there is no value to amortise across requests — long-running
CLI scripts, batch importers, integration tests against a local
test server — the direct client is simpler. No daemon to start,
no IPC layer, no session reuse semantics to think about.

The session manager is the right answer for **request-driven**
applications. Direct client for everything else.
