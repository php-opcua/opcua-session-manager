# Examples

## Basic usage: reading a value

```php
<?php

require 'vendor/autoload.php';

use Gianfriaur\OpcuaSessionManager\Client\ManagedClient;
use Gianfriaur\OpcuaPhpClient\Types\NodeId;

$client = new ManagedClient();
$client->connect('opc.tcp://localhost:4840/UA/Server');

// Read ServerState (ns=0, i=2259)
$dataValue = $client->read(NodeId::numeric(0, 2259));
echo "Server State: " . $dataValue->getValue() . "\n"; // 0 = Running

$client->disconnect();
```

## Browsing the address space

```php
<?php

require 'vendor/autoload.php';

use Gianfriaur\OpcuaSessionManager\Client\ManagedClient;
use Gianfriaur\OpcuaPhpClient\Types\NodeId;

$client = new ManagedClient();
$client->connect('opc.tcp://localhost:4840/UA/Server');

// Browse Objects folder (ns=0, i=85)
$refs = $client->browse(NodeId::numeric(0, 85));

foreach ($refs as $ref) {
    echo sprintf(
        "%s (ns=%d, id=%s) [%s]\n",
        $ref->getBrowseName()->getName(),
        $ref->getNodeId()->getNamespaceIndex(),
        $ref->getNodeId()->getIdentifier(),
        $ref->getNodeClass()->name,
    );
}

$client->disconnect();
```

## Writing values

```php
<?php

require 'vendor/autoload.php';

use Gianfriaur\OpcuaSessionManager\Client\ManagedClient;
use Gianfriaur\OpcuaPhpClient\Types\BuiltinType;
use Gianfriaur\OpcuaPhpClient\Types\NodeId;
use Gianfriaur\OpcuaPhpClient\Types\StatusCode;

$client = new ManagedClient();
$client->connect('opc.tcp://localhost:4840/UA/Server');

$nodeId = NodeId::numeric(2, 1001); // Your writable node

// Single write
$statusCode = $client->write($nodeId, 42, BuiltinType::Int32);
if (StatusCode::isGood($statusCode)) {
    echo "Write OK\n";
} else {
    echo "Error: " . StatusCode::getName($statusCode) . "\n";
}

// Multi write
$results = $client->writeMulti([
    ['nodeId' => NodeId::numeric(2, 1001), 'value' => 100, 'type' => BuiltinType::Int32],
    ['nodeId' => NodeId::numeric(2, 1002), 'value' => 'hello', 'type' => BuiltinType::String],
    ['nodeId' => NodeId::numeric(2, 1003), 'value' => 3.14, 'type' => BuiltinType::Double],
]);

foreach ($results as $i => $code) {
    echo "Item $i: " . (StatusCode::isGood($code) ? 'OK' : StatusCode::getName($code)) . "\n";
}

$client->disconnect();
```

## Multi read

```php
<?php

require 'vendor/autoload.php';

use Gianfriaur\OpcuaSessionManager\Client\ManagedClient;
use Gianfriaur\OpcuaPhpClient\Types\NodeId;
use Gianfriaur\OpcuaPhpClient\Types\StatusCode;

$client = new ManagedClient();
$client->connect('opc.tcp://localhost:4840/UA/Server');

$results = $client->readMulti([
    ['nodeId' => NodeId::numeric(0, 2259)],  // ServerState
    ['nodeId' => NodeId::numeric(0, 2256)],  // ServerStatus
    ['nodeId' => NodeId::numeric(0, 2258)],  // BuildInfo
]);

foreach ($results as $i => $dv) {
    if ($dv->getStatusCode() === StatusCode::Good) {
        echo "Node $i: " . print_r($dv->getValue(), true) . "\n";
    }
}

$client->disconnect();
```

## Method calls

