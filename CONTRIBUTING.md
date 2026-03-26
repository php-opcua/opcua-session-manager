# Contributing to OPC UA Session Manager

## Welcome!

Thank you for considering contributing to this project! Every contribution matters, whether it's a bug report, a feature suggestion, a documentation fix, or a code change. This project is open to everyone, you're welcome here.

If you have any questions or need help getting started, don't hesitate to open an issue. We're happy to help.

## Development Setup

### Requirements

- PHP >= 8.2
- `ext-openssl`
- Composer
- [opcua-test-server-suite](https://github.com/php-opcua/opcua-test-server-suite) (for integration tests)

### Installation

```bash
git clone https://github.com/php-opcua/opcua-session-manager.git
cd opcua-session-manager
composer install
```

### Test Server

Integration tests require the OPC UA test server suite running locally:

```bash
git clone https://github.com/php-opcua/opcua-test-server-suite.git
cd opcua-test-server-suite
docker compose up -d
```

## Running Tests

```bash
# All tests
./vendor/bin/pest

# Unit tests only
./vendor/bin/pest tests/Unit/

# Integration tests only
./vendor/bin/pest tests/Integration/ --group=integration

# A specific test file
./vendor/bin/pest tests/Unit/TypeSerializerTest.php

# With coverage report
php -d pcov.enabled=1 ./vendor/bin/pest --coverage
```

All tests must pass before submitting a pull request.

## Project Structure

```
src/
├── Client/
│   ├── ManagedClient.php       # Drop-in OpcUaClientInterface replacement (IPC proxy)
│   └── SocketConnection.php    # Unix socket IPC transport
├── Daemon/
│   ├── SessionManagerDaemon.php # ReactPHP long-running daemon
│   ├── CommandHandler.php       # IPC command dispatch and security enforcement
│   ├── Session.php              # Immutable session wrapper (Client + metadata)
│   └── SessionStore.php         # In-memory session registry with expiration
├── Serialization/
│   └── TypeSerializer.php       # Bidirectional JSON serialization for OPC UA types and DTOs
└── Exception/
    ├── DaemonException.php      # Daemon communication errors
    ├── SessionNotFoundException.php
    └── SerializationException.php

bin/
└── opcua-session-manager        # CLI entrypoint for the daemon

config/
└── defaults.php                 # Default daemon configuration

tests/
├── Unit/                        # Unit tests (no server or daemon required)
└── Integration/                 # Integration tests (require test server + daemon)
    └── Helpers/TestHelper.php   # Shared test utilities (daemon lifecycle, client helpers)
```

## Design Principles

### Transparent IPC Proxy

`ManagedClient` implements `OpcUaClientInterface` from `php-opcua/opcua-client` and proxies every call to the daemon over a Unix socket. The goal is a drop-in replacement: any code using `Client` should work with `ManagedClient` without changes.

### Security by Default

The daemon enforces a strict method whitelist — only read, browse, subscribe, and query operations are allowed via IPC. Setter methods (`setTimeout`, `setSecurityPolicy`, `setUserCredentials`, etc.) are blocked. Credentials are sanitized from session listings and error messages are truncated and stripped of file paths.

### Stateful Daemon, Stateless Client

The daemon (ReactPHP event loop) keeps OPC UA sessions alive in memory across PHP requests. `ManagedClient` is stateless except for its `sessionId` — it can be constructed, used, and discarded in a single request.

### Public Readonly DTOs

All service response types use `public readonly` properties matching `opcua-client` v4.0.0. `TypeSerializer` handles bidirectional conversion between these DTOs and JSON-safe arrays for IPC transport.

## Guidelines

### Code Style

- Follow the existing code style and conventions
- Use strict types (`declare(strict_types=1)`)
- Use type declarations for parameters, return types, and properties
- Keep methods focused and concise

### Documentation & Comments

- Every class, trait, interface, and enum must have a PHPDoc description
- Every public method must have a PHPDoc block with `@param`, `@return`, `@throws`, and `@see` where applicable
- `@return` and `@param` must be on their own line, not inline with the description
- **Do not add comments inside function bodies.** No `//`, no `/* */`, no section headers. If the code needs a comment to be understood, the method is too complex — split it into smaller, well-named methods instead. The method name and its PHPDoc should be enough to understand what it does.
- Update `CHANGELOG.md` with your changes
- Update `README.md` features list if adding a major feature
- Update `llms.txt` and `llms-full.txt` if the change affects the public API or architecture

### ManagedClient Changes

- Any new public method on `OpcUaClientInterface` must be implemented on `ManagedClient`
- Configuration methods should return `self` for fluent chaining
- All methods accepting a `NodeId` should also accept `string` (OPC UA format: `'i=2259'`, `'ns=2;s=MyNode'`), resolved via `NodeId::parse()` on the client side
- New query methods must be added to `CommandHandler::ALLOWED_METHODS` and `deserializeParams()`

### TypeSerializer Changes

- New DTO types from `opcua-client` must have both `serialize*()` and `deserialize*()` methods
- Use public readonly properties, not deprecated getters
- The generic `serialize()` method must handle the new type via `instanceof`

### CommandHandler Changes

- Only add read/query methods to the whitelist — never setters or state-mutating configuration methods
- Deserialize IPC parameters in `deserializeParams()` to match the real `Client` method signature
- The daemon's `Client` result is serialized via `TypeSerializer::serialize()` automatically

### Testing

- Write unit tests for all new functionality
- Write integration tests for features that interact with an OPC UA server via the daemon
- Use Pest PHP syntax (not PHPUnit)
- Group integration tests with `->group('integration')`
- Use `TestHelper::safeDisconnect()` in `finally` blocks
- Use `TestHelper::startDaemon()` / `TestHelper::stopDaemon()` in `beforeAll` / `afterAll`
- **Code coverage must remain at or above 99.5%.** Pull requests that drop coverage below this threshold will not be merged. Run `php -d pcov.enabled=1 ./vendor/bin/pest --coverage` to check locally before submitting.

### Commits

- Use descriptive commit messages
- Prefix with `[ADD]`, `[UPD]`, `[PATCH]`, `[REF]`, `[DOC]`, `[TEST]` as appropriate

## Pull Request Process

1. Fork the repository and create a feature branch
2. Write your code and tests
3. Ensure all tests pass and coverage is >= 99.5%
4. Update documentation, changelog, and llms files
5. Submit a pull request
6. Wait for review — a maintainer will review your PR, may request changes or ask questions
7. Once approved, your PR will be merged

## Reporting Issues

Use the [issue tracker](https://github.com/php-opcua/opcua-session-manager/issues) to report bugs, request features, or ask questions.
