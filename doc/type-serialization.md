# Type Serialization

The `TypeSerializer` converts `opcua-php-client` OPC UA types into JSON structures for IPC transport, and vice versa.

## Conversion table

| PHP Type | JSON Format | Example |
|----------|-------------|---------|
| `NodeId` | `{"ns": int, "id": int\|string, "type": string}` | `{"ns": 2, "id": 1001, "type": "numeric"}` |
| `DataValue` | `{"value": mixed, "type": ?int, "statusCode": int, "sourceTimestamp": ?string, "serverTimestamp": ?string}` | `{"value": 42, "type": 6, "statusCode": 0, "sourceTimestamp": null, "serverTimestamp": null}` |
| `Variant` | `{"type": int, "value": mixed}` | `{"type": 11, "value": 3.14}` |
| `ReferenceDescription` | Full object | See below |
| `QualifiedName` | `{"ns": int, "name": string}` | `{"ns": 0, "name": "ServerStatus"}` |
| `LocalizedText` | `{"locale": ?string, "text": ?string}` | `{"locale": "en", "text": "Server Status"}` |
| `BuiltinType` (enum) | `int` (backed value) | `6` (Int32) |
| `NodeClass` (enum) | `int` (backed value) | `2` (Variable) |
| `DateTimeImmutable` | ISO 8601 string | `"2024-06-15T12:00:00+00:00"` |
| `EndpointDescription` | Full object | See below |
| Scalars (`int`, `float`, `string`, `bool`) | Unchanged | `42`, `3.14`, `"hello"`, `true` |
| `null` | `null` | `null` |
| `array` | Array with recursively serialized elements | `[1, 2, 3]` |

## NodeId

### Supported types

| Constant | Value | Example |
|----------|-------|---------|
| `NodeId::TYPE_NUMERIC` | `"numeric"` | `{"ns": 0, "id": 85}` |
| `NodeId::TYPE_STRING` | `"string"` | `{"ns": 2, "id": "MyVariable"}` |
| `NodeId::TYPE_GUID` | `"guid"` | `{"ns": 1, "id": "550e8400-e29b-..."}` |
| `NodeId::TYPE_OPAQUE` | `"opaque"` | `{"ns": 1, "id": "base64data"}` |

### Example

```php
// PHP -> JSON
$serializer = new TypeSerializer();
$nodeId = NodeId::numeric(2, 1001);
$json = $serializer->serializeNodeId($nodeId);
// ["ns" => 2, "id" => 1001, "type" => "numeric"]

// JSON -> PHP
$nodeId = $serializer->deserializeNodeId(["ns" => 2, "id" => 1001, "type" => "numeric"]);
// NodeId(namespaceIndex=2, identifier=1001, type="numeric")
```

## DataValue

The `DataValue` contains the value read from a node, along with metadata.

```json
{
  "value": 42,
  "type": 6,
  "statusCode": 0,
  "sourceTimestamp": "2024-06-15T12:00:00+00:00",
  "serverTimestamp": "2024-06-15T12:00:01+00:00"
}
```

- `value` — The actual value (recursively serialized)
- `type` — The Variant's `BuiltinType` (as integer). `null` if the DataValue has no Variant
- `statusCode` — OPC UA status code (`0` = Good)
- `sourceTimestamp` / `serverTimestamp` — ISO 8601 or `null`

## Variant

```json
{
  "type": 11,
  "value": 3.14
}
```

The `type` field corresponds to the `BuiltinType` enum values:

| Name | Value |
|------|-------|
| Boolean | 1 |
| SByte | 2 |
| Byte | 3 |
| Int16 | 4 |
| UInt16 | 5 |
| Int32 | 6 |
| UInt32 | 7 |
| Int64 | 8 |
| UInt64 | 9 |
| Float | 10 |
| Double | 11 |
| String | 12 |
| DateTime | 13 |
| Guid | 14 |
| ByteString | 15 |
| XmlElement | 16 |
| NodeId | 17 |
| ExpandedNodeId | 18 |
| StatusCode | 19 |
| QualifiedName | 20 |
| LocalizedText | 21 |
| ExtensionObject | 22 |
| DataValue | 23 |
| Variant | 24 |
| DiagnosticInfo | 25 |

When `type` is `DateTime` (13), the `value` is serialized as an ISO 8601 string and deserialized to `DateTimeImmutable`.

When `type` is `NodeId` (17), the `value` is serialized/deserialized as a NodeId object.

## ReferenceDescription

```json
{
  "referenceTypeId": {"ns": 0, "id": 35, "type": "numeric"},
  "isForward": true,
  "nodeId": {"ns": 2, "id": 100, "type": "numeric"},
  "browseName": {"ns": 2, "name": "MyNode"},
  "displayName": {"locale": "en", "text": "My Node"},
  "nodeClass": 2,
  "typeDefinition": {"ns": 0, "id": 63, "type": "numeric"}
}
```

- `nodeClass` uses `NodeClass` enum values: Object=1, Variable=2, Method=4, ObjectType=8, etc.
- `typeDefinition` can be `null`

## EndpointDescription

```json
{
  "endpointUrl": "opc.tcp://server:4840/UA/Server",
  "securityMode": 3,
  "securityPolicyUri": "http://opcfoundation.org/UA/SecurityPolicy#Basic256Sha256",
  "securityLevel": 2,
  "transportProfileUri": "http://opcfoundation.org/UA-Profile/Transport/uatcp-uasc-uabinary",
  "userIdentityTokens": [
    {
      "policyId": "anonymous",
      "tokenType": 0,
      "issuedTokenType": null,
      "issuerEndpointUrl": null,
      "securityPolicyUri": null
    },
    {
      "policyId": "username",
      "tokenType": 1,
      "issuedTokenType": null,
      "issuerEndpointUrl": null,
      "securityPolicyUri": "http://opcfoundation.org/UA/SecurityPolicy#Basic256Sha256"
    }
  ]
}
```

## Programmatic usage

```php
use Gianfriaur\OpcuaSessionManager\Serialization\TypeSerializer;
use Gianfriaur\OpcuaPhpClient\Types\NodeId;
use Gianfriaur\OpcuaPhpClient\Types\DataValue;
use Gianfriaur\OpcuaPhpClient\Types\Variant;
use Gianfriaur\OpcuaPhpClient\Types\BuiltinType;

$serializer = new TypeSerializer();

// Generic serialization (auto-detects type)
$json = $serializer->serialize($nodeId);        // any supported type
$json = $serializer->serialize([1, 2, 3]);      // recursive array
$json = $serializer->serialize(null);            // null

// Type-specific serialization
$json = $serializer->serializeNodeId($nodeId);
$json = $serializer->serializeDataValue($dataValue);
$json = $serializer->serializeVariant($variant);
$json = $serializer->serializeQualifiedName($qualifiedName);
$json = $serializer->serializeLocalizedText($localizedText);
$json = $serializer->serializeReferenceDescription($referenceDescription);
$json = $serializer->serializeEndpointDescription($endpointDescription);

// Deserialization
$nodeId = $serializer->deserializeNodeId($json);
$dataValue = $serializer->deserializeDataValue($json);
$variant = $serializer->deserializeVariant($json);
$qualifiedName = $serializer->deserializeQualifiedName($json);
$localizedText = $serializer->deserializeLocalizedText($json);
$referenceDescription = $serializer->deserializeReferenceDescription($json);
$builtinType = $serializer->deserializeBuiltinType(6); // BuiltinType::Int32
```
