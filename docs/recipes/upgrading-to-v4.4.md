---
eyebrow: 'Docs · Recipes'
lede:    'Bump to v4.4.0. Three new module surfaces ride into ManagedClient — HistoryUpdate (9 methods), File Transfer (10), Aggregate (2) — as explicit typed methods. IPC envelope and existing API are unchanged.'

see_also:
  - { href: '../reference/managed-client-api.md', meta: '8 min' }
  - { href: '../reference/exceptions.md',         meta: '7 min' }
  - { href: 'https://github.com/php-opcua/opcua-client/blob/master/CHANGELOG.md', meta: 'external', label: 'opcua-client CHANGELOG' }

prev: { label: 'Exceptions',                      href: '../reference/exceptions.md' }
next: { label: 'Upgrading to v4.3',               href: './upgrading-to-v4.3.md' }
---

# Upgrading to v4.4

v4.4 is a lock-step release with `php-opcua/opcua-client` v4.4.0. The core gained three new module families — HistoryUpdate, File Transfer, Aggregate — plus a pluggable transport seam. The session manager surfaces every new public method on `ManagedClient` as a typed wrapper so IDE autocomplete and static analysis see them the same way they see the rest of the OPC UA service set.

The IPC envelope did not change. The CLI did not change. The wire-serialization registry mechanism did not change. Existing code keeps working as-is.

## Step 1 — Update Composer

<!-- @code-block language="bash" label="terminal" -->
```bash
composer require php-opcua/opcua-session-manager:^4.4
```
<!-- @endcode-block -->

This pulls v4.4.0 (or whatever the latest patch happens to be) and the matching `opcua-client ^4.4` as a transitive dependency. Pin tighter (`~4.4.0`) if you want to vet patches before they roll out.

## Step 2 — No cache flush needed

Unlike the v4.3 upgrade, the cache codec did **not** change between v4.3 and v4.4. Persistent caches survive the bump untouched.

## Step 3 — Adopt the new typed methods (optional)

The new methods on `ManagedClient` are purely an IDE / static-analysis ergonomics improvement. Code that already uses `__call()` (or that goes through Laravel / Symfony service container) continues to work without changes:

<!-- @code-block language="php" label="works in v4.3 and v4.4" -->
```php
/** @var ManagedClient $client */
$result = $client->historyInsertData($nodeId, $values);  // __call dispatch → invokeRemote
```
<!-- @endcode-block -->

But now there is an explicit signature you can type-hint against, which means PHPStan/Psalm stop complaining about the `__call` fallback:

<!-- @code-block language="php" label="v4.4 typed wrappers" -->
```php
use PhpOpcua\Client\Module\Aggregate\AggregateFunction;
use PhpOpcua\Client\Module\FileTransfer\OpenFileMode;

// HistoryUpdate (Part 11 §6.9) — 9 methods
$results = $client->historyInsertData($nodeId, $values);          // : int[]
$results = $client->historyReplaceData($nodeId, $values);         // : int[]
$results = $client->historyUpdateData($nodeId, $values);          // : int[]
$status  = $client->historyDeleteRawModified($nodeId, $start, $end);  // : int
$results = $client->historyDeleteAtTime($nodeId, $timestamps);    // : int[]
$results = $client->historyInsertEvent($nodeId, $selectFields, $eventData);
$results = $client->historyReplaceEvent($nodeId, $selectFields, $eventData);
$results = $client->historyUpdateEvent($nodeId, $selectFields, $eventData);
$results = $client->historyDeleteEvent($nodeId, $eventIds);

// File Transfer (Part 5 §C.2 / §C.3) — 10 methods
$handle  = $client->openFile($fileNode, OpenFileMode::Read);
$bytes   = $client->readFile($fileNode, $handle, 4096);
$pos     = $client->getFilePosition($fileNode, $handle);
$client->setFilePosition($fileNode, $handle, $position);
$client->writeFile($fileNode, $handle, $data);
$client->closeFile($fileNode, $handle);
$dirNode  = $client->createDirectory($parentDir, 'NewDir');
$created  = $client->createFileInDirectory($parentDir, 'new.bin', requestFileOpen: false);
$client->deleteFileSystemObject($parentDir, $targetNode);
$moved    = $client->moveOrCopyFileSystemObject($parentDir, $source, $targetDir, createCopy: false);

// Aggregate (Part 13) — 2 methods (the core exposes these via __call;
// ManagedClient surfaces them explicitly for the same reason)
$buckets = $client->aggregate($rawValues, $start, $end, intervalMs: 60_000, AggregateFunction::Average);
$buckets = $client->historyAggregate($nodeId, $start, $end, intervalMs: 60_000, AggregateFunction::Average);
```
<!-- @endcode-block -->

