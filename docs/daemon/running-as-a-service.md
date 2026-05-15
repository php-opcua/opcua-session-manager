---
eyebrow: 'Docs · Daemon'
lede:    'The daemon stays in the foreground. Your service manager — systemd, supervisor, a container orchestrator — is what makes it run forever.'

see_also:
  - { href: './starting.md',                      meta: '5 min' }
  - { href: './configuration.md',                 meta: '6 min' }
  - { href: '../recipes/healthcheck-and-monitoring.md', meta: '5 min' }

prev: { label: 'Auto-publish',           href: './auto-publish.md' }
next: { label: 'ManagedClient overview', href: '../managed-client/overview.md' }
---

# Running as a service

The daemon does not daemonise itself. It runs in the foreground, logs
to stdout/stderr (or `--log-file`), and exits on `SIGTERM`. Wrap it in
a service manager that handles startup, restarts on failure, and
log capture.

Three common shapes: systemd, supervisor, container orchestration.

## systemd

The canonical Linux setup. Drop a unit file at
`/etc/systemd/system/opcua-session-manager.service`:

<!-- @code-block language="text" label="opcua-session-manager.service" -->
```text
[Unit]
Description=OPC UA Session Manager Daemon
After=network.target

[Service]
Type=simple
User=opcua
Group=opcua
WorkingDirectory=/opt/myapp
EnvironmentFile=/etc/opcua/daemon.env
ExecStart=/opt/myapp/vendor/bin/opcua-session-manager \
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
Restart=on-failure
RestartSec=5
TimeoutStopSec=30

# Sandboxing
NoNewPrivileges=true
ProtectSystem=strict
ProtectHome=true
PrivateTmp=true
ReadWritePaths=/var/run/opcua /var/log/opcua /var/cache/opcua
RuntimeDirectory=opcua
RuntimeDirectoryMode=0750

[Install]
WantedBy=multi-user.target
```
<!-- @endcode-block -->

`/etc/opcua/daemon.env`:

<!-- @code-block language="text" label="/etc/opcua/daemon.env" -->
```text
OPCUA_AUTH_TOKEN=<paste-the-token-here>
```
<!-- @endcode-block -->

Permissions: `chmod 600 /etc/opcua/daemon.env`,
`chown opcua:opcua /etc/opcua/daemon.env`.

Enable + start:

<!-- @code-block language="bash" label="terminal — enable" -->
```bash
sudo systemctl daemon-reload
sudo systemctl enable --now opcua-session-manager
sudo systemctl status opcua-session-manager
```
<!-- @endcode-block -->

### Notes on the unit

- **`Type=simple`** — the daemon does not fork; systemd watches the
  process directly.
- **`Restart=on-failure`** — restart only when the daemon exits
  non-zero. `RestartSec=5` keeps systemd from hammering a broken
  setup.
- **`TimeoutStopSec=30`** — covers the daemon's drain on SIGTERM
  (CloseSession on every active session). Lift it if you have many
  sessions or slow servers.
- **`RuntimeDirectory=opcua`** — systemd creates and cleans
  `/var/run/opcua` automatically; the socket lives there.
- **`ProtectSystem=strict`** — read-only `/usr`, `/boot`. Combine
  with `ReadWritePaths` for the directories the daemon needs to
  write to.

## supervisor

A POSIX alternative when systemd is not available (older distros,
some container bases):

<!-- @code-block language="text" label="/etc/supervisor/conf.d/opcua-session-manager.conf" -->
```text
[program:opcua-session-manager]
command=/opt/myapp/vendor/bin/opcua-session-manager
  --socket /var/run/opcua/sessions.sock
  --auth-token-file /etc/opcua/daemon.token
  --log-file /var/log/opcua/sessions.log
  --log-level info
user=opcua
autostart=true
autorestart=true
startsecs=2
stopsignal=TERM
stopwaitsecs=30
stdout_logfile=/var/log/opcua/supervisor-stdout.log
stderr_logfile=/var/log/opcua/supervisor-stderr.log
```
<!-- @endcode-block -->

