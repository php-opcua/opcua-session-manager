---
eyebrow: 'Docs · Recipes'
lede:    'The ping command answers in milliseconds. Wire it into your monitoring agent, alert on missing responses, surface session counts. Three signals cover most of what an OPC UA daemon owes its operators.'

see_also:
  - { href: '../daemon/starting.md',              meta: '5 min' }
  - { href: '../ipc/direct-interaction.md',       meta: '5 min' }
  - { href: './recovery-and-reconnect.md',        meta: '6 min' }

prev: { label: 'Auto-publish pattern',  href: './auto-publish-pattern.md' }
next: { label: 'Secure connection with ECC', href: './ecc-secure-connection.md' }
---

# Healthcheck and monitoring

The daemon does not expose a metrics endpoint. What it offers is
the `ping` IPC command — cheap, fast, unauthenticated
(authentication is enforced but `ping` works without). Three
signals derive from it; everything else is operating-system
plumbing.

## The signals

| Signal             | Source                                            | What it tells you                              |
| ------------------ | ------------------------------------------------- | ---------------------------------------------- |
| Daemon liveness    | `ping` returns `success: true` in under 100 ms    | Daemon is up and responsive                    |
| Session count      | `ping` response `data.sessions`                   | Number of active OPC UA sessions               |
| OPC UA reachability| A successful `read()` round-trip                  | The daemon's OPC UA side is healthy             |

The first two cover the daemon; the third covers the daemon's
connection to the OPC UA server. Wire all three for full
coverage.

## Daemon liveness — minimal probe

A shell one-liner per check:

<!-- @code-block language="bash" label="terminal — POSIX" -->
```bash
echo '{"command":"ping"}' \
    | timeout 2 nc -U /tmp/opcua-session-manager.sock \
    | grep -q '"success":true' && echo healthy || echo unhealthy
```
<!-- @endcode-block -->

For TCP loopback:

<!-- @code-block language="bash" label="terminal — TCP" -->
```bash
echo '{"command":"ping"}' \
    | timeout 2 nc 127.0.0.1 9990 \
    | grep -q '"success":true' && echo healthy || echo unhealthy
```
<!-- @endcode-block -->

Wire this into:

- **Kubernetes `livenessProbe`** with `exec.command: ["/bin/sh","-c","..."]`
- **systemd `ExecStartPost`** for boot smoke
- **cron / Nagios / Sensu** as the cheap "is it up" check

## Healthcheck script

A more useful version, in PHP, that surfaces the session count:

<!-- @code-block language="php" label="bin/opcua-healthcheck" -->
```php
#!/usr/bin/env php
<?php
require __DIR__ . '/../vendor/autoload.php';

use PhpOpcua\SessionManager\Client\SocketConnection;
use PhpOpcua\SessionManager\Exception\DaemonException;

$endpoint = getenv('OPCUA_SOCKET_PATH') ?: '/tmp/opcua-session-manager.sock';
$token    = getenv('OPCUA_AUTH_TOKEN');

$request = ['command' => 'ping'];
if ($token) {
    $request['authToken'] = $token;
}

$start = microtime(true);

try {
    $response = SocketConnection::send($endpoint, $request, timeout: 2.0);
} catch (DaemonException $e) {
    fwrite(STDERR, "FAIL ipc {$e->getMessage()}\n");
    exit(2);
}

$durationMs = (int) ((microtime(true) - $start) * 1000);

if (! $response['success']) {
    fwrite(STDERR, "FAIL response_not_ok\n");
    exit(2);
}

if ($durationMs > 100) {
    fwrite(STDERR, "WARN slow {$durationMs}ms\n");
    exit(1);
}

printf("OK sessions=%d ms=%d\n", $response['data']['sessions'], $durationMs);
exit(0);
```
<!-- @endcode-block -->

Exit codes follow the Nagios convention (`0` ok, `1` warning,
`2` critical). The output line includes a session count and
duration — useful in scrape-and-graph monitoring.

## OPC UA reachability probe

The `ping` covers the daemon. To cover the OPC UA path end-to-end,
issue a real `read()` against a well-known node:

