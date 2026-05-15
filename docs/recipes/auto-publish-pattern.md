---
eyebrow: 'Docs · Recipes'
lede:    'Run the OPC UA publish loop in the daemon, dispatch PSR-14 events, push notifications onto a queue. Application workers consume the queue without ever talking to OPC UA directly.'

see_also:
  - { href: '../daemon/auto-publish.md',           meta: '6 min' }
  - { href: '../daemon/auto-connect.md',           meta: '5 min' }
  - { href: 'https://github.com/php-opcua/opcua-client/blob/master/docs/operations/subscriptions.md', meta: 'external', label: 'opcua-client — subscriptions' }

prev: { label: 'Persistent sessions in Laravel', href: './persistent-sessions-laravel.md' }
next: { label: 'Healthcheck and monitoring',     href: './healthcheck-and-monitoring.md' }
---

# Auto-publish pattern

The session manager's `AutoPublisher` drives the OPC UA publish
loop on the daemon side. Application workers listen to a queue
the daemon feeds — no application code touches the OPC UA
protocol; no publish loop hand-coded.

This recipe builds the full pipeline: custom daemon launcher,
PSR-14 listener, queue producer, worker consumer.

## When this earns its keep

- **Many subscriptions, one daemon, many consumers.** The daemon
  is the single OPC UA fan-in; the queue is the single fan-out.
- **Reactive architectures.** You want notifications to land in
  a Symfony Messenger / Laravel Horizon worker, not in a `while
  (true) { publish(); }` script.
- **Cross-language consumers.** The queue is the integration
  boundary — a Python worker can consume the same data the daemon
  pushed.

## 1 — Custom daemon launcher

The bin script does not expose auto-publish. Write a small
launcher that constructs the daemon with both
`clientEventDispatcher` and `autoPublish: true`:

<!-- @code-block language="php" label="bin/opcua-daemon-with-pub" -->
```php
#!/usr/bin/env php
<?php
require __DIR__ . '/../vendor/autoload.php';

use PhpOpcua\Client\Cache\InMemoryCache;
use PhpOpcua\Client\Event\AlarmActivated;
use PhpOpcua\Client\Event\DataChangeReceived;
use PhpOpcua\Client\Event\EventNotificationReceived;
use PhpOpcua\SessionManager\Daemon\SessionManagerDaemon;
use PhpOpcua\SessionManager\Logging\StreamLogger;
use Predis\Client as Redis;
use Symfony\Component\EventDispatcher\EventDispatcher;

// 1. PSR-14 dispatcher
$dispatcher = new EventDispatcher();

// 2. Redis as the queue boundary
$redis = new Redis(['scheme' => 'tcp', 'host' => '127.0.0.1', 'port' => 6379]);

// 3. Listeners — turn OPC UA events into queue messages
$dispatcher->addListener(DataChangeReceived::class, function (DataChangeReceived $e) use ($redis) {
    $redis->rpush('opcua:data', json_encode([
        'subscriptionId' => $e->subscriptionId,
        'clientHandle'   => $e->clientHandle,
        'value'          => $e->dataValue->getValue(),
        'statusCode'     => $e->dataValue->statusCode,
        'at'             => $e->dataValue->sourceTimestamp?->format('c'),
    ]));
});

$dispatcher->addListener(AlarmActivated::class, function (AlarmActivated $e) use ($redis) {
    $redis->rpush('opcua:alarms', json_encode([
        'sourceName' => $e->sourceName,
        'severity'   => $e->severity,
        'at'         => date('c'),
    ]));
});

$dispatcher->addListener(EventNotificationReceived::class, function ($e) use ($redis) {
    $redis->rpush('opcua:events', json_encode([
        'clientHandle' => $e->clientHandle,
        'eventFields'  => $e->eventFields,
    ]));
});

// 4. Daemon — wire dispatcher + autoPublish
$daemon = new SessionManagerDaemon(
    socketPath:            getenv('OPCUA_SOCKET_PATH') ?: '/var/run/opcua/sessions.sock',
    timeout:               1800,
    cleanupInterval:       60,
    authToken:             getenv('OPCUA_AUTH_TOKEN') ?: null,
    maxSessions:           200,
    socketMode:            0660,
    logger:                new StreamLogger(getenv('OPCUA_LOG_FILE') ?: 'php://stderr', 'info'),
    clientCache:           new InMemoryCache(600),
    clientEventDispatcher: $dispatcher,
    autoPublish:           true,
);

// 5. Pre-register the subscriptions
$daemon->autoConnect(require __DIR__ . '/../config/opcua-endpoints.php');

// 6. Run
$daemon->run();
```
<!-- @endcode-block -->

## 2 — Configuration file with subscriptions

