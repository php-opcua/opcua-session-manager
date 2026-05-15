---
eyebrow: 'Docs · ManagedClient'
lede:    'Four methods govern the lifecycle: connect, connectForceNew, disconnect, reconnect. They mean different things from their direct-client counterparts — read the differences before relying on the names.'

see_also:
  - { href: './session-reuse.md',                  meta: '5 min' }
  - { href: './differences-from-direct-client.md', meta: '5 min' }
  - { href: '../recipes/recovery-and-reconnect.md', meta: '6 min' }

prev: { label: 'Overview',      href: './overview.md' }
next: { label: 'Session reuse', href: './session-reuse.md' }
---

# Opening and closing

The session lifecycle on `ManagedClient` is shaped by the daemon
holding the OPC UA session. The client's `connect()`, `disconnect()`,
`reconnect()` semantics are about the **client-side relationship
with the daemon**, not the OPC UA session itself — which lives on
the daemon's side independently.

## connect()

<!-- @method name="\$client->connect(string \$endpointUrl): void" returns="void" visibility="public" -->

Opens (or reuses) a daemon-side OPC UA session for `$endpointUrl`
with the configuration currently set on `$client`.

<!-- @code-block language="php" label="examples/connect.php" -->
```php
$client = new ManagedClient('/tmp/opcua-session-manager.sock');

$client
    ->setTimeout(10.0)
    ->setSecurityPolicy(SecurityPolicy::Basic256Sha256)
    ->setSecurityMode(SecurityMode::SignAndEncrypt)
    ->setClientCertificate('/etc/opcua/client.pem', '/etc/opcua/client.key');

$client->connect('opc.tcp://plc.local:4840');
```
<!-- @endcode-block -->

What happens under the covers:

<!-- @steps -->
- **Wire encode the configuration.**

  Every `set*()` call accumulated into the client is serialised into
  a config map that is part of the `open` IPC command.

- **Send the `open` command to the daemon.**

  The daemon receives `{method: "open", args: [endpointUrl, config]}`.

- **The daemon checks for an existing session match.**

  Session keying is `(endpointUrl, sanitized config)` — see
  [Session reuse](./session-reuse.md). On match, the existing
  session ID is returned. On miss, the daemon opens a new OPC UA
  session and returns its ID.

- **`$client->wasSessionReused()` and `getSessionId()` populate.**

  The client now holds the daemon-assigned session ID. Subsequent
  IPC calls reference this ID to address the right session.
<!-- @endsteps -->

A successful `connect()` returns void. Failures raise:

- **`DaemonException`** — IPC failure (socket unreachable, auth
  rejected, frame oversized).
- **`ConnectionException`** — the daemon could not reach the OPC UA
  server (propagated from inside the daemon).
- **`SecurityException`** — TLS / certificate failure on the OPC UA
  side.
- **`ServiceException`** — server rejected the `CreateSession` /
  `ActivateSession` request.

## connectForceNew()

<!-- @method name="\$client->connectForceNew(string \$endpointUrl): void" returns="void" visibility="public" -->

Same shape as `connect()` but **bypasses the session reuse check**.
The daemon opens a fresh OPC UA session unconditionally, even if a
matching one already exists.

<!-- @code-block language="php" label="examples/force-new.php" -->
```php
$client->connectForceNew('opc.tcp://plc.local:4840');

assert($client->wasSessionReused() === false);   // always false
```
<!-- @endcode-block -->

Use it when:

- **You need a clean session state.** A previous session has
  accumulated server-side state (subscriptions, monitored items)
  you want to discard.
- **You suspect the cached session is stale.** Rare; the daemon
  detects most staleness via the `attemptSessionRecovery()` path
  internally, but escape hatches matter.
- **Per-call isolation.** A particular operation wants to run on
  its own session so its failure does not cascade into shared
  state.

The cost is the full OPC UA handshake — exactly what the daemon is
designed to amortise. Use `connectForceNew()` sparingly.

## disconnect()

<!-- @method name="\$client->disconnect(): void" returns="void" visibility="public" -->

`ManagedClient::disconnect()` issues a `close` IPC command and
tears the daemon-side session **down**:

