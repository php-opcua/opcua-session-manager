# Installation

## Requirements

- PHP >= 8.2
- `ext-openssl`
- `ext-pcntl` (recommended — enables graceful SIGTERM/SIGINT shutdown)
- Composer

## Composer

```bash
composer require php-opcua/opcua-session-manager
```

This pulls in:
- [`php-opcua/opcua-client`](https://github.com/php-opcua/opcua-client) ^4.3.0 — the core OPC UA client
- `react/event-loop` ^1.5 — ReactPHP event loop for the daemon
- `react/socket` ^1.16 — Unix socket server
- `psr/log` ^3.0 — PSR-3 logging interface (interface-only, zero runtime code)
- `psr/simple-cache` ^3.0 — PSR-16 cache interface (interface-only, zero runtime code)

## Development Setup

```bash
git clone https://github.com/php-opcua/opcua-session-manager.git
cd opcua-session-manager
composer install
```

### Test Servers

Integration tests require the OPC UA test server suite:

```bash
git clone https://github.com/php-opcua/uanetstandard-test-suite.git
cd uanetstandard-test-suite
docker compose up -d
```

## Project Structure

```
src/
├── Client/
│   ├── ManagedClient.php           # Drop-in OpcUaClientInterface proxy
│   └── SocketConnection.php        # Unix socket IPC transport
├── Daemon/
│   ├── SessionManagerDaemon.php    # ReactPHP long-running daemon
│   ├── CommandHandler.php          # IPC command dispatch and security
│   ├── Session.php                 # Session wrapper (Client + metadata)
│   └── SessionStore.php            # In-memory session registry
├── Serialization/
│   └── TypeSerializer.php          # Bidirectional JSON ↔ OPC UA type conversion
└── Exception/
    ├── DaemonException.php         # Daemon communication errors
    ├── SessionNotFoundException.php
    └── SerializationException.php

bin/
└── opcua-session-manager           # CLI daemon entrypoint

config/
└── defaults.php                    # Default configuration values

tests/
├── Unit/                           # No server or daemon required
└── Integration/                    # Requires test servers + daemon
    └── Helpers/TestHelper.php      # Daemon lifecycle and client helpers
```