The wire side knows how to encode the new parameter types (`OpenFileMode`, `AggregateFunction` enums plus `AggregateOptions`, `CreateFileResult` DTOs) because the daemon's `describe` response advertises them — the same allowlist mechanism every other typed DTO goes through (see [IPC · Type serialization](../ipc/type-serialization.md)).

## Step 4 — Verify the daemon version

<!-- @code-block language="bash" label="terminal — verify" -->
```bash
vendor/bin/opcua-session-manager --version
# → opcua-session-manager 4.4.0
```
<!-- @endcode-block -->

If the output is `4.3.x` or older, the upgrade did not land — check your Composer install path. `--version` has existed since v4.3.0, so if the flag is unrecognised you are on v4.2 or earlier and need to follow [Upgrading to v4.3](./upgrading-to-v4.3.md) first.

## What did not change (visible surface)

- **`ManagedClient` public API.** Every method that worked in v4.3 works in v4.4. Twenty-one **new** methods landed; none of the old ones were removed, renamed, or had their signatures touched.
- **IPC envelope shape.** Still the flat `{command, sessionId?, method?, params?, args?, authToken?}` request and `{success, data | error}` response. The new typed methods reach the daemon through the existing `invoke` command — the `describe` allowlist learned the new names because the underlying `Client::getRegisteredMethods()` returns them.
- **CLI flag names and defaults.** Same set as v4.3.
- **Cache codec.** Still `Cache\WireCacheCodec` (JSON gated by the wire allowlist). No reseed required.

## What did change

### Inherited from `opcua-client` v4.4.0

- **`AggregateModule`** — client-side Part 13 aggregate computation (`Interpolate`, `Minimum`, `Maximum`, `Average`, `Count`) on a raw `DataValue[]` buffer. `aggregate()` and `historyAggregate()` reach the daemon's instance through the IPC layer.
- **`HistoryUpdate`** — 9 new methods on `OpcUaClientInterface` covering Insert / Replace / Update / Remove for data and events.
- **`FileTransferModule`** — OPC UA Part 5 file transfer service set (Open / Read / Write / Close / GetPosition / SetPosition on `FileType` nodes, plus `FileDirectoryType` helpers).
- **`ClientTransportInterface`** — wire transport became pluggable in the core. Companion packages [`opcua-client-ext-reverse-connect`](https://github.com/php-opcua/opcua-client-ext-reverse-connect) and [`opcua-client-ext-transport-https`](https://github.com/php-opcua/opcua-client-ext-transport-https) plug into the same seam. Not directly visible to the session manager today — the daemon constructs the underlying `Client` with the default TCP transport.
- **5 new PSR-14 events** — `HistoryDataUpdated`, `HistoryDataDeleted`, `HistoryEventUpdated`, `HistoryEventDeleted`, `AggregateComputed`. Reachable inside the daemon via the configured `EventDispatcherInterface`.
- **4 new File Transfer events** — `FileOpened`, `FileClosed`, `FileBytesRead`, `FileBytesWritten`.

### Added in `opcua-session-manager` v4.4.0

- **21 new typed methods on `ManagedClient`** wrapping the new core surface above (full list in [Step 3](#step-3--adopt-the-new-typed-methods-optional)).
- **`SessionManagerDaemon::VERSION`** bumped to `'4.4.0'`.
- **CI matrix** bumped to `uanetstandard-test-suite@v1.5.0` (HTTPS Binary on `:4852`, SKS on `:4851`, ECC servers on `:4848`/`:4849`, and the open62541-backed `historizing` server used by the new HistoryUpdate integration tests).

See the [full CHANGELOG](https://github.com/php-opcua/opcua-session-manager/blob/master/CHANGELOG.md) for the line-by-line list.

## Compatibility note — client / daemon version skew

The new typed methods are reachable on the daemon only when the daemon is also on v4.4.0 (older daemons do not register the new method names with their `Client::getRegisteredMethods()`). A v4.4 `ManagedClient` against a v4.3 daemon raises `BadMethodCallException` from `invokeRemote()` when the application calls one of the 21 new methods. Older `ManagedClient` instances (≤ v4.3.1) against a v4.4 daemon continue to work — they reach the new methods through `__call()` if and only if the application code knows about them; otherwise they ignore them entirely.

**Upgrade order**: daemon first, then `ManagedClient` instances. Production deployments running both should plan a brief window where the daemon advertises v4.4 methods that the older clients don't call.

## Rollback

The IPC envelope and the API surface are backward-compatible. Rolling back to v4.3 is safe **if** you have not started using any of the 21 new typed methods at the application layer (they will simply not be present on the older `ManagedClient`).

<!-- @code-block language="bash" label="terminal — rollback" -->
```bash
composer require php-opcua/opcua-session-manager:^4.3
```
<!-- @endcode-block -->

Persistent caches survive the rollback because the codec did not change.
