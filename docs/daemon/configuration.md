---
eyebrow: 'Docs · Daemon'
lede:    'Thirteen CLI options, one environment variable, one config file. Defaults are operator-friendly; the few values you need to override depend on whether you trust the local machine.'

see_also:
  - { href: './authentication.md',         meta: '5 min' }
  - { href: './security-hardening.md',     meta: '6 min' }
  - { href: '../reference/daemon-cli.md',  meta: '6 min' }

prev: { label: 'Starting the daemon', href: './starting.md' }
next: { label: 'Transports',          href: './transports.md' }
---

# Configuration

The daemon reads configuration from three places, in increasing
priority order:

1. **Built-in defaults** — `config/defaults.php` in the package
2. **CLI flags** — `--option value`
3. **Environment variables** — for a small allow-list of high-impact
   settings (auth token, socket path)

The whole config surface fits on one screen.

## Defaults

<!-- @code-block language="text" label="config/defaults.php" -->
```text
socket_path        TransportFactory::defaultEndpoint()   (per-OS)
timeout            600                                    (seconds)
cleanup_interval   30                                     (seconds)
auth_token         null                                   (disabled)
auth_token_file    null
max_sessions       100
socket_mode        0600                                   (unix only)
allowed_cert_dirs  null                                   (no restriction)
log_file           null                                   (= stderr)
log_level          info
cache_driver       memory
cache_path         null                                   (required when cache_driver=file)
cache_ttl          300                                    (seconds)
```
<!-- @endcode-block -->

`TransportFactory::defaultEndpoint()` returns
`unix:///tmp/opcua-session-manager.sock` on POSIX systems and
`tcp://127.0.0.1:9990` on Windows.

## CLI flags

The flat reference of every flag is in
[Reference · Daemon CLI](../reference/daemon-cli.md). Frequently set:

| Flag                          | Default                                          | When to change                                    |
| ----------------------------- | ------------------------------------------------ | ------------------------------------------------- |
| `--socket <uri>`              | per-OS                                           | Non-default endpoint, dedicated socket directory  |
| `--timeout <seconds>`         | `600`                                            | Tighter / looser idle-session expiration          |
| `--max-sessions <n>`          | `100`                                            | Hard cap on concurrent sessions                   |
| `--auth-token-file <path>`    | none                                             | Production — see [Authentication](./authentication.md) |
| `--log-file <path>`           | stderr                                           | Capture logs to file under a service manager      |
| `--log-level <level>`         | `info`                                           | `debug` while diagnosing, `warning` for quiet ops |
| `--cache-driver <driver>`     | `memory`                                         | `file` for cross-process cache reuse, `none` to disable |
| `--allowed-cert-dirs <dirs>`  | none                                             | Restrict where the daemon will load certificates from |

## Environment variables

The daemon's bin script reads exactly one environment variable:

| Variable                  | Effect                                                                  |
| ------------------------- | ----------------------------------------------------------------------- |
| `OPCUA_AUTH_TOKEN`        | Auth token (highest priority — beats `--auth-token` and `--auth-token-file`) |

`OPCUA_SOCKET_PATH` is a **documentation convention** referenced
in `TransportFactory::assertUnixPathFits()` error messages and in
recipes; the bin script does not consult it. Pass
`--socket "$OPCUA_SOCKET_PATH"` explicitly when you want that
behaviour.

Inside a service manager, `OPCUA_AUTH_TOKEN` is the right place for
the auth token — it bypasses `ps` / `/proc/<pid>/cmdline` exposure.

## Priority order

When the same setting is configurable in multiple places, the daemon
picks in this order:

<!-- @code-block language="text" label="precedence" -->
```text
Auth token:
   OPCUA_AUTH_TOKEN env  →  --auth-token-file  →  --auth-token  →  default (null)

All other settings:
   CLI flag  →  default
```
<!-- @endcode-block -->

A CLI `--auth-token` warning is printed to stderr — the value is
visible in the process list — and you should use the env or file
form instead.

## Sample production invocation

<!-- @code-block language="bash" label="terminal — production" -->
```bash
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
<!-- @endcode-block -->

Notes:

- `--socket-mode 0660` lets the daemon's group also write to the
  socket — needed if the PHP-FPM pool runs under a different user
  than the daemon. The socket directory must be group-traversable.
- `--allowed-cert-dirs` is the only path-traversal guard the daemon
  ships. It restricts the directories from which the daemon will
  load OPC UA certificates supplied through `open` commands. See
  [Security hardening](./security-hardening.md).

## Programmatic configuration

When you embed the daemon
([Starting · Programmatic embedding](./starting.md#section-programmatic-embedding)),
the constructor arguments mirror the CLI flags one-to-one. The
`config/defaults.php` file is **not** consulted in the embedded path
— supply explicit values or your own defaults.

<!-- @code-block language="php" label="examples/embedded.php" -->
```php
$daemon = new SessionManagerDaemon(
    socketPath:        '/var/run/opcua/sessions.sock',
    timeout:           1800,
    cleanupInterval:   60,
    authToken:         getenv('OPCUA_AUTH_TOKEN') ?: null,
    maxSessions:       200,
    socketMode:        0660,
    allowedCertDirs:   ['/etc/opcua/certs', '/var/lib/opcua/trust'],
    logger:            $monolog,
    clientCache:       new FileCache('/var/cache/opcua', 600),
);
```
<!-- @endcode-block -->

## What is *not* configurable

- **The IPC envelope format.** The wire is JSON, framed by `\n`,
  with the flat envelope `{command, ...}`. Not pluggable.
- **The publish interval for auto-publish.** Driven by each
  subscription's `revisedPublishingInterval`. The daemon does not
  override server-negotiated values.
- **Per-session limits.** Subscriptions, monitored items, cache TTLs
  per session — those are session-level configuration on the
  `open` command's `config` payload, not daemon configuration. See
  [IPC · Commands](../ipc/commands.md).

## Where to verify the running configuration

The daemon does not currently expose its effective configuration via
IPC. Two ways to inspect:

1. The startup log line (visible at `info` level) reports the
   socket path, timeout, cleanup interval, max sessions, and socket
   mode.
2. The `ping` response (`status`, `sessions`, `time`) confirms the
   daemon is up but does not echo the configuration.

For a production deployment, treat the systemd unit / supervisor
config as the source of truth.
