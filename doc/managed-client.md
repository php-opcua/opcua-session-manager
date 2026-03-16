# ManagedClient

`ManagedClient` is the proxy client that PHP applications use instead of the direct `Client`. It implements `OpcUaClientInterface`, making it a drop-in replacement.

## Basic usage

```php
use Gianfriaur\OpcuaSessionManager\Client\ManagedClient;
use Gianfriaur\OpcuaPhpClient\Types\NodeId;

// Create the client (points to the daemon)
$client = new ManagedClient('/tmp/opcua-session-manager.sock');

// Connect (the daemon opens the OPC UA session)
$client->connect('opc.tcp://my-server:4840/UA/Server');

// Use it like a regular Client
$refs = $client->browse(NodeId::numeric(0, 85));
$value = $client->read(NodeId::numeric(2, 1001));

// Disconnect (the daemon closes the OPC UA session)
$client->disconnect();
```

## Constructor

```php
new ManagedClient(
    string $socketPath = '/tmp/opcua-session-manager.sock',
    float $timeout = 30.0,
    ?string $authToken = null,
)
```

| Parameter | Default | Description |
|-----------|---------|-------------|
| `$socketPath` | `/tmp/opcua-session-manager.sock` | Path to the daemon's Unix socket |
| `$timeout` | `30.0` | Timeout in seconds for each IPC request |
| `$authToken` | `null` | Shared secret for daemon authentication (must match daemon's `--auth-token`) |

When `$authToken` is provided, it is automatically included in every IPC request. The daemon validates it using a timing-safe comparison (`hash_equals`).

## Security configuration

Configuration methods are identical to `Client`:

```php
use Gianfriaur\OpcuaPhpClient\Security\SecurityPolicy;
use Gianfriaur\OpcuaPhpClient\Security\SecurityMode;

$client = new ManagedClient();

// Security policy and mode
$client->setSecurityPolicy(SecurityPolicy::Basic256Sha256);
$client->setSecurityMode(SecurityMode::SignAndEncrypt);

// Client certificate (for the secure channel)
$client->setClientCertificate(
    '/path/to/client/cert.pem',
    '/path/to/client/key.pem',
    '/path/to/ca/cert.pem',  // optional
);

// Username/password authentication
$client->setUserCredentials('admin', 'admin123');

// User certificate authentication
$client->setUserCertificate(
    '/path/to/user/cert.pem',
    '/path/to/user/key.pem',
);

$client->connect('opc.tcp://secure-server:4840/UA/Server');
```

> **Important**: Certificate paths must be **absolute**. The daemon resolves them from its own working directory, not from the PHP application's directory.

## Operational methods

All `OpcUaClientInterface` methods are supported:

### Connection

| Method | Description |
|--------|-------------|
| `connect(string $endpointUrl): void` | Opens an OPC UA session via the daemon |
| `disconnect(): void` | Closes the session |

### Browse

| Method | Description |
|--------|-------------|
| `browse(NodeId, ...)` | Browse a node's children |
| `browseWithContinuation(NodeId, ...)` | Browse with continuation point |
| `browseNext(string $continuationPoint)` | Continue a previous browse |

### Read/Write

| Method | Description |
|--------|-------------|
| `read(NodeId, int $attributeId = 13)` | Read an attribute |
| `readMulti(array $items)` | Read multiple attributes in a single request |
| `write(NodeId, mixed $value, BuiltinType $type)` | Write a value |
| `writeMulti(array $items)` | Write multiple values in a single request |

### Method Call

| Method | Description |
|--------|-------------|
| `call(NodeId $objectId, NodeId $methodId, Variant[] $args)` | Call an OPC UA method |

### Subscription

| Method | Description |
|--------|-------------|
| `createSubscription(...)` | Create a subscription |
| `createMonitoredItems(int $subId, array $items)` | Add monitored items |
| `createEventMonitoredItem(...)` | Add an event monitored item |
| `deleteMonitoredItems(int $subId, int[] $ids)` | Remove monitored items |
| `deleteSubscription(int $subId)` | Delete a subscription |
| `publish(array $acks)` | Execute a publish request |

### History Read

| Method | Description |
|--------|-------------|
| `historyReadRaw(...)` | Raw historical read |
| `historyReadProcessed(...)` | Historical read with aggregation |
| `historyReadAtTime(...)` | Historical read at specific timestamps |

### Endpoints

| Method | Description |
|--------|-------------|
| `getEndpoints(string $endpointUrl)` | List server endpoints |

## Additional methods

```php
// Get the daemon session ID
$sessionId = $client->getSessionId(); // ?string
```

## Error handling

`ManagedClient` re-throws daemon exceptions by mapping them to the original `opcua-php-client` types:

| Daemon error | Client exception |
|--------------|-----------------|
| `ConnectionException` | `Gianfriaur\OpcuaPhpClient\Exception\ConnectionException` |
| `ServiceException` | `Gianfriaur\OpcuaPhpClient\Exception\ServiceException` |
| `session_not_found` | `ConnectionException` (session expired or not found) |
| Any other | `Gianfriaur\OpcuaSessionManager\Exception\DaemonException` |

```php
use Gianfriaur\OpcuaPhpClient\Exception\ConnectionException;
use Gianfriaur\OpcuaSessionManager\Exception\DaemonException;

try {
    $client->connect('opc.tcp://server:4840');
} catch (ConnectionException $e) {
    // OPC UA connection error (unreachable host, auth failure, etc.)
} catch (DaemonException $e) {
    // Daemon unreachable or generic error
}
```

## Differences from direct Client

| Aspect | `Client` | `ManagedClient` |
|--------|----------|-----------------|
| OPC UA connection | Direct (TCP) | Via daemon (Unix socket IPC) |
| Session persistence | Dies with the PHP process | Survives across requests |
| Per-operation overhead | ~1-5ms | ~5-15ms (IPC + serialization) |
| Connection overhead | ~50-200ms (every request) | ~50-200ms (first time only) |
| Subscription publish | Immediate notifications | Limited by synchronous IPC model |
| Certificate paths | Relative or absolute | Absolute only (resolved by daemon) |

## Session persistence across requests

The main advantage of `ManagedClient` is that the OPC UA session persists across PHP requests. To leverage this:

```php
// Request 1: open the session and save its ID
$client = new ManagedClient();
$client->connect('opc.tcp://server:4840');
$sessionId = $client->getSessionId();
// Save $sessionId in PHP session, cache, database, etc.
$_SESSION['opcua_session'] = $sessionId;
// Do NOT call disconnect() — the session stays alive in the daemon

// Request 2: the OPC UA session is already open in the daemon
// You can use SocketConnection directly if you have the sessionId
use Gianfriaur\OpcuaSessionManager\Client\SocketConnection;

$response = SocketConnection::send('/tmp/opcua-session-manager.sock', [
    'command' => 'query',
    'sessionId' => $_SESSION['opcua_session'],
    'method' => 'read',
    'params' => [
        ['ns' => 0, 'id' => 2259, 'type' => 'numeric'],
        13,
    ],
]);

if ($response['success']) {
    $value = $response['data']['value'];
}
```
