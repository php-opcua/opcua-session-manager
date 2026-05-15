---
eyebrow: 'Docs · Daemon'
lede:    'The daemon is a single PHP process. Start it in the foreground for development, wire it to a service manager for production, and trust the PID file to detect duplicate launches.'

see_also:
  - { href: './configuration.md',         meta: '6 min' }
  - { href: './running-as-a-service.md',  meta: '7 min' }
  - { href: '../recipes/healthcheck-and-monitoring.md', meta: '5 min' }

prev: { label: 'Why a session manager', href: '../getting-started/why-a-session-manager.md' }
next: { label: 'Configuration',         href: './configuration.md' }
---

# Starting the daemon

`vendor/bin/opcua-session-manager` is the launcher. Run it in the
foreground and you have the daemon. In production you wrap it in
systemd or supervisor — same binary, no special "daemon mode".

## Foreground

<!-- @code-block language="bash" label="terminal — foreground" -->
```bash
vendor/bin/opcua-session-manager
```
<!-- @endcode-block -->

Output:

<!-- @code-block language="text" label="startup log (illustrative)" -->
```text
[2026-05-15 08:30:00] [INFO] OPC UA Session Manager started on unix:///tmp/opcua-session-manager.sock
[2026-05-15 08:30:00] [INFO] Timeout: 600s, Cleanup interval: 30s, Max sessions: 100
[2026-05-15 08:30:00] [INFO] Socket permissions: 600
```
<!-- @endcode-block -->

The actual `StreamLogger` format is `[YYYY-MM-DD HH:MM:SS]
[LEVEL] message` — the level is uppercased and the socket value
includes its full URI (`unix://...` or `tcp://...`), with `{...}`
context placeholders interpolated into the message itself.

The daemon stays in the foreground until `Ctrl-C` or `SIGTERM`. It
binds its IPC listener, writes a PID file next to the socket, and
enters the ReactPHP event loop.

## The PID file

The daemon writes a PID file alongside its endpoint:

- **Unix socket**: `/tmp/opcua-session-manager.sock.pid`
- **TCP loopback**: `/tmp/opcua-session-manager-tcp_127.0.0.1_9990.pid`

On startup the daemon checks for an existing PID file. If the file
exists and the recorded PID is still alive, the launch fails — there
is already a daemon on that endpoint. To restart cleanly, stop the
running daemon first (SIGTERM is enough; the daemon removes its own
PID file on shutdown).

<!-- @callout variant="note" -->
On sandboxed hosts where neither `posix_kill()` nor `/proc/<pid>` is
available, the PID check **conservatively assumes the process is
alive**. Better to refuse a start than to silently take over another
daemon's socket. Remove the stale PID file by hand if you are sure
the previous process is gone.
<!-- @endcallout -->

## Bind on a non-default endpoint

<!-- @code-block language="bash" label="terminal — custom endpoint" -->
```bash
# Unix socket at a custom path
vendor/bin/opcua-session-manager --socket /var/run/opcua/sessions.sock

# TCP loopback on a chosen port
vendor/bin/opcua-session-manager --socket tcp://127.0.0.1:9991

# Scheme-less path is treated as a Unix socket (backwards-compat)
vendor/bin/opcua-session-manager --socket /opt/opcua/sessions.sock
```
<!-- @endcode-block -->

The `--socket` flag accepts any of:

| Form                                  | Meaning                                                      |
| ------------------------------------- | ------------------------------------------------------------ |
| `unix:///absolute/path.sock`          | Unix-domain socket at the given absolute path                |
| `/absolute/path.sock`                 | Same as above (no scheme — backwards-compat)                 |
| `tcp://127.0.0.1:<port>`              | TCP loopback (IPv4)                                          |
| `tcp://[::1]:<port>`                  | TCP loopback (IPv6)                                          |

Non-loopback hosts (`tcp://0.0.0.0:9990`, `tcp://10.x.x.x:9990`) are
**rejected at startup** with a `RuntimeException`. The daemon refuses
to expose itself to the network without an explicit transport layer
(TLS, SSH tunnel). See [Daemon · Transports](./transports.md).

## The socket path length limit

Unix-domain socket paths are capped by the kernel — 108 bytes on
Linux, 104 on Darwin. The daemon validates the path length **before**
binding:

<!-- @code-block language="text" label="too long (verbatim message)" -->
```text
Unix socket path is too long: 132 bytes, but the Linux kernel limits sun_path to 108 bytes (usable 107). The kernel silently truncates longer paths, which breaks chmod() and reconnect. Set OPCUA_SOCKET_PATH (or pass --socket) to a shorter path, e.g. /tmp/opcua-session.sock. Got: /very/deeply/nested/path/that/exceeds/the/cap.sock
```
<!-- @endcode-block -->

A path that exceeds the cap previously surfaced as a confusing
`chmod(): No such file or directory` after a silently truncated
bind. The explicit check (added in v4.3.1) replaces that with the
message above.

## Shutting down

Send `SIGTERM` (or `SIGINT` from the keyboard). The daemon:

<!-- @steps -->
- **Stops accepting new IPC connections.**

  The listener is unbound; in-flight requests are allowed to complete.

- **Drains the OPC UA sessions.**

  Each active session sends `CloseSession` + `CloseSecureChannel` to
  its server. Failures here are logged but do not block shutdown.

- **Removes the PID file.**

  Releases the lock for the next start.

- **Exits.**
<!-- @endsteps -->

`SIGKILL` skips all of that — the PID file and the Unix socket are
left on disk, the next start will refuse to launch until they are
cleaned up. Always prefer `SIGTERM`.

## What "running" means

The daemon is running when **all** of the following are true:

- The PID in its PID file is alive.
- The IPC endpoint accepts connections.
- A `ping` command returns `{"success": true, "data": {"status": "ok", ...}}`.

The first two are infrastructure checks; the third is the canonical
healthcheck — see
[Recipes · Healthcheck and monitoring](../recipes/healthcheck-and-monitoring.md).

## Programmatic embedding

The daemon is a regular PHP class — `SessionManagerDaemon` — and
nothing forces you to use the binary. If you have a host process
that wants to manage the lifecycle itself (a Symfony Console
command, a long-running worker, a NativePHP shell), construct the
daemon directly:

<!-- @code-block language="php" label="examples/embedded-daemon.php" -->
```php
use PhpOpcua\SessionManager\Daemon\SessionManagerDaemon;
use PhpOpcua\Client\Cache\InMemoryCache;
use Psr\Log\NullLogger;

$daemon = new SessionManagerDaemon(
    socketPath: '/tmp/my-opcua.sock',
    timeout: 600,
    cleanupInterval: 30,
    authToken: null,
    maxSessions: 100,
    socketMode: 0600,
    allowedCertDirs: null,
    logger: new NullLogger(),
    clientCache: new InMemoryCache(300),
);

$daemon->run();   // blocks until SIGTERM / SIGINT
```
<!-- @endcode-block -->

Constructor arguments mirror the CLI options one-to-one (see
[Daemon · Configuration](./configuration.md)). `run()` blocks the
caller until shutdown — wrap it in your own process supervision if
you need finer control.
