---
eyebrow: 'Docs · Reference'
lede:    'The seven IPC commands with their exact request / response shapes. For the operational walkthrough, see IPC · Commands.'

see_also:
  - { href: '../ipc/commands.md',                 meta: '7 min' }
  - { href: '../ipc/envelope-and-framing.md',     meta: '5 min' }
  - { href: '../ipc/type-serialization.md',       meta: '6 min' }

prev: { label: 'ManagedClient API', href: './managed-client-api.md' }
next: { label: 'Exceptions',        href: './exceptions.md' }
---

# IPC commands

Every request frame is a flat JSON object whose `command` field
selects the verb. The other fields depend on the verb (see each
command below). Every response is either
`{"success": true, "data": …}` or
`{"success": false, "error": {"type": …, "message": …}}`. When
the daemon was started with an auth token, every request must
also carry `authToken: "<shared-secret>"`.

<!-- @divider eyebrow="ping" -->
<!-- @enddivider -->

**Request**

| Field     | Type    | Notes                  |
| --------- | ------- | ---------------------- |
| `command` | string  | `"ping"`               |

**Response data**

| Field      | Type    | Notes                            |
| ---------- | ------- | -------------------------------- |
| `status`   | string  | Always `"ok"` on success         |
| `sessions` | int     | Count of active sessions         |
| `time`     | float   | `microtime(true)` at response    |

<!-- @divider eyebrow="list" -->
<!-- @enddivider -->

**Request**

| Field     | Type    | Notes      |
| --------- | ------- | ---------- |
| `command` | string  | `"list"`   |

**Response data**

| Field      | Type     | Notes                                                    |
| ---------- | -------- | -------------------------------------------------------- |
| `count`    | int      | Number of sessions returned                              |
| `sessions` | object[] | Array of session summaries (below)                       |

**Per-session shape**

| Field         | Type    | Notes                                                       |
| ------------- | ------- | ----------------------------------------------------------- |
| `id`          | string  | Opaque session ID                                           |
| `endpointUrl` | string  | OPC UA endpoint URL                                         |
| `lastUsed`    | float   | Epoch timestamp of last activity                            |
| `config`      | object  | Redacted config — sensitive keys stripped (see below)       |

Redacted keys: `username`, `password`, `clientKeyPath`,
`userKeyPath`, `caCertPath`.

<!-- @divider eyebrow="describe" -->
<!-- @enddivider -->

**Request**

| Field       | Type    | Notes                                          |
| ----------- | ------- | ---------------------------------------------- |
| `command`   | string  | `"describe"`                                   |
| `sessionId` | string  | Session to describe                             |

**Response data**

| Field          | Type      | Notes                                              |
| -------------- | --------- | -------------------------------------------------- |
| `methods`      | string[]  | Every method registered on the session's client     |
| `modules`      | string[]  | Fully-qualified loaded module class names           |
| `wireClasses`  | string[]  | Class strings the Wire registry can decode          |
| `enumClasses`  | string[]  | Enum class strings the Wire registry can decode     |
| `wireTypeIds`  | string[]  | Stable Wire type IDs (e.g. `"NodeId"`, `"Variant"`) |

<!-- @divider eyebrow="open" -->
<!-- @enddivider -->

**Request**

| Field         | Type     | Notes                                                            |
| ------------- | -------- | ---------------------------------------------------------------- |
| `command`     | string   | `"open"`                                                         |
| `endpointUrl` | string   | OPC UA endpoint URL                                              |
| `config`      | object   | `SessionConfig` as a flat map (see schema below)                 |
| `forceNew`    | bool     | Optional; when `true` bypass session reuse                       |

**Config schema** (every field optional):

| Key                          | Type     | Notes                                                  |
| ---------------------------- | -------- | ------------------------------------------------------ |
| `securityPolicy`             | string   | Full URI — **in session key**                          |
| `securityMode`               | int      | `1`=None, `2`=Sign, `3`=SignAndEncrypt — **in session key** |
| `username`                   | string   | **In session key**, also redacted from `list`          |
| `password`                   | string   | **NOT in session key** (nulled by `SessionConfig::sanitized()`), redacted from `list` |
| `clientCertPath`             | string   | Filesystem path; gated by `--allowed-cert-dirs`; **in session key** |
| `clientKeyPath`              | string   | **NOT in session key** (nulled by sanitisation), redacted from `list` |
| `caCertPath`                 | string   | **NOT in session key** (nulled by sanitisation), redacted from `list` |
| `userCertPath`               | string   | User-identity X.509 — **in session key**                |
| `userKeyPath`                | string   | **NOT in session key** (nulled by sanitisation), redacted from `list` |
| `opcuaTimeout`               | float    | Per-call OPC UA timeout, seconds — **in session key**   |
| `autoRetry`                  | int      | Per-call retry count — **in session key**               |
| `batchSize`                  | int      | Override for read/write batching — **in session key**   |
| `defaultBrowseMaxDepth`      | int      | Bounds `browseRecursive()` — **in session key**         |
| `autoDetectWriteType`        | bool     | **In session key**                                      |
| `readMetadataCache`          | bool     | Enable metadata-cache — **in session key**              |
| `trustStorePath`             | string   | Trust-store directory — **in session key**              |
| `trustPolicy`                | string   | `"fingerprint"`, `"fingerprint+expiry"`, `"full"` — **in session key** |
| `autoAccept`                 | bool     | TOFU — **in session key**                               |
| `autoAcceptForce`            | bool     | Re-accept rejected certs — **in session key**           |