Enable:

<!-- @code-block language="bash" label="terminal — supervisor" -->
```bash
sudo supervisorctl reread
sudo supervisorctl update
sudo supervisorctl status opcua-session-manager
```
<!-- @endcode-block -->

The supervisor model is simpler than systemd's but lacks sandboxing
primitives — secure the host accordingly.

## Docker

For containerised deployments, the daemon runs as the container's
main process. A minimal image:

<!-- @code-block language="text" label="Dockerfile" -->
```text
FROM php:8.4-cli-alpine

RUN apk add --no-cache --virtual .build-deps $PHPIZE_DEPS \
 && pecl channel-update pecl.php.net \
 && rm -rf /var/cache/apk/* \
 && apk del .build-deps

WORKDIR /app
COPY composer.json composer.lock /app/
RUN composer install --no-dev --no-interaction --optimize-autoloader
COPY . /app

EXPOSE 9990
ENV OPCUA_AUTH_TOKEN=""

ENTRYPOINT ["vendor/bin/opcua-session-manager"]
CMD ["--socket", "tcp://0.0.0.0:9990"]
```
<!-- @endcode-block -->

<!-- @callout variant="warning" -->
The `Dockerfile` example uses `tcp://0.0.0.0:9990` so the daemon is
reachable from another container in the same Docker network. The
daemon's startup guard **refuses non-loopback binds** — this command
will fail. Use either `tcp://127.0.0.1:9990` (only same-container
clients, the rare case) or expose the daemon over Unix socket via a
shared volume and bind it on `--socket /sockets/opcua.sock`. For
cross-container access without an extra transport layer, put both
the daemon and the clients in the same pod / container.
<!-- @endcallout -->

A safer Docker-compose shape: shared Unix socket via a named volume:

<!-- @code-block language="text" label="docker-compose.yml" -->
```text
services:
  opcua-daemon:
    build: .
    user: "1000:1000"
    volumes:
      - opcua-sockets:/sockets
    command:
      - --socket
      - /sockets/opcua-session-manager.sock
      - --socket-mode
      - "0660"
    environment:
      OPCUA_AUTH_TOKEN: "${OPCUA_AUTH_TOKEN:?required}"

  php-app:
    build: ./app
    user: "1000:1000"
    volumes:
      - opcua-sockets:/sockets
    environment:
      OPCUA_AUTH_TOKEN: "${OPCUA_AUTH_TOKEN:?required}"
      OPCUA_SOCKET_PATH: /sockets/opcua-session-manager.sock
    depends_on:
      - opcua-daemon

volumes:
  opcua-sockets: {}
```
<!-- @endcode-block -->

Both containers run under the same UID/GID; the socket mode `0660`
+ shared group is what lets the application reach the daemon.

## Health and observability

A long-running daemon needs:

- **Readiness probe.** The `ping` IPC command returns
  `{"status":"ok","sessions":N,"time":...}` when the daemon is up.
  Use it in healthchecks. See
  [Recipes · Healthcheck and monitoring](../recipes/healthcheck-and-monitoring.md).
- **Liveness probe.** The PID file's existence + PID liveness is the
  cheap probe. The expensive one is the same `ping`.
- **Log capture.** Whatever your platform captures (journald, Docker
  log driver, ELK), point it at the daemon's stderr or
  `--log-file`. The format is one line per entry, structured-enough
  to grep on.

## Restart story

Restarting the daemon **terminates every OPC UA session**. The OPC
UA servers see clean `CloseSession` if `SIGTERM` got through, or an
abrupt close otherwise. After the restart:

- Subscriptions are gone server-side.
- `ManagedClient::connect()` on the application side returns a
  fresh session ID; `wasSessionReused()` returns `false`.
- Auto-connect (if configured) reopens the registered sessions
  before the first application call.

Plan restarts around your subscription topology — if you have a
worker that depends on a live subscription, a daemon restart is a
worker restart too. See [Recipes · Recovery and
reconnect](../recipes/recovery-and-reconnect.md).
