# Roadmap

> **A note on versioning:** We're aware of the rapid major releases in a short time frame. This library is under active, full-time development right now — the goal is to reach a production-stable state as quickly as possible. Breaking changes are being bundled and shipped deliberately to avoid dragging them out across many minor releases. Once the API surface settles, major version bumps will become rare. Thanks for your patience.

## v4.0.0

### Features

- [ ] Multiple daemon instances — support running multiple daemons on different sockets for workload isolation (e.g. one per OPC UA server)
- [ ] Session tagging — allow clients to tag sessions with metadata (e.g. user ID, request context) for better observability in `list` output
- [ ] Config file support — YAML/JSON config file as alternative to CLI flags, with environment variable interpolation
- [ ] Metrics export — session count, operations/sec, latency percentiles, error rates via OpenTelemetry or Prometheus-compatible format
- [ ] Symfony integration — Symfony bundle wrapping `ManagedClient` with service container, config, and console commands (`gianfriaur/opcua-symfony-client`)
- [ ] Windows support — add TCP localhost transport as an alternative to Unix domain sockets for Windows compatibility. `ManagedClient` and `SessionManagerDaemon` would auto-detect the platform and use the appropriate transport. Socket permissions would be replaced by a different auth mechanism on Windows.

### Refactoring

- [ ] Config object — replace the `$config` associative array passed through IPC with a typed `SessionConfig` DTO for type safety
- [ ] CommandHandler method dispatch — replace the growing `match` block in `deserializeParams()` with a registry pattern for cleaner extensibility

## Won't Do (by design)

### Merge into opcua-php-client

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

Have a suggestion? Open an [issue](https://github.com/gianfriaur/opcua-php-client-session-manager/issues) or check the [contributing guide](CONTRIBUTING.md).
