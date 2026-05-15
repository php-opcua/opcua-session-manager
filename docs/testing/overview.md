---
eyebrow: 'Docs · Testing'
lede:    'Three test layers, three audiences. Unit tests run against PHP stubs; ManagedClient IPC tests run against a forked fake daemon; integration tests run against the real daemon and the upstream test suite.'

see_also:
  - { href: 'https://github.com/php-opcua/opcua-client/blob/master/docs/testing/integration.md', meta: 'external', label: 'opcua-client — testing' }
  - { href: '../recipes/healthcheck-and-monitoring.md',  meta: '5 min' }
  - { href: 'https://github.com/php-opcua/uanetstandard-test-suite', meta: 'external', label: 'uanetstandard-test-suite' }

prev: { label: 'Third-party modules',          href: '../extensibility/third-party-modules.md' }
next: { label: 'Daemon CLI reference',         href: '../reference/daemon-cli.md' }
---

# Testing

The library's own test suite is organised in three layers, each
with a different purpose. The same layering is a reasonable model
for application code that depends on the session manager.

## Layers

| Layer            | What it tests                                                | Where               | Cross-OS?           |
| ---------------- | ------------------------------------------------------------ | ------------------- | ------------------- |
| Unit             | Daemon classes, codec, transport guards, type serializer     | `tests/Unit`        | Yes                 |
| IPC (fork-based) | `ManagedClient` ↔ daemon round-trip via a forked fake daemon | `tests/Unit/ManagedClientIpcTest.php` | POSIX only |
| IPC (TCP-based)  | Same surface via TCP loopback + `proc_open()`                | `tests/Unit/ManagedClientTcpTest.php` | Cross-OS  |
| Integration      | Real daemon + real `opcua-client` + real OPC UA test server  | `tests/Integration` | POSIX (Docker)      |

Unit and IPC-TCP run on every CI matrix leg (Linux, macOS, Windows
× PHP 8.2–8.5). IPC-fork is `->skipOnWindows()` because
`pcntl_fork()` is POSIX-only. Integration runs only when both
test-server suites are available.

## Running

<!-- @code-block language="bash" label="terminal" -->
```bash
# Unit + IPC tests
vendor/bin/phpunit --testsuite=unit

# Integration tests (Docker daemon + upstream OPC UA test servers needed)
vendor/bin/phpunit --testsuite=integration

# Single file
vendor/bin/phpunit tests/Unit/CommandHandlerSecurityTest.php

# Filter by test name
vendor/bin/phpunit --filter=sanitize
```
<!-- @endcode-block -->

### Coverage

The repo's `phpunit.xml` produces XML and HTML coverage in
`coverage/`. The integration suite extends coverage of the
`ManagedClient` ↔ daemon ↔ `opcua-client` ↔ server path —
unit-only runs underreport.

## Integration prerequisites

The integration suite needs both upstream test suites running:

| Suite                                                       | Provides                                              |
| ----------------------------------------------------------- | ----------------------------------------------------- |
| [`php-opcua/uanetstandard-test-suite`](https://github.com/php-opcua/uanetstandard-test-suite) | 8 OPC UA endpoints covering every security policy and auth flow |
| [`php-opcua/extra-test-suite`](https://github.com/php-opcua/extra-test-suite)                | open62541 with NodeManagement on `:24840`            |

Start both with `docker compose up -d` from their respective clones.
The endpoints are hardcoded in `tests/Integration/Helpers/TestHelper`
— no env-var indirection. The integration suite also starts its own
daemon as a fixture per test class.

See [opcua-client testing reference](https://github.com/php-opcua/opcua-client/blob/master/docs/testing/integration.md)
for the test-server topology.

## Application tests against the session manager

For an application that depends on `ManagedClient`, the testing
strategy depends on what you want to cover:

### Unit-test application logic

Test against `MockClient` from `opcua-client`. `ManagedClient`,
`MockClient`, and the direct `Client` all implement
`OpcUaClientInterface` — depend on the interface, swap the
implementation per test:

<!-- @code-block language="php" label="examples/test-with-mock.php" -->
```php
use PhpOpcua\Client\Testing\MockClient;
use PhpOpcua\Client\Types\DataValue;

it('records the speed value', function () {
    $client = MockClient::create();
    $client->onRead('ns=2;s=Speed', fn() => DataValue::ofDouble(42.5));

    $service = new SpeedService($client);
    $service->refresh();

    expect($service->speed())->toBe(42.5);
});
```
<!-- @endcode-block -->

No daemon, no IPC — the test runs in pure PHP. See
[opcua-client testing — MockClient](https://github.com/php-opcua/opcua-client/blob/master/docs/testing/integration.md).

### Integration-test the full pipeline

Run the daemon as a test fixture, build a `ManagedClient` pointing
at it, run your application code, assert on the result.

<!-- @code-block language="php" label="examples/integration-fixture.php" -->
```php
use PhpOpcua\SessionManager\Daemon\SessionManagerDaemon;
use PhpOpcua\SessionManager\Client\ManagedClient;

beforeAll(function () {
    // Start daemon in a subprocess; capture PID for teardown.
    $this->daemonPid = startDaemonSubprocess('/tmp/test-opcua.sock');

    $this->client = (new ManagedClient('/tmp/test-opcua.sock'))
        ->setSecurityPolicy(SecurityPolicy::None)
        ->setSecurityMode(SecurityMode::None);

    $this->client->connect('opc.tcp://localhost:4840');
});

afterAll(function () {
    posix_kill($this->daemonPid, SIGTERM);
});

it('reads a real value', function () {
    $dv = $this->client->read('i=2261');
    expect($dv->statusCode)->toBe(0);
});
```
<!-- @endcode-block -->

This pattern lives in `tests/Integration/Helpers/TestHelper.php` —
borrow it as a template.

### Healthcheck tests

Probe the daemon directly with `SocketConnection`:

<!-- @code-block language="php" label="examples/healthcheck-test.php" -->
```php
use PhpOpcua\SessionManager\Client\SocketConnection;

it('responds to ping in under 100ms', function () {
    $start = microtime(true);
    $response = SocketConnection::send('/tmp/test-opcua.sock', [
        'command' => 'ping',
    ], timeout: 1.0);

    expect($response['success'])->toBeTrue();
    expect(microtime(true) - $start)->toBeLessThan(0.1);
});
```
<!-- @endcode-block -->

## Cross-OS test discipline

When writing tests that touch the IPC layer:

- **Mark `pcntl_fork()` tests `->skipOnWindows()`.** The harness
  helper `forkFakeDaemon()` only works on POSIX.
- **Prefer `proc_open()` over `pcntl_fork()`** when possible —
  `ManagedClientTcpTest` is the model. `proc_open()` works on
  every platform.
- **Bind TCP fixtures on `tcp://127.0.0.1:0`** — port 0 lets the
  kernel pick a free port, avoiding flake from port conflicts.

The library follows these rules; mirror them in application tests
that depend on a daemon fixture.

## What does *not* need testing

- **`opcua-client`'s own surface.** Trust the upstream suite. Test
  your wrapping logic, not `read()` behaviour.
- **`TypeSerializer` round-trips.** Covered exhaustively in
  `tests/Unit/Serialization/TypeSerializerTest.php`. If your code
  goes through `TypeSerializer`, you don't need to retest it.
- **IPC envelope structure.** Covered in
  `tests/Unit/Ipc/WireMessageCodecTest.php`. Application tests
  should rely on `ManagedClient`'s typed surface, not on raw IPC
  shapes.

## TestHelper

The integration suite ships `tests/Integration/Helpers/TestHelper`
with helpers for the common patterns:

- `connectForNoSecurity()` — `ManagedClient` against the unsecured
  test endpoint
- `connectForUserpass()` — username / password endpoint
- `connectForBasic256Sha256Encrypt()` — secured endpoint
- `daemonSocket()` — path to the daemon fixture's socket
- `startDaemon()` — boot the fixture daemon
- `stopDaemon()` — teardown

Read it as a template for your own integration harness.
