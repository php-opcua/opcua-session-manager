# IPC Protocol

Communication between `ManagedClient` and the daemon uses a Unix domain socket with a JSON line-delimited protocol.

## Transport

- **Socket type**: Unix domain socket
- **Encoding**: JSON + `\n` as delimiter
- **Model**: request/response — one command per connection, connection closed after response
- **Max request size**: 1MB
- **Connection timeout**: 30 seconds

## Authentication

If the daemon is started with an auth token, every request must include it:

```json
{"command": "ping", "authToken": "my-secret-token"}
```

The token is validated with timing-safe `hash_equals()`. On failure:

```json
{"success": false, "error": {"type": "auth_failed", "message": "Invalid or missing auth token"}}
```

## Response Format

### Success

```json
{"success": true, "data": ...}
```

### Error

```json
{"success": false, "error": {"type": "error_type", "message": "error message"}}
```

Error types: `unknown_command`, `session_not_found`, `forbidden_method`, `max_sessions_reached`, `too_many_connections`, `connection_timeout`, `payload_too_large`, `invalid_json`, `auth_failed`, `ConnectionException`, `ServiceException`.

## Commands

### ping

Health check.

```json
{"command": "ping"}
```

```json
{"success": true, "data": {"status": "ok", "sessions": 3, "time": 1711123456.789}}
```

### list

List all active sessions (credentials sanitized).

```json
{"command": "list"}
```

```json
{
  "success": true,
  "data": {
    "count": 1,
    "sessions": [
      {
        "id": "a1b2c3d4...",
        "endpointUrl": "opc.tcp://localhost:4840",
        "lastUsed": 1711123456.789,
        "config": {"securityPolicy": "http://opcfoundation.org/UA/SecurityPolicy#None"}
      }
    ]
  }
}
```

### open

Create a new session.

```json
{
  "command": "open",
  "endpointUrl": "opc.tcp://localhost:4840",
  "config": {
    "opcuaTimeout": 10.0,
    "autoRetry": 3,
    "batchSize": 50,
    "securityPolicy": "http://opcfoundation.org/UA/SecurityPolicy#Basic256Sha256",
    "securityMode": 3,
    "username": "admin",
    "password": "secret",
    "clientCertPath": "/certs/client.pem",
    "clientKeyPath": "/certs/client.key"
  }
}
```

```json
{"success": true, "data": {"sessionId": "a1b2c3d4..."}}
```

### close

Close a session and disconnect from the OPC UA server.

```json
{"command": "close", "sessionId": "a1b2c3d4..."}
```

```json
{"success": true, "data": null}
```

### query

Execute an OPC UA operation on an existing session.

```json
{
  "command": "query",
  "sessionId": "a1b2c3d4...",
  "method": "read",
  "params": [{"ns": 0, "id": 2259, "type": "numeric"}, 13]
}
```

```json
{
  "success": true,
  "data": {
    "value": 0,
    "type": 6,
    "dimensions": null,
    "statusCode": 0,
    "sourceTimestamp": "2024-01-15T10:30:00+00:00",
    "serverTimestamp": "2024-01-15T10:30:01+00:00"
  }
}
```

### Allowed Query Methods

37 methods are whitelisted for `query`:

**Browse**: `browse`, `browseWithContinuation`, `browseNext`, `browseAll`, `browseRecursive`
**Path**: `translateBrowsePaths`, `resolveNodeId`
**Read/Write**: `read`, `readMulti`, `write`, `writeMulti`
**Call**: `call`
**Subscriptions**: `createSubscription`, `createMonitoredItems`, `createEventMonitoredItem`, `deleteMonitoredItems`, `deleteSubscription`, `publish`, `transferSubscriptions`, `republish`
**History**: `historyReadRaw`, `historyReadProcessed`, `historyReadAtTime`
**State**: `isConnected`, `getConnectionState`, `reconnect`
**Config queries**: `getTimeout`, `getAutoRetry`, `getBatchSize`, `getDefaultBrowseMaxDepth`, `getServerMaxNodesPerRead`, `getServerMaxNodesPerWrite`
**Discovery/Cache**: `getEndpoints`, `discoverDataTypes`, `invalidateCache`, `flushCache`

## Direct Interaction

You can interact with the daemon directly using `SocketConnection`:

```php
use PhpOpcua\SessionManager\Client\SocketConnection;

$response = SocketConnection::send('/tmp/opcua-session-manager.sock', [
    'command' => 'ping',
]);

echo $response['data']['status']; // "ok"
```

### Debugging with netcat

```bash
echo '{"command":"ping"}' | nc -U /tmp/opcua-session-manager.sock
```
