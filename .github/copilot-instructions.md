# OPC UA Session Manager — Copilot Instructions

This repository contains `php-opcua/opcua-session-manager`, a daemon-based session persistence layer for the OPC UA PHP client.

## Project context

For a full understanding of this library, read these files in order:

1. **[llms.txt](../llms.txt)** — compact project summary: architecture, classes, API, configuration
2. **[llms-full.txt](../llms-full.txt)** — comprehensive technical reference: every class, method, DTO, IPC protocol, daemon internals
3. **[llms-skills.md](../llms-skills.md)** — task-oriented recipes: install, configure, deploy, persist sessions, subscriptions, security, monitoring

## Architecture

```
PHP Request (short-lived)
    │
    ▼
ManagedClient (OpcUaClientInterface)
    │ JSON over Unix socket
    ▼
SessionManagerDaemon (ReactPHP)
    ├── CommandHandler (method whitelist, security)
    ├── SessionStore (in-memory registry)
    ├── TypeSerializer (JSON ↔ OPC UA types)
    │
    ▼
OPC UA Client (opcua-client)
    │ TCP binary protocol
    ▼
OPC UA Server
```

## Key classes

- `src/Client/ManagedClient.php` — drop-in `OpcUaClientInterface` proxy, IPC to daemon
- `src/Client/SocketConnection.php` — Unix socket JSON transport
- `src/Daemon/SessionManagerDaemon.php` — ReactPHP event loop daemon
- `src/Daemon/CommandHandler.php` — IPC dispatch, 37-method whitelist, security
- `src/Daemon/Session.php` — session wrapper (Client + metadata + subscriptions)
- `src/Daemon/SessionStore.php` — in-memory CRUD registry with expiration
- `src/Serialization/TypeSerializer.php` — bidirectional JSON serialization for all OPC UA types and DTOs

## Code conventions

- `declare(strict_types=1)` in every file
- Public readonly properties on all DTOs (not getters)
- Setter methods return `$this` for fluent chaining
- PHPDoc on every class and public method (`@param`, `@return`, `@throws`, `@see`)
- **No comments inside function bodies** — if code needs a comment, it should be split into well-named methods
- Tests use Pest PHP (not PHPUnit)
- Integration tests grouped with `->group('integration')`
- Coverage target: 99.5%+

## IPC protocol

Commands are newline-delimited JSON over Unix socket. Five commands: `ping`, `list`, `open`, `close`, `query`. The `query` command proxies OPC UA operations through a 37-method whitelist. Responses are `{"success": true, "data": ...}` or `{"success": false, "error": {"type": "...", "message": "..."}}`.

## Dependencies

- `php-opcua/opcua-client` ^4.0 — OPC UA client (required)
- `react/event-loop` ^1.5 — ReactPHP event loop
- `react/socket` ^1.16 — Unix socket server
- `psr/log` ^3.0, `psr/simple-cache` ^3.0, `psr/event-dispatcher` ^1.0 — interface-only
