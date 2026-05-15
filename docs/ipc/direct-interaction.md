---
eyebrow: 'Docs · IPC'
lede:    'SocketConnection is the helper you reach for when ManagedClient is too high-level — debugging, scripting, integrating from non-PHP languages. It speaks the IPC envelope and nothing else.'

see_also:
  - { href: './overview.md',                       meta: '5 min' }
  - { href: '../recipes/debugging-with-netcat.md', meta: '4 min' }
  - { href: './commands.md',                       meta: '7 min' }

prev: { label: 'Type serialization', href: './type-serialization.md' }
next: { label: 'Custom param deserializer', href: '../extensibility/custom-param-deserializer.md' }
---

# Direct interaction

`PhpOpcua\SessionManager\Client\SocketConnection` is a tiny static
helper that sends one IPC request and returns the decoded response.
It bypasses every typed surface of `ManagedClient` — no session
state, no `wasSessionReused()`, no method dispatch — and is the
right tool for:

- **Healthchecks** that don't need a full client
- **One-shot IPC scripts** (introspection, debugging, smoke tests)
- **Bridges from non-PHP callers** when you want to write the
  protocol by hand

## The API

Two static methods:

<!-- @method name="SocketConnection::send(string \$endpoint, array \$payload, float \$timeout = 30.0): array" returns="array" visibility="public static" -->
<!-- @method name="SocketConnection::sendVia(TransportInterface \$transport, array \$payload): array" returns="array" visibility="public static" -->

`send()` takes care of building the transport from a URI; `sendVia()`
accepts a pre-built transport for hot paths where you keep one
around.

<!-- @code-block language="php" label="examples/ping-via-socketconnection.php" -->
```php
use PhpOpcua\SessionManager\Client\SocketConnection;

$response = SocketConnection::send(
    endpoint: '/tmp/opcua-session-manager.sock',   // unix path, tcp://, or unix:// URI
    payload:  ['command' => 'ping'],
    timeout:  5.0,
);

assert($response['success'] === true);
echo "{$response['data']['sessions']} sessions on the daemon\n";
```
<!-- @endcode-block -->

The return is the decoded response — exactly the envelope the
daemon wrote on the wire. The success / failure discrimination is
on `$response['success']`; failures expose
`$response['error']['type']` and `$response['error']['message']`.

## Endpoint resolution

The `$endpoint` argument is parsed by `TransportFactory::create()`:

| Form                       | Transport selected                                |
| -------------------------- | ------------------------------------------------- |
| `unix:///path.sock`        | `UnixSocketTransport`                              |
| `tcp://127.0.0.1:9990`     | `TcpLoopbackTransport`                             |
| `tcp://[::1]:9990`         | `TcpLoopbackTransport` (IPv6)                      |
| `/path.sock` (scheme-less) | `UnixSocketTransport` (backwards-compat)           |

Non-loopback TCP endpoints throw at transport construction — the
guard the daemon enforces at startup also runs on the client side.

If the endpoint is a Unix socket and the file does not exist, the
helper raises:

<!-- @code-block language="text" label="missing socket" -->
```text
DaemonException: Socket not found: /tmp/opcua-session-manager.sock. Is the daemon running?
```
<!-- @endcode-block -->

A friendly version of "connection refused" for the common case.

## Pre-built transport (sendVia)

When you make many calls in the same script, build the transport
once and pass it to `sendVia()`:

<!-- @code-block language="php" label="examples/multi-call.php" -->
```php
use PhpOpcua\SessionManager\Client\SocketConnection;
use PhpOpcua\SessionManager\Ipc\TransportFactory;

$transport = TransportFactory::create('/tmp/opcua-session-manager.sock', timeout: 5.0);

// One open/close per call inside sendVia — the daemon expects request/response per connection.
for ($i = 0; $i < 100; $i++) {
    $response = SocketConnection::sendVia($transport, ['command' => 'ping']);
}
```
<!-- @endcode-block -->

Each `sendVia()` opens and closes the transport (the daemon
expects request/response per connection, not a persistent
multiplex). The benefit over `send()` is the cached endpoint
resolution and TransportFactory instantiation.

## Healthcheck shape

The canonical healthcheck:

<!-- @code-block language="php" label="examples/healthcheck.php" -->
```php
use PhpOpcua\SessionManager\Client\SocketConnection;
use PhpOpcua\SessionManager\Exception\DaemonException;

$endpoint = getenv('OPCUA_SOCKET_PATH') ?: '/tmp/opcua-session-manager.sock';

try {
    $response = SocketConnection::send($endpoint, [
        'command' => 'ping',
    ], timeout: 2.0);

    if (! $response['success']) {
        exit(1);
    }

    echo "ok sessions={$response['data']['sessions']}\n";
    exit(0);
} catch (DaemonException $e) {
    fwrite(STDERR, "fail {$e->getMessage()}\n");
    exit(1);
}
```
<!-- @endcode-block -->

Wire it as a periodic check from your monitoring agent. See
[Recipes · Healthcheck and monitoring](../recipes/healthcheck-and-monitoring.md).

## Debugging with netcat

The simplest IPC tool of all is `nc(1)`. For Unix sockets:

<!-- @code-block language="bash" label="terminal — nc on unix socket" -->
```bash
echo '{"command":"ping"}' \
    | nc -U /tmp/opcua-session-manager.sock
# → {"success":true,"data":{"status":"ok",...}}
```
<!-- @endcode-block -->

For TCP loopback:

<!-- @code-block language="bash" label="terminal — nc on TCP" -->
```bash
echo '{"command":"ping"}' \
    | nc 127.0.0.1 9990
```
<!-- @endcode-block -->

The trailing newline matters — NDJSON framing relies on `\n` to
delimit. `echo` adds it for free; `printf` does not unless you
include it explicitly.

For deeper netcat patterns (list sessions, run commands by hand,
verify the auth path), see
[Recipes · Debugging with netcat](../recipes/debugging-with-netcat.md).

## Cross-language bridges

The IPC protocol is JSON over a local socket — every language has
a client for that. To call the daemon from Go, Python, Node.js, or
anything else:

<!-- @steps -->
- **Open a Unix or TCP loopback socket** to the daemon's endpoint.

- **Write a request frame** — one JSON object, terminated by `\n`,
  with the fields documented in [Envelope and framing](./envelope-and-framing.md).

- **Read the response frame** — one JSON object up to the first
  `\n`. Parse it.

- **Close the socket.** The daemon expects one round-trip per
  connection.
<!-- @endsteps -->

The type serialisation rules in
[Type serialization](./type-serialization.md) apply — your
cross-language client needs to encode `NodeId`, `Variant`, etc.
matching the PHP shapes.

## Where not to use it

- **Anywhere `ManagedClient` already covers the surface.** The
  typed surface — error handling, session state, fluent
  configuration — is what you give up.
- **For high-frequency calls.** `ManagedClient` reuses a single
  transport object across many calls without the per-call open /
  close overhead `SocketConnection` incurs.
- **In application code.** `SocketConnection` is plumbing. Treat
  it like `pg_connect()` — useful for tooling, hide behind a
  proper abstraction in production.
