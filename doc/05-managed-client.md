# ManagedClient

`ManagedClient` is the proxy client that PHP applications use instead of the direct `Client`. It implements `OpcUaClientInterface`, making it a drop-in replacement.

## Basic Usage

```php
use PhpOpcua\SessionManager\Client\ManagedClient;

$client = new ManagedClient();
$client->connect('opc.tcp://localhost:4840');

$value = $client->read('i=2259');
echo $value->getValue();

$client->disconnect();
```

## Constructor

```php
$client = new ManagedClient(
    socketPath: '/tmp/opcua-session-manager.sock',  // daemon socket
    timeout: 30.0,                                   // IPC timeout in seconds
    authToken: 'my-secret-token',                    // IPC authentication
);
```

| Parameter | Default | Description |
|-----------|---------|-------------|
| `socketPath` | `/tmp/opcua-session-manager.sock` | Path to the daemon's Unix socket |
| `timeout` | `30.0` | IPC request timeout in seconds |
| `authToken` | `null` | Shared secret for daemon authentication |

## Configuration

All configuration methods are fluent (return `$this`):

```php
$client->setTimeout(10.0);                  // OPC UA operation timeout
$client->setAutoRetry(3);                   // retry on connection failure
$client->setBatchSize(50);                  // manual batch size override
$client->setDefaultBrowseMaxDepth(20);      // recursive browse depth
```

### Security

```php
use PhpOpcua\Client\Security\SecurityPolicy;
use PhpOpcua\Client\Security\SecurityMode;

$client->setSecurityPolicy(SecurityPolicy::Basic256Sha256);
$client->setSecurityMode(SecurityMode::SignAndEncrypt);
$client->setClientCertificate('/certs/client.pem', '/certs/client.key', '/certs/ca.pem');
$client->setUserCredentials('operator', 'secret');
$client->setUserCertificate('/certs/user.pem', '/certs/user.key');
```

### Logging

```php
use Psr\Log\NullLogger;

$client->setLogger($myLogger);     // any PSR-3 logger
$client->getLogger();              // returns LoggerInterface (NullLogger by default)
```

### Cache

```php
$client->setCache($myCacheDriver);          // any PSR-16 cache
$client->getCache();                        // returns ?CacheInterface
$client->invalidateCache('i=85');           // forwarded to daemon
$client->flushCache();                      // forwarded to daemon
```

### Extension Object Repository

```php
$repo = $client->getExtensionObjectRepository();
```

Returns a local `ExtensionObjectRepository` instance.

## Connection

```php
$client->connect('opc.tcp://localhost:4840');
$client->isConnected();                      // bool
$client->getConnectionState();               // ConnectionState enum
$client->reconnect();                        // re-establish connection
$client->disconnect();                       // close session in daemon
```

## String NodeIds

All methods accepting `NodeId` also accept OPC UA string format:

```php
$client->read('i=2259');
$client->read('ns=2;i=1001');
$client->read('ns=2;s=MyNode');
$client->browse('i=85');
$client->write('ns=2;i=1001', 42, BuiltinType::Int32);
$client->call('i=2253', 'i=11492', [...]);
```

## Browse Operations

```php
$refs = $client->browse('i=85', nodeClasses: [NodeClass::Object, NodeClass::Variable]);
$result = $client->browseWithContinuation('i=85');  // returns BrowseResultSet
$result = $client->browseNext($continuationPoint);   // returns BrowseResultSet
$refs = $client->browseAll('i=85');
$tree = $client->browseRecursive('i=85', maxDepth: 3);
```

Browse methods accept a `useCache` parameter (default `true`), forwarded to the daemon.

### Path Resolution

```php
$nodeId = $client->resolveNodeId('/Objects/Server/ServerStatus');
$results = $client->translateBrowsePaths([...]);
```

### Fluent Builder

```php
$results = $client->translateBrowsePaths()
    ->from('i=85')->path('Server', 'ServerStatus')
    ->execute();
```

