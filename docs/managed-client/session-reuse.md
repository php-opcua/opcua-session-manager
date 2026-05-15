---
eyebrow: 'Docs · ManagedClient'
lede:    'Sessions are keyed by endpoint URL plus a sanitised configuration. Match the key, reuse the session. Miss any field, get a fresh handshake. The sanitisation rules matter.'

see_also:
  - { href: './opening-and-closing.md',           meta: '6 min' }
  - { href: '../daemon/auto-connect.md',          meta: '5 min' }
  - { href: '../ipc/commands.md',                 meta: '7 min' }

prev: { label: 'Opening and closing',           href: './opening-and-closing.md' }
next: { label: 'Differences from the direct client', href: './differences-from-direct-client.md' }
---

# Session reuse

`SessionStore::findByEndpointAndConfig()` is the function that
decides whether a `connect()` call reuses an existing session or
opens a fresh one. The match is exact: two `(endpointUrl, sanitized
config)` tuples either match in every field, or they don't.

## The sanitised config

Not every field on the `open` command's `config` payload
participates in the keying. `SessionConfig::sanitized()` produces
the keying view:

| Field                       | Part of key? | Why                                                       |
| --------------------------- | ------------ | --------------------------------------------------------- |
| `securityPolicy`            | yes          | Different policy = different OPC UA session, period       |
| `securityMode`              | yes          | Same                                                      |
| `username`                  | yes          | Different identity = different session                    |
| `password`                  | **NO**       | Nulled by `SessionConfig::sanitized()` before keying       |
| `clientCertPath`            | yes          | Different cert path = different identity                  |
| `clientKeyPath`             | **NO**       | Nulled by `sanitized()`                                    |
| `caCertPath`                | **NO**       | Nulled by `sanitized()`                                    |
| `userCertPath`              | yes          | Same                                                      |
| `userKeyPath`               | **NO**       | Nulled by `sanitized()`                                    |
| `opcuaTimeout`              | yes          | Different per-call timeout = different client config      |
| `autoRetry`                 | yes          | Different retry policy = different behavioural surface    |
| `batchSize`                 | yes          | Same                                                      |
| `defaultBrowseMaxDepth`     | yes          | Same                                                      |
| `autoDetectWriteType`       | yes          | Same                                                      |
| `readMetadataCache`         | yes          | Same                                                      |
| `trustStorePath`            | yes          | Different trust store = different validation              |
| `trustPolicy`               | yes          | Same                                                      |
| `autoAccept`, `autoAcceptForce` | yes      | Same                                                      |

The `endpointUrl` is always part of the key.

> **Secret values are stripped before keying.** `password`,
> `clientKeyPath`, `caCertPath`, and `userKeyPath` are nulled by
> `SessionConfig::sanitized()` before the key tuple is built. Two
> `open` calls with the same `username` but different `password`
> values therefore **match the same daemon-side session** and the
> second caller silently reuses the first caller's credentials.
> This is a deliberate trade-off (secrets would otherwise sit in
> memory as cache-key strings) but it has security implications —
> rotate `username` (which **is** in the key) whenever credentials
> change, not just `password`.

What is **not** part of the key:

- The application-level fields the daemon ignores anyway (a custom
  `description` field passed by a wrapper, for instance).
- The IPC `authToken` — that is daemon-level authentication, not
  session-level identity.

## Sanitisation versus credential exposure

Two separate sanitisations operate on the config:

- `SessionConfig::sanitized()` — **strips** `password`,
  `clientKeyPath`, `caCertPath`, `userKeyPath` (sets them to
  `null`) before computing the session-lookup key. `username` is
  preserved because it is part of session identity.
- `CommandHandler::sanitizeConfig()` — strips
  `username`, `password`, `clientKeyPath`, `userKeyPath`,
  `caCertPath` from the `config` returned by the `list` IPC
  command. See
  [Daemon · Security hardening](../daemon/security-hardening.md).

The keying happens in-memory and never reaches the wire; the
list-side redaction protects the introspection surface. Both work
together, but they strip slightly different field sets — the table
above shows which fields end up in the lookup key.

## Worked example

