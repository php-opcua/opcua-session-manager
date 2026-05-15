---
eyebrow: 'Docs · Extensibility'
lede:    'Custom ServiceModules registered on the daemon are reachable from ManagedClient out of the box. ::__call() routes unknown methods through invoke; the Wire registry handles the typed payloads.'

see_also:
  - { href: './custom-param-deserializer.md',     meta: '6 min' }
  - { href: '../ipc/commands.md',                 meta: '7 min' }
  - { href: 'https://github.com/php-opcua/opcua-client/blob/master/docs/extensibility/modules.md', meta: 'external', label: 'opcua-client — module system' }

prev: { label: 'Custom param deserializer',  href: './custom-param-deserializer.md' }
next: { label: 'Testing',                    href: '../testing/overview.md' }
---

# Third-party modules

`opcua-client` exposes a module system: `ServiceModule` subclasses
register methods on the client and can ship their own DTOs. When
the daemon embeds the client, those modules are available
server-side; when `ManagedClient` reaches a method it does not
declare, the daemon's `invoke` path handles dispatch — no daemon
patching required.

This page is the integration pattern. For writing the module
itself, see the
[opcua-client module-system reference](https://github.com/php-opcua/opcua-client/blob/master/docs/extensibility/modules.md).

## The integration loop

<!-- @steps -->
- **Write a `ServiceModule`** in your application (or in a
  reusable package). The module declares its methods and DTOs.

- **Make sure the DTOs implement `WireSerializable`.** This is
  what lets typed args and results cross the IPC boundary safely.

- **Register the module on the daemon's client** at daemon
  construction time. The daemon embeds the client; you control
  what modules it loads.

- **Call the method via `ManagedClient`** as you would any other.
  `__call()` routes the unknown name through `invoke`; the daemon
  dispatches to your handler; the typed result comes back.
<!-- @endsteps -->

No changes to `CommandHandler`, no entries added to
`ALLOWED_METHODS`, no custom `ParamDeserializerInterface`.

## A worked example

Suppose your application ships a `QueryFirstModule` that exposes a
`queryFirst()` method against servers that implement OPC UA's
Query service set (not part of the default modules).

### The module side (in your package or app code)

<!-- @code-block language="php" label="examples/QueryFirstModule.php" -->
```php
namespace App\Opcua\Query;

use PhpOpcua\Client\Module\ServiceModule;
use PhpOpcua\Client\Wire\WireTypeRegistry;

final class QueryFirstModule extends ServiceModule
{
    public function register(): void
    {
        // Methods are registered on the concrete Client — ServiceModule
        // exposes it as $this->client. The kernel ($this->kernel) is for
        // infrastructure operations only (executeWithRetry, dispatch, …).
        $this->client->registerMethod('queryFirst', $this->handleQueryFirst(...));
    }

    public function handleQueryFirst(array $filter, int $maxNodes): QueryFirstResult
    {
        // Issue the OPC UA Query service via the kernel; return a typed DTO.
        // …
    }

    public function registerWireTypes(WireTypeRegistry $registry): void
    {
        $registry->register(QueryFirstResult::wireTypeId(), QueryFirstResult::class);
    }
}
```
<!-- @endcode-block -->

`QueryFirstResult` is a `final readonly` class that implements
`WireSerializable`. See [opcua-client wire serialization](https://github.com/php-opcua/opcua-client/blob/master/docs/extensibility/wire-serialization.md)
for the contract.

### The daemon side

Module registration happens **before** the daemon is constructed.
You write a small custom launcher that wires the module into the
`ClientBuilder` the daemon will use:

<!-- @code-block language="php" label="bin/my-opcua-daemon" -->
```php
#!/usr/bin/env php
<?php
require __DIR__ . '/../vendor/autoload.php';

use PhpOpcua\SessionManager\Daemon\SessionManagerDaemon;

// SessionManagerDaemon does NOT currently expose a clientFactory
// argument: every Client it constructs is built internally by
// CommandHandler::buildClientFromConfig() with a vanilla
// ClientBuilder. To make a module reachable through `invoke` on
// the packaged daemon, the module has to be registered by the
// underlying opcua-client at the autoloader level — for example
// via ClientBuilder's default-module hook (see opcua-client docs).
// If your module cannot be wired that way, the only option today
// is to fork SessionManagerDaemon / CommandHandler and inject a
// custom ClientBuilder factory in buildClientFromConfig().

$daemon = new SessionManagerDaemon(
    socketPath: '/var/run/opcua/sessions.sock',
);

$daemon->run();
```
<!-- @endcode-block -->

<!-- @callout variant="warning" -->
There is no `clientFactory` parameter on `SessionManagerDaemon` in
the current release. Attempting `new SessionManagerDaemon(...,
clientFactory: fn() => ...)` fails with "Unknown named parameter
$clientFactory". Treat any third-party module registration as
requiring upstream coordination (a default-module hook on
`ClientBuilder`) or a local fork until a public extension surface
ships.
<!-- @endcallout -->

### The application side

<!-- @code-block language="php" label="examples/call-third-party.php" -->
```php
use PhpOpcua\SessionManager\Client\ManagedClient;

$client = new ManagedClient('/var/run/opcua/sessions.sock');
$client->connect('opc.tcp://plc.local:4840');

// queryFirst() is not on OpcUaClientInterface — __call() routes
// it through invoke automatically.
$result = $client->queryFirst($filter, maxNodes: 100);

// $result is a QueryFirstResult instance, fully decoded by the
// Wire registry round-trip.
```
<!-- @endcode-block -->

The application never sees the IPC plumbing. It calls
`$client->queryFirst()`; the result is a typed PHP object on its
side; the actual OPC UA call ran in the daemon process.

## How `ManagedClient::__call()` decides to use `invoke`

When the application calls a method `ManagedClient` does not
declare:

<!-- @steps -->
- **`__call()` consults `hasMethod($name)`.**

  This in turn consults the cached `describe` response, populated
  on first introspective call. If the daemon's client did not
  register a method by that name, `__call()` raises
  `BadMethodCallException` — the daemon never sees the call.

- **If the method exists, `__call()` routes through `invoke`.**

  Typed args are Wire-encoded against the registry built from the
  daemon's `describe` response. The result comes back wire-encoded
  and is decoded the same way.

- **Cached `describe` becomes stale only on IPC disconnect.**

  Within a session, the method set is fixed. Cross-session, a
  daemon restart with a different module list refreshes the
  describe on the next `connect()`.
<!-- @endsteps -->

## Why this matters

Before v4.2.0, the only way to expose a third-party method through
the daemon was to patch `CommandHandler::ALLOWED_METHODS` and write
a matching entry in the param deserializer. That coupled module
authors to the session-manager package — a non-starter for
extension authors.

The Wire-registry-gated `invoke` path makes third-party modules
**transparent**: an extension that follows the
`WireSerializable` contract works end-to-end without any
session-manager-specific code.

## Constraints

- **DTOs must implement `WireSerializable`.** Plain arrays travel
  too — JSON handles them — but typed objects need the
  discriminator-based decoding the Wire registry provides.
- **Server-side dispatch is synchronous.** Each `invoke` call
  blocks the daemon's IPC handling for that connection until the
  underlying OPC UA call returns. Long-running method handlers
  hold up other commands on the same connection (not on the
  process — other connections are unaffected).
- **No streaming results.** A method that wants to push many
  results needs to return them all at once. For streaming,
  subscriptions + auto-publish is the canonical pattern.

## When to fall back to `query` + a custom deserializer

Two cases:

- You are **patching a built-in method** whose param shape
  changed and the daemon version in production cannot yet be
  upgraded. Register a `ParamDeserializerInterface` for the
  patched method.
- You are **integrating a non-PHP client** that prefers the
  legacy untyped `query` shape. Write a `ParamDeserializerInterface`
  so the daemon decodes whatever your client sends.

For new code in PHP, `invoke` is the path of least resistance.
