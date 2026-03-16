# IPC Protocol

Communication between `ManagedClient` and the daemon uses a Unix domain socket with a JSON line-delimited protocol.

## Transport

- **Socket**: Unix domain socket (configurable path, default permissions `0600`)
- **Format**: JSON + `\n` (newline as delimiter)
- **Max request size**: 1 MB
- **Model**: Request/Response — each IPC connection handles a single command
- **Flow**: Client opens connection -> sends JSON + `\n` -> receives JSON + `\n` -> connection closed

## Authentication

If the daemon is started with `--auth-token` or `--auth-token-file`, every request must include the token:

```json
{
  "command": "ping",
  "authToken": "your-secret-token-here"
}
```

The token is validated using `hash_equals()` (timing-safe). If missing or invalid, the daemon responds with:

```json
{
  "success": false,
  "error": {
    "type": "auth_failed",
    "message": "Invalid or missing auth token"
  }
}
```

When using `ManagedClient`, the token is injected automatically via the constructor's `$authToken` parameter.

## Response format

All responses follow the same schema:

### Success

```json
{
  "success": true,
  "data": <mixed>
}
```

### Error

```json
{
  "success": false,
  "error": {
    "type": "<string>",
    "message": "<string>"
  }
}
```

Common error types:
- `auth_failed` — Invalid or missing auth token
- `unknown_command` — Unrecognized command
- `invalid_json` — Malformed JSON
- `payload_too_large` — Request exceeds 1MB limit
- `forbidden_method` — Method not in the allowed whitelist
- `max_sessions_reached` — Maximum session limit reached
- `session_not_found` — Session not found or expired
- `ConnectionException` — OPC UA connection error
- `ConfigurationException` — Configuration error
- `ServiceException` — OPC UA service error
- `InvalidArgumentException` — Invalid certificate path or parameter

## Commands

### `ping`

Daemon health check.

**Request:**
```json
{"command": "ping"}
```

**Response:**
```json
{
  "success": true,
  "data": {
    "status": "ok",
    "sessions": 3,
    "time": 1710600000.123
  }
}
```

### `list`

List active sessions.

**Request:**
```json
{"command": "list"}
```

**Response:**
```json
{
  "success": true,
  "data": {
    "count": 2,
    "sessions": [
      {
        "id": "a1b2c3d4e5f6...",
        "endpointUrl": "opc.tcp://server:4840/UA/Server",
        "lastUsed": 1710600000.123,
        "config": {}
      }
    ]
  }
}
```

### `open`

Open a new OPC UA session.

**Request:**
```json
{
  "command": "open",
  "endpointUrl": "opc.tcp://server:4840/UA/Server",
  "config": {
    "securityPolicy": "http://opcfoundation.org/UA/SecurityPolicy#Basic256Sha256",
    "securityMode": 3,
    "username": "admin",
    "password": "admin123",
    "clientCertPath": "/path/to/cert.pem",
    "clientKeyPath": "/path/to/key.pem",
    "caCertPath": "/path/to/ca.pem",
    "userCertPath": "/path/to/user-cert.pem",
    "userKeyPath": "/path/to/user-key.pem"
  }
}
```

All `config` fields are optional.

**Response:**
```json
{
  "success": true,
  "data": {
    "sessionId": "a1b2c3d4e5f6..."
  }
}
```

### `close`

Close an OPC UA session.

**Request:**
```json
{
  "command": "close",
  "sessionId": "a1b2c3d4e5f6..."
}
```

**Response:**
```json
{
  "success": true,
  "data": null
}
```

### `query`

Execute an OPC UA method on an existing session.

**Request:**
```json
{
  "command": "query",
  "sessionId": "a1b2c3d4e5f6...",
  "method": "<method_name>",
  "params": [<parameters>]
}
```

**Response:**
```json
{
  "success": true,
  "data": <serialized_result>
}
```

#### Supported methods and parameters

##### `browse`

```json
{
  "method": "browse",
  "params": [
    {"ns": 0, "id": 85, "type": "numeric"},
    0,
    null,
    true,
    0
  ]
}
```

Parameters: `[nodeId, direction, referenceTypeId, includeSubtypes, nodeClassMask]`

##### `browseWithContinuation`

Same parameters as `browse`. Returns `{references: [...], continuationPoint: ?string}`.

##### `browseNext`

```json
{
  "method": "browseNext",
  "params": ["<base64_continuation_point>"]
}
```

##### `read`

```json
{
  "method": "read",
  "params": [
    {"ns": 2, "id": 1001, "type": "numeric"},
    13
  ]
}
```

Parameters: `[nodeId, attributeId]`. AttributeId 13 = Value (default).

##### `readMulti`

```json
{
  "method": "readMulti",
  "params": [
    [
      {"nodeId": {"ns": 0, "id": 2259, "type": "numeric"}, "attributeId": 13},
      {"nodeId": {"ns": 2, "id": 1001, "type": "numeric"}}
    ]
  ]
}
```

