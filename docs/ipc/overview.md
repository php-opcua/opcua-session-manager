---
eyebrow: 'Docs · IPC'
lede:    'NDJSON-framed flat JSON envelopes over a local socket. Two transports, one wire format, seven commands. The whole protocol fits on one screen.'

see_also:
  - { href: './envelope-and-framing.md',  meta: '5 min' }
  - { href: './commands.md',              meta: '7 min' }
  - { href: '../daemon/transports.md',    meta: '5 min' }

prev: { label: 'Differences from the direct client', href: '../managed-client/differences-from-direct-client.md' }
next: { label: 'Envelope and framing',               href: './envelope-and-framing.md' }
---

# IPC overview

The IPC channel between `ManagedClient` and `SessionManagerDaemon`
is intentionally narrow:

- **Transport.** One of `UnixSocketTransport` or
  `TcpLoopbackTransport`. See [Daemon · Transports](../daemon/transports.md)
  for the trust posture of each.
- **Framing.** NDJSON — one JSON value per line, separated by `\n`.
  Streams are opened in binary mode so Windows text-mode CRLF
  translation never mangles frame boundaries.
- **Envelope.** Flat JSON object with a `command` discriminator on
  every request; `{success, data | error}` on every response. See
  [Envelope and framing](./envelope-and-framing.md).
- **Commands.** Seven verbs — `ping`, `list`, `open`, `close`,
  `query`, `describe`, `invoke` — covered in
  [Commands](./commands.md).

## The protocol in one round-trip

<!-- @code-block language="text" label="example round-trip" -->
```text
client → daemon
  {"command":"open","endpointUrl":"opc.tcp://plc.local:4840","config":{"securityPolicy":"http://opcfoundation.org/UA/SecurityPolicy#None"}}\n

daemon → client
  {"success":true,"data":{"sessionId":"a1b2c3...","reused":false}}\n
```
<!-- @endcode-block -->

That is the entire wire — JSON, line, JSON, line. No length
prefix, no XML envelopes, no Protobuf descriptor needed.

## Constraints and gates

The daemon enforces a small set of envelope-level constraints. They
are not configurable — they are part of the contract.

| Constraint                  | Value                                | Why                                                       |
| --------------------------- | ------------------------------------ | --------------------------------------------------------- |
| Max frame size              | 65 536 bytes                         | DoS gate — legitimate frames are < 2 KiB                  |
| Max per-connection buffer   | 1 048 576 bytes                      | Backstop for misbehaving senders                          |
| Max concurrent connections  | 50                                   | Per-process cap; configurable in code but not via CLI     |
| Per-connection idle timeout | 30 seconds                           | Connection-level; not a per-request timeout               |

Frames that exceed any of these are rejected with `payload_too_large`
or `invalid_json` and the connection is closed. See
[Reference · Exceptions](../reference/exceptions.md).

## Two RPC paths

The daemon exposes two dispatch paths into the underlying OPC UA
client. Same command shape, different gating.

| Path     | Use                                                                | Gating                                            |
| -------- | ------------------------------------------------------------------ | ------------------------------------------------- |
| `query`  | Built-in `OpcUaClientInterface` methods                            | Static whitelist (`CommandHandler::ALLOWED_METHODS`) |
| `invoke` | Any method registered on the daemon's `Client`, including custom modules | `$client->hasMethod($name)` + Wire type allowlist |

`ManagedClient` routes the standard methods through `query` and any
unknown method (via `__call()`) through `invoke`. Application code
does not pick — the dispatcher does. See [Commands](./commands.md).

## Typed values cross the boundary

Every value crossing the IPC boundary is JSON-encoded by
`TypeSerializer` (`query` path) or by the Wire layer (`invoke`
path). The two formats are different in detail but share the same
goal:

- **No `unserialize()`** on the receiving side.
- **Explicit type tags** for typed payloads.
- **Deterministic round-trip** for the OPC UA value-object set:
  `NodeId`, `Variant`, `DataValue`, `LocalizedText`, `QualifiedName`,
  `ReferenceDescription`, …

See [Type serialization](./type-serialization.md) for the per-type
shapes.

## What "authenticated" looks like on the wire

When the daemon requires an auth token, every request frame must
carry an `authToken` field:

<!-- @code-block language="text" label="authenticated frame" -->
```text
{"command":"ping","authToken":"<shared-secret>"}
```
<!-- @endcode-block -->

The daemon validates with `hash_equals()` (timing-safe) before
dispatching. Failure responds with `auth_failed` and closes the
connection. See [Daemon · Authentication](../daemon/authentication.md).

## What is *not* in the IPC layer

- **Per-request authentication.** The daemon-level shared secret is
  the only auth gate. Per-user authorisation has to live in your
  application code, not the IPC channel.
- **Asynchronous notifications.** The IPC channel is request /
  response. Notifications from OPC UA subscriptions are surfaced
  via the daemon's auto-publish path (see [Daemon ·
  Auto-publish](../daemon/auto-publish.md)) — they do not stream
  back over the IPC channel.
- **Backpressure signalling.** The 50-connection cap is a hard
  rejection, not a queue. Clients beyond the cap see a connection
  refused at the transport level.
- **Versioning.** The wire format does not carry a version field.
  Compatibility is by behaviour: the flat `{command, ...}` envelope
  is the current shape and the only one the daemon accepts.

## When the wire matters to you

In normal use, the IPC layer is invisible — `ManagedClient` and the
daemon handle it for you. You reach for the wire-level docs when:

- **Debugging a stuck call.** Run `netcat` against the socket and
  send a `ping`. See [Recipes · Debugging with
  netcat](../recipes/debugging-with-netcat.md).
- **Integrating from a non-PHP language.** A Go or Python consumer
  speaks the same JSON envelope.
- **Writing a custom module** that ships its own parameter shapes.
  See [Extensibility · Custom param
  deserializer](../extensibility/custom-param-deserializer.md).
- **Operating a daemon at scale.** Knowing the frame cap is the
  difference between diagnosing `payload_too_large` quickly and
  staring at logs.
