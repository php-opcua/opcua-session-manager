# Changelog

## [4.0.2] - 2026-04-07

### Changed

- Updated CI test server suite from `php-opcua/opcua-test-suite@v1.1.5` to `php-opcua/uanetstandard-test-suite@v1.0.0`.
- Updated all documentation references: `opcua-test-server-suite` → `uanetstandard-test-suite`, `opcua-laravel-client` → `laravel-opcua`.
- Added "Tested against the OPC UA reference implementation" disclaimer to README.
- Added "Versioning" section to README.
- Aligned Ecosystem table with `opcua-client` (added `opcua-cli`, `opcua-client-nodeset`).

## [4.0.1] - 2026-03-30

### Added

- **Automatic session reuse.** When `connect()` is called with an endpoint URL and config that match an already-active session in the daemon, the existing session is returned instead of opening a new one. This eliminates accidental session duplication and makes multi-request persistence seamless — no need to manually track and pass session IDs. The reused session's inactivity timer is refreshed automatically.
- **`connectForceNew(string $endpointUrl): void`** on `ManagedClient` — forces creation of a new session even when a matching one already exists. Use this when you explicitly need parallel sessions to the same server.
- **`wasSessionReused(): bool`** on `ManagedClient` — returns `true` if the last `connect()` call reused an existing daemon session instead of creating a new one.
- **`forceNew` flag** in the IPC `open` command — when `true`, bypasses session reuse and always creates a new session.
- `SessionStore::findByEndpointAndConfig()` — finds an existing session by endpoint URL and sanitized config.
- Unit tests for session reuse: matching endpoint/config, config mismatch, endpoint mismatch, `forceNew` bypass, sensitive config stripping, and touch-on-reuse.

### Changed

- The IPC `open` command response now includes `'reused' => true` when an existing session is returned.

## [4.0.0] - 2026-03-26

### Rebranding

