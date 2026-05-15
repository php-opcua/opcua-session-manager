---
eyebrow: 'Docs · Recipes'
lede:    'netcat against the daemon socket is the fastest way to verify what the daemon thinks. Five one-liners cover every diagnostic question worth asking from the terminal.'

see_also:
  - { href: '../ipc/direct-interaction.md',     meta: '5 min' }
  - { href: '../ipc/commands.md',               meta: '7 min' }
  - { href: './healthcheck-and-monitoring.md',  meta: '5 min' }

prev: { label: 'Recovery and reconnect', href: './recovery-and-reconnect.md' }
next: { label: 'No further page',        href: '#' }
---

# Debugging with netcat

`nc(1)` is the cheapest IPC client there is. The daemon speaks
NDJSON over a Unix socket or TCP loopback — pipe a JSON request
into `nc`, get the JSON response back. Useful for verifying the
daemon is up, listing sessions, sanity-checking auth, and
inspecting the wire envelope when something looks wrong.

For TCP loopback, replace `nc -U /path` with `nc 127.0.0.1 9990`
in every example below.

## Prerequisites

- `nc` (BSD or GNU variant — both work)
- `jq` (optional, makes responses readable)
- Read permission on the Unix socket (or your token, if auth is on)

## 1 — Liveness

<!-- @code-block language="bash" label="terminal — ping" -->
```bash
echo '{"command":"ping"}' \
    | nc -U /tmp/opcua-session-manager.sock \
    | jq .
```
<!-- @endcode-block -->

Response:

<!-- @code-block language="text" label="ping response" -->
```text
{
  "success": true,
  "data": {
    "status": "ok",
    "sessions": 3,
    "time": 1716000000.123
  }
}
```
<!-- @endcode-block -->

If `nc` hangs, the daemon is unreachable — check the socket file
exists, check the daemon process is running, check the socket
permissions. If `nc` returns immediately with no output, the
daemon closed the connection — usually a malformed frame or an
auth failure.

## 2 — Authenticated ping (when the daemon has a token)

<!-- @code-block language="bash" label="terminal — authed ping" -->
```bash
TOKEN="$(cat /etc/opcua/daemon.token)"

echo "{\"command\":\"ping\",\"authToken\":\"$TOKEN\"}" \
    | nc -U /var/run/opcua/sessions.sock \
    | jq .
```
<!-- @endcode-block -->

If the token is wrong:

<!-- @code-block language="text" label="auth failure" -->
```text
{"success":false,"error":{"type":"auth_failed","message":"Invalid or missing auth token"}}
```
<!-- @endcode-block -->

## 3 — List active sessions

<!-- @code-block language="bash" label="terminal — list" -->
```bash
TOKEN="$(cat /etc/opcua/daemon.token)"

echo "{\"command\":\"list\",\"authToken\":\"$TOKEN\"}" \
    | nc -U /var/run/opcua/sessions.sock \
    | jq '.data.sessions[] | {id, endpointUrl, lastUsed}'
```
<!-- @endcode-block -->

Output:

<!-- @code-block language="text" label="list response (jq-filtered)" -->
```text
{"id":"a1b2c3...","endpointUrl":"opc.tcp://plc-1.plant.local:4840","lastUsed":1716000000.1}
{"id":"d4e5f6...","endpointUrl":"opc.tcp://plc-2.plant.local:4840","lastUsed":1716000010.7}
```
<!-- @endcode-block -->

Credentials are redacted from the `config` blob. The full,
unredacted view exists only inside the daemon's session store and
never reaches the wire.

## 4 — Describe a session's surface

`describe` returns the methods, modules, and Wire types the
underlying client exposes. Useful when debugging
`__call()`-routed method calls that fail with
`unknown_method`:

<!-- @code-block language="bash" label="terminal — describe" -->
```bash
TOKEN="$(cat /etc/opcua/daemon.token)"
SID="a1b2c3..."   # from the list response above

echo "{\"command\":\"describe\",\"sessionId\":\"$SID\",\"authToken\":\"$TOKEN\"}" \
    | nc -U /var/run/opcua/sessions.sock \
    | jq '.data | {methods: .methods | length, modules}'
```
<!-- @endcode-block -->