> The four "secret" path fields (`password`, `clientKeyPath`,
> `caCertPath`, `userKeyPath`) are stripped from the session
> lookup key by `SessionConfig::sanitized()`. As a consequence,
> two `open` calls with the same `username` but different
> `password` values match the same daemon-side session. This is a
> deliberate trade-off (secret values would otherwise sit in
> memory as cache-key strings) but worth knowing when reasoning
> about session identity.

**Response data**

| Field       | Type    | Notes                                       |
| ----------- | ------- | ------------------------------------------- |
| `sessionId` | string  | Opaque daemon-assigned ID                   |
| `reused`    | bool    | Whether an existing session matched the key |

<!-- @divider eyebrow="close" -->
<!-- @enddivider -->

**Request**

| Field       | Type    | Notes                          |
| ----------- | ------- | ------------------------------ |
| `command`   | string  | `"close"`                      |
| `sessionId` | string  | Session to close                |

**Response data**

| Type    | Notes                          |
| ------- | ------------------------------ |
| `null`  | Always `null` on success       |

<!-- @divider eyebrow="query" -->
<!-- @enddivider -->

**Request**

| Field       | Type    | Notes                                                       |
| ----------- | ------- | ----------------------------------------------------------- |
| `command`   | string  | `"query"`                                                   |
| `sessionId` | string  | Target session                                              |
| `method`    | string  | Method name to invoke against the session's OPC UA client    |
| `params`    | array   | Positional arguments — one entry per method parameter        |

`method` must be in `CommandHandler::ALLOWED_METHODS` (44 entries
as of v4.3.1). Methods outside the list raise `forbidden_method`.

`params` is the positional arguments array, with each typed value
shaped per [Type serialization](../ipc/type-serialization.md).

**Response data** — method-dependent; matches the shape that
`TypeSerializer::serialize()` produces for the method's return
type.

<!-- @divider eyebrow="invoke" -->
<!-- @enddivider -->

**Request**

| Field       | Type    | Notes                                                              |
| ----------- | ------- | ------------------------------------------------------------------ |
| `command`   | string  | `"invoke"`                                                         |
| `sessionId` | string  | Target session                                                     |
| `method`    | string  | Method name (gated by `$client->hasMethod($name)`)                  |
| `args`      | array   | Wire-encoded values (each typed payload wraps in `{"__t": "<id>", ...}`) |

`method` is gated by `$client->hasMethod($name)` — no static
whitelist. Methods that are not registered on the session's client
raise `unknown_method`.

**Response data** — `{"data": <wire-encoded result>}`. The client
decodes the inner value via its mirror of the daemon's Wire
registry.

<!-- @divider eyebrow="Error response (shared by every command)" -->
<!-- @enddivider -->

**Shape**

| Field      | Type     | Notes                                                       |
| ---------- | -------- | ----------------------------------------------------------- |
| `success`  | bool     | `false`                                                     |
| `error`    | object   | `{type: string, message: string}`                           |

**Error type catalogue**

| `error.type`                  | Cause                                                      |
| ----------------------------- | ---------------------------------------------------------- |
| `invalid_json`                | Frame did not parse                                        |
| `payload_too_large`           | Frame > 65 536 bytes (or buffer > 1 MiB)                  |
| `connection_timeout`          | No activity for 30 s on the IPC connection                  |
| `auth_failed`                 | Missing or wrong auth token                                |
| `unknown_command`             | `command` is not one of the seven recognised verbs         |
| `unknown_method`              | `invoke` referenced a method not registered on the client  |
| `forbidden_method`            | `query` outside the allowlist                              |
| `session_not_found`           | `sessionId` references a missing session                   |
| `max_sessions_reached`        | Daemon at `--max-sessions`                                 |
| `auto_publish_active`         | Manual `publish()` blocked while auto-publish owns the session |
| `ConnectionException`         | Propagated OPC UA transport failure                        |
| `ServiceException`            | Propagated OPC UA bad status                               |
| `ServiceUnsupportedException` | Propagated `BadServiceUnsupported`                         |
| *short class name*            | Any other exception thrown server-side; the daemon emits   |
|                               | `(new ReflectionClass($e))->getShortName()` as the token   |

See [Exceptions](./exceptions.md) for the PHP-side mapping.

## Authentication shape

When the daemon was started with an auth token, **every** request
must include it:

<!-- @code-block language="text" label="authenticated request" -->
```text
{"command":"ping","authToken":"<shared-secret>"}
```
<!-- @endcode-block -->

Validation is `hash_equals()` (timing-safe). Failure responds
`auth_failed` and closes the connection.
