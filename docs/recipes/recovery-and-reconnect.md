---
eyebrow: 'Docs · Recipes'
lede:    'A session goes stale, the daemon restarts, the OPC UA server reboots. Each case has its own signal and its own minimum recovery — three patterns cover all three.'

see_also:
  - { href: '../managed-client/opening-and-closing.md', meta: '6 min' }
  - { href: '../reference/exceptions.md',               meta: '7 min' }
  - { href: '../daemon/auto-connect.md',                meta: '5 min' }

prev: { label: 'Secure connection with ECC', href: './ecc-secure-connection.md' }
next: { label: 'Debugging with netcat',      href: './debugging-with-netcat.md' }
---

# Recovery and reconnect

A long-lived integration meets three flavours of failure. Each is
signalled differently and recovers differently. This page is the
shortest path through each.

## The three failure shapes

| Shape                          | Signal                                                  | Who handles it                                      |
| ------------------------------ | ------------------------------------------------------- | --------------------------------------------------- |
| OPC UA session expired         | `ConnectionException` (`"Session expired or not found"`) | Application — reconnect once                        |
| OPC UA channel broken          | `ConnectionException`                                   | Daemon's `attemptSessionRecovery()`, then app       |
| Daemon down or restarted       | `DaemonException` on IPC connect                        | Application — retry with backoff                    |

The daemon handles the *middle* case internally most of the time
— `CommandHandler::attemptSessionRecovery()` rebuilds the OPC UA
session under the same daemon-side session ID. The application
sees only the recovered call. The first and third cases need
application-side handling.

## Pattern 1 — Session expired

The daemon dropped the session because the inactivity timeout
(`--timeout`, default 600 s) lapsed, or because someone called
`disconnect()` and a later call still referenced the gone session
ID. The daemon emits `error.type = "session_not_found"`, which
`ManagedClient` translates to
`ConnectionException("Session expired or not found: …")`. Catch
the parent class and discriminate on the message:

<!-- @code-block language="php" label="examples/handle-session-expired.php" -->
```php
use PhpOpcua\Client\Exception\ConnectionException;

function readWithRetry($client, string $endpoint, string $nodeId)
{
    try {
        return $client->read($nodeId);
    } catch (ConnectionException $e) {
        if (! str_starts_with($e->getMessage(), 'Session expired or not found')) {
            throw $e;
        }
        // Reconnect with the same configuration and retry once.
        $client->connect($endpoint);
        return $client->read($nodeId);
    }
}
```
<!-- @endcode-block -->

A single retry is enough — `connect()` re-establishes the session
deterministically. **Do not loop** on this branch; if the second
attempt also fails, the cause is something else (daemon down,
OPC UA server down) and looping just delays the real error.

> `SessionNotFoundException` exists in the package but is **never
> raised on the client side** — it is internal to the daemon's
> `SessionStore::get()` and gets translated into the
> `session_not_found` wire token. Application code should catch
> `ConnectionException`, not `SessionNotFoundException`.

