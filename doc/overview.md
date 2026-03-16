# OPC UA PHP Client Session Manager

## Problem

PHP follows the request/response model: each HTTP request spawns a process that terminates once the response is complete. OPC UA, on the other hand, requires persistent sessions: opening a secure channel, creating and activating a session, and maintaining a TCP connection.

Without a session manager, every PHP request must:
1. Open a TCP connection
2. Perform the OPC UA handshake (Hello/Acknowledge)
3. Open a secure channel
4. Create and activate a session
5. Execute the desired operation
6. Close everything

This overhead (typically 50-200ms) makes frequent-call scenarios impractical.

## Solution

The session manager solves the problem by separating the OPC UA session lifecycle from the PHP request lifecycle:

```
┌─────────────┐     Unix Socket IPC      ┌───────────────────┐     OPC UA TCP      ┌────────────┐
│  PHP Request │ ◄──────────────────────► │  Session Manager  │ ◄────────────────► │  OPC UA    │
│  (ManagedClient)                        │  Daemon           │                     │  Server    │
└─────────────┘                           │  (ReactPHP)       │                     └────────────┘
                                          │                   │
┌─────────────┐     Unix Socket IPC      │  In-memory        │     OPC UA TCP      ┌────────────┐
│  PHP Request │ ◄──────────────────────► │  sessions with    │ ◄────────────────► │  OPC UA    │
│  (ManagedClient)                        │  timeout          │                     │  Server    │
└─────────────┘                           └───────────────────┘                     └────────────┘
```

- The **daemon** (a long-running ReactPHP process) keeps OPC UA connections alive
- The **ManagedClient** (used in PHP requests) communicates with the daemon via Unix socket
- Sessions are automatically closed after a configurable inactivity period (default: 10 minutes)

## Components

### Daemon (`bin/opcua-session-manager`)

A long-running PHP process that:
- Listens on a Unix domain socket
- Manages a pool of in-memory OPC UA sessions
- Executes OPC UA operations on behalf of clients
- Periodically cleans up expired sessions
- Shuts down gracefully on SIGTERM/SIGINT

### ManagedClient (`Gianfriaur\OpcuaSessionManager\Client\ManagedClient`)

Drop-in replacement for `Gianfriaur\OpcuaPhpClient\Client`. Implements the same `OpcUaClientInterface`, but instead of communicating directly with the OPC UA server, it sends commands to the daemon via Unix socket.

### TypeSerializer (`Gianfriaur\OpcuaSessionManager\Serialization\TypeSerializer`)

Converts OPC UA types (NodeId, DataValue, Variant, etc.) to JSON and back for IPC transport.

## Requirements

- PHP >= 8.2
- ext-pcntl (recommended, for signal handling in the daemon)
- `gianfriaur/opcua-php-client`
- `react/event-loop` ^1.5
- `react/socket` ^1.16
