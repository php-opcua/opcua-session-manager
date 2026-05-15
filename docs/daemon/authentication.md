---
eyebrow: 'Docs · Daemon'
lede:    'IPC authentication is a single shared secret — pass the same string to the daemon and to every ManagedClient. The how-to-pass matters more than the value itself.'

see_also:
  - { href: './security-hardening.md',  meta: '6 min' }
  - { href: './transports.md',          meta: '5 min' }
  - { href: '../ipc/envelope-and-framing.md', meta: '5 min' }

prev: { label: 'Transports',         href: './transports.md' }
next: { label: 'Security hardening', href: './security-hardening.md' }
---

# Authentication

The daemon's IPC layer supports a single, optional shared-secret
**auth token**. When configured, every command frame must include the
token; the daemon validates it with a timing-safe `hash_equals()`
and rejects mismatches with `auth_failed`.

The token is **off by default**. Turn it on whenever the host is not
single-tenant or whenever the transport is TCP loopback.

## When you need it

| Scenario                                            | Token required?       |
| --------------------------------------------------- | --------------------- |
| Single-tenant host, Unix socket, `0600` perms       | Optional              |
| Multi-user shell host (Unix socket)                 | **Yes**               |
| Any TCP loopback host                               | **Yes**               |
| Container shared with side-containers (Unix socket) | **Yes** if other containers can read the socket |
| Production, anywhere                                | **Yes** as defence in depth |

Without a token, **any process on the host that can open the socket**
is a trusted peer. Unix-socket file permissions narrow that to one
user (`0600`) or one group (`0660`). TCP loopback widens it to every
process on the host. The auth token closes the gap.

## Three ways to pass the token

In **decreasing** preference order:

### 1. Environment variable

`OPCUA_AUTH_TOKEN`. Highest priority — overrides both the file and
the CLI flag. **Not visible in `ps` / `/proc/<pid>/cmdline`**.

<!-- @code-block language="bash" label="terminal — env" -->
```bash
OPCUA_AUTH_TOKEN="$(cat /etc/opcua/daemon.token)" \
    vendor/bin/opcua-session-manager
```
<!-- @endcode-block -->

In systemd, this is what `EnvironmentFile=` is for — see
[Daemon · Running as a service](./running-as-a-service.md).

### 2. File

`--auth-token-file <path>`. The daemon reads the file at startup,
trims whitespace, and uses its content as the token. The file should
be `0600`, owned by the daemon user.

<!-- @code-block language="bash" label="terminal — file" -->
```bash
openssl rand -hex 32 > /etc/opcua/daemon.token
chmod 600 /etc/opcua/daemon.token
chown opcua:opcua /etc/opcua/daemon.token

vendor/bin/opcua-session-manager \
    --auth-token-file /etc/opcua/daemon.token
```
<!-- @endcode-block -->

The file path is visible in `ps`; the token contents are not.

### 3. CLI flag

`--auth-token <token>`. **The token is visible in `ps` / `top`** —
the daemon prints a warning to stderr if you use this form:

<!-- @code-block language="text" label="warning at startup" -->
```text
WARNING: --auth-token is visible in the process list (ps/top).
         Use OPCUA_AUTH_TOKEN env var or --auth-token-file instead.
```
<!-- @endcode-block -->

Acceptable for one-shot dev scripts; never for production.

## Picking a token

Any opaque string the daemon and clients agree on. The library does
not validate length or character set. The conservative default:

<!-- @code-block language="bash" label="terminal — generate" -->
```bash
openssl rand -hex 32
# → 256-bit random, hex-encoded — 64 characters
```
<!-- @endcode-block -->

Rotate the token by re-running the generation, updating the file
(or env var), and restarting the daemon. Every `ManagedClient`
needs the new value before its next IPC call.

## Client-side configuration

`ManagedClient` accepts the auth token as the third constructor
argument:

<!-- @code-block language="php" label="examples/authed-client.php" -->
```php
use PhpOpcua\SessionManager\Client\ManagedClient;

$client = new ManagedClient(
    socketPath: '/tmp/opcua-session-manager.sock',
    timeout: 30.0,
    authToken: getenv('OPCUA_AUTH_TOKEN'),
);
```
<!-- @endcode-block -->

Inject it from the same source the daemon uses — typically the
process environment or a secrets file your deployment writes. Avoid
hardcoding tokens in the application repo; treat them like database
passwords.

## What "authenticated" guarantees

- **The caller knows the shared secret.** Either the operator
  authorised them, or the secret has leaked. There is no per-user
  identity in the IPC layer — every authenticated peer has the same
  privileges.
- **The frame was not replayed by an unauthorised peer.** Loopback
  intercepts on a single host are theoretical without root; on a
  multi-tenant host, the token is what gates them.

What it does **not** guarantee:

- **Privilege separation.** Every authenticated peer can `open`,
  `close`, `list`, `query`, `invoke`, `describe`. There is no
  per-method ACL.
- **TLS-grade confidentiality.** The wire is plaintext. On a Unix
  socket this is fine; on TCP loopback it is fine; on a
  hypothetical cross-host TCP (which the daemon refuses to bind
  anyway), it would not be.

For richer trust models, layer them outside — a TLS-terminating
reverse proxy in front of a TCP-loopback daemon is the canonical
escape hatch.

## Failure modes

| Failure                                       | Error                                                   |
| --------------------------------------------- | ------------------------------------------------------- |
| Daemon expects a token, client sends none     | `auth_failed`, "Invalid or missing auth token"          |
| Daemon expects token A, client sends token B  | `auth_failed`, "Invalid or missing auth token"          |
| Daemon expects no token, client sends one     | Ignored; request succeeds                               |

Mismatches raise `DaemonException` on the client side. See
[Reference · Exceptions](../reference/exceptions.md).

## Rotation

There is no built-in token rotation. The procedure:

<!-- @steps -->
- **Generate a new token.** `openssl rand -hex 32`.
- **Update the secret store** the daemon and clients consult.
- **Restart the daemon.** It picks up the new token at startup.
- **Restart workers** (or invalidate cached `ManagedClient` factory
  output) so they pick up the new value on their next IPC call.
<!-- @endsteps -->

The window between the daemon restart and the worker restart is
the rotation outage — keep it short by automating the worker
reload (`systemctl reload php-fpm`, queue worker restart, etc).