<!-- @code-block language="php" label="config/opcua-endpoints.php" -->
```php
return [
    'plc1' => [
        'endpoint' => 'opc.tcp://plc-1.plant.local:4840',
        'config'   => [
            'securityPolicy' => 'http://opcfoundation.org/UA/SecurityPolicy#Basic256Sha256',
            'securityMode'   => 3,
            'username'       => 'integrations',
            'password'       => getenv('PLC1_PASSWORD'),
        ],
        'subscriptions' => [
            [
                'publishing_interval' => 500.0,
                'monitored_items' => [
                    ['node_id' => 'ns=2;s=Devices/PLC1/Speed',  'sampling_interval' => 500.0],
                    ['node_id' => 'ns=2;s=Devices/PLC1/Mode',   'sampling_interval' => 1000.0],
                    ['node_id' => 'ns=2;s=Devices/PLC1/Health', 'sampling_interval' => 1000.0],
                ],
            ],
        ],
    ],
];
```
<!-- @endcode-block -->

## 3 — Application-side worker

The application consumes from Redis — no OPC UA dependency at
all in the worker:

<!-- @code-block language="php" label="examples/redis-worker.php" -->
```php
<?php
require __DIR__ . '/vendor/autoload.php';

use Predis\Client as Redis;

$redis = new Redis(['scheme' => 'tcp', 'host' => '127.0.0.1', 'port' => 6379]);

while (true) {
    [$queue, $payload] = $redis->blpop(['opcua:data', 'opcua:alarms', 'opcua:events'], 0);
    $data = json_decode($payload, true);

    match ($queue) {
        'opcua:data'   => persistTagValue($data),
        'opcua:alarms' => triggerPagerduty($data),
        'opcua:events' => recordAuditLog($data),
    };
}
```
<!-- @endcode-block -->

The worker is small, stateless, restartable, and has no idea OPC
UA exists. The daemon is the abstraction boundary.

## 4 — Service unit for the custom launcher

<!-- @code-block language="text" label="/etc/systemd/system/opcua-daemon-with-pub.service" -->
```text
[Unit]
Description=OPC UA Session Manager (with auto-publish)
After=network.target redis-server.service
Requires=redis-server.service

[Service]
Type=simple
User=opcua
EnvironmentFile=/etc/opcua/daemon.env
ExecStart=/opt/myapp/bin/opcua-daemon-with-pub
Restart=on-failure
RestartSec=5
TimeoutStopSec=30

[Install]
WantedBy=multi-user.target
```
<!-- @endcode-block -->

`/etc/opcua/daemon.env` carries the env vars the launcher reads
— `OPCUA_AUTH_TOKEN`, `PLC1_PASSWORD`, `OPCUA_SOCKET_PATH`,
`OPCUA_LOG_FILE`.

## Listener best practices

The listener runs **inside the daemon process**. Three rules:

<!-- @do-dont -->
<!-- @do -->
**Push to a queue, return immediately.** The queue is the
boundary between the daemon's event loop and your application's
processing. A `$redis->rpush()` is ~0.1 ms; everything slower
than that should not happen in the listener.
<!-- @enddo -->
<!-- @dont -->
**Don't block the listener** on a synchronous HTTP call, a slow
database write, or an expensive computation. Every millisecond
the listener spends blocks the publish loop for *every* session
on the daemon — back-pressure that surfaces as missed
notifications.
<!-- @enddont -->
<!-- @enddo-dont -->

Also:

- **Wrap listener bodies in `try`/`catch`.** Unhandled exceptions
  in a PSR-14 listener can cascade into the daemon's loop. Log
  and swallow.
- **Idempotent payloads.** The queue may deliver duplicates if
  the consumer crashes mid-processing — design your worker to
  tolerate it.

## Capacity planning

Each active subscription dispatches one `publish()` per
publishing-interval per session. Worked example:

- 50 sessions, each with 5 subscriptions, each at 500 ms publishing
  interval
- 50 × 5 × 2 = 500 publish round-trips per second (worst case, no
  notification batching)
- Each round-trip ~2-5 ms OPC UA + ~0.1 ms Redis enqueue
- Listener CPU: ~50-200 ms/s total — comfortable on a single core

The daemon's event loop is single-threaded; if your listener load
exceeds what a single core can handle, the architecture needs to
change (shard subscriptions across multiple daemons, push to a
queue earlier with no per-event work).

## Comparison: drive `publish()` yourself

The alternative — every consumer running its own publish loop —
is documented in
[`opcua-client` — subscriptions](https://github.com/php-opcua/opcua-client/blob/master/docs/operations/subscriptions.md).
Use it when:

- The consumer is the only one needing the data (no fan-out)
- The publish cadence is low (≤ 1 Hz) and the worker is already
  long-lived
- You need per-call back-pressure that auto-publish cannot
  provide

For everything fan-out shaped, auto-publish + queue is the right
tool.