- **Package renamed** from `php-opcua/opcua-client-session-manager` to `php-opcua/opcua-session-manager`.
- **Namespace renamed** from `Gianfriaur\OpcuaSessionManager` to `PhpOpcua\SessionManager`. All classes, tests, and configuration updated accordingly.
- **Dependency renamed** from `gianfriaur/opcua-php-client` to `php-opcua/opcua-client`. Dependency namespace changed from `Gianfriaur\OpcuaPhpClient` to `PhpOpcua\Client`.
- **Repository moved** to [github.com/php-opcua/opcua-session-manager](https://github.com/php-opcua/opcua-session-manager).
- All documentation, URLs, composer.json metadata, and code references updated to reflect the new organization.

### Changed

- **Breaking**: Updated dependency `php-opcua/opcua-client` from `^3.0` to `^4.0`.
- **Breaking**: **ClientBuilder/Client split.** The daemon's `CommandHandler` now uses `ClientBuilder::create()` instead of `new Client()`. All configuration (security, timeout, cache, batching, etc.) is applied to the builder before calling `connect()`, which returns a `Client` instance. This mirrors the upstream v4.0.0 architecture change. No impact on `ManagedClient` consumers — the proxy API remains the same.
- **Breaking**: `write()` type parameter is now nullable (`?BuiltinType $type = null`). When omitted, the daemon's underlying client auto-detects the node's type by reading it first, then caches the result for subsequent writes. Existing code passing an explicit `BuiltinType` continues to work unchanged.
- **Breaking**: `writeMulti()` items can now have a nullable `type` field. When `type` is null or omitted, auto-detection is used per-node.
- `read()` now accepts a third parameter `bool $refresh = false`. When `true`, the daemon bypasses the read metadata cache and forces a server read. Default `false` preserves existing behaviour.
- Method whitelist expanded from 37 to 45 methods to support all new v4.0.0 operations.
- `psr/event-dispatcher` ^1.0 added as dependency (interface-only package, zero runtime code).

### Added

- **`modifyMonitoredItems(int $subscriptionId, array $itemsToModify): MonitoredItemModifyResult[]`** — Change sampling interval, queue size, and other parameters on existing monitored items without recreating them. Proxied to the daemon's underlying `Client`. Returns `MonitoredItemModifyResult[]` with revised parameters.
- **`setTriggering(int $subscriptionId, int $triggeringItemId, array $linksToAdd, array $linksToRemove): SetTriggeringResult`** — Configure a monitored item as a trigger for other items. Linked items are only sampled when the trigger changes. Returns `SetTriggeringResult` with per-link status codes.
- **Trust store support (daemon-side).** Server certificate validation can now be configured through `ManagedClient`:
  - `setTrustStorePath(string)` — Set the file-based trust store path. The daemon creates a `FileTrustStore` instance.
  - `setTrustPolicy(?TrustPolicy)` — Set the validation level: `Fingerprint`, `FingerprintAndExpiry`, or `Full`. Pass `null` to disable.
  - `autoAccept(bool $enabled, bool $force)` — Enable TOFU (Trust On First Use) for unknown server certificates.
  - `trustCertificate(string $certDer)` — Manually trust a DER-encoded certificate (proxied to daemon via IPC).
  - `untrustCertificate(string $fingerprint)` — Remove a certificate from the trust store (proxied to daemon via IPC).
  - `getTrustPolicy(): ?TrustPolicy` — Get the current trust policy.
  - `getTrustStore(): ?TrustStoreInterface` — Returns `null` on `ManagedClient` (trust store lives daemon-side).
- **Write type auto-detection forwarding.** `setAutoDetectWriteType(bool)` on `ManagedClient` configures whether the daemon's `Client` auto-detects write types. Enabled by default.
- **Read metadata cache forwarding.** `setReadMetadataCache(bool)` on `ManagedClient` enables caching of non-Value attributes (DisplayName, BrowseName, DataType, etc.) on the daemon's `Client`.
- **PSR-14 Event Dispatcher interface compliance.** `ManagedClient` now exposes `setEventDispatcher(EventDispatcherInterface)` and `getEventDispatcher()`. Events are dispatched locally on the `ManagedClient` side (daemon-side events are handled by the daemon's own dispatcher). Default: `NullEventDispatcher`.
- `TypeSerializer` now serializes/deserializes `MonitoredItemModifyResult`, `SetTriggeringResult`, and `ExtensionObject` DTOs.
- `TypeSerializer::deserializeBuiltinType()` now accepts `?int` and returns `?BuiltinType` for nullable write type support.
- `TypeSerializer` handles `ExtensionObject` values inside `Variant` deserialization.
- New IPC config keys in the `open` command: `trustStorePath`, `trustPolicy`, `autoAccept`, `autoAcceptForce`, `autoDetectWriteType`, `readMetadataCache`.
- `CommandHandler` now configures `ClientBuilder` with trust store, trust policy, auto-accept, auto-detect write type, and read metadata cache settings from the IPC `open` command.

### Breaking Changes

- Package name changed: `composer require php-opcua/opcua-session-manager` (was `php-opcua/opcua-client-session-manager`).
- Namespace changed: `PhpOpcua\SessionManager\` (was `Gianfriaur\OpcuaSessionManager\`).
- `write()` signature changed from `write(NodeId|string, mixed, BuiltinType)` to `write(NodeId|string, mixed, ?BuiltinType = null)`. The third parameter is now optional.
- Dependency `php-opcua/opcua-client` ^4.0 required (was `php-opcua/opcua-client` ^3.0).

## [3.0.0] - 2026-03-23

### Changed

- **Breaking**: Updated dependency `php-opcua/opcua-client` from `^2.0` to `^3.0`.
- **Breaking**: `nodeClassMask` parameter replaced with `nodeClasses` array. Browse methods (`browse()`, `browseWithContinuation()`, `browseAll()`, `browseRecursive()`) now accept `NodeClass[] $nodeClasses = []` instead of `int $nodeClassMask = 0`. Pass an array of `NodeClass` enum values (e.g. `[NodeClass::Object, NodeClass::Variable]`) instead of a raw bitmask integer. Empty array means all classes (same as the old `0`).
- **Breaking**: Strict return types for all service responses. The following methods now return typed DTOs instead of associative arrays:
  - `createSubscription()` → `SubscriptionResult` (`->subscriptionId`, `->revisedPublishingInterval`, `->revisedLifetimeCount`, `->revisedMaxKeepAliveCount`)
  - `createMonitoredItems()` → `MonitoredItemResult[]` (`->statusCode`, `->monitoredItemId`, `->revisedSamplingInterval`, `->revisedQueueSize`)
  - `createEventMonitoredItem()` → `MonitoredItemResult`
  - `call()` → `CallResult` (`->statusCode`, `->inputArgumentResults`, `->outputArguments`)
  - `browseWithContinuation()` / `browseNext()` → `BrowseResultSet` (`->references`, `->continuationPoint`)
  - `publish()` → `PublishResult` (`->subscriptionId`, `->sequenceNumber`, `->moreNotifications`, `->notifications`, `->availableSequenceNumbers`)
  - `translateBrowsePaths()` → `BrowsePathResult[]` (`->statusCode`, `->targets`) with `BrowsePathTarget` (`->targetId`, `->remainingPathIndex`)
- **Breaking**: Ambiguous `$items` parameters renamed for named parameter clarity: `readMulti($readItems)`, `writeMulti($writeItems)`, `createMonitoredItems($subscriptionId, $monitoredItems)`. Only affects code using named parameters.
- All `TypeSerializer` getters updated to use `public readonly` properties from `opcua-client` v3.0.0 (`$ref->nodeId` instead of `$ref->getNodeId()`, etc.).
- `TypeSerializer` now preserves `Variant` multi-dimensional array dimensions through serialization/deserialization roundtrips.
- Method whitelist expanded from 32 to 37 methods to support all new v3.0.0 operations.

### Added

- **All methods accepting `NodeId` now also accept `string`.** Pass OPC UA string format directly (e.g. `'i=2259'`, `'ns=2;s=MyNode'`). Applies to: `read`, `write`, `browse`, `browseAll`, `browseWithContinuation`, `browseRecursive`, `call` (both params), `historyReadRaw`, `historyReadProcessed`, `historyReadAtTime`, `createEventMonitoredItem`, `resolveNodeId` (`$startingNodeId`), `invalidateCache`. Also works inside arrays for `readMulti`, `writeMulti`, `createMonitoredItems`, `translateBrowsePaths`.
- **Fluent/Builder API** for multi-node operations. `readMulti()`, `writeMulti()`, `createMonitoredItems()`, and `translateBrowsePaths()` now return a fluent builder when called without arguments: `$client->readMulti()->node('i=2259')->value()->node('i=1001')->displayName()->execute()`. The array-based API still works when passing arguments directly.
- **PSR-3 Logging.** `setLogger(LoggerInterface)` / `getLogger()` on `ManagedClient`. Uses `NullLogger` by default.
- **PSR-16 Cache management.** `setCache(?CacheInterface)` / `getCache()` on `ManagedClient`. `invalidateCache(NodeId|string)` and `flushCache()` are forwarded to the daemon's underlying `Client` via IPC.
- **`useCache` parameter** added to `browse()`, `browseAll()`, `getEndpoints()`, and `resolveNodeId()`. Forwarded to the daemon to control cache behaviour per-call.
- **`getExtensionObjectRepository()`** returns a local `ExtensionObjectRepository` instance on `ManagedClient`.
- **`discoverDataTypes(?int $namespaceIndex, bool $useCache)`** — forwarded to the daemon's `Client` to discover and register dynamic codecs for server-defined structured types.
- **`transferSubscriptions(int[] $subscriptionIds, bool $sendInitialValues)`** — transfer existing subscriptions to a new session after reconnection without data loss. Returns `TransferResult[]`.
- **`republish(int $subscriptionId, int $retransmitSequenceNumber)`** — re-request notifications that were sent but not yet acknowledged.
- `psr/log` ^3.0 and `psr/simple-cache` ^3.0 added as dependencies.
- `TypeSerializer` now serializes/deserializes all new v3.0.0 DTO types: `SubscriptionResult`, `MonitoredItemResult`, `CallResult`, `BrowseResultSet`, `PublishResult`, `BrowsePathResult`, `BrowsePathTarget`, `TransferResult`.
- Unit tests for all new DTO serialization roundtrips (SubscriptionResult, MonitoredItemResult, CallResult, BrowseResultSet, PublishResult, BrowsePathResult, TransferResult).
- Unit tests for Variant multi-dimensional dimensions preservation.
- Unit tests for `ManagedClient` Logger, Cache, and ExtensionObjectRepository configuration.
- Unit tests for new setter rejection (`setLogger`, `setCache` blocked via method whitelist).
- **Daemon-side PSR-3 logging.** The daemon now uses a `StreamLogger` (PSR-3 compliant) instead of `echo`. Configure via `--log-file` (default: stderr) and `--log-level` (default: info). The same logger is injected into each OPC UA `Client` created by the daemon, so client-level events (connections, retries, errors) are captured in the daemon's log output.
- **Daemon-side cache configuration.** The daemon's `Client` instances now accept a configurable PSR-16 cache driver. Configure via `--cache-driver` (`memory`, `file`, `none`), `--cache-path` (required for file driver), and `--cache-ttl` (default: 300s). Browse, resolve, endpoint discovery, and type discovery results are cached inside the daemon.
- `StreamLogger` class (`src/Logging/StreamLogger.php`) — minimal PSR-3 logger that writes to a file or stream with configurable minimum log level and `{placeholder}` interpolation.
- **Automatic session recovery with subscription transfer.** When a `query` operation fails with `ConnectionException`, the daemon automatically attempts to reconnect, transfer active subscriptions to the new session via `transferSubscriptions()`, and republish unacknowledged notifications. If recovery succeeds, the original operation is retried transparently. Subscriptions that fail to transfer are removed from tracking. All recovery events are logged.
- `Session` now tracks active subscription IDs. `addSubscription()`, `removeSubscription()`, `getSubscriptionIds()`, `hasSubscriptions()` methods added. Subscriptions are automatically tracked on `createSubscription` and untracked on successful `deleteSubscription`.
- Fixed `getEndpoints()` returning raw arrays instead of `EndpointDescription[]` objects.
- Fixed `republish()` returning `publishTime` as ISO 8601 string instead of `?DateTimeImmutable`.
- Fixed `serializeEndpointDescription()` not including the `serverCertificate` field.

### Breaking Changes

- All service response methods listed above now return typed objects instead of arrays. Code using `$result['key']` must change to `$result->key`.
- Browse methods no longer accept `int $nodeClassMask`. Use `array $nodeClasses` with `NodeClass` enum values instead. Replace `nodeClassMask: 3` with `nodeClasses: [NodeClass::Object, NodeClass::Variable]`.
- `readMulti($items)` renamed to `readMulti($readItems)`, `writeMulti($items)` to `writeMulti($writeItems)`, `createMonitoredItems(..., $items)` to `createMonitoredItems(..., $monitoredItems)`. Only affects code using named parameters.

## [2.0.0] - 2026-03-20

### Changed

- **Breaking**: Updated dependency `php-opcua/opcua-client` from `^1.1` to `^2.0`.
- **Breaking**: `browse()` and `browseWithContinuation()` `$direction` parameter changed from `int` to `BrowseDirection` enum. Replace raw integers (`0`, `1`) with `BrowseDirection::Forward`, `BrowseDirection::Inverse`, or `BrowseDirection::Both`.
- Updated CI test server suite from `opcua-test-server-suite@v1.1.2` to `php-opcua/uanetstandard-test-suite@v1.0.0`.
- Method whitelist expanded from 18 to 32 methods to support all new v2.0.0 operations.

### Added

- **Connection state management.** `isConnected()`, `getConnectionState()`, and `reconnect()` are now available on `ManagedClient`. Connection state (`Disconnected`, `Connected`, `Broken`) is queried from the daemon's underlying `Client`.
- **Configurable timeout.** `setTimeout(float)` / `getTimeout()` — configure the OPC UA operation timeout (default 5s). Applied to the daemon's `Client` before connection.
- **Auto-retry mechanism.** `setAutoRetry(int)` / `getAutoRetry()` — configure automatic reconnection attempts on `ConnectionException`. Default: 0 if never connected, 1 after first successful connection.
- **Automatic batching.** `setBatchSize(int)` / `getBatchSize()` — transparent batching for `readMulti()` and `writeMulti()`. Server operation limits are auto-discovered on connect. `getServerMaxNodesPerRead()` / `getServerMaxNodesPerWrite()` query the discovered values. `setBatchSize(0)` disables batching.
- **BrowseDirection enum.** `BrowseDirection::Forward`, `BrowseDirection::Inverse`, `BrowseDirection::Both` replace raw integer direction parameters.
- **`browseAll()` method.** Automatically follows all continuation points and returns the complete list of `ReferenceDescription` objects.
- **Recursive browsing.** `browseRecursive()` performs a full tree traversal with configurable max depth and cycle detection, returning `BrowseNode[]`. `setDefaultBrowseMaxDepth(int)` / `getDefaultBrowseMaxDepth()` configure the default depth (10). `-1` for unlimited (hardcapped at 256).
- **Path resolution.** `translateBrowsePaths()` translates browse paths to NodeIds. `resolveNodeId(string $path)` resolves human-readable paths like `/Objects/Server/ServerStatus` with support for namespaced segments (`2:Temperature`).
- **BrowseNode serialization.** `TypeSerializer` now serializes/deserializes `BrowseNode` trees, `BrowseDirection` enum, and `ConnectionState` enum.
- **New IPC config keys.** `open` command now accepts `opcuaTimeout`, `autoRetry`, `batchSize`, and `defaultBrowseMaxDepth` in the `config` payload.
- Unit tests for new types: `BrowseDirection`, `BrowseNode`, `ConnectionState` serialization roundtrips (18 new assertions).
- Unit tests for `ManagedClient` configuration: timeout, auto-retry, batching, browse depth, connection state (15 new tests).
- Unit tests for setter rejection via method whitelist (`setTimeout`, `setAutoRetry`, etc. are blocked).
- Integration tests for: browse recursive (6 tests), translate browse path (4 tests), connection state (5 tests), timeout/batching/auto-retry (6 tests).

## [1.1.0] - 2026-03-18

### Changed

- Updated dependency `php-opcua/opcua-client` from `^1.0` to `^1.1`, requiring the new auto-generated certificate feature introduced in that release.

### Added

- **Auto-generated client certificate support.** When a secure connection is opened through the daemon with `SecurityPolicy` and `SecurityMode` configured but no `clientCertPath`/`clientKeyPath` provided, the underlying `Client` automatically generates an in-memory self-signed certificate. The behaviour is transparent and inherited from `opcua-client` v1.1 — no changes required in `ManagedClient` or `CommandHandler`.
- Unit and integration tests for the auto-generated certificate flow.