<!-- @code-block language="php" label="bin/opcua-readinessprobe" -->
```php
#!/usr/bin/env php
<?php
require __DIR__ . '/../vendor/autoload.php';

use PhpOpcua\SessionManager\Client\ManagedClient;
use PhpOpcua\Client\Types\StatusCode;

$client = new ManagedClient(
    socketPath: getenv('OPCUA_SOCKET_PATH'),
    timeout:    5.0,
    authToken:  getenv('OPCUA_AUTH_TOKEN'),
);

try {
    $client->connect(getenv('OPCUA_ENDPOINT'));
    $dv = $client->read('i=2261');   // ProductName — every server has it
} catch (Throwable $e) {
    fwrite(STDERR, "FAIL " . $e->getMessage() . "\n");
    exit(2);
} finally {
    $client->disconnect();
}

if (! StatusCode::isGood($dv->statusCode)) {
    fwrite(STDERR, "FAIL bad_status " . StatusCode::getName($dv->statusCode) . "\n");
    exit(2);
}

echo "OK product=" . $dv->getValue() . "\n";
exit(0);
```
<!-- @endcode-block -->

This costs more than `ping` — one round-trip to the OPC UA server.
Reserve it for the readiness probe (run every 30-60 s), not for
the liveness probe (run every 5-10 s).

## Metrics scraping

The daemon does not currently emit Prometheus metrics. The
healthcheck script above can be wrapped in a textfile collector:

<!-- @code-block language="bash" label="terminal — Prometheus textfile" -->
```bash
TMP="$(mktemp --suffix=.prom)"
{
    if /opt/myapp/bin/opcua-healthcheck > /tmp/oh.out; then
        sessions=$(awk -F= '/sessions/{print $2}' /tmp/oh.out | tr -d ' ')
        echo "opcua_daemon_up 1"
        echo "opcua_daemon_sessions ${sessions:-0}"
    else
        echo "opcua_daemon_up 0"
        echo "opcua_daemon_sessions 0"
    fi
} > "$TMP" && mv "$TMP" /var/lib/node_exporter/textfile/opcua.prom
```
<!-- @endcode-block -->

Schedule it via `cron` every 15-60 s; node_exporter picks up the
file on its scrape interval.

## Alerts worth wiring

| Alert                              | Threshold                          | Severity |
| ---------------------------------- | ---------------------------------- | -------- |
| Daemon unreachable                 | `ping` fails 2 checks in a row     | Critical |
| Daemon slow                        | `ping` > 100 ms over 3 checks      | Warning  |
| Session count drops sharply        | Drop ≥ 30 % in 5 minutes           | Warning  |
| OPC UA readiness fails             | `read` healthcheck fails 2 checks  | Critical |
| Frame-size cap hit                 | Any `payload_too_large` in logs    | Warning  |
| Auth failures                      | Any `auth_failed` in logs          | Critical (potential intrusion attempt) |

The session-count drop matters because:

- A drop in active sessions usually means workers died or the
  daemon restarted. Either case is worth investigating.
- Sustained zero sessions usually means **the workers are not
  reaching the daemon** (network, auth, configuration drift) —
  the daemon may be perfectly healthy and still useless.

## Inspect from the operator side

For ad-hoc inspection, the `list` IPC command enumerates every
active session with its `endpoint`, `lastUsed`, and (redacted)
config:

<!-- @code-block language="bash" label="terminal — netcat list" -->
```bash
echo "{\"command\":\"list\",\"authToken\":\"$(cat /etc/opcua/daemon.token)\"}" \
    | nc -U /var/run/opcua/sessions.sock \
    | jq .
```
<!-- @endcode-block -->

See [Recipes · Debugging with netcat](./debugging-with-netcat.md)
for more interactive patterns.

## Log-based monitoring

The daemon's `info` level captures session create / close,
auto-connect outcomes, cleanup runs. Common alert patterns:

| Pattern in logs                              | What it means                                |
| -------------------------------------------- | -------------------------------------------- |
| `session_not_found` on `query`               | Worker tried a stale session — reconnect expected |
| `forbidden_method` on `query`                | Worker tried an unknown method — code bug    |
| `auth_failed`                                | Worker is sending wrong token, or attacker probing |
| `payload_too_large`                          | Worker is sending oversized frames — bug     |
| Many `Session <id> expired (endpoint: <url>)` lines | Worker idle pattern — review `--timeout` |

The daemon does **not** emit a summary line per cleanup run; the
only cleanup-related entry is the per-session expiry log line
shown above (emitted by `SessionManagerDaemon::cleanupExpiredSessions()`).

Pipe `--log-file` into your log aggregation; alert on the patterns
above.
