# Introduction

## What Is This?

`php-opcua/opcua-session-manager` is a daemon-based session manager for [`opcua-client`](https://github.com/php-opcua/opcua-client). It keeps OPC UA connections alive across PHP requests by running a long-lived [ReactPHP](https://reactphp.org/) process that holds sessions in memory, communicating with PHP applications via a Unix socket IPC protocol.

## Why Do You Need It?

PHP's request/response model destroys all state — including network connections — at the end of every request. OPC UA requires a 5-step handshake costing 50–200ms that must be repeated every single time. This library eliminates that overhead: the handshake happens once, and all subsequent requests reuse the existing session.

## Requirements

- PHP >= 8.2
- `ext-openssl`
- `ext-pcntl` (recommended)
- Composer

## Installation

```bash
composer require php-opcua/opcua-session-manager
```

## Quick Start

### 1. Start the daemon

```bash
php bin/opcua-session-manager
```

### 2. Use ManagedClient

```php
use PhpOpcua\SessionManager\Client\ManagedClient;

$client = new ManagedClient();
$client->connect('opc.tcp://localhost:4840');

$value = $client->read('i=2259');
echo $value->getValue();

$refs = $client->browse('i=85');
foreach ($refs as $ref) {
    echo "{$ref->displayName} ({$ref->nodeId})\n";
}

$client->disconnect();
```

`ManagedClient` implements the same `OpcUaClientInterface` as the direct `Client`. Swap one line, keep all your code.

## Features

| Category | Features |
|----------|----------|
| **Session** | Persistence across requests, automatic cleanup, graceful shutdown |
| **OPC UA** | Browse, read, write, method calls, subscriptions, history, path resolution, type discovery |
| **API** | String NodeIds, fluent builders, typed DTO returns, auto-retry, auto-batching |
| **Security** | 10 policies (RSA + ECC), 3 auth modes, IPC auth token, method whitelist, credential stripping |
| **Integrations** | PSR-3 logging, PSR-16 cache, transfer & recovery |

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
OPC UA Client (opcua-client v4.3.0)
    │ TCP binary protocol
    ▼
OPC UA Server
```

## Documentation

| # | Document | Covers |
|---|----------|--------|
| 01 | [Introduction](01-introduction.md) | Overview, requirements, quick start (this page) |
| 02 | [Overview & Architecture](02-overview.md) | Problem, solution, components |
| 03 | [Installation](03-installation.md) | Requirements, Composer, project structure |
| 04 | [Daemon](04-daemon.md) | CLI options, security, systemd/Supervisor, internals |
| 05 | [ManagedClient API](05-managed-client.md) | Full API reference, configuration, session persistence |
| 06 | [IPC Protocol](06-ipc-protocol.md) | Transport, commands, authentication, wire format |
| 07 | [Type Serialization](07-type-serialization.md) | JSON conversion for all OPC UA types and DTOs |
| 08 | [Testing](08-testing.md) | Test infrastructure, helper class, running tests |
| 09 | [Examples](09-examples.md) | Complete code examples for all features |

## Ecosystem

| Package | Description |
|---------|-------------|
| [opcua-client](https://github.com/php-opcua/opcua-client) | Pure PHP OPC UA client — the core protocol implementation |
| [opcua-session-manager](https://github.com/php-opcua/opcua-session-manager) | Session persistence daemon (this package) |
| [laravel-opcua](https://github.com/php-opcua/laravel-opcua) | Laravel integration — service provider, facade, config |
| [uanetstandard-test-suite](https://github.com/php-opcua/uanetstandard-test-suite) | Docker-based OPC UA test servers (UA-.NETStandard) |
