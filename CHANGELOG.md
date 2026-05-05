# Changelog

## [4.3.1] - 2026-05-05

### Added

- `TransportFactory::assertUnixPathFits(string $path)` — throws `DaemonException` when a Unix-socket path exceeds `sun_path` capacity (108 on Linux, 104 on Darwin). Replaces the silent kernel truncation that previously surfaced as a confusing `chmod(): No such file or directory`.
- `TransportFactory::MAX_UNIX_PATH_LINUX` / `MAX_UNIX_PATH_DARWIN` constants.

### Changed

- `SessionManagerDaemon::run()` validates the socket path length before binding; the error message reports the offending length and points at `OPCUA_SOCKET_PATH`.

### Tests

- +4 unit tests in `TransportFactoryTest.php`;

## [4.3.0] - 2026-04-24

### Changed

- Bumped `php-opcua/opcua-client` from `^4.2.0` to `^4.3.0`.
- Bumped CI test-server suite `uanetstandard-test-suite@v1.1.0` → `@v1.2.0`.
- Documentation — realigned doc/ and README version mentions to `^4.3.0` and renamed stale "v4.0.0 DTOs" references to "module DTOs" (DTOs were relocated to their module namespaces in v4.2.0).

### Added

- `--version` / `-v` flag on `bin/opcua-session-manager` prints the daemon version. Version exposed as `SessionManagerDaemon::VERSION`.
- `ServiceUnsupportedException` is now properly propagated through the IPC boundary. The daemon's error serializer already emits the short class name; the `ManagedClient` IPC decoder now recognises `ServiceUnsupportedException` and re-throws it as the correct subclass instead of degrading it to a generic `DaemonException`. Relevant whenever a server does not implement a requested service set (typical case: `NodeManagementModule` operations against UA-.NETStandard, which returns `BadServiceUnsupported`). `catch (ServiceUnsupportedException $e)` in user code now works as expected without string-matching on the message.
- New `src/Cli/ArgvParser` class and `tests/Unit/Cli/ArgvParserTest.php` covering the daemon's CLI argument parsing. The previous inline parser in `bin/opcua-session-manager` silently dropped flags whose value was missing (e.g. `--socket` as last argument); the extracted parser now emits `Missing value for option <flag>` and the bin exits with code 1 instead of starting with a half-applied config. Backwards-compat preserved for unknown flags (still ignored).
- New `tests/Unit/ManagedClientTcpTest.php` — cross-OS coverage of the IPC error-mapping path via the loopback TCP transport. Uses `proc_open()` instead of `pcntl_fork()` and binds on `tcp://127.0.0.1:0`, so it runs on Linux, macOS, and Windows. The existing `ManagedClientIpcTest.php` stays Unix-only (`->skipOnWindows()`) as before; together the two files provide error-mapping coverage on every matrix leg.

### Changed

- `CommandHandler::handleCommand()` — the error-class short-name computation now uses `(new ReflectionClass($e))->getShortName()` instead of the previous `basename(str_replace('\\', '/', get_class($e)))` hack. Equivalent output, idiomatic and resilient to future `get_class()` edge cases.
- `bin/opcua-session-manager` — the 60-line inline argv loop has been extracted into `src/Cli/ArgvParser::parse()`, leaving the bin as a short glue script that handles action dispatch, env-var overrides, interdependencies (e.g. `--cache-path` required when `--cache-driver=file`), and daemon bootstrap.

### Security

- **Socket file permission race closed.** `SessionManagerDaemon::run()` now calls `umask(0077)` around the `SocketServer` bind so the Unix socket is created `0600` atomically. Previously a permissive process umask could leave the socket world-readable/writable in the window between `bind()` and the follow-up `chmod()`, and a daemon crash in that window could leave a permissive leftover on disk.
- **`username` no longer leaked via the `list` IPC command.** Added `'username'` to `CommandHandler::SENSITIVE_CONFIG_KEYS`; the session-lookup cache key (`SessionConfig::sanitized()`) still preserves username to keep sessions properly scoped per user. A local peer calling `list` can no longer enumerate `(endpoint → username)` tuples of other sessions, which previously enabled targeted credential-stuffing against those endpoints.
- **Per-frame NDJSON cap on inbound IPC.** Added `SessionManagerDaemon::MAX_FRAME_BYTES = 65_536` and a length check before `json_decode()`. Closes a DoS partiale where a single client could force repeated parsing of ~1 MiB of JSON per connection (MAX_BUFFER_SIZE) × 50 concurrent connections. Legitimate requests are under 2 KiB, 64 KiB is comfortable headroom.
- **IPv4-mapped IPv6 loopback now accepted/rejected consistently.** `TcpLoopbackTransport::isLoopbackAddress()` previously rejected `::ffff:127.0.0.1` (false negative) and would have misclassified `::ffff:192.168.1.10` as non-loopback only by coincidence. Explicit handling added: `::ffff:127.*` accepted, everything else under `::ffff:` rejected at construction.
- **`sanitizeErrorMessage` redacts Windows paths and URLs.** The previous Unix-only regex let `C:\Users\...\secret.pem` and URLs with embedded credentials (`opc.tcp://user:pwd@host`) leak unchanged. Three regexes now run: URL (any scheme), Windows path, Unix path; each emits `[url]` / `[path]`. Regression tests added in `CommandHandlerSecurityTest`.
- **PID check conservative fallback.** `SessionManagerDaemon::isProcessRunning()` now treats "can neither call `posix_kill` nor read `/proc`" as "process alive" instead of "dead". Prevents a new daemon from stealing the PID file from a live instance on sandboxed environments where both introspection paths are denied.
- **Persistent cache hardening.** `opcua-client` v4.3.0 removed `unserialize()` from every cache code path in favour of JSON gated by an allowlist (`Cache\WireCacheCodec`). The daemon is long-running and its per-session caches persist across requests, so upgrade paths that share a cache backend across processes should flush it once on upgrade. No API change for the daemon itself — the new `CacheCodecInterface` is picked up automatically via the default `ClientBuilder`.

