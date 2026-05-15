---
eyebrow: 'Docs · IPC'
lede:    'TypeSerializer turns OPC UA value objects into JSON and back. Every type used in the query path has a deterministic shape — useful to know when debugging a wire capture or writing a non-PHP client.'

see_also:
  - { href: './envelope-and-framing.md',  meta: '5 min' }
  - { href: './commands.md',              meta: '7 min' }
  - { href: '../extensibility/custom-param-deserializer.md', meta: '6 min' }

prev: { label: 'Commands',           href: './commands.md' }
next: { label: 'Direct interaction', href: './direct-interaction.md' }
---

# Type serialization

`PhpOpcua\SessionManager\Serialization\TypeSerializer` converts
between PHP `Types\*` / `Module\*` value objects and the JSON
representation the IPC `query` path uses. Every common type has a
fixed shape — this page is the reference.

> **`query` vs `invoke`.** This page covers the `query` path's
> JSON shapes. The `invoke` path uses `opcua-client`'s Wire
> registry instead — typed payloads carry an explicit `__t`
> discriminator. See
> [Envelope and framing — typed payloads for invoke](./envelope-and-framing.md#section-the-wire-registry-typed-payloads-for-invoke).

## Core value objects

### NodeId

<!-- @code-block language="text" label="NodeId on the wire" -->
```text
Numeric:  {"ns": 0, "id": 2261, "type": "numeric"}
String:   {"ns": 2, "id": "Devices/PLC/Speed", "type": "string"}
GUID:     {"ns": 0, "id": "72962B91-FA75-4AE6-8D28-B404DC7DAE63", "type": "guid"}
Bytes:    {"ns": 3, "id": "<base64>", "type": "opaque"}
```
<!-- @endcode-block -->

| Field  | Meaning                                          |
| ------ | ------------------------------------------------ |
| `ns`   | Namespace index (int ≥ 0)                        |
| `id`   | Identifier value — type-dependent representation |
| `type` | One of `numeric`, `string`, `guid`, `opaque`     |

The keying matches `NodeId::numeric()` / `::string()` / `::guid()` /
`::opaque()` factories on the OPC UA client side.

### Variant

<!-- @code-block language="text" label="Variant on the wire" -->
```text
Scalar Double:  {"type": 11, "value": 42.5, "dimensions": null}
Int32 array:    {"type": 6, "value": [1, 2, 3], "dimensions": null}
2-D Double:     {"type": 11, "value": [1.0, 2.0, 3.0, 4.0], "dimensions": [2, 2]}
```
<!-- @endcode-block -->

| Field        | Meaning                                                       |
| ------------ | ------------------------------------------------------------- |
| `type`       | `BuiltinType` integer value (1-25)                            |
| `value`      | PHP-native value — scalar, array, or nested Variant            |
| `dimensions` | Array of integers for multi-dimensional arrays; `null` otherwise |

### DataValue

<!-- @code-block language="text" label="DataValue on the wire" -->
```text
{
  "value":             42.5,
  "type":              11,
  "dimensions":        null,
  "statusCode":        0,
  "sourceTimestamp":   "2026-05-15T10:30:00.000000+00:00",
  "serverTimestamp":   "2026-05-15T10:30:00.123456+00:00"
}
```
<!-- @endcode-block -->

The DataValue payload **inlines** the Variant fields
(`value`, `type`, `dimensions`) — it does not nest a `Variant`
object. This makes the common-case read response one less level of
indentation.

Timestamps use ISO 8601 with microseconds and explicit UTC offset.
Bad-status DataValues still carry `value` and `type`; check
`statusCode` before trusting the value.

### QualifiedName

<!-- @code-block language="text" label="QualifiedName" -->
```text
{"ns": 2, "name": "Speed"}
```
<!-- @endcode-block -->

### LocalizedText

<!-- @code-block language="text" label="LocalizedText" -->
```text
{"locale": "en-US", "text": "Conveyor Speed"}
```
<!-- @endcode-block -->

`locale` may be `null` for locale-less texts.

### ReferenceDescription

Returned by `browse` and friends.

<!-- @code-block language="text" label="ReferenceDescription" -->
```text
{
  "referenceTypeId": {"ns": 0, "id": 47, "type": "numeric"},
  "isForward":       true,
  "nodeId":          {"ns": 2, "id": "Devices/PLC1", "type": "string"},
  "browseName":      {"ns": 2, "name": "PLC1"},
  "displayName":     {"locale": "en", "text": "PLC 1"},
  "nodeClass":       1,
  "typeDefinition":  {"ns": 0, "id": 58, "type": "numeric"}
}
```
<!-- @endcode-block -->

`nodeClass` is the `NodeClass` enum's integer value (1 = Object,
2 = Variable, 4 = Method, …).

### BrowseNode

Returned by `browseRecursive`. Recursive shape with a `reference`
and a `children` array.

<!-- @code-block language="text" label="BrowseNode" -->
```text
{
  "reference": {/* ReferenceDescription as above */},
  "children":  [
    {"reference": {...}, "children": [...]},
    {"reference": {...}, "children": []}
  ]
}
```
<!-- @endcode-block -->