Output (truncated for brevity):

<!-- @code-block language="text" label="describe response" -->
```text
{
  "methods": 51,
  "modules": [
    "PhpOpcua\\Client\\Module\\ReadWrite\\ReadWriteModule",
    "PhpOpcua\\Client\\Module\\Browse\\BrowseModule",
    ...
  ]
}
```
<!-- @endcode-block -->

If your custom module is not in the `modules` list, the daemon
launcher did not register it — see
[Extensibility · Third-party modules](../extensibility/third-party-modules.md).

## 5 — Run a query by hand

This is heavy — you need to know the exact wire shape per method
([Type serialization](../ipc/type-serialization.md)) — but useful
for isolating "is the server problem or the application problem":

<!-- @code-block language="bash" label="terminal — read by hand" -->
```bash
TOKEN="$(cat /etc/opcua/daemon.token)"
SID="a1b2c3..."

REQUEST=$(cat <<EOF
{
  "command": "query",
  "sessionId": "$SID",
  "method": "read",
  "params": [{"ns": 0, "id": 2261, "type": "numeric"}, 13, false],
  "authToken": "$TOKEN"
}
EOF
)

echo "$REQUEST" | nc -U /var/run/opcua/sessions.sock | jq .
```
<!-- @endcode-block -->

Response — a `DataValue` with `value`, `type`, `statusCode`,
timestamps:

<!-- @code-block language="text" label="read response" -->
```text
{
  "success": true,
  "data": {
    "value": "open62541 OPC UA Server",
    "type": 12,
    "dimensions": null,
    "statusCode": 0,
    "sourceTimestamp": "2026-05-15T10:30:00.000000+00:00",
    "serverTimestamp": "2026-05-15T10:30:00.123456+00:00"
  }
}
```
<!-- @endcode-block -->

If the response has `success: false` with `ConnectionException` or
`ServiceException`, the daemon could reach you (good) but the
OPC UA server is the problem.

## Common failures and what they look like

| You sent                                | Daemon responds                                                | Interpretation                            |
| --------------------------------------- | -------------------------------------------------------------- | ----------------------------------------- |
| Frame without trailing `\n`             | (hangs until 30 s connection timeout)                          | NDJSON framing — append `\n`              |
| Frame with broken JSON                  | `{"success":false,"error":{"type":"invalid_json",...}}`        | JSON parse failure                        |
| Frame > 64 KiB                          | `{"success":false,"error":{"type":"payload_too_large",...}}`   | Frame cap, see [Daemon · Security hardening](../daemon/security-hardening.md) |
| Wrong auth token                        | `{"success":false,"error":{"type":"auth_failed",...}}`         | Token mismatch                            |
| Unknown command name                    | `{"success":false,"error":{"type":"unknown_command",...}}`     | Typo in the `command` field                |
| `query` to a method outside the whitelist | `{"success":false,"error":{"type":"forbidden_method",...}}` | Use `invoke` for custom methods           |
| `invoke` to a method not registered on the client | `{"success":false,"error":{"type":"unknown_method",...}}` | Module not loaded daemon-side       |
| `query` to a session that expired       | `{"success":false,"error":{"type":"session_not_found",...}}`   | Session timed out — open a new one        |

## Two-line one-liners

For the very impatient:

<!-- @code-block language="bash" label="terminal — one-liners" -->
```bash
# Is the daemon up?
echo '{"command":"ping"}' | nc -U /tmp/opcua-session-manager.sock

# How many sessions?
echo '{"command":"ping"}' | nc -U /tmp/opcua-session-manager.sock | grep -o '"sessions":[0-9]*'

# Auth token from env?
echo "{\"command\":\"ping\",\"authToken\":\"$OPCUA_AUTH_TOKEN\"}" | nc -U /tmp/opcua-session-manager.sock
```
<!-- @endcode-block -->

## Switching to the typed surface

Once netcat tells you the daemon is healthy, the **typed**
surface — `SocketConnection` from PHP, see
[IPC · Direct interaction](../ipc/direct-interaction.md) — is the
right tool for everything else. Netcat is for diagnostics; PHP
helpers are for code.