## [4.2.0] - 2026-04-17

### Changed

- Bumped minimum `php-opcua/opcua-client` from `^4.1` to `^4.2.0`. Aligns `ManagedClient` with the new Kernel + ServiceModule architecture and unlocks the Wire-serialization pipeline below.
- Module-specific DTO imports updated: `SubscriptionResult`, `TransferResult`, `MonitoredItemResult`, `MonitoredItemModifyResult`, `PublishResult`, `SetTriggeringResult`, `CallResult`, `BrowsePathResult`, `BrowsePathTarget`, `BrowseResultSet` now live in their module namespaces (`PhpOpcua\Client\Module\*`) rather than `PhpOpcua\Client\Types\*`. Affected files: `Serialization/TypeSerializer.php`, `Client/ManagedClient.php`, and the corresponding unit tests.

### Added

- **Transport-layer abstraction** in `src/Ipc/` to unblock non-Unix-socket IPC (Windows in particular) without touching the wire format:
  - **`TransportInterface`** — narrow send/receive-line API suitable for NDJSON framing. Opens streams in binary mode so that Windows text-mode `\n` ↔ `\r\n` translation never silently mangles frame boundaries.
  - **`AbstractStreamTransport`** — shared NDJSON loop on top of a PHP stream resource. Concrete transports only implement `openStream()`.
  - **`UnixSocketTransport`** — the established default on Linux / macOS. Connects to `unix://<path>`.
  - **`TcpLoopbackTransport`** — portable alternative. Refuses to bind to anything outside `127.0.0.0/8` or `::1` / `localhost` at construction time, preserving the "trusted local origin" posture that the Unix socket file permissions grant today. Primarily aimed at Windows, where ReactPHP's Unix-socket support is still partial.
  - **`WireMessageCodec`** — NDJSON-framed typed-envelope codec. Requests: `{id, t: "req", method, args}`, responses: `{id, t: "res", ok: true/false, data | error}`. Rejects frames larger than 16 MiB or nested deeper than 32 levels (DoS gate). Strict on envelope shape so that wire drift is loud instead of silently masked.
- **Generic method dispatch over IPC — third-party modules work out of the box.** Two new `CommandHandler` commands plus a corresponding `ManagedClient` path turn the daemon into a fully introspectable RPC surface:
  - **`describe`** — returns `{methods, modules, wireClasses, enumClasses, wireTypeIds}` for the session's underlying client. The ManagedClient caches the response for the lifetime of the session and uses it to populate `hasMethod()` / `hasModule()` / `getRegisteredMethods()` / `getLoadedModules()` without further round-trips.
  - **`invoke`** — generic dispatch: `{method, args: [<wire-encoded>…]}`. Args are decoded with a `WireTypeRegistry` built from the daemon's `moduleRegistry->buildWireTypeRegistry()`; the result is encoded the same way. Unlike `query`, `invoke` is **not** gated by a static method whitelist — instead, `$client->hasMethod($method)` is the authoritative check, and the wire registry is the authoritative allowlist for typed payload classes. Third-party modules registered on the daemon via `ClientBuilder::addModule()` become callable from `ManagedClient::$name(...)` with no further plumbing.
  - **`ManagedClient::__call()`** — proxies any method that is not concretely declared (e.g. a custom `acme:queryFirst` provided by a third-party module) through the `invoke` path. Declared methods continue to use the existing typed-command path (`query` + `TypeSerializer`) unchanged — this release is strictly additive.
  - **NodeManagement methods** (`addNodes`, `deleteNodes`, `addReferences`, `deleteReferences`) — previously guarded by `BadMethodCallException` — now delegate through `invoke`, so they succeed whenever the daemon has opted into `NodeManagementModule` via `ClientBuilder::addModule(new NodeManagementModule())`.

