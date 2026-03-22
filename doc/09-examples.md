# Examples

## Reading a Value

```php
use Gianfriaur\OpcuaSessionManager\Client\ManagedClient;

$client = new ManagedClient();
$client->connect('opc.tcp://localhost:4840');

$dv = $client->read('i=2259');
echo "Server state: " . $dv->getValue() . "\n";
echo "Status: " . $dv->statusCode . "\n";

$client->disconnect();
```

## Browsing the Address Space

```php
$client = new ManagedClient();
$client->connect('opc.tcp://localhost:4840');

$refs = $client->browse('i=85');
foreach ($refs as $ref) {
    echo "{$ref->displayName} — {$ref->nodeId} (class: {$ref->nodeClass->name})\n";
}

$client->disconnect();
```

## Recursive Browse

```php
$tree = $client->browseRecursive('i=85', maxDepth: 3);

foreach ($tree as $node) {
    echo "{$node->reference->displayName}\n";
    foreach ($node->getChildren() as $child) {
        echo "  └─ {$child->reference->displayName}\n";
    }
}
```

## Path Resolution

```php
$nodeId = $client->resolveNodeId('/Objects/Server/ServerStatus/State');
$value = $client->read($nodeId);
echo "State: " . $value->getValue() . "\n";
```

## Reading Multiple Values

```php
// Fluent builder
$results = $client->readMulti()
    ->node('i=2259')->value()
    ->node('ns=2;i=1001')->displayName()
    ->node('ns=2;s=Temperature')->value()
    ->execute();

foreach ($results as $dv) {
    echo $dv->getValue() . "\n";
}

// Array style
$results = $client->readMulti([
    ['nodeId' => 'i=2259'],
    ['nodeId' => 'ns=2;i=1001', 'attributeId' => 4],
]);
```

## Writing Values

```php
use Gianfriaur\OpcuaPhpClient\Types\BuiltinType;

$status = $client->write('ns=2;i=1001', 42, BuiltinType::Int32);

// Multiple writes
$statuses = $client->writeMulti([
    ['nodeId' => 'ns=2;i=1001', 'value' => 42, 'type' => BuiltinType::Int32],
    ['nodeId' => 'ns=2;i=1002', 'value' => 3.14, 'type' => BuiltinType::Double],
    ['nodeId' => 'ns=2;i=1003', 'value' => 'hello', 'type' => BuiltinType::String],
]);
```

## Calling a Method

```php
use Gianfriaur\OpcuaPhpClient\Types\Variant;

$result = $client->call(
    'ns=2;i=100',       // object
    'ns=2;i=200',       // method
    [
        new Variant(BuiltinType::Double, 3.0),
        new Variant(BuiltinType::Double, 4.0),
    ],
);

echo "Status: " . $result->statusCode . "\n";
echo "Result: " . $result->outputArguments[0]->value . "\n";
```

## Subscriptions

```php
$sub = $client->createSubscription(publishingInterval: 500.0);

$items = $client->createMonitoredItems($sub->subscriptionId, [
    ['nodeId' => 'ns=2;i=1001', 'samplingInterval' => 250.0],
    ['nodeId' => 'ns=2;i=1002'],
]);

for ($i = 0; $i < 10; $i++) {
    $response = $client->publish([
        ['subscriptionId' => $sub->subscriptionId, 'sequenceNumber' => $i],
    ]);

    foreach ($response->notifications as $notif) {
        if ($notif['type'] === 'DataChange') {
            echo $notif['dataValue']->getValue() . "\n";
        }
    }
}

$client->deleteSubscription($sub->subscriptionId);
```

## Secure Connection

```php
use Gianfriaur\OpcuaPhpClient\Security\SecurityPolicy;
use Gianfriaur\OpcuaPhpClient\Security\SecurityMode;
use Gianfriaur\OpcuaSessionManager\Client\ManagedClient;

$client = new ManagedClient(
    socketPath: '/var/run/opcua-session-manager.sock',
    authToken: trim(file_get_contents('/etc/opcua/daemon.token')),
);

$client->setSecurityPolicy(SecurityPolicy::Basic256Sha256);
$client->setSecurityMode(SecurityMode::SignAndEncrypt);
$client->setClientCertificate('/certs/client.pem', '/certs/client.key', '/certs/ca.pem');
$client->setUserCredentials('operator', 'secret');
$client->connect('opc.tcp://192.168.1.100:4840');

$value = $client->read('ns=2;i=1001');
echo $value->getValue();
```

## Session Persistence Across Requests

```php
// Request 1: open session
$client = new ManagedClient();
$client->connect('opc.tcp://localhost:4840');
$sessionId = $client->getSessionId();
$_SESSION['opcua_session'] = $sessionId;
// Do NOT disconnect — session stays alive in daemon

// Request 2: reuse session (no handshake, ~5ms)
$client = new ManagedClient();
$client->connect('opc.tcp://localhost:4840');
$value = $client->read('i=2259');
echo $value->getValue();
```

## Daemon Health Monitoring

```php
use Gianfriaur\OpcuaSessionManager\Client\SocketConnection;

$response = SocketConnection::send('/tmp/opcua-session-manager.sock', [
    'command' => 'ping',
]);

echo "Status: " . $response['data']['status'] . "\n";
echo "Active sessions: " . $response['data']['sessions'] . "\n";

$response = SocketConnection::send('/tmp/opcua-session-manager.sock', [
    'command' => 'list',
]);

foreach ($response['data']['sessions'] as $session) {
    echo "Session {$session['id']}: {$session['endpointUrl']}\n";
}
```

## Error Handling

```php
use Gianfriaur\OpcuaPhpClient\Exception\ConnectionException;
use Gianfriaur\OpcuaPhpClient\Exception\ServiceException;
use Gianfriaur\OpcuaSessionManager\Exception\DaemonException;

$client = new ManagedClient();

try {
    $client->connect('opc.tcp://localhost:4840');
    $value = $client->read('i=2259');
    echo $value->getValue();
} catch (DaemonException $e) {
    echo "Daemon error: " . $e->getMessage() . "\n";
} catch (ConnectionException $e) {
    echo "Connection lost: " . $e->getMessage() . "\n";
} catch (ServiceException $e) {
    echo "OPC UA error: " . $e->getMessage() . "\n";
} finally {
    try { $client->disconnect(); } catch (\Throwable) {}
}
```