For Laravel applications, wrap this pattern in a service per
[Recipes · Persistent sessions in Laravel](./persistent-sessions-laravel.md#section-reconnect-when-the-session-goes-stale).

## Pattern 2 — Channel broken (daemon-side recovery)

When the OPC UA secure channel breaks (server restart, network
blip), the daemon's `CommandHandler` catches it on the next
service call and calls `attemptSessionRecovery()`:

<!-- @steps -->
- **Reopen the secure channel** against the same OPC UA endpoint
  with the same configuration.

- **Re-activate the session** with the same credentials.

- **Re-create subscriptions** if any were associated with the
  session, using the saved subscription state.

- **Retry the originating call.**
<!-- @endsteps -->

From the application's perspective, this is invisible — the
service call simply takes longer. The recovery is best-effort:

- If reopening the channel fails, the daemon raises
  `ConnectionException` to the caller.
- If recreating a subscription returns a bad status, the daemon
  raises `ServiceException` for that subscription on the next
  call that touches it.

When recovery fails, application code falls back to the manual
reconnect of pattern 1, or to the daemon-restart pattern below.

## Pattern 3 — Daemon down or restarted

The IPC connection itself fails. Symptoms:

- `DaemonException("Socket not found: ...")` — daemon process
  not running.
- `DaemonException("Connection refused")` — daemon process up but
  socket not bound (mid-startup, mid-shutdown).
- `DaemonException("auth_failed")` — auth token rotated, client
  has the old one.

Each of these needs human or infrastructure intervention. The
application can mitigate by:

<!-- @code-block language="php" label="examples/daemon-resilient.php" -->
```php
use PhpOpcua\SessionManager\Client\ManagedClient;
use PhpOpcua\SessionManager\Exception\DaemonException;

function readResilient(string $endpoint, string $nodeId, string $url): mixed
{
    $delays = [0.5, 1.0, 2.0, 5.0];   // backoff schedule

    foreach ($delays as $delay) {
        try {
            $client = new ManagedClient($endpoint, authToken: getenv('OPCUA_AUTH_TOKEN'));
            $client->connect($url);
            return $client->read($nodeId);
        } catch (DaemonException $e) {
            error_log("opcua daemon unreachable: {$e->getMessage()}");
            usleep((int) ($delay * 1_000_000));
        }
    }

    throw new RuntimeException('OPC UA daemon unreachable after backoff');
}
```
<!-- @endcode-block -->

Four attempts, ~8.5 seconds of total backoff. For longer outages,
let the caller fail and surface the error — sustained
unreachability is an operational problem the application cannot
fix on its own.

## Subscription survival across daemon restart

The daemon process is the holder of OPC UA sessions. When it
dies, **every session goes with it** — including every active
subscription. There is no daemon-side persistence of session
state to disk.

Restart paths:

- **Without `--auto-connect`**: After the daemon restarts, the
  next `ManagedClient::connect()` opens a fresh session. The
  worker has to recreate its subscriptions explicitly.
- **With auto-connect**: Pre-registered sessions and their
  subscriptions come back at daemon boot. Workers that were
  using those subscriptions resume after their own reconnect.

See [Daemon · Auto-connect](../daemon/auto-connect.md) for the
boot-time pre-registration pattern. For subscription workers,
combine auto-connect with auto-publish per
[Recipes · Auto-publish pattern](./auto-publish-pattern.md) to
make the survival fully transparent.

## Reconnect inside a subscription worker

A subscription worker driving its own publish loop needs a
deliberate strategy. The skeleton:

<!-- @code-block language="php" label="examples/sub-worker-recovery.php" -->
```php
use PhpOpcua\Client\Exception\ConnectionException;
use PhpOpcua\SessionManager\Client\ManagedClient;
use PhpOpcua\SessionManager\Exception\DaemonException;

$client = new ManagedClient('/tmp/opcua-session-manager.sock');
$client->connect('opc.tcp://plc.local:4840');

$subId  = $client->createSubscription(publishingInterval: 500.0)->subscriptionId;
$items  = createMonitoredItems($client, $subId, $nodeIds);
$saved  = ['subId' => $subId, 'items' => $items];

while (! $stopped) {
    try {
        $reply = $client->publish();
        handle($reply);
    } catch (DaemonException $e) {
        // IPC-level failure (daemon down, auth, frame).
        sleep(2);
        if (! reconnect($client, $saved)) {
            sleep(10);
        }
    } catch (ConnectionException $e) {
        // Covers BOTH "session_not_found" (mapped from the wire)
        // AND raw OPC UA channel breaks the daemon could not recover.
        // Treat them the same: rebuild from saved state.
        sleep(2);
        if (! reconnect($client, $saved)) {
            sleep(10);
        }
    }
}

function reconnect($client, array &$saved): bool
{
    try {
        $client->connect($endpoint);
    } catch (Throwable) {
        return false;
    }

    // The daemon may have kept the subscription via attemptSessionRecovery,
    // or may have lost it. Probe before recreating.
    $transfer = $client->transferSubscriptions([$saved['subId']], sendInitialValues: true);

    if ($transfer[0]->statusCode !== 0) {
        $sub = $client->createSubscription(publishingInterval: 500.0);
        $saved['subId'] = $sub->subscriptionId;
        $saved['items'] = createMonitoredItems($client, $sub->subscriptionId, $nodeIds);
    }

    return true;
}
```
<!-- @endcode-block -->

Key bits:

- **`transferSubscriptions()` first** — when the daemon recovered
  the session, the subscription survived too, and transfer is
  fast.
- **Recreate as fallback** — when recovery failed, recreate from
  the saved item list.
- **Backoff between attempts** — `sleep(2)` between recovery
  tries, `sleep(10)` after a full recreate failure.

## What `reconnect()` does

`ManagedClient::reconnect()` issues `{command: "query",
sessionId: <current>, method: "reconnect"}`. The daemon calls
`IClient::reconnect()` on the underlying client, which rebuilds
the OPC UA secure channel for the same daemon-side session
without re-issuing an `open`. The session ID stays the same;
subscriptions tracked by the daemon stay registered.

If the current session is gone server-side (daemon restarted,
session timed out), the daemon responds `session_not_found` and
the client raises `ConnectionException("Session expired or not
found: …")`. From there, fall back to `connect($endpointUrl)` —
the same recovery as Pattern 1.

`reconnect()` and `connect($endpointUrl)` are **not** equivalent:
the former keeps the current session ID, the latter may reuse a
matching session or open a new one. Use `reconnect()` when the
channel broke but the daemon still has the session; use
`connect()` when the session itself is gone.

## When to call `connectForceNew()`

`connectForceNew()` opens a fresh session unconditionally —
bypassing reuse. Three legitimate uses:

- **After a daemon restart that you know about** (e.g. a deploy
  hook). Avoids the round-trip of the daemon discovering the old
  session is gone.
- **To recover from a session in a confused state** that recovery
  cannot fix. Rare; if you find yourself here often, something
  upstream is wrong.
- **In tests that need session isolation per scenario.** Each
  test gets its own session, no cross-contamination.

Production application code should default to `connect()` — the
reuse is the whole point.