### EndpointDescription

Returned by `getEndpoints`.

<!-- @code-block language="text" label="EndpointDescription" -->
```text
{
  "endpointUrl":          "opc.tcp://plc.local:4840",
  "serverCertificate":    "<base64 DER>",
  "securityMode":         3,
  "securityPolicyUri":    "http://opcfoundation.org/UA/SecurityPolicy#Basic256Sha256",
  "userIdentityTokens":   [
    {"policyId": "anonymous", "tokenType": 0, "issuedTokenType": null, "issuerEndpointUrl": null, "securityPolicyUri": null}
  ],
  "transportProfileUri":  "http://opcfoundation.org/UA-Profile/Transport/uatcp-uasc-uabinary",
  "securityLevel":        50
}
```
<!-- @endcode-block -->

## Module result DTOs

Service-set responses serialise as plain object shapes. The fields
mirror the PHP DTO property-by-property:

| DTO                          | Shape                                                                               |
| ---------------------------- | ----------------------------------------------------------------------------------- |
| `SubscriptionResult`         | `{subscriptionId, revisedPublishingInterval, revisedLifetimeCount, revisedMaxKeepAliveCount}` |
| `MonitoredItemResult`        | `{statusCode, monitoredItemId, revisedSamplingInterval, revisedQueueSize}`          |
| `MonitoredItemModifyResult`  | `{statusCode, revisedSamplingInterval, revisedQueueSize}`                           |
| `CallResult`                 | `{statusCode, inputArgumentResults, outputArguments}` — `outputArguments` is `Variant[]` |
| `PublishResult`              | `{subscriptionId, sequenceNumber, moreNotifications, notifications, availableSequenceNumbers}` |
| `TransferResult`             | `{statusCode, availableSequenceNumbers}`                                            |
| `BrowsePathResult`           | `{statusCode, targets}` — `targets` is `BrowsePathTarget[]`                         |
| `BrowsePathTarget`           | `{targetId, remainingPathIndex}`                                                    |
| `BrowseResultSet`            | `{references, continuationPoint}` — `references` is `ReferenceDescription[]`        |
| `AddNodesResult`             | `{statusCode, addedNodeId}` — `addedNodeId` is `NodeId`                             |
| `BuildInfo`                  | `{productName, manufacturerName, softwareVersion, buildNumber, buildDate}`          |
| `SetTriggeringResult`        | `{addResults, removeResults}`                                                       |

Nested types follow their own shape rules — a `CallResult.outputArguments[0]`
is a Variant object as documented above.

## Enums

Enums are serialised by their integer value (backed enums) or by
their case name (pure enums):

| Enum                | Wire form                            |
| ------------------- | ------------------------------------ |
| `BuiltinType`       | int (1-25)                           |
| `NodeClass`         | int (1, 2, 4, 8, 16, 32, 64, 128)    |
| `BrowseDirection`   | int (0 = Forward, 1 = Inverse, 2 = Both) |
| `ConnectionState`   | string (`"Disconnected"`, `"Connected"`, `"Broken"`) |

The client-side deserialiser (`TypeSerializer::deserializeXxx`)
handles the inverse mapping.

## DateTimeImmutable

ISO 8601 with microseconds and explicit UTC offset:
`"2026-05-15T10:30:00.000000+00:00"`.

The serialiser always emits UTC. Times from the OPC UA wire (which
are FILETIME ticks since 1601-01-01 UTC) are converted to PHP
`DateTimeImmutable` and re-encoded to ISO before serialisation.

## ExtensionObject

<!-- @code-block language="text" label="ExtensionObject" -->
```text
{
  "typeId":   {"ns": 2, "id": 5001, "type": "numeric"},
  "encoding": 1,
  "body":     "<base64-encoded bytes, when raw>",
  "value":    null
}
```
<!-- @endcode-block -->

When the daemon has a codec registered for the typeId, `body` is
`null` and `value` carries the decoded structured payload.

## Programmatic use

You can use `TypeSerializer` directly when implementing custom
deserialisers or debugging:

<!-- @code-block language="php" label="examples/serialize-by-hand.php" -->
```php
use PhpOpcua\SessionManager\Serialization\TypeSerializer;
use PhpOpcua\Client\Types\NodeId;

$serializer = new TypeSerializer();

$wire = $serializer->serialize(NodeId::numeric(0, 2261));
// → ['ns' => 0, 'id' => 2261, 'type' => 'numeric']

$back = $serializer->deserializeNodeId($wire);
// → NodeId(ns=0;i=2261)
```
<!-- @endcode-block -->

The serializer holds no state and is safe to share across calls.

## Round-trip safety

Every type listed above round-trips losslessly:

- Numerics through JSON's number type (with the caveat that very
  large UInt64 values that exceed `PHP_INT_MAX` are not supported
  by JSON natively).
- Strings as JSON strings (UTF-8).
- Byte strings as base64-encoded strings.
- DateTimeImmutable as ISO with microseconds.

The `Float` type loses precision compared to PHP's native double —
that is the OPC UA spec's problem, not the serialiser's.