### Security
- **Loopback-only TCP transport.** `TcpLoopbackTransport` rejects non-loopback hosts at construction time. Remote access — if ever wanted — must be layered explicitly (TLS, SSH tunnel).
- **Frame size and depth caps** on every decode: 16 MiB per frame and 32-level JSON nesting. Bounded memory even against authenticated but hostile peers.
- **`invoke` dispatch is gated by `hasMethod`**: only methods the daemon's module set has actually registered are reachable; arbitrary method names are rejected with `unknown_method`.

### Tests

- 37 new unit tests in `tests/Unit/Ipc/` cover framing / codec / transport guard rails, 6 more in `tests/Unit/CommandHandlerDescribeInvokeTest.php` cover the describe / invoke dispatch. 10 additional `TransportFactoryTest` cases + 7 `SessionManagerDaemonTransportTest` cases cover endpoint URI parsing and the loopback-only guard on both sides. 9 `ParamDeserializerRegistryTest` cases + 10 `SessionConfigTest` cases cover the two v4.2.0 refactors below. Full suite: **539 passing, 0 failing** (up from 456).
- `tests/Unit/SocketConnectionTest.php` and `tests/Unit/ManagedClientIpcTest.php` — the test harness fork-based fake daemons use `pcntl_fork`, so the affected cases are marked `->skipOnWindows()`; the rest of the unit suite runs cross-OS.

### Refactoring

- **`SessionConfig` DTO.** Consumption of the `config` associative array in `CommandHandler::handleOpen()` now goes through a typed readonly `PhpOpcua\SessionManager\Daemon\SessionConfig` DTO (`fromArray` / `toArray` / `sanitized()`). `handleOpen()` no longer reads `$config['opcuaTimeout'] ?? null` / `isset($config['...'])` — every knob is a typed property on the DTO. A dedicated `buildClientFromConfig()` helper consumes the DTO and returns the connected `Client`. Wire format is unchanged (still a plain JSON object for backwards compatibility); the conversion happens at the IPC boundary via `SessionConfig::fromArray()`.
- **Pluggable param deserializer.** The 200-line `match` that used to live inside `CommandHandler::deserializeParams()` is now a `PhpOpcua\SessionManager\Serialization\ParamDeserializerRegistry` that delegates to one or more `ParamDeserializerInterface` implementations consulted in registration order. The shipped behaviour lives in `BuiltInParamDeserializer`, covering every method in the default whitelist. Third-party modules that register custom service methods on the daemon's client can now ship a matching `ParamDeserializerInterface` and wire it up with `CommandHandler::registerParamDeserializer()` — no more patching the command handler. `CommandHandler` drops its `DateTimeImmutable`, `BrowseDirection`, `NodeClass`, `BuiltinType`, `ConnectionState`, `NodeId` imports as a side effect.

### Windows support

- **`TransportFactory` (new, `src/Ipc/TransportFactory.php`)** — central client-side factory that turns an endpoint string into the correct `TransportInterface`:
  - `unix:///absolute/path.sock` → `UnixSocketTransport`
  - `tcp://127.0.0.1:<port>` / `tcp://[::1]:<port>` → `TcpLoopbackTransport` (non-loopback hosts rejected at construction)
  - scheme-less path → `UnixSocketTransport` (backwards-compatible with the pre-v4.2.0 `--socket /tmp/foo.sock` convention)
  - `TransportFactory::defaultEndpoint()` picks per-OS: `unix:///tmp/opcua-session-manager.sock` on Linux/macOS, `tcp://127.0.0.1:9990` on Windows
- **`SocketConnection::send()` rewritten** on top of `TransportInterface` — one code path for every transport; drops ~80 lines of inline socket plumbing.
- **`SocketConnection::sendVia(TransportInterface, array)`** — new helper for callers that hold a long-lived transport (future pooled connections).
- **`SessionManagerDaemon` listener accepts both `unix://` and `tcp://` URIs** via `React\Socket\SocketServer`. PID file path is resolved per-transport (next to the socket file for Unix, in `sys_get_temp_dir()` keyed by endpoint slug for TCP). `chmod` + file cleanup run only for Unix endpoints. A construction-time `assertLoopbackIfTcp()` guard refuses any `tcp://0.0.0.0:…` / public-host binding, matching the TCP transport's client-side posture.
- **`config/defaults.php` default `socket_path`** now uses `TransportFactory::defaultEndpoint()` so deploying on Windows picks up TCP loopback automatically without a config edit.
- **`bin/opcua-session-manager --help` updated** — `--socket <uri>` now documents the full URI surface and the per-OS default.
- **CI workflow** — mirrors the `opcua-client` pattern: `unit` job cross-OS on `ubuntu-latest` / `macos-latest` / `windows-latest` × PHP 8.2–8.5 (12 combinations); `integration` job stays Ubuntu-only (Docker-hosted OPC UA servers) with `needs: unit` gating. `[DOC]` commits skip CI. `codecov/codecov-action` bumped from `v5` to `v6`.

