# Roadmap

## v4.1.0

### Features

- [ ] Metrics export — session count, operations/sec, latency percentiles, error rates via OpenTelemetry or Prometheus-compatible format
- [ ] Windows support — add TCP localhost transport as an alternative to Unix domain sockets for Windows compatibility. `ManagedClient` and `SessionManagerDaemon` would auto-detect the platform and use the appropriate transport. Socket permissions would be replaced by a different auth mechanism on Windows.

### Refactoring

- [ ] Config object — replace the `$config` associative array passed through IPC with a typed `SessionConfig` DTO for type safety
- [ ] CommandHandler method dispatch — replace the growing `match` block in `deserializeParams()` with a registry pattern for cleaner extensibility

## Completed in v4.0.0

- [x] Trust store support — certificate trust store for managing trusted/rejected certificates
- [x] Event dispatcher — hook into session lifecycle events (created, expired, closed, error)
- [x] ClientBuilder pattern — `ClientBuilder::create()->connect()` replaces `new Client()`
- [x] Write auto-detection — `write($nodeId, $value)` infers the OPC UA type automatically
- [x] Rebranding — all packages moved from `gianfriaur/*` to `php-opcua/*` organization

## Won't Do (by design)

### Merge into opcua-client

The session manager is intentionally kept as a separate package and will not be merged into the client library. The reasons:

- **Cross-platform compatibility.** The client works on Linux, macOS, and Windows. The session manager uses Unix domain sockets, which are not available on Windows.
- **Zero-dependency philosophy.** The client requires only `ext-openssl`. The session manager depends on `react/event-loop` and `react/socket` — pulling those into the client would force every user to install ReactPHP.
- **Architectural separation.** The client is a synchronous library. The session manager runs as a separate long-lived daemon process with an async event loop. These are fundamentally different execution models.
- **The daemon is a separate process anyway.** Even if the code lived in the same package, you'd still need to start a separate `php bin/opcua-session-manager` process.

### Shared Memory / APCu Session Store

The session store is intentionally in-memory within the daemon process. Using shared memory (shmop, APCu) would couple the daemon to specific PHP extensions, complicate the architecture (multiple processes accessing shared state), and provide no meaningful benefit — the daemon is already a single long-lived process that holds all sessions.

### HTTP/WebSocket IPC

Unix sockets are faster, simpler, and more secure (file permissions) than HTTP or WebSocket for local IPC. Adding an HTTP interface would require an HTTP server dependency, increase attack surface, and provide no benefit for the intended use case (same-host communication).

---

Have a suggestion? Open an [issue](https://github.com/php-opcua/opcua-session-manager/issues) or check the [contributing guide](CONTRIBUTING.md).
