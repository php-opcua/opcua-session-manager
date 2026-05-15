---
eyebrow: 'Docs · Daemon'
lede:    'Two transports — Unix-domain socket and TCP loopback. Pick by platform. The wire format is identical; the trust model is filesystem permissions on one, the kernel''s loopback contract on the other.'

see_also:
  - { href: './authentication.md',          meta: '5 min' }
  - { href: './security-hardening.md',      meta: '6 min' }
  - { href: '../ipc/overview.md',           meta: '5 min' }

prev: { label: 'Configuration',   href: './configuration.md' }
next: { label: 'Authentication',  href: './authentication.md' }
---

# Transports

The daemon supports two IPC transports:

| Transport            | Default on                         | Built on                                            |
| -------------------- | ---------------------------------- | --------------------------------------------------- |
| Unix-domain socket   | Linux, macOS                       | `ReactPHP\Socket` `unix://` listener                |
| TCP loopback         | Windows                            | `ReactPHP\Socket` `tcp://` listener, loopback-only  |

Pick by platform first, by ergonomics second. The wire protocol —
NDJSON-framed flat JSON envelopes — is identical on both.

## Default per platform

| Platform           | Default endpoint                              |
| ------------------ | --------------------------------------------- |
| Linux              | `unix:///tmp/opcua-session-manager.sock`      |
| macOS              | `unix:///tmp/opcua-session-manager.sock`      |
| Windows            | `tcp://127.0.0.1:9990`                        |

Both forms are accepted on every platform — Windows can use Unix
sockets via WSL paths, Linux can use TCP loopback for cross-language
clients. `TransportFactory::defaultEndpoint()` is the function the
daemon and `ManagedClient` consult.

## Unix-domain socket

The POSIX default. Trust posture: **filesystem permissions**.

<!-- @code-block language="bash" label="terminal — unix" -->
```bash
vendor/bin/opcua-session-manager --socket /var/run/opcua/sessions.sock
```
<!-- @endcode-block -->

Properties:

- **Permission mode** — `0600` by default (owner read/write only).
  Override with `--socket-mode 0660` for group-shared access.
- **Path length** — capped by the kernel: 108 bytes on Linux, 104
  on Darwin. The daemon validates this at startup (added v4.3.1).
- **Atomic permission** — the daemon `umask(0077)`s around the bind
  so the socket is created with the configured mode atomically. No
  permissive window between `bind()` and `chmod()`.

The socket file is created at startup and removed at clean shutdown.
A leftover socket file from a crashed daemon is detected at the next
start (the bind fails); the operator removes it by hand. There is no
auto-cleanup of stale sockets — this is intentional, since the
operator's intent is the only reliable signal.

### Permissions in practice

| Scenario                                   | Recommended mode | Notes                                                       |
| ------------------------------------------ | ---------------- | ----------------------------------------------------------- |
| Single-user dev box                         | `0600`           | Default                                                     |
| PHP-FPM under a dedicated user             | `0600`           | Run daemon as the same user                                 |
| PHP-FPM under a different user, same group | `0660`           | Daemon + FPM in the same group                              |
| Multi-tenant container                     | `0600`           | One daemon per tenant; do not share                         |

## TCP loopback

The Windows default; available on every platform as a portable
fallback. Trust posture: **kernel loopback contract**.

<!-- @code-block language="bash" label="terminal — TCP loopback" -->
```bash
vendor/bin/opcua-session-manager --socket tcp://127.0.0.1:9990
vendor/bin/opcua-session-manager --socket "tcp://[::1]:9990"
```
<!-- @endcode-block -->

Properties:

- **Loopback only** — the daemon refuses to bind to non-loopback
  hosts (`0.0.0.0`, any external interface). Attempting to do so
  raises a `RuntimeException` at startup.
- **IPv4 + IPv6** — `127.0.0.0/8`, `::1`, and `::ffff:127.*`
  (IPv4-mapped IPv6 loopback) are all accepted. Anything else is
  rejected at construction.
- **No filesystem permissions** — there is no socket file. Trust
  comes from the kernel's guarantee that loopback traffic stays
  local to the host.

<!-- @callout variant="warning" -->
The loopback guarantee assumes a single-tenant host. On a host
shared with hostile users (a multi-user shell server, a poorly
isolated container), every process on the host can reach a TCP
loopback socket. Use the **auth token** in that case —
loopback is necessary but not sufficient. See
[Authentication](./authentication.md).
<!-- @endcallout -->