<!-- @code-block language="php" label="examples/session-reuse.php" -->
```php
$a = (new ManagedClient('/tmp/opcua-session-manager.sock'))
    ->setSecurityPolicy(SecurityPolicy::Basic256Sha256)
    ->setSecurityMode(SecurityMode::SignAndEncrypt)
    ->setUserCredentials('integrations', getenv('PASS'));

$a->connect('opc.tcp://plc.local:4840');
// First connect — fresh session
assert($a->wasSessionReused() === false);

$b = (new ManagedClient('/tmp/opcua-session-manager.sock'))
    ->setSecurityPolicy(SecurityPolicy::Basic256Sha256)
    ->setSecurityMode(SecurityMode::SignAndEncrypt)
    ->setUserCredentials('integrations', getenv('PASS'));   // same

$b->connect('opc.tcp://plc.local:4840');
// Identical config — session reused
assert($b->wasSessionReused() === true);
assert($a->getSessionId() === $b->getSessionId());

$c = (new ManagedClient('/tmp/opcua-session-manager.sock'))
    ->setSecurityPolicy(SecurityPolicy::Basic256Sha256)
    ->setSecurityMode(SecurityMode::SignAndEncrypt)
    ->setUserCredentials('different-user', getenv('PASS2'));

$c->connect('opc.tcp://plc.local:4840');
// Different username — different session
assert($c->wasSessionReused() === false);
assert($a->getSessionId() !== $c->getSessionId());
```
<!-- @endcode-block -->

## Subtle traps

### `setTimeout()` participates in the key

Two clients that differ only in `setTimeout(10.0)` vs `setTimeout(30.0)`
get **two distinct daemon-side sessions**. Each session is opened
with the per-call OPC UA timeout the caller asked for.

If your application has one config object passing through multiple
factories, make sure they all produce the same timeout value —
otherwise you fragment the session pool.

### Trust store path participates

`setTrustStorePath('/var/lib/opcua/trust')` and
`setTrustStorePath('/var/lib/opcua/trust/')` (trailing slash) are
**different keys** as far as the daemon is concerned. Canonicalise
your paths before passing them in.

### `autoAccept(true, force: true)` participates

The `autoAcceptForce` flag is keyed independently of `autoAccept`.
A boot-time `autoAccept(true, force: false)` session is distinct
from an admin tool's `autoAccept(true, force: true)` session. This
is intentional — you want operator overrides to be a distinct
session.

## Maximising reuse

To get the highest cache and session reuse rate across your fleet:

<!-- @do-dont -->
<!-- @do -->
**Centralise the ManagedClient factory** in one place — a Laravel
service binding, a Symfony factory, a single module that all
consumers call. The factory builds the client with a frozen,
canonical configuration. Every call site gets the same
configuration, so every call site reuses the same daemon-side
session.
<!-- @enddo -->
<!-- @dont -->
**Don't build the ManagedClient inline at every call site** with
slight variations in setters. Each variation is a new session on
the daemon; on a busy fleet you end up with hundreds of "almost
the same" sessions instead of one shared one.
<!-- @enddont -->
<!-- @enddo-dont -->

## Inspecting active sessions

The `list` IPC command returns every active session with its
endpoint, last-used timestamp, and (redacted) config. Drive it via
`SocketConnection`:

<!-- @code-block language="php" label="examples/list-sessions.php" -->
```php
use PhpOpcua\SessionManager\Client\SocketConnection;

$response = SocketConnection::send('/tmp/opcua-session-manager.sock', [
    'command' => 'list',
]);

foreach ($response['data']['sessions'] as $sess) {
    printf("%s %s lastUsed=%s\n",
        $sess['id'],
        $sess['endpointUrl'],
        date('c', (int) $sess['lastUsed']),
    );
}
```
<!-- @endcode-block -->

For raw debugging, see
[Recipes · Debugging with netcat](../recipes/debugging-with-netcat.md).

## When sessions expire

Sessions are reclaimed by the cleanup loop when they have been
idle longer than `--timeout` (default 600 s). "Idle" means: no IPC
command has referenced the session in that window. A live
subscription that the daemon publish loop touches counts as
activity — sessions with active subscriptions never expire.

When an expired session is reclaimed:

- The daemon sends `CloseSession` + `CloseSecureChannel` to the
  server.
- The session is removed from the store.
- The next `connect()` with that config opens a fresh session.

The application does not get notified of the expiration. The next
operation simply pays the handshake cost.