```php
<?php

require 'vendor/autoload.php';

use Gianfriaur\OpcuaSessionManager\Client\ManagedClient;
use Gianfriaur\OpcuaPhpClient\Types\BuiltinType;
use Gianfriaur\OpcuaPhpClient\Types\NodeId;
use Gianfriaur\OpcuaPhpClient\Types\StatusCode;
use Gianfriaur\OpcuaPhpClient\Types\Variant;

$client = new ManagedClient();
$client->connect('opc.tcp://localhost:4840/UA/Server');

$result = $client->call(
    NodeId::numeric(2, 100),  // objectId
    NodeId::numeric(2, 101),  // methodId
    [
        new Variant(BuiltinType::Double, 3.0),
        new Variant(BuiltinType::Double, 4.0),
    ],
);

if (StatusCode::isGood($result['statusCode'])) {
    foreach ($result['outputArguments'] as $arg) {
        echo "Output: " . $arg->getValue() . "\n";
    }
}

$client->disconnect();
```

## Secure connection

```php
<?php

require 'vendor/autoload.php';

use Gianfriaur\OpcuaSessionManager\Client\ManagedClient;
use Gianfriaur\OpcuaPhpClient\Security\SecurityPolicy;
use Gianfriaur\OpcuaPhpClient\Security\SecurityMode;
use Gianfriaur\OpcuaPhpClient\Types\NodeId;

$client = new ManagedClient();

// Configure security (ABSOLUTE PATHS for certificates)
$client->setSecurityPolicy(SecurityPolicy::Basic256Sha256);
$client->setSecurityMode(SecurityMode::SignAndEncrypt);
$client->setClientCertificate(
    '/etc/opcua/certs/client/cert.pem',
    '/etc/opcua/certs/client/key.pem',
    '/etc/opcua/certs/ca/ca-cert.pem',
);

// Username/password authentication
$client->setUserCredentials('admin', 'admin123');

$client->connect('opc.tcp://secure-server:4841/UA/Server');

$value = $client->read(NodeId::numeric(0, 2259));
echo "Server State: " . $value->getValue() . "\n";

$client->disconnect();
```

## Subscriptions and monitored items

```php
<?php

require 'vendor/autoload.php';

use Gianfriaur\OpcuaSessionManager\Client\ManagedClient;
use Gianfriaur\OpcuaPhpClient\Types\NodeId;
use Gianfriaur\OpcuaPhpClient\Types\StatusCode;

$client = new ManagedClient();
$client->connect('opc.tcp://localhost:4840/UA/Server');

// Create subscription
$sub = $client->createSubscription(
    publishingInterval: 500.0,
    lifetimeCount: 2400,
    maxKeepAliveCount: 10,
);
$subId = $sub['subscriptionId'];
echo "Subscription ID: $subId\n";

// Add monitored items
$monResults = $client->createMonitoredItems($subId, [
    ['nodeId' => NodeId::numeric(2, 1001), 'clientHandle' => 1],
    ['nodeId' => NodeId::numeric(2, 1002), 'clientHandle' => 2],
]);

$monIds = [];
foreach ($monResults as $result) {
    if (StatusCode::isGood($result['statusCode'])) {
        $monIds[] = $result['monitoredItemId'];
        echo "Monitored Item ID: {$result['monitoredItemId']}\n";
    }
}

// Publish (collect notifications)
$pub = $client->publish();
echo "Subscription: {$pub['subscriptionId']}, Sequence: {$pub['sequenceNumber']}\n";
echo "Notifications: " . count($pub['notifications']) . "\n";

// Cleanup
$client->deleteMonitoredItems($subId, $monIds);
$client->deleteSubscription($subId);
$client->disconnect();
```

## Session persistence across PHP requests

### Request 1: open session

```php
<?php
// request_open.php
require 'vendor/autoload.php';

use Gianfriaur\OpcuaSessionManager\Client\ManagedClient;

session_start();

$client = new ManagedClient();
$client->connect('opc.tcp://localhost:4840/UA/Server');

// Save the session ID for subsequent requests
$_SESSION['opcua_session_id'] = $client->getSessionId();

echo "OPC UA session opened: " . $client->getSessionId() . "\n";
// Do NOT call disconnect() — the session stays alive in the daemon
```

