---
eyebrow: 'Docs · Daemon'
lede:    'The daemon drives the OPC UA publish loop for you when you wire an event dispatcher. Application code listens to PSR-14 events instead of calling publish() in a loop.'

see_also:
  - { href: './auto-connect.md',                  meta: '5 min' }
  - { href: '../recipes/auto-publish-pattern.md', meta: '6 min' }
  - { href: 'https://github.com/php-opcua/opcua-client/blob/master/docs/observability/events.md', meta: 'external', label: 'opcua-client — events reference' }

prev: { label: 'Auto-connect',          href: './auto-connect.md' }
next: { label: 'Running as a service',  href: './running-as-a-service.md' }
---

# Auto-publish

`publish()` is the OPC UA service call that retrieves queued
notifications from a subscription. By spec, the **client** drives
the loop: send `publish()`, receive the response, send another. The
session manager can run that loop on your behalf — the
`AutoPublisher` — so application code listens for PSR-14 events
instead of polling.

Auto-publish is **opt-in** and **embedded-only**. Enable it by
constructing the daemon with both an `EventDispatcherInterface` and
`autoPublish: true`.

## What it does

For every session that has at least one active subscription, the
`AutoPublisher` schedules a periodic call to `publish()` and
dispatches the resulting `DataChangeReceived` / `EventNotificationReceived`
events through the configured PSR-14 dispatcher.

The publish cadence comes from the session's smallest
`publishingInterval` — typically 250-1000 ms. Sessions without
subscriptions are idle and do not trigger publish calls.

## When to use it

- **Long-lived background workers** that need live data without
  hand-coding the publish loop in every consumer.
- **Event-driven architectures** that already speak PSR-14 — wire
  the daemon's dispatcher into your existing event bus and let
  listeners react.
- **Multi-tenant subscriptions** managed by the daemon (via
  [Auto-connect](./auto-connect.md)) where no application code
  exists to drive the loop manually.

If your application explicitly calls `publish()` in a tight loop
(typical of single-purpose CLI workers), do not turn on auto-publish
— you would be driving the loop twice.

## Wiring

Auto-publish requires the embedded path — the bin script does not
expose it. Construct the daemon with both pieces:

<!-- @code-block language="php" label="examples/auto-publish-daemon.php" -->
```php
use PhpOpcua\SessionManager\Daemon\SessionManagerDaemon;
use Psr\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\EventDispatcher\EventDispatcher;
use PhpOpcua\Client\Event\DataChangeReceived;

$dispatcher = new EventDispatcher();

$dispatcher->addListener(DataChangeReceived::class, function (DataChangeReceived $e) {
    // Your reaction here. Correlate via $e->clientHandle (the
    // value you passed when creating the monitored item) — there
    // is no monitoredItemId on the event.
    persist($e->clientHandle, $e->dataValue);
});

$daemon = new SessionManagerDaemon(
    socketPath: '/var/run/opcua/sessions.sock',
    timeout: 1800,
    cleanupInterval: 60,
    clientEventDispatcher: $dispatcher,
    autoPublish: true,
);

$daemon->autoConnect([
    /* see auto-connect.md */
]);

$daemon->run();
```
<!-- @endcode-block -->

`clientEventDispatcher` is the PSR-14 dispatcher the daemon injects
into every `Client` it constructs. `autoPublish: true` enables the
`AutoPublisher`. Without **both**, the dispatcher is wired but the
publish loop is your responsibility.

## How sessions enter the publish loop

`AutoPublisher::startSession(sessionId)` is called by
`CommandHandler` whenever a `createSubscription` reply is processed
for that session. From that moment on, the autoplisher schedules
`publish()` calls until `stopSession(sessionId)` runs — triggered
by `deleteSubscription` (when the last subscription is removed) or
by session shutdown.

The state model:

<!-- @code-block language="text" label="autoplisher lifecycle" -->
```text
Session opened
   │
   │ first createSubscription
   ▼
AutoPublisher::startSession   ← scheduler now polls publish() every $publishingInterval
   │
   │ deleteSubscription (last one)
   ▼
AutoPublisher::stopSession    ← scheduler stops; session remains open
   │
   │ session close
   ▼
gone
```
<!-- @endcode-block -->

The autoplisher does **not** introduce sessions of its own. It only
schedules calls against sessions other code has opened.

## Events you can listen for

Every PSR-14 event from `opcua-client` is dispatchable through
auto-publish. The most common are:

| Event                       | Fires when                                                   |
| --------------------------- | ------------------------------------------------------------ |
| `DataChangeReceived`        | A data-change notification was delivered                     |
| `EventNotificationReceived` | An event notification was delivered                          |
| `AlarmActivated`            | An alarm transitioned to `Active` (auto-deduced from payload)|
| `AlarmAcknowledged`         | An alarm was acknowledged                                    |
| `PublishResponseReceived`   | Every publish response, including keep-alives                |
| `SubscriptionKeepAlive`     | The server sent an empty publish (no notifications)          |

For the full catalogue, see
[`opcua-client` — events reference](https://github.com/php-opcua/opcua-client/blob/master/docs/observability/events.md).

## What the events carry

Each event payload reaches the listener as a fully-decoded PHP
object — same shape your application would see calling
`publish()` directly. The auto-publish layer does not add or remove
fields.

There is one caveat: the dispatcher runs **inside the daemon
process**. Your listener runs in the daemon's address space, not in
the application's. This means:

- Listeners cannot access request-scoped state.
- Side effects (database writes, queue publishes, HTTP calls)
  happen from the daemon process, with whatever credentials and
  network access it has.
- Listener exceptions propagate inside the daemon — wrap your
  listener body in `try`/`catch` to keep the publish loop healthy.

If you want application-side reactions, your listener typically
**publishes to a queue** (Redis, Beanstalk, SQS) and the
application consumes that queue. Direct synchronous calls from the
listener to application infrastructure are possible but couple the
two processes tightly.

## Cost

Every active subscription schedules one `publish()` per publishing
interval per session. At 250 ms publishing intervals on 10
subscriptions: ~40 publish round-trips per second across the
daemon's OPC UA fan-out. The library buffers internally, so the
listener side does not feel it directly — but the OPC UA server
needs the headroom.

If notifications are dense (alarm storms, high-frequency tag
changes), the `AutoPublisher` keeps calling `publish()` back-to-back
as long as the server reports `moreNotifications: true`. There is no
artificial back-pressure beyond ReactPHP's natural event-loop
fairness.

## When auto-publish is the wrong tool

- **Workers that explicitly run `publish()`.** Two loops, double
  cost, possible double-dispatch.
- **Application that needs notifications in its own process.** The
  daemon's dispatcher does not bridge across processes — you need
  a queue or some other IPC for that.
- **Per-subscription pace control.** All sessions share the same
  autoplisher cadence model (per-subscription `publishingInterval`).
  Custom backoff per session requires hand-driving the loop.

See [Recipes · Auto-publish
pattern](../recipes/auto-publish-pattern.md) for an end-to-end
worker.
