---
eyebrow: 'Docs · Extensibility'
lede:    'Built-in methods get their params decoded by BuiltInParamDeserializer. For third-party module methods routed through query, register your own ParamDeserializerInterface so the daemon knows how to typed-decode the args.'

see_also:
  - { href: './third-party-modules.md',                       meta: '6 min' }
  - { href: '../ipc/type-serialization.md',                   meta: '6 min' }
  - { href: '../ipc/commands.md',                             meta: '7 min' }

prev: { label: 'Direct interaction',     href: '../ipc/direct-interaction.md' }
next: { label: 'Third-party modules',    href: './third-party-modules.md' }
---

# Custom param deserializer

The IPC `query` path takes positional arguments as JSON-encoded
PHP values. Before dispatching, the daemon must decode the typed
ones — turn `{"ns":2,"id":"PLC/Speed","type":"string"}` into a
`NodeId` object, `{"type":11,"value":42.5}` into a `Variant`. That
decoding is what `ParamDeserializerInterface` implementations do.

`BuiltInParamDeserializer` ships with the package and covers every
method in `CommandHandler::ALLOWED_METHODS`. You implement and
register your own when:

- You ship a custom module that exposes a method through `query`
  (rare — most custom modules go through `invoke` instead).
- A built-in method gains an argument shape the bundled deserializer
  doesn't know about (typically a temporary patch ahead of an
  upstream release).

For methods routed through `invoke`, you don't need a custom
deserializer at all — the Wire registry handles typed args. See
[Third-party modules](./third-party-modules.md).

## The interface

<!-- @code-block language="php" label="ParamDeserializerInterface" -->
```php
namespace PhpOpcua\SessionManager\Serialization;

interface ParamDeserializerInterface
{
    /**
     * Whether this deserializer handles the given method name.
     */
    public function supports(string $method): bool;

    /**
     * Decode the positional params array into typed PHP values
     * suitable for the corresponding ManagedClient method.
     *
     * @param string $method
     * @param array  $params The JSON-decoded args array from the request envelope
     * @return array         The decoded args ready to splat into the method
     */
    public function deserialize(string $method, array $params): array;
}
```
<!-- @endcode-block -->

Two methods, both purely functional. `supports()` is the dispatch
gate — the registry walks deserializers in registration order and
hands the params to the first one whose `supports()` returns
`true`.

## A worked example

You ship a module that exposes a `queryHistoricalAverages()`
method. It takes a `NodeId`, two `DateTimeImmutable`s, and a
custom `AggregateWindow` enum. The wire shape:

<!-- @code-block language="text" label="wire request" -->
```text
{
  "command": "query",
  "sessionId": "a1b2c3...",
  "method": "queryHistoricalAverages",
  "params": [
    {"ns": 2, "id": "Tank42/Level", "type": "string"},
    "2026-05-15T08:00:00+00:00",
    "2026-05-15T12:00:00+00:00",
    "Hourly"
  ],
  "authToken": "..."
}
```
<!-- @endcode-block -->

The default deserializer would leave those args as plain arrays /
strings. Your method expects typed objects. Write a deserializer:

<!-- @code-block language="php" label="examples/HistoryParamDeserializer.php" -->
```php
namespace App\Opcua;

use App\Opcua\AggregateWindow;
use DateTimeImmutable;
use PhpOpcua\Client\Types\NodeId;
use PhpOpcua\SessionManager\Serialization\ParamDeserializerInterface;

final class HistoryParamDeserializer implements ParamDeserializerInterface
{
    public function supports(string $method): bool
    {
        return $method === 'queryHistoricalAverages';
    }

    public function deserialize(string $method, array $params): array
    {
        [$nodeId, $start, $end, $window] = $params;

        return [
            NodeId::{$nodeId['type']}($nodeId['ns'], $nodeId['id']),
            new DateTimeImmutable($start),
            new DateTimeImmutable($end),
            AggregateWindow::from($window),
        ];
    }
}
```
<!-- @endcode-block -->

## Registering

`SessionManagerDaemon` does **not** currently expose its
`CommandHandler` to the outside world (there is no
`commandHandler()` getter, and no constructor parameter to inject
a pre-configured handler or `ParamDeserializerRegistry`). The
underlying `CommandHandler::registerParamDeserializer()` method
exists, but the public daemon API does not provide a way to reach
it.

In practice, with the current public surface, **there is no
runnable example** for registering a custom
`ParamDeserializerInterface` against the packaged
`SessionManagerDaemon`. The two viable paths are:

- **Route the custom method through `invoke` instead of `query`.**
  The `invoke` path uses the Wire registry, which **is**
  extensible from module code via
  `ServiceModule::registerWireTypes()`. A custom param
  deserializer is unnecessary in that flow. This is the
  recommended path — see
  [Third-party modules](./third-party-modules.md).
- **Patch a fork of `SessionManagerDaemon`** to expose the
  `CommandHandler` (a one-line getter, or a `?CommandHandler`
  constructor argument) and then register your deserializer
  between construction and `run()`. Upstream tracks adding a
  public hook; until that ships, the fork is the only way to
  register a custom `ParamDeserializerInterface` against `query`.

<!-- @code-block language="php" label="examples/wire-deserializer.php (illustrative — requires patched daemon)" -->
```php
use PhpOpcua\SessionManager\Daemon\SessionManagerDaemon;
use App\Opcua\HistoryParamDeserializer;

$daemon = new SessionManagerDaemon(/* … */);

// NOTE: $daemon->commandHandler() does NOT exist on the current
// SessionManagerDaemon. The snippet below shows the intended
// shape once a public hook is added.
//
// $daemon->commandHandler()->registerParamDeserializer(
//     new HistoryParamDeserializer(),
// );

$daemon->run();
```
<!-- @endcode-block -->

## Registration order matters

Deserializers are consulted in registration order. The first one
whose `supports()` returns `true` wins. The built-in deserializer
is registered automatically by the daemon's `CommandHandler`
constructor — your custom deserializers go after it.

To **replace** a built-in deserializer for a specific method,
register your custom one **first** and have its `supports()`
return `true` for that method. The built-in is consulted after
yours and never claims a method another deserializer already
handled.

The cleaner pattern: do not overlap with built-in method names.
Pick a method name your module owns.

## The `BuiltInParamDeserializer` shape

The bundled deserializer (`src/Serialization/BuiltInParamDeserializer.php`)
is a single `match` per method against the allowed-method list. Read
its body when in doubt about a built-in's expected param shape — it
is the authoritative source for what each method accepts on the
wire.

A representative excerpt:

<!-- @code-block language="text" label="dispatcher shape" -->
```text
'read' => [
    NodeId from $params[0],            // {ns, id, type} → NodeId
    $params[1] ?? AttributeId::Value,  // int attribute id
    $params[2] ?? false,               // bool refresh
],
'write' => [
    NodeId from $params[0],
    $params[1],                        // mixed value
    isset($params[2]) ? BuiltinType::from($params[2]) : null,
],
'createSubscription' => [
    (float) ($params[0] ?? 500.0),     // float publishingInterval
    (int)   ($params[1] ?? 2400),      // int   lifetimeCount
    (int)   ($params[2] ?? 10),        // int   maxKeepAliveCount
    (int)   ($params[3] ?? 0),         // int   maxNotificationsPerPublish
    (bool)  ($params[4] ?? true),      // bool  publishingEnabled
    (int)   ($params[5] ?? 0),         // int   priority
],
```
<!-- @endcode-block -->

The pattern is consistent: NodeIds via `TypeSerializer::deserializeNodeId()`,
enums via their `from()` constructor, defaults applied with `??`
on missing positional args.

## When to use `invoke` instead

If your custom method is on a third-party module registered via
`ClientBuilder::addModule()` on the daemon, the recommended path is
`invoke`, not `query`. The `invoke` path:

- Does not need a custom param deserializer.
- Uses the Wire registry — type allowlist built from the loaded
  modules.
- Is reached automatically by `ManagedClient::__call()` for any
  method not declared on `OpcUaClientInterface`.

In other words: a `ParamDeserializerInterface` is the right tool
for legacy patching or for stretching the `query` whitelist. New
custom modules should ship `WireSerializable` DTOs and go through
`invoke` — no daemon-side bridge required. See [Third-party
modules](./third-party-modules.md).

## Failure modes

| Cause                                          | IPC error_type             | PHP exception              |
| ---------------------------------------------- | -------------------------- | -------------------------- |
| No deserializer supports the method            | `serialization_error`      | `SerializationException`   |
| `deserialize()` raises an exception            | `serialization_error`      | `SerializationException`   |
| Decoded args don't match the method signature  | `ServiceException` or similar from the underlying call | per OPC UA spec |

The first two surface during the daemon's dispatch and never reach
the OPC UA call; the third is a true OPC UA failure.

A deserializer that throws raises the error inside the
`CommandHandler` and aborts the request — the connection stays
open, the daemon serves the next frame. Wrap your deserializer
body defensively if your input source is untrusted.
