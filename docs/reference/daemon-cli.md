---
eyebrow: 'Docs · Reference'
lede:    'Every CLI flag of the bin/opcua-session-manager binary, with its default and effect. Defaults are kept short; the explanation links to the operational page for each topic.'

see_also:
  - { href: '../daemon/configuration.md',  meta: '6 min' }
  - { href: '../daemon/authentication.md', meta: '5 min' }
  - { href: '../daemon/transports.md',     meta: '5 min' }

prev: { label: 'Testing',            href: '../testing/overview.md' }
next: { label: 'ManagedClient API',  href: './managed-client-api.md' }
---

# Daemon CLI

`vendor/bin/opcua-session-manager` exposes thirteen flags plus the
two informational actions (`--help`, `--version`). Reading them in
groups (transport, security, sessions, logging, cache) is more
useful than the alphabetical list — this page does both.

<!-- @divider eyebrow="Transport" -->
Where the daemon listens.
<!-- @enddivider -->

### `--socket <uri>`

Endpoint URI. Accepts `unix://<path>`, `tcp://127.0.0.1:<port>`,
`tcp://[::1]:<port>`, or a scheme-less path (= `unix://<path>`).
Default per OS: `unix:///tmp/opcua-session-manager.sock` on
Linux/macOS, `tcp://127.0.0.1:9990` on Windows. Non-loopback TCP
hosts are rejected at startup. See
[Daemon · Transports](../daemon/transports.md).

### `--socket-mode <octal>`

File mode applied to a Unix-socket file. Default `0600`. Ignored
for TCP endpoints. `0660` is the common choice when the daemon and
PHP-FPM share a group but not a user. The mode is applied atomically
via `umask` — no permissive window.

<!-- @divider eyebrow="Authentication" -->
Shared-secret IPC authentication. See
[Daemon · Authentication](../daemon/authentication.md).
<!-- @enddivider -->

### `--auth-token <token>`

CLI auth token. **Visible in `ps` / `top`** — the daemon prints a
warning to stderr if you use this form. Acceptable for dev only.

### `--auth-token-file <path>`

Path to a file whose content is the auth token. The daemon trims
whitespace. File should be `0600` and owned by the daemon user.

`OPCUA_AUTH_TOKEN` environment variable beats both flags and is
the recommended production form.

<!-- @divider eyebrow="Session management" -->
Capacity and lifetime knobs.
<!-- @enddivider -->

### `--timeout <seconds>`

Per-session inactivity timeout. Default `600`. After this many
seconds without any IPC activity referencing the session, the
cleanup loop reclaims it: `CloseSession` to the OPC UA server, then
the session-store entry drops.

Sessions with active subscriptions are touched on every publish, so
they never hit the timeout.

### `--cleanup-interval <seconds>`

How often the cleanup loop runs. Default `30`. Lowering it makes
expiration more punctual but adds load (negligible at sensible
values).

### `--max-sessions <n>`

Hard cap on concurrent sessions. Default `100`. New `open` commands
beyond this cap fail with `max_sessions_reached`. Sized to the
expected fleet — a multi-tenant daemon serving many endpoints
benefits from a higher cap.

### `--allowed-cert-dirs <dirs>`

Comma-separated list of parent directories from which the daemon
will load certificates (`clientCertPath`, `clientKeyPath`,
`caCertPath`, `userCertPath`, `userKeyPath` in the `open` config).
Each candidate path is canonicalised via `realpath()` and verified
to sit under one of the allowed roots; mismatches raise
`InvalidArgumentException("<label> is not in an allowed directory:
<path>")`. On the client side that surfaces as
`DaemonException("[InvalidArgumentException] …")`.

When unset (the default), the daemon trusts the IPC peer to supply
sensible paths. **Set this in any deployment where IPC peers are
not fully trusted.** See [Daemon · Security
hardening](../daemon/security-hardening.md).

<!-- @divider eyebrow="Logging" -->
PSR-3 stream output.
<!-- @enddivider -->

### `--log-file <path>`

Destination for the daemon's `StreamLogger`. Default `null` (=
stderr). Wrap the daemon in a service manager to capture the
output stream.

### `--log-level <level>`