##### `write`

```json
{
  "method": "write",
  "params": [
    {"ns": 2, "id": 1001, "type": "numeric"},
    42,
    6
  ]
}
```

Parameters: `[nodeId, value, builtinTypeId]`. BuiltinType IDs: Boolean=1, SByte=2, Byte=3, Int16=4, UInt16=5, Int32=6, UInt32=7, Int64=8, UInt64=9, Float=10, Double=11, String=12, DateTime=13.

##### `writeMulti`

```json
{
  "method": "writeMulti",
  "params": [
    [
      {"nodeId": {"ns": 2, "id": 1001, "type": "numeric"}, "value": 42, "type": 6},
      {"nodeId": {"ns": 2, "id": 1002, "type": "numeric"}, "value": "hello", "type": 12}
    ]
  ]
}
```

##### `call`

```json
{
  "method": "call",
  "params": [
    {"ns": 2, "id": 100, "type": "numeric"},
    {"ns": 2, "id": 101, "type": "numeric"},
    [
      {"type": 11, "value": 3.0},
      {"type": 11, "value": 4.0}
    ]
  ]
}
```

Parameters: `[objectId, methodId, inputArguments]`. Each input argument is a Variant `{type: builtinTypeId, value: mixed}`.

##### `createSubscription`

```json
{
  "method": "createSubscription",
  "params": [500.0, 2400, 10, 0, true, 0]
}
```

Parameters: `[publishingInterval, lifetimeCount, maxKeepAliveCount, maxNotificationsPerPublish, publishingEnabled, priority]`

##### `createMonitoredItems`

```json
{
  "method": "createMonitoredItems",
  "params": [
    12345,
    [
      {
        "nodeId": {"ns": 2, "id": 1001, "type": "numeric"},
        "clientHandle": 1,
        "samplingInterval": 250.0,
        "queueSize": 1
      }
    ]
  ]
}
```

Parameters: `[subscriptionId, items]`

##### `deleteMonitoredItems`

```json
{
  "method": "deleteMonitoredItems",
  "params": [12345, [1, 2, 3]]
}
```

Parameters: `[subscriptionId, monitoredItemIds]`

##### `deleteSubscription`

```json
{
  "method": "deleteSubscription",
  "params": [12345]
}
```

##### `publish`

```json
{
  "method": "publish",
  "params": [[]]
}
```

Parameters: `[acknowledgements]`. Each acknowledgement: `{subscriptionId: int, sequenceNumber: int}`.

##### `historyReadRaw`

```json
{
  "method": "historyReadRaw",
  "params": [
    {"ns": 2, "id": 2001, "type": "numeric"},
    "2024-01-01T00:00:00+00:00",
    "2024-01-02T00:00:00+00:00",
    100,
    false
  ]
}
```

Parameters: `[nodeId, startTime, endTime, numValuesPerNode, returnBounds]`. Timestamps are ISO 8601 strings or `null`.

##### `historyReadProcessed`

```json
{
  "method": "historyReadProcessed",
  "params": [
    {"ns": 2, "id": 2001, "type": "numeric"},
    "2024-01-01T00:00:00+00:00",
    "2024-01-02T00:00:00+00:00",
    3600000.0,
    {"ns": 0, "id": 2341, "type": "numeric"}
  ]
}
```

Parameters: `[nodeId, startTime, endTime, processingInterval, aggregateType]`

##### `historyReadAtTime`

```json
{
  "method": "historyReadAtTime",
  "params": [
    {"ns": 2, "id": 2001, "type": "numeric"},
    ["2024-01-01T12:00:00+00:00", "2024-01-01T13:00:00+00:00"]
  ]
}
```

Parameters: `[nodeId, timestamps]`

## Example: direct interaction with the daemon

```php
use Gianfriaur\OpcuaSessionManager\Client\SocketConnection;

$socketPath = '/tmp/opcua-session-manager.sock';

// Ping
$response = SocketConnection::send($socketPath, ['command' => 'ping']);
echo $response['data']['status']; // "ok"

// Open session
$response = SocketConnection::send($socketPath, [
    'command' => 'open',
    'endpointUrl' => 'opc.tcp://localhost:4840/UA/Server',
    'config' => [],
]);
$sessionId = $response['data']['sessionId'];

// Read a value
$response = SocketConnection::send($socketPath, [
    'command' => 'query',
    'sessionId' => $sessionId,
    'method' => 'read',
    'params' => [
        ['ns' => 0, 'id' => 2259, 'type' => 'numeric'],
        13,
    ],
]);
echo $response['data']['value']; // 0 (ServerState = Running)

// Close session
SocketConnection::send($socketPath, [
    'command' => 'close',
    'sessionId' => $sessionId,
]);
```

## Example: interaction with netcat (debugging)

```bash
# Ping
echo '{"command":"ping"}' | nc -U /tmp/opcua-session-manager.sock

# List sessions
echo '{"command":"list"}' | nc -U /tmp/opcua-session-manager.sock
```
