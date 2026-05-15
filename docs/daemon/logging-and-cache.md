---
eyebrow: 'Docs · Daemon'
lede:    'One PSR-3 logger and one PSR-16 cache, both wired by the bin script from CLI flags. The cache lives inside every Client the daemon constructs — its lifetime is the daemon''s, not the request''s.'

see_also:
  - { href: './configuration.md',          meta: '6 min' }
  - { href: './security-hardening.md',     meta: '6 min' }
  - { href: 'https://github.com/php-opcua/opcua-client/blob/master/docs/operations/browsing.md', meta: 'external', label: 'opcua-client — caching reference' }

prev: { label: 'Security hardening', href: './security-hardening.md' }
next: { label: 'Auto-connect',       href: './auto-connect.md' }
---

# Logging and cache

The daemon's diagnostic surface is small: a single PSR-3 logger and
a single PSR-16 cache backend. Both are configured at startup, both
live for the daemon's entire lifetime, both flow through every OPC
UA `Client` the daemon constructs.

## Logging

### CLI options

| Flag                   | Default      | Effect                                                |
| ---------------------- | ------------ | ----------------------------------------------------- |
| `--log-file <path>`    | `null` → stderr | Write logs to a file path                          |
| `--log-level <level>`  | `info`       | Minimum level: `debug`, `info`, `notice`, `warning`, `error` |

The bin script wires a `StreamLogger` configured from those flags.

<!-- @code-block language="bash" label="terminal — log to file" -->
```bash
vendor/bin/opcua-session-manager \
    --log-file /var/log/opcua/sessions.log \
    --log-level info
```
<!-- @endcode-block -->

### What gets logged

| Level     | Examples                                                                |
| --------- | ----------------------------------------------------------------------- |
| `error`   | Bind failure, unhandled exception, IPC connection refused mid-frame     |
| `warning` | Session recovery triggered, expired-session cleanup with active subs, frame size cap hit |
| `notice`  | Session created / closed, auto-connect successful, daemon shutdown      |
| `info`    | Startup banner, configuration summary, periodic cleanup stats           |
| `debug`   | Per-command IPC trace, OPC UA call dispatch, cache hits/misses          |

Default `info` is right for production. `debug` is verbose enough to
saturate a busy daemon's disk — turn it on for diagnostics, off
otherwise.

### Format

`StreamLogger` writes a single line per entry:

<!-- @code-block language="text" label="sample log lines" -->
```text
[2026-05-15 08:30:12] [INFO] OPC UA Session Manager started on unix:///tmp/opcua-session-manager.sock
[2026-05-15 08:30:42] [INFO] Session a1b2c3 expired (endpoint: opc.tcp://plc.local:4840)
[2026-05-15 08:31:05] [WARNING] Connection lost for session a1b2c3, attempting recovery
```
<!-- @endcode-block -->

Each line is `[YYYY-MM-DD HH:MM:SS] [LEVEL] message`. The timestamp
is local time (no timezone, no `T` separator), `LEVEL` is the
PSR-3 level uppercased, and the message is the result of
interpolating `{key}` placeholders with the context array — there
is **no** trailing JSON blob of context fields. The daemon does
**not** emit a per-cleanup summary line; the only cleanup-related
log entries are the per-session expiry lines shown above.

