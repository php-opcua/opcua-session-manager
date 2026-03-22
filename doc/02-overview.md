# Overview & Architecture

## The Problem

PHP follows a **request/response model**: each HTTP request spawns a process, executes the script, returns the response, and destroys all state. There is no built-in way to keep a network connection alive between two separate HTTP requests.

OPC UA is a **stateful protocol**. Communicating with an OPC UA server requires a 5-step setup:

1. **TCP connection** — open a socket to the server
2. **Hello/Acknowledge** — OPC UA transport handshake
3. **OpenSecureChannel** — cryptographic secure channel (mandatory even without security)
4. **CreateSession** — server allocates a session ID and nonce
5. **ActivateSession** — authenticate and bind the session

This setup takes **50–200ms** depending on latency and security configuration. Without a session manager, every HTTP request repeats the full handshake.

Beyond latency, reconnecting on every request means:

- **No subscriptions** — you can't subscribe to value changes if the session dies after each request
- **No continuation points** — browse results with pagination are lost between requests
- **Server load** — creating/destroying sessions puts unnecessary load on the OPC UA server
- **Certificate handshake** — with security enabled, the TLS-like handshake adds even more overhead

## The Solution

A long-running daemon (powered by [ReactPHP](https://reactphp.org/)) holds OPC UA sessions in memory. PHP applications communicate with it via a Unix socket IPC protocol. Sessions are automatically cleaned up after a configurable inactivity timeout.

```
┌──────────────┐         ┌──────────────────────────────┐         ┌──────────────┐
│  PHP Request │ ──IPC──►│  Session Manager Daemon       │ ──TCP──►│  OPC UA      │
│  (short-     │◄──IPC── │                              │◄──TCP── │  Server      │
│   lived)     │         │  ● ReactPHP event loop       │         │              │
└──────────────┘         │  ● Sessions in memory        │         └──────────────┘
                         │  ● Periodic cleanup          │
┌──────────────┐         │  ● Signal handlers           │
│  PHP Request │ ──IPC──►│                              │
│  (reuses     │◄──IPC── └──────────────────────────────┘
│   session)   │
└──────────────┘
```

### How it works

1. **First request**: `ManagedClient` sends an `open` command via the Unix socket. The daemon creates a real `Client`, performs the 5-step handshake, and returns a session ID.
2. **Subsequent requests**: `ManagedClient` sends `query` commands referencing the session ID. The daemon looks up the existing `Client` (already connected) and executes the operation directly.
3. **Between requests**: the daemon keeps the TCP connection alive. A periodic timer tracks `lastUsed` timestamps and closes sessions exceeding the inactivity timeout.
4. **Cleanup**: on SIGTERM/SIGINT, the daemon gracefully disconnects all sessions before exiting.

## Components

### SessionManagerDaemon

The long-running process. Listens on a Unix socket, manages sessions, handles cleanup and shutdown. Built on ReactPHP's event loop.

### ManagedClient

Drop-in replacement for `opcua-php-client`'s `Client`. Implements the same `OpcUaClientInterface`. Translates every method call into a JSON message sent to the daemon via `SocketConnection`.

### CommandHandler

Processes IPC commands inside the daemon. Enforces a method whitelist (37 allowed methods), sanitizes credentials, and maps exceptions.

### TypeSerializer

Bidirectional JSON serialization for all OPC UA types and v3.0.0 DTOs. Handles `NodeId`, `DataValue`, `Variant`, `ReferenceDescription`, `SubscriptionResult`, `CallResult`, `BrowseResultSet`, `PublishResult`, `BrowsePathResult`, `TransferResult`, `MonitoredItemResult`, and all scalar/array types.

### SessionStore

In-memory registry of active sessions with expiration support.

## Requirements

- PHP >= 8.2
- `ext-openssl`
- `ext-pcntl` (recommended, for signal handling)
- [`gianfriaur/opcua-php-client`](https://github.com/GianfriAur/opcua-php-client) ^3.0
