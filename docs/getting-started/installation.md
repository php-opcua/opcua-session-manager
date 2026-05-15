---
eyebrow: 'Docs · Getting started'
lede:    'Install the package, install the daemon binary that ships with it, and confirm that the IPC channel is reachable.'

see_also:
  - { href: './quick-start.md',                  meta: '5 min' }
  - { href: '../daemon/starting.md',             meta: '4 min' }
  - { href: '../daemon/transports.md',           meta: '5 min' }

prev: { label: 'Overview',    href: '../overview.md' }
next: { label: 'Quick start', href: './quick-start.md' }
---

# Installation

`php-opcua/opcua-session-manager` is distributed through Packagist.
The Composer install gives you both the daemon binary
(`bin/opcua-session-manager`) and the `ManagedClient` class your
application uses.

## Requirements

- **PHP** ≥ 8.2 (tested against 8.2, 8.3, 8.4, 8.5)
- **`ext-openssl`** — inherited from `opcua-client`
- **`react/event-loop`** + **`react/socket`** — pulled in
  automatically as Composer dependencies
- **A long-running process host** — systemd, supervisor, a Docker
  container, anything that will keep the daemon alive

Cross-platform: Linux, macOS, Windows. Default IPC transport is
Unix-domain socket on POSIX systems and TCP loopback on Windows; both
are supported on every platform. See [Daemon ·
Transports](../daemon/transports.md).

## Install

<!-- @code-block language="bash" label="terminal" -->
```bash
composer require php-opcua/opcua-session-manager
```
<!-- @endcode-block -->

That pulls the package, its OPC UA dependency
(`php-opcua/opcua-client`), and the ReactPHP transport. No service
provider to register, no PHP configuration to edit.

## Find the daemon binary

Composer drops a launcher at
`vendor/bin/opcua-session-manager` — a tiny shim that points at the
real binary (`vendor/php-opcua/opcua-session-manager/bin/opcua-session-manager`).
Both invocations work.

<!-- @code-block language="bash" label="terminal" -->
```bash
vendor/bin/opcua-session-manager --version
# → opcua-session-manager 4.3.1
```
<!-- @endcode-block -->

## Verify

Start the daemon in the foreground, send it a `ping` from another
terminal, then stop it with `Ctrl-C`.

<!-- @tabs labels="POSIX, Windows" -->
<!-- @tab index="0" -->
```bash
# Terminal 1 — daemon
vendor/bin/opcua-session-manager
# OPC UA Session Manager started on /tmp/opcua-session-manager.sock
# Timeout: 600s, Cleanup interval: 30s, Max sessions: 100
# Socket permissions: 600

# Terminal 2 — ping
echo '{"command":"ping"}' \
  | nc -U /tmp/opcua-session-manager.sock
# → {"success":true,"data":{"status":"ok",...}}
```
<!-- @endtab -->
<!-- @tab index="1" -->
```bash
:: Terminal 1 — daemon
vendor\bin\opcua-session-manager
:: OPC UA Session Manager started on tcp://127.0.0.1:9990

:: Terminal 2 — ping
echo {"command":"ping"} ^
  | nc 127.0.0.1 9990
```
<!-- @endtab -->
<!-- @endtabs -->

If the ping round-trips, the install is complete and the IPC channel
is reachable. Move on to [Quick start](./quick-start.md) to wire
`ManagedClient` into actual PHP code.

<!-- @callout variant="note" -->
The default daemon endpoint is per-OS:
`unix:///tmp/opcua-session-manager.sock` on Linux/macOS,
`tcp://127.0.0.1:9990` on Windows. Override with the `--socket`
flag. `OPCUA_SOCKET_PATH` is **only a convention** — the daemon
bin does not read it automatically; pass it explicitly via
`--socket "$OPCUA_SOCKET_PATH"` if you want that behaviour. See
[Daemon · Configuration](../daemon/configuration.md).
<!-- @endcallout -->

## What you do not need to install

- **`opcua-client`** explicitly. It is a transitive dependency of
  this package. The exact version is pinned in `composer.lock`.
- **A separate launcher script.** The daemon binary is the launcher
  — wrap it in your service manager of choice
  ([Daemon · Running as a service](../daemon/running-as-a-service.md)).
- **PHP extensions other than `ext-openssl`.** The daemon and the
  client both rely on the same minimal `ext-openssl` requirement; no
  `ext-event`, `ext-ev`, or `ext-uv` is needed (ReactPHP's polling
  loop is sufficient at this load profile).