The format is hardcoded. For richer structure (JSON-only logs,
trace IDs, custom processors), embed the daemon
(see [Starting · Programmatic embedding](./starting.md#section-programmatic-embedding))
and pass your own PSR-3 logger to the constructor.

### Two PSR-3 loggers in play

The daemon wires its logger in two places:

- **The daemon itself** — startup, session lifecycle, IPC errors.
- **Every OPC UA `Client` the daemon constructs** — connect, retry,
  protocol-level events. The same logger handles both, so a single
  log file captures everything.

If your application also uses `opcua-client` directly (outside the
daemon), that direct-client logger is independent of the daemon's
logger. Two clients, two logger wirings.

### Sensitive payloads

The daemon does **not** log:

- Auth tokens
- OPC UA passwords supplied through `open`
- Certificate or key bytes
- Variant values (no observation-level data leakage)

It **does** log:

- Endpoint URLs (architectural info)
- NodeIds (also architectural)
- Status codes
- Timing
- Session IDs (opaque opaque server-assigned tokens)

Sanitiser regexes redact filesystem paths and URL credentials from
error messages — see [Security hardening](./security-hardening.md).
If your monitoring captures logs, the redaction discipline is
adequate for sharing.

## Cache

### CLI options

| Flag                    | Default   | Effect                                              |
| ----------------------- | --------- | --------------------------------------------------- |
| `--cache-driver <d>`    | `memory`  | `memory`, `file`, or `none`                         |
| `--cache-path <path>`   | none      | Required when `--cache-driver=file`                 |
| `--cache-ttl <seconds>` | `300`     | Default TTL applied to all cached entries           |

The bin script translates these into an `InMemoryCache`, a
`FileCache`, or `null` (disabled), then hands the result to the
daemon as the `clientCache` constructor argument.

### Where the cache lives

Each OPC UA `Client` the daemon constructs **gets the same cache
instance**. Two consequences:

- **Cross-session reuse.** A browse result cached during session A's
  initial discovery is available to session B if the cache key
  matches (which it does — keys include the endpoint hash, not the
  session ID). Multiple sessions targeting the same server share
  their caching effort.
- **Long lifetime.** The cache lives for the daemon's lifetime,
  which is hours to days. The `--cache-ttl` is the only expiration
  mechanism; without TTL, the cache would grow unboundedly.

This is **fundamentally different** from caching in a direct
`opcua-client` setup, where the cache is request-scoped (or
process-scoped at most). The cross-session reuse is one of the
under-advertised wins of running through the daemon.

### Driver choice

| Driver   | Persistent across daemon restart? | Shared across daemon instances? | When                                              |
| -------- | --------------------------------- | -------------------------------- | ------------------------------------------------- |
| `memory` | No                                | No                               | Single-daemon dev / staging; small address spaces  |
| `file`   | Yes                               | Yes (same dir)                   | Production, large address spaces, frequent restarts |
| `none`   | —                                 | —                                | Adversarial environments where any cache is risk   |

The cache passes through the v4.3.0 `Cache\WireCacheCodec` —
JSON-only, gated by an allowlist, no `unserialize()`. The hardening
matters more for the file driver (the on-disk format is exposed) than
for memory (in-process only). See
[`opcua-client` — caching reference](https://github.com/php-opcua/opcua-client/blob/master/docs/operations/browsing.md).

### What gets cached

Inherited from `opcua-client`'s cache wiring:

- Browse, `browseAll`, `resolveNodeId` results
- `getEndpoints` results
- `discoverDataTypes` results
- Read metadata (when the per-session `setReadMetadataCache(true)` is
  enabled on the `open` config)
- **`Value` attribute reads are never cached.**

The daemon does not introduce additional caching at the IPC layer —
the cache state is entirely the OPC UA client's.

### Invalidation

Per-NodeId invalidation flows through the IPC `query` command:

<!-- @code-block language="php" label="examples/invalidate.php" -->
```php
$client->invalidateCache('ns=2;s=Devices/PLC/Speed');
$client->flushCache();
```
<!-- @endcode-block -->

Both calls reach the daemon and act on the **daemon-side** cache —
the only cache that exists in this architecture. There is no
per-client cache in `ManagedClient` itself.

### Cache and session reuse

Two `ManagedClient`s targeting the same endpoint with the same
config share **both** the OPC UA session and the cache. Cross-
session cache hits are common in worker pools — the first worker
warms the cache, every subsequent worker pays only the IPC round-trip
for the lookup.

For the keying details, see [ManagedClient · Session
reuse](../managed-client/session-reuse.md).