### Request 2: use existing session

```php
<?php
// request_read.php
require 'vendor/autoload.php';

use Gianfriaur\OpcuaSessionManager\Client\SocketConnection;
use Gianfriaur\OpcuaPhpClient\Types\StatusCode;

session_start();

$sessionId = $_SESSION['opcua_session_id'] ?? null;
if ($sessionId === null) {
    die("No active OPC UA session\n");
}

$response = SocketConnection::send('/tmp/opcua-session-manager.sock', [
    'command' => 'query',
    'sessionId' => $sessionId,
    'method' => 'read',
    'params' => [
        ['ns' => 0, 'id' => 2259, 'type' => 'numeric'],
        13,
    ],
]);

if ($response['success']) {
    echo "Server State: " . $response['data']['value'] . "\n";
} else {
    echo "Error: " . $response['error']['message'] . "\n";

    // Session may have expired — reopen it
    unset($_SESSION['opcua_session_id']);
}
```

### Request 3: close session

```php
<?php
// request_close.php
require 'vendor/autoload.php';

use Gianfriaur\OpcuaSessionManager\Client\SocketConnection;

session_start();

$sessionId = $_SESSION['opcua_session_id'] ?? null;
if ($sessionId !== null) {
    SocketConnection::send('/tmp/opcua-session-manager.sock', [
        'command' => 'close',
        'sessionId' => $sessionId,
    ]);
    unset($_SESSION['opcua_session_id']);
    echo "OPC UA session closed\n";
}
```

## Monitoring: daemon health check

```php
<?php

require 'vendor/autoload.php';

use Gianfriaur\OpcuaSessionManager\Client\SocketConnection;

try {
    $response = SocketConnection::send('/tmp/opcua-session-manager.sock', [
        'command' => 'ping',
    ]);

    if ($response['success']) {
        echo "Daemon OK\n";
        echo "Active sessions: " . $response['data']['sessions'] . "\n";
    }
} catch (\Throwable $e) {
    echo "Daemon unreachable: " . $e->getMessage() . "\n";
    exit(1);
}

// Detailed session list
$response = SocketConnection::send('/tmp/opcua-session-manager.sock', [
    'command' => 'list',
]);

foreach ($response['data']['sessions'] as $session) {
    $lastUsed = date('Y-m-d H:i:s', (int) $session['lastUsed']);
    echo sprintf(
        "  %s -> %s (last used: %s)\n",
        substr($session['id'], 0, 12) . '...',
        $session['endpointUrl'],
        $lastUsed,
    );
}
```

## Error handling

```php
<?php

require 'vendor/autoload.php';

use Gianfriaur\OpcuaSessionManager\Client\ManagedClient;
use Gianfriaur\OpcuaPhpClient\Exception\ConnectionException;
use Gianfriaur\OpcuaPhpClient\Exception\ServiceException;
use Gianfriaur\OpcuaSessionManager\Exception\DaemonException;
use Gianfriaur\OpcuaPhpClient\Types\NodeId;

$client = new ManagedClient();

try {
    $client->connect('opc.tcp://server:4840/UA/Server');
    $value = $client->read(NodeId::numeric(2, 1001));
    echo "Value: " . $value->getValue() . "\n";
} catch (DaemonException $e) {
    // Daemon is unreachable or generic error
    echo "Daemon error: " . $e->getMessage() . "\n";
} catch (ConnectionException $e) {
    // OPC UA connection error
    echo "Connection error: " . $e->getMessage() . "\n";
} catch (ServiceException $e) {
    // OPC UA service error
    echo "Service error: " . $e->getMessage() . "\n";
} finally {
    try {
        $client->disconnect();
    } catch (\Throwable) {
    }
}
```
