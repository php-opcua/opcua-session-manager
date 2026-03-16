# Installation

## Requirements

- PHP >= 8.2
- `ext-openssl` extension (required by `opcua-php-client`)
- `ext-pcntl` extension (recommended for daemon signal handling)
- Composer

## Install via Composer

```bash
composer require gianfriaur/opcua-php-client-session-manager
```

## Install from source (development)

```bash
git clone <repository-url> opcua-php-client-session-manager
cd opcua-php-client-session-manager
composer install
```

If working with a local copy of `opcua-php-client`, make sure the path repository in `composer.json` points to the correct directory:

```json
{
  "repositories": [
    {
      "type": "path",
      "url": "../opcua-php-client"
    }
  ]
}
```

## Verify installation

```bash
# Syntax check
find src -name "*.php" -exec php -l {} \;

# Quick test — show daemon help
php bin/opcua-session-manager --help
```

## Project structure

```
opcua-php-client-session-manager/
├── bin/
│   └── opcua-session-manager          # Daemon CLI entry point
├── config/
│   └── defaults.php                   # Default configuration
├── doc/                               # Documentation
├── src/
│   ├── Client/
│   │   ├── ManagedClient.php          # Proxy client (drop-in for Client)
│   │   └── SocketConnection.php       # Unix socket communication helper
│   ├── Daemon/
│   │   ├── CommandHandler.php         # IPC command dispatcher
│   │   ├── Session.php                # Session value object
│   │   ├── SessionManagerDaemon.php   # Event loop and socket server
│   │   └── SessionStore.php           # In-memory session registry
│   ├── Exception/
│   │   ├── DaemonException.php
│   │   ├── SerializationException.php
│   │   └── SessionNotFoundException.php
│   └── Serialization/
│       └── TypeSerializer.php         # OPC UA types <-> JSON conversion
└── tests/
    ├── Integration/                   # Tests with real daemon and OPC UA servers
    └── Unit/                          # Unit tests (no external dependencies)
```
