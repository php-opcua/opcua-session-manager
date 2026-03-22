# Type Serialization

`TypeSerializer` converts OPC UA types and v3.0.0 DTOs into JSON-safe arrays for IPC transport, and reconstructs them on the other side.

## Conversion Table

| OPC UA Type | JSON Representation |
|---|---|
| `NodeId` | `{"ns": 0, "id": 2259, "type": "numeric"}` |
| `Variant` | `{"type": 6, "value": 42, "dimensions": null}` |
| `DataValue` | `{"value": 42, "type": 6, "dimensions": null, "statusCode": 0, "sourceTimestamp": "...", "serverTimestamp": "..."}` |
| `QualifiedName` | `{"ns": 0, "name": "Server"}` |
| `LocalizedText` | `{"locale": "en", "text": "Server"}` |
| `ReferenceDescription` | `{"referenceTypeId": {...}, "isForward": true, "nodeId": {...}, "browseName": {...}, "displayName": {...}, "nodeClass": 1, "typeDefinition": {...}}` |
| `BrowseNode` | `{"reference": {...}, "children": [...]}` |
| `EndpointDescription` | `{"endpointUrl": "...", "securityMode": 1, "securityPolicyUri": "...", ...}` |
| `BuiltinType` | `int` (enum value) |
| `NodeClass` | `int` (enum value) |
| `BrowseDirection` | `int` (enum value) |
| `ConnectionState` | `string` (enum name: `"Connected"`, `"Disconnected"`, `"Broken"`) |
| `DateTimeImmutable` | `string` (ISO 8601) |
| `null` | `null` |
| scalars | as-is |
| arrays | recursively serialized |

## v3.0.0 DTO Conversion

| DTO | JSON Representation |
|---|---|
| `SubscriptionResult` | `{"subscriptionId": 1, "revisedPublishingInterval": 500.0, "revisedLifetimeCount": 2400, "revisedMaxKeepAliveCount": 10}` |
| `MonitoredItemResult` | `{"statusCode": 0, "monitoredItemId": 100, "revisedSamplingInterval": 250.0, "revisedQueueSize": 1}` |
| `CallResult` | `{"statusCode": 0, "inputArgumentResults": [0], "outputArguments": [{"type": 6, "value": 42, "dimensions": null}]}` |
| `BrowseResultSet` | `{"references": [...], "continuationPoint": "abc123"}` |
| `PublishResult` | `{"subscriptionId": 1, "sequenceNumber": 42, "moreNotifications": false, "notifications": [...], "availableSequenceNumbers": [1, 2]}` |
| `BrowsePathResult` | `{"statusCode": 0, "targets": [{"targetId": {...}, "remainingPathIndex": 0}]}` |
| `BrowsePathTarget` | `{"targetId": {"ns": 2, "id": 100, "type": "numeric"}, "remainingPathIndex": 0}` |
| `TransferResult` | `{"statusCode": 0, "availableSequenceNumbers": [1, 2, 3]}` |

## Variant Dimensions

Multi-dimensional arrays preserve their dimensions through serialization:

```php
$variant = new Variant(BuiltinType::Int32, [1, 2, 3, 4], [2, 2]);
$serialized = $serializer->serializeVariant($variant);
// {"type": 6, "value": [1, 2, 3, 4], "dimensions": [2, 2]}
```

## Variant Value Deserialization

Some variant values require type-specific deserialization:

| BuiltinType | Deserialization |
|---|---|
| `DateTime` | ISO 8601 string → `DateTimeImmutable` |
| `NodeId` | Array → `NodeId` object |
| `QualifiedName` | Array → `QualifiedName` object |
| `LocalizedText` | Array → `LocalizedText` object |
| All others | Returned as-is |

## Programmatic Usage

```php
use Gianfriaur\OpcuaSessionManager\Serialization\TypeSerializer;

$serializer = new TypeSerializer();

// Generic serialization (handles any supported type)
$json = $serializer->serialize($anyValue);

// Type-specific serialization
$data = $serializer->serializeNodeId($nodeId);
$data = $serializer->serializeDataValue($dataValue);
$data = $serializer->serializeSubscriptionResult($result);

// Type-specific deserialization
$nodeId = $serializer->deserializeNodeId($data);
$dataValue = $serializer->deserializeDataValue($data);
$result = $serializer->deserializeSubscriptionResult($data);
$result = $serializer->deserializeCallResult($data);
$result = $serializer->deserializeBrowseResultSet($data);
$result = $serializer->deserializePublishResult($data);
$result = $serializer->deserializeBrowsePathResult($data);
$result = $serializer->deserializeTransferResult($data);
$result = $serializer->deserializeMonitoredItemResult($data);
```
