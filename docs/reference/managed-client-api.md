---
eyebrow: 'Docs · Reference'
lede:    'Every method on ManagedClient, grouped by concern. The OPC UA operations mirror OpcUaClientInterface; the daemon-only methods (connectForceNew, wasSessionReused, getSessionId) are flagged.'

see_also:
  - { href: '../managed-client/overview.md',                 meta: '5 min' }
  - { href: '../managed-client/differences-from-direct-client.md', meta: '5 min' }
  - { href: 'https://github.com/php-opcua/opcua-client/blob/master/docs/reference/client-api.md', meta: 'external', label: 'opcua-client — OpcUaClientInterface' }

prev: { label: 'Daemon CLI',     href: './daemon-cli.md' }
next: { label: 'IPC commands',   href: './ipc-commands.md' }
---

# ManagedClient API

`PhpOpcua\SessionManager\Client\ManagedClient` implements
`OpcUaClientInterface` plus three daemon-specific methods. This
page lists the full surface; per-method semantics are in the
linked topic pages.

For OPC UA operations (`read`, `write`, `browse`, `subscribe`, …),
the **operational** documentation is in `opcua-client` — same
interface, same semantics, same examples. The cards below are the
signatures; click the links for the prose.

<!-- @divider eyebrow="Construction" -->
<!-- @enddivider -->

<!-- @method name="new ManagedClient(string \$socketPath = '/tmp/opcua-session-manager.sock', float \$timeout = 30.0, ?string \$authToken = null)" returns="ManagedClient" visibility="public" -->

