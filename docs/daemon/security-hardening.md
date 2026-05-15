---
eyebrow: 'Docs · Daemon'
lede:    'A long-lived daemon with privileged access to industrial endpoints needs more than an auth token. Nine concrete hardenings the library ships — what they do, when they kick in, what they assume.'

see_also:
  - { href: './authentication.md', meta: '5 min' }
  - { href: './transports.md',     meta: '5 min' }
  - { href: 'https://github.com/php-opcua/opcua-session-manager/blob/master/SECURITY.md',   meta: 'external', label: 'SECURITY.md' }

prev: { label: 'Authentication',     href: './authentication.md' }
next: { label: 'Logging and cache',  href: './logging-and-cache.md' }
---

# Security hardening

The daemon ships nine hardenings on top of the auth-token /
transport posture covered earlier. Each one closes a class of
mistake — most of them were closed in v4.3.0 after real-world
deployments surfaced the gap.

## 1 — Socket file permission race

**Problem.** A naive bind-then-chmod sequence leaves the socket file
world-readable / world-writeable in the window between `bind()` and
`chmod()`. A daemon crash in that window leaves a permissive socket
on disk for the next process to pick up.

**Fix.** `SessionManagerDaemon::run()` calls `umask(0077)` around
the bind, so the socket is created with the configured mode
**atomically**. The follow-up `chmod()` is now a belt-and-braces
guard, not a load-bearing call.

You do not need to do anything to enable this — it runs unconditionally.

## 2 — Method whitelist on `query`

**Problem.** Without an allow-list, any IPC peer could invoke any
`ManagedClient`-callable method. Some operations (e.g. raw
`SecureChannel` internals) would let a misbehaving peer corrupt the
session state for others.

**Fix.** `CommandHandler::ALLOWED_METHODS` enumerates the 44
methods that the `query` command will dispatch. Methods outside the
list raise `forbidden_method`.

The whitelist is **conservative by design**. To call a method
outside it — typically because a third-party module ships a custom
operation — use the `invoke` command, which gates on
`hasMethod($name)` against the loaded module set instead of a
static list. See [Extensibility · Third-party
modules](../extensibility/third-party-modules.md).

## 3 — Credential sanitisation in `list`

**Problem.** The `list` command returns the set of active sessions
with their config. Earlier versions echoed `username` /
`clientKeyPath` / `caCertPath` / `userKeyPath` / `password` —
enabling a local peer to enumerate `(endpoint → username)` tuples
and target the credentials elsewhere.

**Fix.** `CommandHandler::SENSITIVE_CONFIG_KEYS` redacts those keys
from the `list` payload. The session-lookup keying (see
[Session reuse](../managed-client/session-reuse.md)) still uses the
real values internally — sessions stay correctly scoped per user —
but the wire never carries them.

## 4 — Per-frame size cap

**Problem.** A single IPC peer could push the daemon's per-
connection buffer to its 1 MiB limit and force repeated
`json_decode()` of the entire buffer on every byte read. At 50
concurrent connections, that is ~50 MiB of JSON parsing per cycle —
trivially DoS-able.

**Fix.** `SessionManagerDaemon::MAX_FRAME_BYTES = 65 536`. Any frame
larger than 64 KiB is rejected with a `payload_too_large` error and
the connection is closed. Legitimate requests are under 2 KiB; 64
KiB is comfortable headroom.

## 5 — IPv6 loopback consistency

**Problem.** `TcpLoopbackTransport::isLoopbackAddress()` previously
rejected `::ffff:127.0.0.1` (IPv4-mapped IPv6 loopback — a false
negative) and would misclassify `::ffff:192.168.1.10` as non-
loopback only by coincidence of address prefix.

**Fix.** Explicit handling: `::ffff:127.*` is accepted (it is
genuinely loopback), every other `::ffff:` address is rejected at
construction. The check runs on both the daemon (refuses to bind)
and the client (refuses to connect).

## 6 — Cross-platform path redaction

**Problem.** The error-sanitiser regex was Unix-only. Windows paths
(`C:\Users\…\secret.pem`) and URLs with embedded credentials
(`opc.tcp://user:pwd@host`) leaked through error messages unchanged
— into logs, IPC error responses, and any monitoring that captured
them.

**Fix.** `CommandHandler::sanitizeErrorMessage()` now runs three
regexes — URL (any scheme), Windows path, Unix path — and emits
`[url]` / `[path]` in their place. Regression coverage in
`CommandHandlerSecurityTest`.

## 7 — Allowed certificate directories

**Problem.** The `open` command accepts certificate paths in its
`config` payload. Without restriction, a peer could ask the daemon
to load `/etc/shadow` or `/proc/1/environ` and trigger predictable
file reads (the failure mode is constrained — the daemon only
calls OpenSSL on the file — but the exposure is real).

**Fix.** `--allowed-cert-dirs <dir1>,<dir2>` restricts certificate
loading to a closed list of parent directories. When set, the
daemon canonicalises every certificate path and verifies it sits
within one of the allowed roots.

<!-- @code-block language="bash" label="terminal — restricted cert dirs" -->
```bash
vendor/bin/opcua-session-manager \
    --allowed-cert-dirs /etc/opcua/certs,/var/lib/opcua/trust
```
<!-- @endcode-block -->

Recommended for any deployment where IPC peers are not fully
trusted. The cost is operational: every certificate the daemon
loads must live under one of the allowed roots.

## 8 — Conservative PID liveness check

**Problem.** On sandboxed hosts where neither `posix_kill(0)` nor
`/proc/<pid>` is available, `isProcessRunning()` previously returned
"dead". A new daemon could then steal the PID file from a still-
running instance.

**Fix.** When both introspection paths are unavailable, the check
returns "alive" — the daemon refuses to launch rather than risk
co-tenancy. You remove the stale PID file by hand if you are sure
the previous process is gone.

## 9 — Persistent cache hardening (inherited)

**Background.** `opcua-client` v4.3.0 removed `unserialize()` from
every cache code path in favour of JSON gated by an allowlist
(`Cache\WireCacheCodec`). The session manager is long-running and
its per-session caches persist across requests; this hardening
applies to it for free.

**You do not need to do anything,** other than flush persistent
caches on upgrade — see
[Recipes · Upgrading to v4.3](../recipes/upgrading-to-v4.3.md).

## What the daemon does **not** harden against

- **A compromised PHP process** with the auth token. The token is
  binary trust — possess it, run any allowed command. Use
  application-layer ACLs (in your code, not the daemon) if you need
  per-user authorisation.
- **OPC UA server compromise.** The daemon trusts the OPC UA
  server's responses; if the server lies, the daemon happily passes
  the lie through. Validate at the application layer.
- **Information disclosure via timing.** Error responses are sanitised
  but not constant-time. A determined attacker could in principle
  infer the existence of files by timing differences. The threat
  model assumes local trust.

For coordinated disclosure of security issues, see the project's
[`SECURITY.md`](https://github.com/php-opcua/opcua-session-manager/blob/master/SECURITY.md).