## Read / Write

```php
$dv = $client->read('i=2259');

$results = $client->readMulti([
    ['nodeId' => 'i=2259'],
    ['nodeId' => 'ns=2;i=1001', 'attributeId' => 4],
]);

$results = $client->readMulti()
    ->node('i=2259')->value()
    ->node('ns=2;i=1001')->displayName()
    ->execute();

// Auto-detection (v4) — type inferred automatically
$status = $client->write('ns=2;i=1001', 42);

// Explicit type (still supported)
$status = $client->write('ns=2;i=1001', 42, BuiltinType::Int32);

$statuses = $client->writeMulti([
    ['nodeId' => 'ns=2;i=1001', 'value' => 42],
    ['nodeId' => 'ns=2;i=1001', 'value' => 42, 'type' => BuiltinType::Int32],
]);
```

## Method Call

```php
$result = $client->call('i=2253', 'i=11492', [
    new Variant(BuiltinType::UInt32, 1),
]);

echo $result->statusCode;
echo $result->outputArguments[0]->value;
```

Returns a `CallResult` DTO.

## Subscriptions

```php
$sub = $client->createSubscription(publishingInterval: 500.0);

$items = $client->createMonitoredItems($sub->subscriptionId, [
    ['nodeId' => 'ns=2;i=1001'],
]);

$response = $client->publish();
echo $response->subscriptionId;

$client->deleteMonitoredItems($sub->subscriptionId, [$items[0]->monitoredItemId]);
$client->deleteSubscription($sub->subscriptionId);
```

### Transfer & Recovery

```php
$results = $client->transferSubscriptions([1, 2], sendInitialValues: true);
$notifications = $client->republish(subscriptionId: 1, retransmitSequenceNumber: 42);
```

## Type Discovery

```php
$count = $client->discoverDataTypes();
$count = $client->discoverDataTypes(namespaceIndex: 2, useCache: false);
```

Forwarded to the daemon's `Client`.

## History

```php
$values = $client->historyReadRaw('ns=2;i=1001',
    startTime: new DateTimeImmutable('-1 hour'),
    endTime: new DateTimeImmutable(),
);

$values = $client->historyReadProcessed('ns=2;i=1001', $start, $end, 3600000.0, 'i=2342');
$values = $client->historyReadAtTime('ns=2;i=1001', $timestamps);
```

## Endpoints

```php
$endpoints = $client->getEndpoints('opc.tcp://localhost:4840');
```

## Session Persistence

The main advantage — reuse sessions across HTTP requests:

```php
// Request 1: open session
$client = new ManagedClient();
$client->connect('opc.tcp://localhost:4840');
$sessionId = $client->getSessionId();
// Store $sessionId in $_SESSION, Redis, database, etc.
// Do NOT call disconnect()

// Request 2: the session is still alive in the daemon
$client = new ManagedClient();
$client->connect('opc.tcp://localhost:4840');
$value = $client->read('i=2259'); // no handshake — instant
```

## Error Handling

Daemon errors are re-thrown as the original `opcua-client` exception types:

```php
use PhpOpcua\Client\Exception\ConnectionException;
use PhpOpcua\Client\Exception\ServiceException;
use PhpOpcua\SessionManager\Exception\DaemonException;

try {
    $value = $client->read('i=2259');
} catch (ConnectionException $e) {
    // connection lost or session expired
} catch (ServiceException $e) {
    // OPC UA server error
} catch (DaemonException $e) {
    // IPC error (socket not found, timeout, auth failed)
}
```

## Comparison with Direct Client

| | Direct `Client` | `ManagedClient` |
|-|-----------------|-----------------|
| Connection | Direct TCP | Via daemon (Unix socket) |
| Session lifetime | Dies with PHP process | Persists across requests |
| Per-operation overhead | ~1–5ms | ~5–15ms |
| Connection overhead | ~50–200ms every request | ~50–200ms first time only |
| Subscriptions | Lost between requests | Maintained by daemon |
