# Daemon

The daemon is the central process that keeps OPC UA sessions alive across PHP requests.

## Starting the daemon

```bash
php bin/opcua-session-manager [options]
```

### CLI options

| Option | Default | Description |
|--------|---------|-------------|
| `--socket <path>` | `/tmp/opcua-session-manager.sock` | Unix domain socket path |
| `--timeout <seconds>` | `600` | Session inactivity timeout (seconds) |
| `--cleanup-interval <seconds>` | `30` | Interval for expired session cleanup (seconds) |
| `--auth-token <token>` | *(none)* | Shared secret for IPC authentication (prefer env var or file) |
| `--auth-token-file <path>` | *(none)* | Read auth token from a file (recommended) |
| `--max-sessions <n>` | `100` | Maximum concurrent sessions |
| `--socket-mode <octal>` | `0600` | Socket file permissions |
| `--allowed-cert-dirs <dirs>` | *(none)* | Comma-separated list of allowed certificate directories |
| `--help`, `-h` | - | Show help |

### Examples

```bash
# Start with default configuration
php bin/opcua-session-manager

# Custom socket and 5-minute timeout
php bin/opcua-session-manager --socket /var/run/opcua.sock --timeout 300

# Production: auth token, restricted permissions
php bin/opcua-session-manager \
    --auth-token-file /etc/opcua/daemon.token \
    --socket /var/run/opcua-session-manager.sock \
    --socket-mode 0660 \
    --max-sessions 50 \
    --allowed-cert-dirs /etc/opcua/certs

# More frequent cleanup (every 10 seconds)
php bin/opcua-session-manager --cleanup-interval 10
```

## Security

### Authentication

By default, any local process that can access the Unix socket can send commands to the daemon. For production deployments, configure an auth token:

```bash
# Generate a token
openssl rand -hex 32 > /etc/opcua/daemon.token
chmod 600 /etc/opcua/daemon.token

# Recommended: via environment variable (not visible in process list)
OPCUA_AUTH_TOKEN=$(cat /etc/opcua/daemon.token) php bin/opcua-session-manager

# Alternative: via file (also safe)
php bin/opcua-session-manager --auth-token-file /etc/opcua/daemon.token
```

Token resolution priority: `OPCUA_AUTH_TOKEN` env var > `--auth-token-file` > `--auth-token`.

**Warning**: Avoid `--auth-token` on the command line in production — the token is visible in the process list (`ps`, `/proc`). Use the environment variable or file method instead.

When an auth token is configured, every IPC request must include the token. The `ManagedClient` handles this automatically:

```php
$client = new ManagedClient(
    socketPath: '/var/run/opcua-session-manager.sock',
    authToken: file_get_contents('/etc/opcua/daemon.token'),
);
```

Token comparison uses `hash_equals()` to prevent timing attacks.

### Socket permissions

The daemon creates the socket file with permissions `0600` by default (owner read/write only). Use `--socket-mode` to adjust:

```bash
# Allow group access (e.g., www-data group)
php bin/opcua-session-manager --socket-mode 0660
```

### Session limits

The `--max-sessions` option prevents resource exhaustion. When the limit is reached, new `open` commands return an error.

### Certificate path restrictions

The `--allowed-cert-dirs` option restricts which directories the daemon will read certificates from. This prevents clients from pointing to arbitrary files on the filesystem:

```bash
php bin/opcua-session-manager --allowed-cert-dirs /etc/opcua/certs,/opt/app/certs
```

Without this option, the daemon still validates that certificate paths point to existing regular files.

### Connection protection

The daemon implements several protections against abusive IPC connections:

- **Per-connection timeout** (30s) — prevents slowloris-style attacks where a client opens a connection but sends data very slowly
- **Max concurrent connections** (50) — prevents connection exhaustion
- **Credential sanitization** — passwords and private key paths are stripped from session data immediately after the OPC UA connection is established, so they are never stored in memory longer than necessary
- **Error message sanitization** — exception messages are truncated to 500 characters and file paths are stripped to prevent information leakage

### PID file

The daemon writes a PID file (`<socket-path>.pid`) to prevent multiple instances from running on the same socket. If a stale PID file exists (from a crash), the daemon detects that the process is no longer running and starts normally.

## Running as a systemd service

```ini
# /etc/systemd/system/opcua-session-manager.service
[Unit]
Description=OPC UA Session Manager Daemon
After=network.target

[Service]
Type=simple
User=www-data
EnvironmentFile=/etc/opcua/daemon.env
ExecStart=/usr/bin/php /path/to/bin/opcua-session-manager \
    --socket /var/run/opcua-session-manager.sock \
    --socket-mode 0660 \
    --timeout 600
Restart=on-failure
RestartSec=5

[Install]
WantedBy=multi-user.target
```

```bash
sudo systemctl enable opcua-session-manager
sudo systemctl start opcua-session-manager
```

## Running with Supervisor

```ini
# /etc/supervisor/conf.d/opcua-session-manager.conf
[program:opcua-session-manager]
command=php /path/to/bin/opcua-session-manager --socket /var/run/opcua-session-manager.sock --auth-token-file /etc/opcua/daemon.token
user=www-data
autostart=true
autorestart=true
stderr_logfile=/var/log/opcua-session-manager.err.log
stdout_logfile=/var/log/opcua-session-manager.out.log
```

## Internal architecture

### Event Loop (ReactPHP)

The daemon uses `react/event-loop` to manage:
- **Incoming IPC connections** — each client command arrives as a Unix socket connection
- **Periodic timer** — every `cleanup-interval` seconds it checks and closes expired sessions
- **Signal handlers** — catches SIGTERM and SIGINT for graceful shutdown

### Request lifecycle

1. The PHP client opens a connection to the Unix socket
2. Sends a JSON command terminated by `\n` (max 1MB)
3. If an auth token is configured, the daemon validates it (timing-safe comparison)
4. The daemon decodes the JSON and passes it to the `CommandHandler`
5. The `CommandHandler` validates the method against a whitelist, then executes the operation
6. The result is serialized to JSON and sent back to the client
7. The IPC connection is closed

### Session management

OPC UA sessions are stored in an in-memory `SessionStore`. Each session contains:

- **id** — Unique identifier (32-character hex string, generated with `random_bytes`)
- **client** — Instance of `Gianfriaur\OpcuaPhpClient\Client` with an active connection
- **endpointUrl** — OPC UA server URL
- **config** — Configuration used for the connection (passwords are never exposed via `list`)
- **lastUsed** — Timestamp of last usage

On every `query` operation, the `lastUsed` field is updated. The periodic timer closes and removes sessions whose `lastUsed` exceeds the configured timeout.

### Method whitelist

Only the following OPC UA client methods can be invoked via the `query` command:

`getEndpoints`, `browse`, `browseWithContinuation`, `browseNext`, `read`, `readMulti`, `write`, `writeMulti`, `call`, `createSubscription`, `createMonitoredItems`, `createEventMonitoredItem`, `deleteMonitoredItems`, `deleteSubscription`, `publish`, `historyReadRaw`, `historyReadProcessed`, `historyReadAtTime`

Note: `connect` and `disconnect` are **not** in the whitelist — they can only be triggered via the `open` and `close` IPC commands, respectively. This prevents a client from reconnecting to a different server or disconnecting another session's connection.

Any other method name is rejected with a `forbidden_method` error.

### Shutdown

On SIGTERM or SIGINT:
1. Disconnects all active OPC UA sessions
2. Closes the Unix socket server
3. Removes the socket file
4. Removes the PID file
5. Stops the event loop