See [ManagedClient · Overview](../managed-client/overview.md#section-constructor).

<!-- @divider eyebrow="Session lifecycle (daemon-specific)" -->
<!-- @enddivider -->

<!-- @method name="connect(string \$endpointUrl): void" returns="void" visibility="public" -->
<!-- @method name="connectForceNew(string \$endpointUrl): void" returns="void" visibility="public" -->
<!-- @method name="disconnect(): void" returns="void" visibility="public" -->
<!-- @method name="reconnect(): void" returns="void" visibility="public" -->
<!-- @method name="isConnected(): bool" returns="bool" visibility="public" -->
<!-- @method name="getConnectionState(): ConnectionState" returns="ConnectionState" visibility="public" -->
<!-- @method name="wasSessionReused(): bool" returns="bool" visibility="public" -->
<!-- @method name="getSessionId(): ?string" returns="?string" visibility="public" -->

`connectForceNew()`, `wasSessionReused()`, and `getSessionId()` are
**not** on `OpcUaClientInterface` — they exist only on
`ManagedClient`. See [ManagedClient · Opening and
closing](../managed-client/opening-and-closing.md).

<!-- @divider eyebrow="Configuration (builder-style)" -->
Set before `connect()`; setters return `$this` for chaining.
<!-- @enddivider -->

<!-- @method name="setTimeout(float \$timeout): self" returns="self" visibility="public" -->
<!-- @method name="setAutoRetry(int \$maxRetries): self" returns="self" visibility="public" -->
<!-- @method name="setBatchSize(int \$batchSize): self" returns="self" visibility="public" -->
<!-- @method name="setDefaultBrowseMaxDepth(int \$maxDepth): self" returns="self" visibility="public" -->
<!-- @method name="setAutoDetectWriteType(bool \$enabled): self" returns="self" visibility="public" -->
<!-- @method name="setReadMetadataCache(bool \$enabled): self" returns="self" visibility="public" -->
<!-- @method name="setCache(?CacheInterface \$cache): self" returns="self" visibility="public" -->

<!-- @divider eyebrow="Security (builder-style)" -->
<!-- @enddivider -->

<!-- @method name="setSecurityPolicy(SecurityPolicy \$policy): self" returns="self" visibility="public" -->
<!-- @method name="setSecurityMode(SecurityMode \$mode): self" returns="self" visibility="public" -->
<!-- @method name="setClientCertificate(string \$certPath, string \$keyPath, ?string \$caCertPath = null): self" returns="self" visibility="public" -->
<!-- @method name="setUserCredentials(string \$username, string \$password): self" returns="self" visibility="public" -->
<!-- @method name="setUserCertificate(string \$certPath, string \$keyPath): self" returns="self" visibility="public" -->
<!-- @method name="setTrustStorePath(string \$trustStorePath): self" returns="self" visibility="public" -->
<!-- @method name="setTrustPolicy(?TrustPolicy \$policy): self" returns="self" visibility="public" -->
<!-- @method name="autoAccept(bool \$enabled = true, bool \$force = false): self" returns="self" visibility="public" -->

Certificate paths must sit under the daemon's `--allowed-cert-dirs`
when that flag is set — see
[Daemon · Security hardening](../daemon/security-hardening.md).

<!-- @divider eyebrow="Trust store runtime" -->
<!-- @enddivider -->

<!-- @method name="trustCertificate(string \$certDer): void" returns="void" visibility="public" -->
<!-- @method name="untrustCertificate(string \$fingerprint): void" returns="void" visibility="public" -->
<!-- @method name="getTrustStore(): ?TrustStoreInterface" returns="?TrustStoreInterface" visibility="public" -->
<!-- @method name="getTrustPolicy(): ?TrustPolicy" returns="?TrustPolicy" visibility="public" -->

<!-- @divider eyebrow="Logging and events" -->
<!-- @enddivider -->

<!-- @method name="setLogger(LoggerInterface \$logger): self" returns="self" visibility="public" -->
<!-- @method name="getLogger(): LoggerInterface" returns="LoggerInterface" visibility="public" -->
<!-- @method name="setEventDispatcher(EventDispatcherInterface \$dispatcher): self" returns="self" visibility="public" -->
<!-- @method name="getEventDispatcher(): EventDispatcherInterface" returns="EventDispatcherInterface" visibility="public" -->
<!-- @method name="getExtensionObjectRepository(): ExtensionObjectRepository" returns="ExtensionObjectRepository" visibility="public" -->

These are **client-side** loggers and dispatchers. Events fired by
the OPC UA stack land in the **daemon's** dispatcher, not here.
See [ManagedClient · Differences from the direct
client](../managed-client/differences-from-direct-client.md).

<!-- @divider eyebrow="Introspection (v4.2.0+)" -->
First call costs one IPC round-trip; subsequent calls are answered
from a per-session cache.
<!-- @enddivider -->

<!-- @method name="hasMethod(string \$name): bool" returns="bool" visibility="public" -->
<!-- @method name="hasModule(string \$moduleClass): bool" returns="bool" visibility="public" -->
<!-- @method name="getRegisteredMethods(): string[]" returns="string[]" visibility="public" -->
<!-- @method name="getLoadedModules(): class-string[]" returns="class-string[]" visibility="public" -->

<!-- @divider eyebrow="Reading" -->
See [`opcua-client` — reading attributes](https://github.com/php-opcua/opcua-client/blob/master/docs/operations/reading-attributes.md).
<!-- @enddivider -->

<!-- @method name="read(NodeId|string \$nodeId, int \$attributeId = 13, bool \$refresh = false): DataValue" returns="DataValue" visibility="public" -->
<!-- @method name="readMulti(?array \$readItems = null): array|ReadMultiBuilder" returns="DataValue[] or builder" visibility="public" -->

<!-- @divider eyebrow="Writing" -->
<!-- @enddivider -->

<!-- @method name="write(NodeId|string \$nodeId, mixed \$value, ?BuiltinType \$type = null): int" returns="int (StatusCode)" visibility="public" -->
<!-- @method name="writeMulti(?array \$writeItems = null): array|WriteMultiBuilder" returns="int[] or builder" visibility="public" -->

<!-- @divider eyebrow="Browsing" -->
See [`opcua-client` — browsing](https://github.com/php-opcua/opcua-client/blob/master/docs/operations/browsing.md).
<!-- @enddivider -->

<!-- @method name="browse(NodeId|string \$nodeId, BrowseDirection \$direction = BrowseDirection::Forward, ?NodeId \$referenceTypeId = null, bool \$includeSubtypes = true, array \$nodeClasses = [], bool \$useCache = true): ReferenceDescription[]" returns="ReferenceDescription[]" visibility="public" -->
<!-- @method name="browseAll(NodeId|string \$nodeId, BrowseDirection \$direction = BrowseDirection::Forward, ?NodeId \$referenceTypeId = null, bool \$includeSubtypes = true, array \$nodeClasses = [], bool \$useCache = true): ReferenceDescription[]" returns="ReferenceDescription[]" visibility="public" -->
<!-- @method name="browseRecursive(NodeId|string \$nodeId, BrowseDirection \$direction = BrowseDirection::Forward, ?int \$maxDepth = null, ?NodeId \$referenceTypeId = null, bool \$includeSubtypes = true, array \$nodeClasses = []): BrowseNode[]" returns="BrowseNode[]" visibility="public" -->
<!-- @method name="browseWithContinuation(NodeId|string \$nodeId, ...): BrowseResultSet" returns="BrowseResultSet" visibility="public" -->
<!-- @method name="browseNext(string \$continuationPoint): BrowseResultSet" returns="BrowseResultSet" visibility="public" -->
<!-- @method name="translateBrowsePaths(?array \$browsePaths = null): array|BrowsePathsBuilder" returns="BrowsePathResult[] or builder" visibility="public" -->
<!-- @method name="resolveNodeId(string \$path, NodeId|string|null \$startingNodeId = null, bool \$useCache = true): NodeId" returns="NodeId" visibility="public" -->

<!-- @divider eyebrow="Method calls" -->
<!-- @enddivider -->

<!-- @method name="call(NodeId|string \$objectId, NodeId|string \$methodId, array \$inputArguments = []): CallResult" returns="CallResult" visibility="public" -->

<!-- @divider eyebrow="Subscriptions and monitored items" -->
See [`opcua-client` — subscriptions](https://github.com/php-opcua/opcua-client/blob/master/docs/operations/subscriptions.md).
<!-- @enddivider -->

<!-- @method name="createSubscription(float \$publishingInterval = 500.0, int \$lifetimeCount = 2400, int \$maxKeepAliveCount = 10, int \$maxNotificationsPerPublish = 0, bool \$publishingEnabled = true, int \$priority = 0): SubscriptionResult" returns="SubscriptionResult" visibility="public" -->
<!-- @method name="createMonitoredItems(int \$subscriptionId, ?array \$monitoredItems = null): MonitoredItemResult[]|MonitoredItemsBuilder" returns="MonitoredItemResult[] or builder" visibility="public" -->
<!-- @method name="createEventMonitoredItem(int \$subscriptionId, NodeId|string \$nodeId, array \$selectFields = ['EventId','EventType','SourceName','Time','Message','Severity'], int \$clientHandle = 1): MonitoredItemResult" returns="MonitoredItemResult" visibility="public" -->
<!-- @method name="modifyMonitoredItems(int \$subscriptionId, array \$itemsToModify): MonitoredItemModifyResult[]" returns="MonitoredItemModifyResult[]" visibility="public" -->
<!-- @method name="deleteMonitoredItems(int \$subscriptionId, array \$monitoredItemIds): int[]" returns="int[]" visibility="public" -->
<!-- @method name="setTriggering(int \$subscriptionId, int \$triggeringItemId, array \$linksToAdd = [], array \$linksToRemove = []): SetTriggeringResult" returns="SetTriggeringResult" visibility="public" -->
<!-- @method name="deleteSubscription(int \$subscriptionId): int" returns="int (StatusCode)" visibility="public" -->
<!-- @method name="publish(array \$acknowledgements = []): PublishResult" returns="PublishResult" visibility="public" -->
<!-- @method name="transferSubscriptions(array \$subscriptionIds, bool \$sendInitialValues = false): TransferResult[]" returns="TransferResult[]" visibility="public" -->
<!-- @method name="republish(int \$subscriptionId, int \$retransmitSequenceNumber): array" returns="array" visibility="public" -->

<!-- @divider eyebrow="History reads" -->
<!-- @enddivider -->

<!-- @method name="historyReadRaw(NodeId|string \$nodeId, ?DateTimeImmutable \$startTime = null, ?DateTimeImmutable \$endTime = null, int \$numValuesPerNode = 0, bool \$returnBounds = false): DataValue[]" returns="DataValue[]" visibility="public" -->
<!-- @method name="historyReadProcessed(NodeId|string \$nodeId, DateTimeImmutable \$startTime, DateTimeImmutable \$endTime, float \$processingInterval, NodeId \$aggregateType): DataValue[]" returns="DataValue[]" visibility="public" -->
<!-- @method name="historyReadAtTime(NodeId|string \$nodeId, array \$timestamps): DataValue[]" returns="DataValue[]" visibility="public" -->

<!-- @divider eyebrow="Node management" -->
<!-- @enddivider -->

<!-- @method name="addNodes(array \$nodesToAdd): AddNodesResult[]" returns="AddNodesResult[]" visibility="public" -->
<!-- @method name="deleteNodes(array \$nodesToDelete): int[]" returns="int[]" visibility="public" -->
<!-- @method name="addReferences(array \$referencesToAdd): int[]" returns="int[]" visibility="public" -->
<!-- @method name="deleteReferences(array \$referencesToDelete): int[]" returns="int[]" visibility="public" -->

NodeManagement is optional in the OPC UA spec — servers may
respond with `ServiceUnsupportedException`. See [`opcua-client` —
managing nodes](https://github.com/php-opcua/opcua-client/blob/master/docs/operations/managing-nodes.md).

<!-- @divider eyebrow="Discovery and server info" -->
<!-- @enddivider -->

<!-- @method name="getEndpoints(string \$endpointUrl, bool \$useCache = true): EndpointDescription[]" returns="EndpointDescription[]" visibility="public" -->
<!-- @method name="discoverDataTypes(?int \$namespaceIndex = null, bool \$useCache = true): int" returns="int" visibility="public" -->
<!-- @method name="getServerProductName(): ?string" returns="?string" visibility="public" -->
<!-- @method name="getServerManufacturerName(): ?string" returns="?string" visibility="public" -->
<!-- @method name="getServerSoftwareVersion(): ?string" returns="?string" visibility="public" -->
<!-- @method name="getServerBuildNumber(): ?string" returns="?string" visibility="public" -->
<!-- @method name="getServerBuildDate(): ?DateTimeImmutable" returns="?DateTimeImmutable" visibility="public" -->
<!-- @method name="getServerBuildInfo(): BuildInfo" returns="BuildInfo" visibility="public" -->

<!-- @divider eyebrow="Cache (runtime)" -->
<!-- @enddivider -->

<!-- @method name="getCache(): ?CacheInterface" returns="?CacheInterface" visibility="public" -->
<!-- @method name="invalidateCache(NodeId|string \$nodeId): void" returns="void" visibility="public" -->
<!-- @method name="flushCache(): void" returns="void" visibility="public" -->

`invalidateCache()` and `flushCache()` act on the **daemon-side**
cache via IPC — the only cache that exists in this architecture.
See [Daemon · Logging and cache](../daemon/logging-and-cache.md).

<!-- @divider eyebrow="Configuration accessors" -->
<!-- @enddivider -->

<!-- @method name="getTimeout(): float" returns="float" visibility="public" -->
<!-- @method name="getAutoRetry(): int" returns="int" visibility="public" -->
<!-- @method name="getBatchSize(): ?int" returns="?int" visibility="public" -->
<!-- @method name="getServerMaxNodesPerRead(): ?int" returns="?int" visibility="public" -->
<!-- @method name="getServerMaxNodesPerWrite(): ?int" returns="?int" visibility="public" -->
<!-- @method name="getDefaultBrowseMaxDepth(): int" returns="int" visibility="public" -->

Each accessor that reaches the daemon costs one IPC round-trip.
The local-only ones (`getLogger`, `getEventDispatcher`,
`getExtensionObjectRepository`, `getTrustStore`, `getTrustPolicy`)
do not.