Minimum PSR-3 level: `debug`, `info`, `notice`, `warning`,
`error`. Default `info`. `debug` writes one entry per IPC command
plus one per OPC UA round-trip; production runs `info`.

<!-- @divider eyebrow="Cache" -->
PSR-16 cache shared across every Client the daemon constructs.
<!-- @enddivider -->

### `--cache-driver <driver>`

`memory` (default), `file`, or `none`. See
[Daemon · Logging and cache](../daemon/logging-and-cache.md).

| Driver   | Notes                                                       |
| -------- | ----------------------------------------------------------- |
| `memory` | `InMemoryCache`; loses state at daemon restart              |
| `file`   | `FileCache`; persistent. Requires `--cache-path`            |
| `none`   | Caching disabled — every Client gets `setCache(null)`       |

### `--cache-path <path>`

Cache directory. **Required** when `--cache-driver=file`.
Recommended: `/var/cache/opcua/` with daemon-user ownership and
`0700` permissions.

### `--cache-ttl <seconds>`

Default TTL for cache entries. Default `300`. Applied to every
cache write the daemon's Clients perform.

<!-- @divider eyebrow="Informational" -->
<!-- @enddivider -->

### `--help`, `-h`

Print the usage screen and exit `0`.

### `--version`, `-v`

Print `opcua-session-manager <version>` and exit `0`. The version
matches `SessionManagerDaemon::VERSION`.

<!-- @divider eyebrow="Environment variables" -->
Picked up at startup.
<!-- @enddivider -->

### `OPCUA_AUTH_TOKEN`

Auth token, highest priority. Overrides `--auth-token-file` and
`--auth-token`. Does **not** appear in `ps` / `/proc/<pid>/cmdline`,
which is why it is the production-grade form. See
[Daemon · Authentication](../daemon/authentication.md).

### `OPCUA_SOCKET_PATH`

Convention referenced by the daemon's error messages when the
socket path exceeds the kernel cap. **Not** auto-applied to the
runtime `--socket` value; pass it explicitly:

<!-- @code-block language="bash" label="terminal" -->
```bash
vendor/bin/opcua-session-manager --socket "$OPCUA_SOCKET_PATH"
```
<!-- @endcode-block -->

<!-- @divider eyebrow="Argument parsing semantics" -->
<!-- @enddivider -->

The bin uses `ArgvParser` (`src/Cli/ArgvParser.php`). Notable
behaviours:

- **Missing value for a flag** — emits
  `Missing value for option <flag>` to stderr and exits `1`. The
  daemon will not boot with a half-applied config.
- **Unknown flags** — silently ignored. Reserved for forward-
  compatibility; do not rely on this for typos.
- **Order-independent** — flags can appear in any order.
- **`--key value` only** — `--key=value` is not currently
  supported.

## Sample invocations

<!-- @tabs labels="Dev, Production, Docker" -->
<!-- @tab index="0" -->
```bash
# Default Unix socket, no auth, foreground
vendor/bin/opcua-session-manager
```
<!-- @endtab -->
<!-- @tab index="1" -->
```bash
# Production: hardened, file cache, restricted cert dirs
OPCUA_AUTH_TOKEN="$(cat /etc/opcua/daemon.token)" \
vendor/bin/opcua-session-manager \
    --socket /var/run/opcua/sessions.sock \
    --socket-mode 0660 \
    --timeout 1800 \
    --max-sessions 200 \
    --allowed-cert-dirs /etc/opcua/certs,/var/lib/opcua/trust \
    --log-file /var/log/opcua/sessions.log \
    --log-level info \
    --cache-driver file \
    --cache-path /var/cache/opcua \
    --cache-ttl 600
```
<!-- @endtab -->
<!-- @tab index="2" -->
```bash
# Inside a container — Unix socket on a shared volume
vendor/bin/opcua-session-manager \
    --socket /sockets/opcua-session-manager.sock \
    --socket-mode 0660 \
    --log-file /dev/stderr \
    --log-level info
```
<!-- @endtab -->
<!-- @endtabs -->

For the matching service-manager configuration, see
[Daemon · Running as a service](../daemon/running-as-a-service.md).