| Action                                          | Happens?                                            |
| ----------------------------------------------- | --------------------------------------------------- |
| Sends `{command: "close", sessionId: ...}` to the daemon | **Yes** — see `ManagedClient::disconnect()` |
| Daemon calls `$session->client->disconnect()` and removes the session | **Yes** — see `CommandHandler::handleClose()` |
| OPC UA secure channel + session torn down on the server | **Yes** — the underlying `Client::disconnect()` does this |
| Local IPC resources released                    | **Yes** — `sessionId`, describe cache, wire codec are cleared |

The next call from any `ManagedClient` with the same
`(endpointUrl, sanitized config)` tuple pays the OPC UA handshake
again, because the session is gone from the store.

The OPC UA session on the daemon side is reclaimed when **any** of
these happen:

- **Explicit `close` IPC command** — what `disconnect()` does.
- **Inactivity timeout** — `--timeout`, default 600 seconds
  without any IPC activity referencing the session.
- **Daemon restart** — all sessions terminate.

> **Pitfall: short-lived processes lose reuse.** In a Laravel /
> CLI / FPM pattern where a request constructs a `ManagedClient`,
> uses it, and lets it go out of scope, the request ends with a
> `close` (either explicit or via the destructor pattern your code
> uses) and the daemon-side session **disappears** before the next
> request arrives — defeating the reuse the session manager exists
> to provide. To keep the daemon session alive across requests,
> reuse the `ManagedClient` object (singleton bind in Laravel,
> long-running worker, etc.) **without** calling `disconnect()`
> between calls.

## reconnect()

<!-- @method name="\$client->reconnect(): void" returns="void" visibility="public" -->

Issues a `query` IPC command with `method = "reconnect"` against
the **current** `sessionId`. The daemon delegates to the
underlying `IClient::reconnect()`, which rebuilds the OPC UA
secure channel without reopening a brand-new session.

| What `reconnect()` does                                | What it does **not** do                              |
| ------------------------------------------------------ | ---------------------------------------------------- |
| Sends `{command:"query", sessionId, method:"reconnect"}` | Re-issue an `open` IPC command                      |
| Asks the daemon to rebuild the OPC UA channel for the existing session | Open a new session ID                       |
| Preserves subscriptions tracked by the daemon          | Discard the session-store entry                      |

If the current session is gone (daemon restart, expired), the
daemon raises `session_not_found` and `ManagedClient` surfaces a
`ConnectionException("Session expired or not found: …")`. In that
case call `connect($endpointUrl)` (or `connectForceNew()`) to open
a fresh session.

For the recovery pattern, see
[Recipes · Recovery and reconnect](../recipes/recovery-and-reconnect.md).

## isConnected() and getConnectionState()

These methods reflect the **daemon-side OPC UA session state**, not
the IPC connection. `isConnected()` returns `true` whenever the
daemon reports `ConnectionState::Connected` for the session;
`getConnectionState()` returns the full enum (`Disconnected`,
`Connected`, `Broken`).

Both calls cost one IPC round-trip — they are not free. Cache them
in the application if they appear in a hot path; the daemon does
not push state changes to the client.

## getSessionId()

<!-- @method name="\$client->getSessionId(): ?string" returns="?string" visibility="public" -->

Returns the opaque daemon-assigned session ID, or `null` if the
client has never connected. Useful for logging, debugging,
correlating with the daemon's `list` command output:

<!-- @code-block language="php" label="examples/session-id.php" -->
```php
$client->connect('opc.tcp://plc.local:4840');
$logger->info('opcua.session.open', ['sessionId' => $client->getSessionId()]);
```
<!-- @endcode-block -->

The ID is **not** the OPC UA `AuthenticationToken` or `SessionId`
— it is the daemon's internal identifier, scoped to the daemon
process. Two daemons running in parallel produce different IDs for
the same OPC UA session.

## Lifecycle in summary

<!-- @code-block language="text" label="lifecycle" -->
```text
new ManagedClient(socket)         ← no IPC, no OPC UA
       │
       │ set*(...)                ← still no IPC
       │
       │ connect($url)            ← IPC open command
       ▼
  Daemon checks session reuse:
     match    → return existing ID, wasSessionReused = true
     no match → open new OPC UA session, return its ID
       │
       │ read / write / browse … ← per-call IPC round-trip
       │
       │ disconnect()             ← sends `close` IPC; daemon tears
       │                            down the OPC UA session as well
       ▼
new connect() on another ManagedClient must re-handshake. Session
reuse only kicks in when the previous client is still alive (it
has not called disconnect()) and another client opens with the
same sanitized config — see [Session reuse](./session-reuse.md).
```
<!-- @endcode-block -->
