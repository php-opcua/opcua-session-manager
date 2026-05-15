---
eyebrow: 'Docs · Recipes'
lede:    'Update the package, flush persistent caches, simplify error handling that string-matched on the message. ServiceUnsupportedException now propagates correctly across the IPC boundary.'

see_also:
  - { href: '../daemon/security-hardening.md',  meta: '6 min' }
  - { href: '../reference/exceptions.md',       meta: '7 min' }
  - { href: 'https://github.com/php-opcua/opcua-client/blob/master/CHANGELOG.md', meta: 'external', label: 'opcua-client CHANGELOG' }

prev: { label: 'Exceptions',                    href: '../reference/exceptions.md' }
next: { label: 'Persistent sessions in Laravel', href: './persistent-sessions-laravel.md' }
---

# Upgrading to v4.3

v4.3 is mostly a security and consistency consolidation. Two
visible changes are worth knowing about; the rest are operational
hardenings you inherit for free.

## Step 1 — Update Composer

<!-- @code-block language="bash" label="terminal" -->
```bash
composer require php-opcua/opcua-session-manager:^4.3
```
<!-- @endcode-block -->

This pulls v4.3.1 (or whatever the latest patch happens to be) and
the matching `opcua-client ^4.3` as a transitive dependency. Pin
tighter (`~4.3.0`) if you want to vet patches before they roll out.

## Step 2 — Flush persistent caches

Inherited from `opcua-client` v4.3.0: the cache codec changed from
`serialize()`-based to JSON gated by a type allowlist
(`Cache\WireCacheCodec`). The daemon uses this codec for every
`Client` it constructs. **Old cache entries cannot be decoded by
the new codec.**

The daemon catches the resulting `CacheCorruptedException`
internally and treats those entries as misses — the next request
refetches from the OPC UA server. That works, but it adds a cold-
cache window after deploy.

Flush the persistent cache directory to skip the window:

<!-- @code-block language="bash" label="terminal — file-cache flush" -->
```bash
# Only if --cache-driver=file
rm -rf /var/cache/opcua/*
```
<!-- @endcode-block -->

For `memory` caches, no action is needed — the daemon restart
already resets the cache.

For a Redis or other PSR-16 backend wired into a custom embedded
daemon, flush whatever keyspace your cache uses. The library does
not impose a key prefix on the cache.

## Step 3 — Simplify ServiceUnsupportedException handling

Before v4.3.0, `ServiceUnsupportedException` (raised by
`opcua-client` when an OPC UA server replies with
`BadServiceUnsupported`) was **flattened** at the IPC boundary
into a generic `ServiceException`. Code that wanted to react to
it had to string-match the message:

<!-- @do-dont -->
<!-- @do -->
```php
use PhpOpcua\Client\Exception\ServiceUnsupportedException;

try {
    $client->addNodes($nodes);
} catch (ServiceUnsupportedException $e) {
    // Server does not implement NodeManagement.
    $caps->nodeManagement = false;
}
```
<!-- @enddo -->
<!-- @dont -->
```php
use PhpOpcua\Client\Exception\ServiceException;

try {
    $client->addNodes($nodes);
} catch (ServiceException $e) {
    if (str_contains($e->getMessage(), 'BadServiceUnsupported')) {
        // brittle pre-v4.3 workaround — drop this
        $caps->nodeManagement = false;
    } else {
        throw $e;
    }
}
```
<!-- @enddont -->
<!-- @enddo-dont -->

If you have the brittle form anywhere in the codebase, this is the
time to replace it. See [Recipes · Handling unsupported services](https://github.com/php-opcua/opcua-client/blob/master/docs/operations/managing-nodes.md)
in `opcua-client`'s docs for the recommended capability-probe
pattern.

## Step 4 — Verify the daemon version

Run the daemon binary with `--version`:

<!-- @code-block language="bash" label="terminal — verify" -->
```bash
vendor/bin/opcua-session-manager --version
# → opcua-session-manager 4.3.1
```
<!-- @endcode-block -->

If you see anything older, the upgrade did not land in the
deployed package — check your Composer install path. `--version`
was added in v4.3.0, so if the flag is unrecognised, you are on
v4.2 or earlier.

## What did not change (visible surface)

- **`ManagedClient` public API.** Every method that worked in v4.2
  works in v4.3.
- **IPC envelope shape.** Still the flat `{command, sessionId?,
  method?, params?, args?, authToken?}` request and
  `{success, data | error}` response — see
  [IPC · Envelope and framing](../ipc/envelope-and-framing.md).
- **CLI flag names and defaults.** Same set as v4.2.
- **Allowed methods on `query`.** Same 44 entries.

## What did change (operational)

### Inherited from v4.3.0

- **Socket file permission race closed** — `umask(0077)` around the
  bind makes the socket `0600` atomically.
- **`username` redacted from `list`** — pre-v4.3 sessions exposed
  `username` to local IPC peers.
- **Per-frame size cap** — 64 KiB per inbound frame; oversized
  frames respond `payload_too_large` and close the connection.
- **IPv6 loopback consistency** — `::ffff:127.*` accepted,
  `::ffff:*` non-loopback rejected.
- **`sanitizeErrorMessage` redacts Windows paths and URLs** — was
  Unix-only before.
- **Conservative PID liveness check** — on sandboxed hosts where
  neither `posix_kill` nor `/proc` is available, the daemon
  refuses to launch rather than steal a PID file.
- **Persistent cache hardening** (the codec change above).

### Added in v4.3.1

- **Unix-socket path length validation** — paths exceeding the
  kernel cap (108 on Linux, 104 on Darwin) are rejected at startup
  with an explicit error instead of a confusing `chmod()` failure.
- **`TransportFactory::assertUnixPathFits()`** — public helper if
  you want to validate paths before constructing transports.

See the
[full CHANGELOG](https://github.com/php-opcua/opcua-session-manager/blob/master/CHANGELOG.md)
for the line-by-line list.

## Rollback

The IPC envelope and the API surface are unchanged. Rolling back
to v4.2 is safe:

<!-- @code-block language="bash" label="terminal — rollback" -->
```bash
composer require php-opcua/opcua-session-manager:^4.2
```
<!-- @endcode-block -->

The only edge case: a daemon and `ManagedClient` should be the
**same major + minor** version. A v4.3 client against a v4.2
daemon mostly works — the flat envelope is identical — but the
`ServiceUnsupportedException` propagation degrades to
`ServiceException`. Roll back daemon and client together if you
roll back.
