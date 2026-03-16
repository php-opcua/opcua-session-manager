# Testing

## Prerequisites

To run integration tests, the OPC UA test server suite must be running:

```bash
cd /path/to/opcua-test-server-suite
docker compose up -d
```

The suite starts 8 servers on ports 4840-4847 with different security configurations:

| Port | Server | Security |
|------|--------|----------|
| 4840 | opcua-no-security | None / Anonymous |
| 4841 | opcua-userpass | Basic256Sha256 / Username+Password |
| 4842 | opcua-certificate | Basic256Sha256 / X.509 Certificate |
| 4843 | opcua-all-security | All policies / All auth methods |
| 4844 | opcua-discovery | Discovery server |
| 4845 | opcua-auto-accept | Basic256Sha256 / Auto-accept certs |
| 4846 | opcua-sign-only | Basic256Sha256 Sign / Anonymous+Username |
| 4847 | opcua-legacy-security | Basic128Rsa15, Basic256 / Anonymous+Username |

## Running tests

### All tests

```bash
vendor/bin/pest
```

### Unit tests only (no server required)

```bash
vendor/bin/pest tests/Unit
```

### Integration tests only

```bash
vendor/bin/pest tests/Integration --group=integration
```

### A single test file

```bash
vendor/bin/pest tests/Integration/BrowseTest.php
vendor/bin/pest tests/Unit/TypeSerializerTest.php
```

## Test structure

### Unit tests (`tests/Unit/`)

No external resources required (no daemon, no OPC UA server).

| File | What it tests |
|------|---------------|
| `TypeSerializerTest.php` | Serialization/deserialization of all OPC UA types, roundtrips, edge cases |
| `SessionStoreTest.php` | Session registry: create, get, remove, touch, expiry, count |
| `CommandHandlerSecurityTest.php` | Method whitelist, connect/disconnect rejection, credential stripping, max sessions, certificate path validation, error message sanitization, unknown commands |

### Integration tests (`tests/Integration/`)

Require the Docker OPC UA servers to be running. The daemon is started and stopped automatically via `beforeAll`/`afterAll` in each test file.

| File | What it tests |
|------|---------------|
| `DaemonTest.php` | IPC commands: ping, list, open, close, errors, concurrent sessions |
| `ConnectionTest.php` | Anonymous connection, username/password, certificate, reconnect, invalid host/port errors |
| `BrowseTest.php` | Browse Objects, TestServer, DataTypes, Methods; inverse browse, by reference type, with continuation |
| `ReadTest.php` | Scalar reads (Boolean, Int32, Double, String, Float, Byte, UInt16), readMulti, arrays, ServerState, Temperature |
| `WriteTest.php` | Scalar write and readback, writeMulti, arrays, write to read-only nodes |
| `MethodCallTest.php` | Add, Multiply, Concatenate, Reverse, Echo, Failing (BadInternalError) |
| `SubscriptionTest.php` | Create subscription/monitored items, publish, delete, multiple subscriptions |
| `SessionPersistenceTest.php` | Session persistence across ManagedClient instances, isolation, independent disconnect |
| `SecurityTest.php` | Method whitelist via IPC (including connect/disconnect rejection), credential stripping, buffer overflow, socket permissions, auth token (with dedicated auth daemon), max sessions enforcement |

## TestHelper

The class `Gianfriaur\OpcuaSessionManager\Tests\Integration\Helpers\TestHelper` provides:

### Constants

- Endpoint URLs for all 8 test servers
- User credentials (admin, operator, viewer, test)
- Standard NodeIds (Root, Objects, Server, ServerStatus, ServerState)

### Methods

| Method | Description |
|--------|-------------|
| `startDaemon()` | Start the daemon on a dedicated test socket |
| `stopDaemon()` | Stop the daemon and clean up the socket |
| `isDaemonRunning()` | Check if the daemon is active |
| `createManagedClient()` | Create a `ManagedClient` configured for the test socket |
| `connectNoSecurity()` | Create and connect a `ManagedClient` to the no-security server |
| `browseToNode($client, $path)` | Navigate the address space by browse name |
| `findRefByName($refs, $name)` | Find a reference by name in a `ReferenceDescription` array |
| `safeDisconnect($client)` | Disconnect suppressing exceptions |
| `getCertsDir()` / `getClientCertPath()` / `getClientKeyPath()` / `getCaCertPath()` | Certificate paths |

### Test daemons

The **standard test daemon** uses:
- Socket: `/tmp/opcua-session-manager-test.sock`
- Timeout: 60 seconds
- Cleanup interval: 5 seconds
- Auth: none (backward-compatible mode)

The **security test daemon** (used by `SecurityTest.php`) uses:
- Socket: `/tmp/opcua-session-manager-security-test.sock`
- Timeout: 60 seconds
- Cleanup interval: 5 seconds
- Auth token: configured at test runtime
- Max sessions: 3 (to test limit enforcement)

Both are started automatically by `beforeAll` and stopped by `afterAll`. Multiple calls to `startDaemon()` are idempotent (the daemon is started only once).

## Certificates

Certificate paths are resolved relative to the `server/certs/` directory of the `opcua-test-server-suite` project. They can be overridden with the `OPCUA_CERTS_DIR` environment variable:

```bash
OPCUA_CERTS_DIR=/custom/certs/path vendor/bin/pest tests/Integration
```

## Adding new tests

Standard pattern for an integration test:

```php
<?php

declare(strict_types=1);

use Gianfriaur\OpcuaSessionManager\Tests\Integration\Helpers\TestHelper;

beforeAll(fn() => TestHelper::startDaemon());
afterAll(fn() => TestHelper::stopDaemon());

describe('My Feature', function () {

    it('does something', function () {
        $client = null;
        try {
            $client = TestHelper::connectNoSecurity();

            // ... test logic ...

            expect($result)->toBe($expected);
        } finally {
            TestHelper::safeDisconnect($client);
        }
    })->group('integration');

})->group('integration');
```

Key points:
- `beforeAll`/`afterAll` outside the `describe` (Pest limitation)
- Each test manages its own connection in `try/finally`
- `safeDisconnect` in the `finally` block for cleanup
- `integration` group for filtering
