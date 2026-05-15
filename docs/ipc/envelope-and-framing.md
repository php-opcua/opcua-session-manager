---
eyebrow: 'Docs · IPC'
lede:    'Every IPC frame is a flat JSON object, one per newline-terminated line. Two response shapes (success / error), one request shape that varies by command type.'

see_also:
  - { href: './overview.md',                         meta: '5 min' }
  - { href: './commands.md',                         meta: '7 min' }
  - { href: '../recipes/debugging-with-netcat.md',   meta: '4 min' }

prev: { label: 'IPC overview', href: './overview.md' }
next: { label: 'Commands',     href: './commands.md' }
---

# Envelope and framing

The daemon parses every inbound frame as a single JSON object and
dispatches on its `command` field. The envelope is **flat** — there
is no `id`, no `t`, no `method` discriminator at the envelope level
— and the response is `{success, data}` or `{success, error}`. This
page documents the exact field set per command type.

## Framing — NDJSON

One JSON value per line, separated by a single `\n`. No length
prefix, no fragmentation, no chunked encoding.

<!-- @code-block language="text" label="two frames back to back" -->
```text
{"command":"ping"}\n
{"command":"list","authToken":"<shared-secret>"}\n
```
<!-- @endcode-block -->

The transport opens the underlying stream in binary mode
(`fopen()`'s `'b'` flag) — this matters on Windows, where text
mode silently translates `\r\n` ↔ `\n`. Binary mode keeps the
framing reliable on every platform.

A frame **must** contain valid JSON and **must** end with `\n`.
Anything else is rejected with `invalid_json` and the connection is
closed.

## Request envelope

Every request carries `command` as the discriminator. The other
fields depend on which command was selected.

| Field         | Type     | Required for                                          |
| ------------- | -------- | ----------------------------------------------------- |
| `command`     | string   | All — one of `ping`, `list`, `describe`, `open`, `close`, `query`, `invoke` |
| `sessionId`   | string   | `close`, `query`, `describe`, `invoke`                 |
| `endpointUrl` | string   | `open`                                                 |
| `config`      | object   | `open` (typed `SessionConfig` as a flat map)           |
| `forceNew`    | bool     | `open` (optional)                                      |
| `method`      | string   | `query`, `invoke`                                      |
| `params`      | array    | `query`                                                |
| `args`        | array    | `invoke` (Wire-encoded values, each tagged `__t`)      |
| `authToken`   | string   | All commands when the daemon was started with a token  |

<!-- @code-block language="text" label="request examples" -->
```text
{"command":"ping"}
{"command":"open","endpointUrl":"opc.tcp://plc.local:4840","config":{"securityPolicy":"http://opcfoundation.org/UA/SecurityPolicy#None"},"authToken":"..."}
{"command":"close","sessionId":"a1b2c3","authToken":"..."}
{"command":"list","authToken":"..."}
{"command":"describe","sessionId":"a1b2c3","authToken":"..."}
{"command":"query","sessionId":"a1b2c3","method":"read","params":[{"ns":2,"id":"PLC/Speed","type":"string"},13,false],"authToken":"..."}
{"command":"invoke","sessionId":"a1b2c3","method":"customMethod","args":[{"__t":"NodeId","ns":2,"i":"Counter","type":"string"}],"authToken":"..."}
```
<!-- @endcode-block -->

There is no caller-assigned correlation ID — the daemon serves one
request per connection and writes exactly one response. The
client correlates by call site, not by frame field.

## Response envelopes

Two shapes — success and failure. The `success` boolean
discriminates.

### Success

| Field      | Type     | Notes                                                       |
| ---------- | -------- | ----------------------------------------------------------- |
| `success`  | `true`   | Marker                                                       |
| `data`     | any      | Command-specific result payload (may be `null` for `close`) |

<!-- @code-block language="text" label="success" -->
```text
{"success":true,"data":{"status":"ok","sessions":3,"time":1716000000.123}}
```
<!-- @endcode-block -->

### Error

| Field           | Type     | Notes                                                |
| --------------- | -------- | ---------------------------------------------------- |
| `success`       | `false`  | Marker                                                |
| `error.type`    | string   | Error token (see catalogue)                           |
| `error.message` | string   | Sanitised human-readable message                      |

<!-- @code-block language="text" label="errors" -->
```text
{"success":false,"error":{"type":"forbidden_method","message":"Method not allowed: rawCall"}}
{"success":false,"error":{"type":"payload_too_large","message":"Request frame exceeds maximum size of 65536 bytes"}}
{"success":false,"error":{"type":"ServiceUnsupportedException","message":"Server returned ServiceFault: 0x800B0000 BadServiceUnsupported"}}
```
<!-- @endcode-block -->

## Error type catalogue

The IPC layer surfaces failures with one of the tokens below. The
client-side decoder (`ManagedClient::sendCommand()`) reconstructs a
matching exception for a small set — everything else is wrapped in
`DaemonException`. See
[Reference · Exceptions](../reference/exceptions.md) for the full
mapping.

| `error.type`                  | Cause                                                       | PHP exception thrown by `ManagedClient`            |
| ----------------------------- | ----------------------------------------------------------- | -------------------------------------------------- |
| `invalid_json`                | Frame did not parse                                          | `DaemonException`                                  |
| `payload_too_large`           | Frame > 65 536 bytes (or buffer > 1 MiB)                    | `DaemonException`                                  |
| `connection_timeout`          | No activity within 30 s after IPC connect                   | `DaemonException`                                  |
| `auth_failed`                 | Missing or wrong auth token                                  | `DaemonException`                                  |
| `unknown_command`             | `command` field is not one of the seven recognised verbs    | `DaemonException`                                  |
| `forbidden_method`            | `query` was called with a method outside `ALLOWED_METHODS`  | `DaemonException`                                  |
| `unknown_method`              | `invoke` was called with a method not registered on the session's client | `DaemonException`                       |
| `session_not_found`           | `sessionId` references a session that no longer exists      | `ConnectionException` (wrapped — see note below)   |
| `max_sessions_reached`        | Daemon already holds `--max-sessions` sessions              | `DaemonException`                                  |
| `auto_publish_active`         | Manual `publish()` blocked while auto-publish owns the session | `DaemonException`                              |
| `ConnectionException`         | OPC UA transport failure (propagated)                       | `ConnectionException` (from opcua-client)          |
| `ServiceException`            | OPC UA server returned a bad status                         | `ServiceException` (from opcua-client)             |
| `ServiceUnsupportedException` | OPC UA `BadServiceUnsupported`                              | `ServiceUnsupportedException` (from opcua-client)  |
| *Other short class name*      | Any other exception thrown server-side, encoded by reflection | `DaemonException` (`[<type>] <message>`)         |

For every other thrown exception, the daemon emits `error.type =
(new ReflectionClass($e))->getShortName()`. The client wraps that
in a generic `DaemonException` carrying the same message. The
client does **not** try to reconstruct the original PHP class.

> **`session_not_found` is special.** The daemon emits it with the
> literal token `session_not_found`, but `ManagedClient` translates
> that to `ConnectionException("Session expired or not found: …")`,
> not `SessionNotFoundException`. The `SessionNotFoundException`
> class exists for internal daemon use (catch site inside
> `CommandHandler::handle()`); it is not raised on the client side.

The `error.message` is **sanitised** by
`CommandHandler::sanitizeErrorMessage()`: URLs, Windows paths, and
Unix paths are replaced with `[url]` / `[path]` before reaching the
wire. This prevents credentials and file-system layout from leaking
through error responses.

## Size gates

`SessionManagerDaemon` enforces two caps on inbound frames and one
on the per-connection buffer:

| Cap                  | Limit            | What happens on violation                        |
| -------------------- | ---------------- | ------------------------------------------------ |
| Frame size           | 65 536 bytes     | `payload_too_large`, connection closed           |
| Per-connection buffer | 1 048 576 bytes | `payload_too_large`, connection closed           |
| IPC idle timeout     | 30 seconds       | `connection_timeout`, connection closed          |

These are not configurable. They are part of the contract; if you
encounter them with legitimate traffic, something has gone wrong
upstream (a parameter loop, a misbuilt config map).

## The Wire registry — typed payloads for `invoke`

The `invoke` command carries typed arguments. The daemon uses a
`WireTypeRegistry` (from `opcua-client`'s Wire layer) to encode and
decode them with explicit `__t` discriminators:

<!-- @code-block language="text" label="invoke args" -->
```text
{
  "command": "invoke",
  "sessionId": "a1b2c3",
  "method": "customMethod",
  "args": [
    {"__t": "NodeId", "ns": 2, "i": "PLC/Speed", "type": "string"},
    {"__t": "Variant", "type": 11, "value": 42.5},
    {"__t": "DateTime", "v": "2026-05-15T10:30:00.000000+00:00"}
  ],
  "authToken": "..."
}
```
<!-- @endcode-block -->

Discriminators that are not in the registry cause the daemon to
reject the frame with the short class name of the underlying
exception. The registry seeds itself from the daemon's loaded
modules — anything reachable through the daemon's `Client` is
allowlisted.

For the rationale, see [`opcua-client` — wire serialization](https://github.com/php-opcua/opcua-client/blob/master/docs/extensibility/wire-serialization.md).

## Note on `WireMessageCodec`

The package ships a `PhpOpcua\SessionManager\Ipc\WireMessageCodec`
class that defines a typed-envelope shape with `{id, t, ...}`
framing. The class is **not** on the live code path: the daemon's
read / write loop in `SessionManagerDaemon` calls `json_decode` /
`json_encode` directly against the flat envelope documented above.
The codec's `encodeRequest()` / `decodeFrame()` /
`encodeOkResponse()` methods are not called anywhere in the current
codebase; only the underlying `WireTypeRegistry` is reused by the
`invoke` path. Treat any docs referencing the typed envelope as
historical and rely on the field set on this page.
