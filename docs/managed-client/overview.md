---
eyebrow: 'Docs · ManagedClient'
lede:    'ManagedClient is OpcUaClientInterface routed through IPC. Drop it in wherever the direct Client used to live — every read, write, browse, subscribe works unchanged.'

see_also:
  - { href: './opening-and-closing.md',           meta: '5 min' }
  - { href: './differences-from-direct-client.md', meta: '5 min' }
  - { href: 'https://github.com/php-opcua/opcua-client/blob/master/docs/reference/client-api.md', meta: 'external', label: 'opcua-client — OpcUaClientInterface' }

prev: { label: 'Running as a service', href: '../daemon/running-as-a-service.md' }
next: { label: 'Opening and closing',  href: './opening-and-closing.md' }
---

# Overview

`PhpOpcua\SessionManager\Client\ManagedClient` is the application-
side companion to the session manager daemon. It implements
`OpcUaClientInterface` — the same contract as the direct
`PhpOpcua\Client\Client` — and forwards every call through the IPC
channel to the daemon, which owns the actual OPC UA session.

For application code, the substitution is mechanical:

<!-- @do-dont -->
<!-- @do -->
```php
use PhpOpcua\SessionManager\Client\ManagedClient;
use PhpOpcua\Client\Types\NodeId;

$client = new ManagedClient('/tmp/opcua-session-manager.sock');
$client->connect('opc.tcp://plc.local:4840');

$value = $client->read(NodeId::numeric(0, 2261));
```
<!-- @enddo -->
<!-- @dont -->
```php
use PhpOpcua\Client\ClientBuilder;
use PhpOpcua\Client\Types\NodeId;

// Direct — no daemon, no session reuse across requests
$client = ClientBuilder::create()->connect('opc.tcp://plc.local:4840');

$value = $client->read(NodeId::numeric(0, 2261));
```
<!-- @enddont -->
<!-- @enddo-dont -->

The two are interchangeable from a call-site perspective. The
difference is operational — see [Differences from the direct
client](./differences-from-direct-client.md).

## Constructor

<!-- @method name="new ManagedClient(string \$socketPath = '/tmp/opcua-session-manager.sock', float \$timeout = 30.0, ?string \$authToken = null)" returns="ManagedClient" visibility="public" -->

<!-- @params -->
<!-- @param name="$socketPath" type="string" default="'/tmp/opcua-session-manager.sock'" -->
Daemon endpoint URI. Accepts `unix:///absolute/path.sock`,
`tcp://127.0.0.1:<port>`, `tcp://[::1]:<port>`, or a scheme-less
Unix path. Use `TransportFactory::defaultEndpoint()` to pick the
per-OS default without hardcoding.
<!-- @endparam -->
<!-- @param name="$timeout" type="float" default="30.0" -->
IPC timeout in seconds. Bounds the per-call wait on the daemon —
not the OPC UA timeout the daemon uses against the server. Set the
OPC UA timeout via `setTimeout()` on the same client.
<!-- @endparam -->
<!-- @param name="$authToken" type="?string" default="null" -->
Shared secret expected by the daemon's auth check. Pass `null`
when the daemon was started without `--auth-token` / env / file.
See [Daemon · Authentication](../daemon/authentication.md).
<!-- @endparam -->
<!-- @endparams -->

The constructor does **not** open a connection. Configure the
builder-style setters first, then call `connect()`.

## Builder-style configuration

`ManagedClient` exposes the same configuration setters as the
direct `ClientBuilder` — security, trust store, cache, logging,
events, retry, batching, browse depth. Every setter returns `$this`
for chaining:

<!-- @code-block language="php" label="examples/configured-client.php" -->
```php
use PhpOpcua\SessionManager\Client\ManagedClient;
use PhpOpcua\Client\Security\SecurityPolicy;
use PhpOpcua\Client\Security\SecurityMode;
use PhpOpcua\Client\TrustStore\TrustPolicy;

$client = (new ManagedClient(
    socketPath: '/var/run/opcua/sessions.sock',
    authToken:  getenv('OPCUA_AUTH_TOKEN'),
))
    ->setSecurityPolicy(SecurityPolicy::Basic256Sha256)
    ->setSecurityMode(SecurityMode::SignAndEncrypt)
    ->setClientCertificate('/etc/opcua/client.pem', '/etc/opcua/client.key')
    ->setUserCredentials('integrations', getenv('OPCUA_PASSWORD'))
    ->setTrustStorePath('/var/lib/opcua/trust')
    ->setTrustPolicy(TrustPolicy::FingerprintAndExpiry)
    ->setTimeout(10.0)
    ->setAutoRetry(3);

$client->connect('opc.tcp://plc.local:4840');
```
<!-- @endcode-block -->

Configuration values travel to the daemon as part of the `open`
IPC command — the daemon constructs the underlying `Client` with
those values. **Setting these after `connect()` does not affect the
already-open session** — that session was opened with the values
present at `connect()` time. To change them, disconnect and
reconnect with the new configuration (see
[Opening and closing](./opening-and-closing.md)).

## Session reuse

When `connect()` runs, the daemon looks for an existing session
with the same `(endpointUrl, sanitized config)` pair. On a match,
the existing session is reused — no OPC UA handshake, no
`CreateSession`. The reuse is what amortises the cost of OPC UA
across PHP requests.

`wasSessionReused()` exposes the answer to the caller:

<!-- @code-block language="php" label="examples/reuse-check.php" -->
```php
$client->connect('opc.tcp://plc.local:4840');

if ($client->wasSessionReused()) {
    // No handshake — the session was already open on the daemon.
} else {
    // Fresh session — first connect with this configuration.
}

echo $client->getSessionId();   // daemon-assigned session ID, opaque
```
<!-- @endcode-block -->

See [Session reuse](./session-reuse.md) for the keying rules.

## Drop-in compatibility

`ManagedClient` implements `OpcUaClientInterface`, so it can be
type-hinted wherever the direct client would be:

<!-- @code-block language="php" label="examples/abstract-injection.php" -->
```php
use PhpOpcua\Client\OpcUaClientInterface;

final class DeviceService
{
    public function __construct(
        private readonly OpcUaClientInterface $client,
    ) {}

    public function readSpeed(): float
    {
        return $this->client->read('ns=2;s=PLC/Speed')->getValue();
    }
}

// Production wiring — through the daemon
$service = new DeviceService(new ManagedClient('/tmp/opcua.sock'));

// Test wiring — against the mock
$service = new DeviceService(MockClient::create());
```
<!-- @endcode-block -->

This is the architectural payoff. The same service class works
against the direct client, the managed client, and the mock —
because all three implement the same interface. See [`opcua-client`
— testing reference](https://github.com/php-opcua/opcua-client/blob/master/docs/testing/integration.md).

## What ManagedClient adds

Three methods exist on `ManagedClient` that are **not** on
`OpcUaClientInterface`:

| Method                       | Purpose                                                |
| ---------------------------- | ------------------------------------------------------ |
| `connectForceNew(string)`    | Open a fresh session, ignoring any existing match      |
| `wasSessionReused(): bool`   | Whether the last `connect()` returned an existing session |
| `getSessionId(): ?string`    | The opaque daemon-side session ID                      |

`getEventDispatcher()` exists on the interface but does **not**
reach the daemon's events from the client side — see [Differences
from the direct client](./differences-from-direct-client.md).

## What ManagedClient routes through IPC

Every public operation. The transport cost is bounded by the
serialiser (`TypeSerializer`) plus the daemon's `query` / `invoke`
dispatch — typically a few milliseconds per call on a Unix socket,
dominated by the OPC UA round-trip itself.

For exotic typed arguments — third-party module DTOs, custom
ExtensionObjects — the daemon needs a `ParamDeserializerInterface`
to decode them on its side. See
[Extensibility · Custom param deserializer](../extensibility/custom-param-deserializer.md).
