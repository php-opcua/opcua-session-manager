---
eyebrow: 'Docs · IPC'
lede:    'Seven commands cover every interaction with the daemon. Three for the IPC layer (ping, list, describe), two for the session lifecycle (open, close), and two for dispatch (query, invoke).'

see_also:
  - { href: './envelope-and-framing.md', meta: '5 min' }
  - { href: '../reference/ipc-commands.md', meta: '7 min' }
  - { href: '../extensibility/third-party-modules.md', meta: '5 min' }

prev: { label: 'Envelope and framing', href: './envelope-and-framing.md' }
next: { label: 'Type serialization',   href: './type-serialization.md' }
---

# Commands

The daemon's `CommandHandler` dispatches on the `command` field of
every request frame. Seven commands are recognised:

| Command    | Path     | What                                                                |
| ---------- | -------- | ------------------------------------------------------------------- |
| `ping`     | IPC      | Daemon liveness probe                                               |
| `list`     | IPC      | Enumerate active sessions (redacted config)                         |
| `describe` | IPC      | Surface of the underlying client (methods, modules, wire types)     |
| `open`     | Session  | Create or reuse an OPC UA session                                   |
| `close`    | Session  | Drop a specific session                                             |
| `query`    | Dispatch | Invoke an `OpcUaClientInterface` method (whitelisted)               |
| `invoke`   | Dispatch | Invoke any method registered on the daemon's client (typed wire)    |

This page is the operational walkthrough. For the formal request /
response schemas, see [Reference · IPC commands](../reference/ipc-commands.md).

## ping

Cheapest possible call. The daemon answers with its current state.

<!-- @code-block language="text" label="request / response" -->
```text
→ {"command":"ping"}
← {"success":true,"data":{"status":"ok","sessions":3,"time":1716000000.123}}
```
<!-- @endcode-block -->

Use it for healthchecks, readiness probes, smoke tests after
deploy. The `sessions` count is the number of active OPC UA
sessions; `time` is the daemon's `microtime(true)` at response.

## list

Enumerate active sessions. Credentials are redacted; everything
else useful is returned.

<!-- @code-block language="text" label="list" -->
```text
→ {"command":"list","authToken":"..."}
← {"success":true,"data":{
    "count": 2,
    "sessions": [
      {
        "id": "a1b2c3d4...",
        "endpointUrl": "opc.tcp://plc.local:4840",
        "lastUsed": 1716000000.1,
        "config": {
          "securityPolicy": "http://opcfoundation.org/UA/SecurityPolicy#Basic256Sha256",
          "securityMode": 3,
          "autoRetry": 3
        }
      },
      ...
    ]
}}
```
<!-- @endcode-block -->

The `config` map has the sensitive keys
(`username`, `password`, `clientKeyPath`, `userKeyPath`, `caCertPath`)
stripped before display. See
[ManagedClient · Session reuse](../managed-client/session-reuse.md)
for what the full key actually contains.

## describe

Asks the daemon for the API surface of the underlying client
attached to a session. Used by `ManagedClient::__call()` to decide
whether to dispatch through `query` or `invoke`.

<!-- @code-block language="text" label="describe" -->
```text
→ {"command":"describe","sessionId":"a1b2c3...","authToken":"..."}
← {"success":true,"data":{
    "methods":     ["read","write","browse",...,"customMethod"],
    "modules":     ["PhpOpcua\\Client\\Module\\ReadWrite\\ReadWriteModule",...],
    "wireClasses": ["PhpOpcua\\Client\\Types\\NodeId",...],
    "enumClasses": ["PhpOpcua\\Client\\Types\\BuiltinType",...]
}}
```
<!-- @endcode-block -->

`ManagedClient` caches the describe response for the lifetime of
the IPC connection; subsequent `hasMethod()` / `hasModule()` /
`getRegisteredMethods()` / `getLoadedModules()` are answered from
the cache without round-trips.

## open

Create or reuse a session. The request carries the endpoint URL
and the typed `SessionConfig` as a flat map. Set `forceNew: true`
to bypass session reuse.