### When to pick TCP loopback over Unix sockets

- **Windows.** ReactPHP's Unix-socket support is partial on Windows;
  TCP loopback is the recommended transport.
- **Cross-language consumers.** A Go / Python / Node.js client
  speaking the IPC protocol may have stronger TCP than Unix-socket
  bindings.
- **Container networking quirks.** A side-container that cannot
  share the daemon's filesystem can still reach a TCP loopback via
  the shared network namespace.

Otherwise the Unix socket is the better default: filesystem
permissions give you a stricter trust model than loopback alone.

## Address rejection

The daemon **refuses** at startup to bind:

| Endpoint attempted             | Why rejected                                              |
| ------------------------------ | --------------------------------------------------------- |
| `tcp://0.0.0.0:9990`           | Non-loopback                                              |
| `tcp://10.x.x.x:9990`          | Non-loopback                                              |
| `tcp://192.168.1.100:9990`     | Non-loopback                                              |
| `tcp://[::ffff:10.0.0.1]:9990` | Non-loopback (IPv4-mapped IPv6, but not loopback)         |
| `tcp://[::ffff:127.0.0.1]:9990`| **Asymmetric** — see caveat below                          |

> **Known asymmetry on IPv4-mapped IPv6 loopback.** The
> client-side guard in `TcpLoopbackTransport::isLoopbackAddress()`
> **accepts** `::ffff:127.0.0.1` (it is genuinely loopback), but
> the daemon-side guard in
> `SessionManagerDaemon::assertLoopbackIfTcp()` checks only
> `127.0.0.1`, `::1`, `localhost`, and `127.*`, and therefore
> **rejects** the IPv4-mapped form at bind time. Until the daemon
> guard is aligned with the client guard, configure the daemon
> with the bare IPv4 (`tcp://127.0.0.1:<port>`) or with `::1`,
> and use the same form on the client to avoid the mismatch. This
> is a source-side bug, not a doc-side ambiguity.

If you need a network-accessible OPC UA front end, layer an explicit
transport on top (TLS-terminating reverse proxy, SSH tunnel). The
daemon will not bind to those addresses directly.

## The client side

`ManagedClient` resolves the endpoint string the same way the
daemon does — through `TransportFactory`. Pass an explicit URI:

<!-- @code-block language="php" label="examples/client-tcp.php" -->
```php
use PhpOpcua\SessionManager\Client\ManagedClient;

// Unix socket
$client = new ManagedClient(
    socketPath: '/var/run/opcua/sessions.sock',
    timeout: 30.0,
);

// TCP loopback
$client = new ManagedClient(
    socketPath: 'tcp://127.0.0.1:9990',
    timeout: 30.0,
);

// Backwards-compatible — scheme-less path is Unix socket
$client = new ManagedClient(
    socketPath: '/tmp/opcua-session-manager.sock',
);
```
<!-- @endcode-block -->

`TransportFactory::defaultEndpoint()` is the right argument when you
want the per-OS default without hardcoding it:

<!-- @code-block language="php" label="examples/client-default.php" -->
```php
use PhpOpcua\SessionManager\Ipc\TransportFactory;

$client = new ManagedClient(TransportFactory::defaultEndpoint());
```
<!-- @endcode-block -->

## Migrating from Unix to TCP

Both sides need to agree. Roll out:

<!-- @steps -->
- **Stop the daemon.**

  Existing OPC UA sessions terminate. Subscriptions are lost on the
  server side — see [Recipes · Recovery and
  reconnect](../recipes/recovery-and-reconnect.md).

- **Update the daemon launch command.**

  Replace `--socket /path.sock` with `--socket tcp://127.0.0.1:9990`.

- **Update every `ManagedClient` construction.**

  Replace the path with the matching URI. Most applications wire this
  through a single factory — one place to change.

- **Start the daemon.**

  Verify with `nc 127.0.0.1 9990` per
  [Recipes · Debugging with netcat](../recipes/debugging-with-netcat.md).
<!-- @endsteps -->

There is no fallback / dual-listen mode — the daemon binds one
endpoint per process. Run a second daemon on a second endpoint if
you need a transition period.