## [4.1.0] - 2026-04-13

### Added

- **ECC security policy support.** The daemon and `ManagedClient` now support the 4 new Elliptic Curve Cryptography policies introduced in `opcua-client` v4.1.0:
  - `SecurityPolicy::EccNistP256` (NIST P-256, AES-128-CBC, SHA-256)
  - `SecurityPolicy::EccNistP384` (NIST P-384, AES-256-CBC, SHA-384)
  - `SecurityPolicy::EccBrainpoolP256r1` (Brainpool P-256, AES-128-CBC, SHA-256)
  - `SecurityPolicy::EccBrainpoolP384r1` (Brainpool P-384, AES-256-CBC, SHA-384)
  - No code changes required — ECC policies work transparently via `SecurityPolicy::from()` and `ClientBuilder`. ECC certificates are auto-generated when no client certificate is provided. Username/password authentication uses the `EccEncryptedSecret` protocol automatically.
  - **ECC disclaimer:** No commercial OPC UA vendor supports ECC endpoints yet. This implementation is tested exclusively against the OPC Foundation's UA-.NETStandard reference stack.

### Changed

- Bumped minimum `php-opcua/opcua-client` dependency from `^4.0` to `^4.1`.
- Security support expanded from 6 to **10 policies** (6 RSA + 4 ECC).
- Updated CI test server suite from `php-opcua/uanetstandard-test-suite@v1.0.0` to `@v1.1.0`.
- Updated documentation (README, doc/, llms.txt, llms-full.txt, llms-skills.md) to reflect ECC support and add ECC examples.

## [4.0.3] - 2026-04-08

### Added

- **Auto-publish.** When an `EventDispatcherInterface` is provided and `autoPublish` is enabled, the daemon automatically calls `publish()` for every session that has active subscriptions. The client's existing PSR-14 event dispatch fires `DataChangeReceived`, `EventNotificationReceived`, `AlarmActivated`, and all other subscription events automatically — no manual publish loop required. Acknowledgements are tracked and sent internally. A self-rescheduling one-shot timer adapts to each session's minimum publishing interval.
- **`AutoPublisher`** — new internal class managing per-session publish cycles with self-rescheduling timers, automatic acknowledgement tracking, connection recovery, and backoff on consecutive errors (stops after 5).
- **Auto-connect.** `SessionManagerDaemon::autoConnect(array $connections)` accepts pre-configured connection definitions. On the first event loop tick after startup, the daemon connects to each endpoint, creates subscriptions, and registers monitored items and event monitored items as specified. Combined with auto-publish, this enables fully declarative monitoring — zero application code needed.
- **`CommandHandler::autoConnectSession()`** — opens a session to a given endpoint and creates subscriptions with monitored items in a single call. Subscription tracking (and auto-publish start) is wired automatically.
- **Event dispatcher injection.** `CommandHandler` accepts an optional `EventDispatcherInterface` and injects it into every `ClientBuilder` created via `handleOpen()`. This enables PSR-14 event delivery for all OPC UA client events in daemon-managed sessions.
- **Manual `publish()` blocking.** When auto-publish is active for a session, manual `publish()` calls via IPC return an `auto_publish_active` error to prevent conflicting publish cycles.
- `Session::getMinPublishingInterval()` — returns the minimum publishing interval (in seconds) across all tracked subscriptions, used by `AutoPublisher` for timer scheduling.
- `Session::addSubscription()` now accepts an optional `float $publishingInterval` parameter (default 500.0 ms) to track the revised publishing interval from `SubscriptionResult`.
- `CommandHandler::attemptSessionRecovery()` visibility changed from `private` to `public` to allow the daemon to pass it as a recovery callback to `AutoPublisher`.

### Changed

- `SessionManagerDaemon` constructor accepts two new optional parameters: `?EventDispatcherInterface $clientEventDispatcher` and `bool $autoPublish`.
- `CommandHandler` constructor accepts a new optional parameter: `?EventDispatcherInterface $clientEventDispatcher`.
- `trackSubscriptionChanges()` now stores `revisedPublishingInterval` from `SubscriptionResult` and triggers `AutoPublisher::startSession()`/`stopSession()` when a session's first subscription is created or its last subscription is deleted.
- `cleanupExpiredSessions()` and `shutdown()` now stop auto-publish timers before disconnecting sessions.

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
