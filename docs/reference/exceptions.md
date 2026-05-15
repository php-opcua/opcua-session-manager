---
eyebrow: 'Docs · Reference'
lede:    'Three exceptions are raised by this package; several more are propagated from opcua-client across the IPC boundary. The mapping from IPC error_type to PHP exception class is fixed.'

see_also:
  - { href: '../ipc/envelope-and-framing.md',  meta: '5 min' }
  - { href: '../recipes/recovery-and-reconnect.md', meta: '6 min' }
  - { href: 'https://github.com/php-opcua/opcua-client/blob/master/docs/reference/exceptions.md', meta: 'external', label: 'opcua-client — error handling' }

prev: { label: 'IPC commands',           href: './ipc-commands.md' }
next: { label: 'Upgrading to v4.3',      href: '../recipes/upgrading-to-v4.3.md' }
---

# Exceptions

`ManagedClient` raises three exception classes of its own and
re-raises every exception class `opcua-client` would have raised
on a direct call. The IPC layer is responsible for the mapping
from wire-level `error.type` to the right PHP class.

All session-manager exceptions live in
`PhpOpcua\SessionManager\Exception\` and extend `\RuntimeException`.

## Session-manager exceptions

### DaemonException

The base IPC failure. Raised for everything that goes wrong with
the daemon connection or with the IPC envelope itself:

| Cause                                        | `error.type`              |
| -------------------------------------------- | ------------------------- |
| Socket unreachable, daemon down              | n/a — fails before IPC    |
| Bad envelope shape                           | `invalid_json`            |
| Frame too large                              | `payload_too_large`       |
| Auth failure                                 | `auth_failed`             |
| Unknown command name                         | `unknown_command`         |
| `query` outside the whitelist                | `forbidden_method`        |
| `invoke` method not registered on the client | `unknown_method`          |
| Daemon at session cap                        | `max_sessions_reached`    |
| Manual `publish()` blocked while auto-publish owns the session | `auto_publish_active` |

`DaemonException::getMessage()` carries the daemon-sanitised text;
the original cause is not preserved as `getPrevious()` for the
IPC-originated cases (only for genuine PHP-side wrap-ups).

**Recovery.** Most causes are operational — daemon down, mis-
configured token, peer hammering. The right reaction is to log,
back off, and either retry or escalate. **Do not** loop tightly
on `DaemonException`; you risk pinning a struggling daemon.

### SessionNotFoundException

Subclass of `\RuntimeException` (**not** of `DaemonException`).
Used inside the daemon by `SessionStore::get()` to flag a missing
session, then caught by `CommandHandler::handle()` and emitted on
the wire as `error.type = "session_not_found"`.

> **The client never raises `SessionNotFoundException` itself.**
> `ManagedClient::sendCommand()` translates the `session_not_found`
> wire token into `ConnectionException("Session expired or not
> found: …")`. Recovery code on the application side should
> catch `ConnectionException` (or its parent
> `\PhpOpcua\Client\Exception\OpcUaException`) — not
> `SessionNotFoundException`, which only exists for internal
> bookkeeping on the daemon side.

**Recovery.** Call `connect()` again. The daemon opens a fresh
session and `ManagedClient` continues. The
`attemptSessionRecovery()` path inside the daemon handles many
session-staleness cases automatically; what reaches the client
side is the cases that recovery could not handle.

See [Recipes · Recovery and
reconnect](../recipes/recovery-and-reconnect.md).

### SerializationException

Subclass of `RuntimeException` (not of `DaemonException`). The
class exists in `PhpOpcua\SessionManager\Exception\` but is
**not** raised anywhere in the current source tree — neither the
daemon nor the client throws it on its own. It is retained as a
public extension point for callers that ship their own
deserializers (see
[Custom param deserializer](../extensibility/custom-param-deserializer.md)).

When the daemon's `ParamDeserializerRegistry` cannot find a
deserializer for a `query` method, it raises
`InvalidArgumentException` (handled by `CommandHandler::handle()`).
The wire token in that case is `InvalidArgumentException` —
**not** a stable `serialization_error` constant. Callers that need
to react to a missing-deserializer condition should match on the
message text or on the wrapping `DaemonException`.

**Recovery.** Always a bug — either in the caller's argument
encoding, in the deserializer registration, or in a wire-format
mismatch. Do not retry; fix the encoding.

## Propagated exceptions (from opcua-client)

The daemon catches OPC UA failures from `opcua-client`, encodes
them onto the wire with their **short class name** as
`error.type`. The client-side IPC decoder
(`ManagedClient::sendCommand()`) reconstructs a typed exception
only for a **small, hard-coded set**; every other token is wrapped
in a generic `DaemonException` carrying the same message.

**Tokens the client reconstructs**

| Wire `error.type`             | PHP exception reconstructed by `ManagedClient`         |
| ----------------------------- | ------------------------------------------------------ |
| `ConnectionException`         | `PhpOpcua\Client\Exception\ConnectionException`        |
| `ServiceException`            | `PhpOpcua\Client\Exception\ServiceException`           |
| `ServiceUnsupportedException` | `PhpOpcua\Client\Exception\ServiceUnsupportedException` |
| `session_not_found`           | `PhpOpcua\Client\Exception\ConnectionException` (wrapped with `"Session expired or not found: …"`) |

**Tokens the daemon may emit but the client does not reconstruct**

Anything else thrown server-side becomes
`error.type = (new ReflectionClass($e))->getShortName()`. On the
client side the `ManagedClient::sendCommand()` `match` falls
through to `default => new DaemonException("[<type>] <message>")`.
That means callers receive a `DaemonException` (not the upstream
class) for any of:
`SecurityException`, `UntrustedCertificateException`,
`HandshakeException`, `MessageTypeException`,
`ConfigurationException`, `EncodingException`,
`InvalidNodeIdException`, `WriteTypeDetectionException`,
`WriteTypeMismatchException`, `InvalidArgumentException`, etc.

To branch on the original exception class without losing
information, inspect `DaemonException::getMessage()` — the message
is prefixed with the short class name in brackets, e.g.
`"[SecurityException] certificate parse failed"`.

`ServiceUnsupportedException` was added to the propagation list in
v4.3.0 — earlier daemons mapped it to a generic `ServiceException`.
See [Recipes · Upgrading to v4.3](../recipes/upgrading-to-v4.3.md).

## Recommended try / catch shape

<!-- @code-block language="php" label="examples/error-handling.php" -->
```php
use PhpOpcua\SessionManager\Exception\DaemonException;
use PhpOpcua\Client\Exception\ConnectionException;
use PhpOpcua\Client\Exception\ServiceUnsupportedException;
use PhpOpcua\Client\Exception\ServiceException;

try {
    $client->connect($endpointUrl);
    $value = $client->read($nodeId);
} catch (ConnectionException $e) {
    // Covers both session-expired (mapped from session_not_found)
    // AND OPC UA transport failures. The string "Session expired
    // or not found" identifies the former — reconnect and retry once.
    if (str_starts_with($e->getMessage(), 'Session expired or not found')) {
        $client->connect($endpointUrl);
        $value = $client->read($nodeId);
    } else {
        throw $e;
    }
} catch (ServiceUnsupportedException $e) {
    // OPC UA service set not implemented by the server.
    // Cache the capability and skip going forward.
} catch (ServiceException $e) {
    // Other OPC UA bad status — inspect $e->getStatusCode().
} catch (DaemonException $e) {
    // Everything else from the daemon (IPC failure, auth, encoded
    // upstream exceptions like SecurityException). Inspect
    // $e->getMessage() — the leading "[ShortClass]" prefix tells you
    // what was thrown server-side.
    throw $e;
}
```
<!-- @endcode-block -->

`DaemonException` and the `opcua-client` exception hierarchies are
**disjoint** — catching `DaemonException` does **not** catch
`ConnectionException`, and vice versa. The `opcua-client` exceptions
all extend `OpcUaException`; catching that covers
`ConnectionException`, `ServiceException`,
`ServiceUnsupportedException`, and anything else propagated
through the four-entry reconstruction `match`.

## Things that look like exceptions but aren't

The IPC layer also returns these failure shapes — none of them
surface as PHP exceptions:

- **`statusCode != 0`** in a per-item result. The call returned
  normally; the per-item result tells you about the specific
  problem. See [`opcua-client` — error handling](https://github.com/php-opcua/opcua-client/blob/master/docs/reference/exceptions.md).
- **`reused: false`** in `open` response. Not a failure — the
  daemon opened a fresh session because no key matched. See
  [ManagedClient · Session reuse](../managed-client/session-reuse.md).
- **`isConnected(): false`**. Not a failure — the session is not
  in `Connected` state. Could be `Disconnected` (fresh client) or
  `Broken` (needs `reconnect()`).

## Sanitisation

Error messages travelling on the IPC channel are sanitised by
`CommandHandler::sanitizeErrorMessage()`:

- URLs → `[url]`
- Windows paths → `[path]`
- Unix paths → `[path]`

The sanitisation is one-way — the PHP exception you catch on the
client side has the sanitised text. To see the original (during
debugging), enable `debug` logging on the daemon — the unsanitised
message lands in the log file before the IPC response is built.