<!-- @code-block language="text" label="open" -->
```text
→ {
    "command": "open",
    "endpointUrl": "opc.tcp://plc.local:4840",
    "config": {
      "securityPolicy": "http://opcfoundation.org/UA/SecurityPolicy#Basic256Sha256",
      "securityMode":   3,
      "username":       "integrations",
      "password":       "secret",
      "clientCertPath": "/etc/opcua/client.pem",
      "clientKeyPath":  "/etc/opcua/client.key",
      "opcuaTimeout":   10.0,
      "autoRetry":      3
    },
    "authToken": "..."
}
← {"success":true,"data":{"sessionId":"a1b2c3...","reused":false}}
```
<!-- @endcode-block -->

`reused` tells the caller whether the daemon matched an existing
session — this is what powers `ManagedClient::wasSessionReused()`.

The full config schema (which fields are accepted, which are part
of the session key, which are redacted) is in [Reference · IPC
commands](../reference/ipc-commands.md#section-open).

## close

Drop a specific session. The daemon sends `CloseSession` to the
server and frees the session-store entry.

<!-- @code-block language="text" label="close" -->
```text
→ {"command":"close","sessionId":"a1b2c3...","authToken":"..."}
← {"success":true,"data":null}
```
<!-- @endcode-block -->

`ManagedClient::disconnect()` issues this command for you — the
daemon-side session **is** torn down when the client disconnects.
Use the raw IPC path (or netcat) only when you need to close a
session whose `ManagedClient` instance is no longer in your
process (e.g. cleaning up after a crashed worker).

## query

Invoke a built-in `OpcUaClientInterface` method against a session.
Gated by the static `CommandHandler::ALLOWED_METHODS` list (44
entries).

<!-- @code-block language="text" label="query" -->
```text
→ {
    "command": "query",
    "sessionId": "a1b2c3...",
    "method": "read",
    "params": [{"ns":2,"id":"PLC/Speed","type":"string"}, 13, false],
    "authToken": "..."
}
← {"success":true,"data":{
    "value": 42.5,
    "type":  11,
    "dimensions": null,
    "statusCode": 0,
    "sourceTimestamp": "2026-05-15T10:30:00.000000+00:00",
    "serverTimestamp": "2026-05-15T10:30:00.123456+00:00"
}}
```
<!-- @endcode-block -->

The `params` array shape is method-specific. Parameter decoding
goes through `BuiltInParamDeserializer` — see
[Type serialization](./type-serialization.md) for the wire shapes
of common types.

Methods outside the allowlist return `forbidden_method`. For
custom methods, use `invoke`.

## invoke

Generic dispatch. Calls any method registered on the daemon's
client, gated by `$client->hasMethod($name)` rather than a static
list.

<!-- @code-block language="text" label="invoke" -->
```text
→ {
    "command": "invoke",
    "sessionId": "a1b2c3...",
    "method": "addNodes",
    "args": [
        [{"__t": "AddNodesItemSpec", ...wire-encoded body... }]
    ],
    "authToken": "..."
}
← {"success":true,"data":{"data":[
    {"__t": "AddNodesResult", "statusCode": 0, "addedNodeId": {"__t": "NodeId", "ns": 1, "i": "Counter", "type": "string"}}
]}}
```
<!-- @endcode-block -->

The `invoke` success response wraps the Wire-encoded result inside
a `data.data` key — the inner `data` is what `WireTypeRegistry::encode()`
emitted on the daemon side, and the outer `data` is the standard
success envelope wrapper.

`invoke` carries typed args via the Wire registry — every typed
value is wrapped with a `__t` discriminator. The registry comes
from `ModuleRegistry::buildWireTypeRegistry()` on the daemon side;
custom modules contribute their own types via
`ServiceModule::registerWireTypes()`. See
[`opcua-client` — wire serialization](https://github.com/php-opcua/opcua-client/blob/master/docs/extensibility/wire-serialization.md).

`invoke` is how `ManagedClient::__call()` reaches third-party module
methods — the path enabling pluggable custom services without
patching the command handler. See [Extensibility · Third-party
modules](../extensibility/third-party-modules.md).

## Auth across all commands

Every command, except `ping`, requires the auth token when the
daemon was configured with one. `ping` accepts it too — and
validates if present — but you can use it as an unauthenticated
liveness probe.

This matters for healthchecks: a Kubernetes liveness probe does
not have access to the auth token; the `ping` command lets it
probe regardless.
