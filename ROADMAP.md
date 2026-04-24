# Roadmap

## v4.3.0

### Completed

- [x] Bumped `php-opcua/opcua-client` from `^4.2.0` to `^4.3.0`.
- [x] Bumped CI test-server suite `uanetstandard-test-suite@v1.1.0` → `@v1.2.0`.
- [x] Flagged the persistent-cache security hardening from `opcua-client` v4.3.0 (`unserialize` → `WireCacheCodec` allowlist) — daemon picks it up automatically, users sharing a cache across processes should flush once on upgrade.
- [x] Added `--version` / `-v` flag on the daemon binary (`SessionManagerDaemon::VERSION`).
- [x] Propagated `ServiceUnsupportedException` across the IPC boundary as a distinct subclass, so `catch (ServiceUnsupportedException $e)` in user code works without string-matching.
- [x] Documentation sweep — doc/ and README version mentions now read `^4.3.0`; stale "v4.0.0 DTOs" wording replaced with "module DTOs" (DTOs moved to their module namespaces in v4.2.0).
- [x] Extracted `bin/opcua-session-manager` argv parser into `src/Cli/ArgvParser` (unit-testable; reports missing-value errors instead of silently dropping them).
- [x] Added `tests/Unit/ManagedClientTcpTest.php` to give cross-OS coverage to the ManagedClient IPC error-mapping path (`ManagedClientIpcTest` is still Unix-only via `->skipOnWindows()`).
- [x] Replaced a fragile `basename(str_replace('\\', '/', …))` short-class-name hack in `CommandHandler` with `ReflectionClass::getShortName()`.
- [x] **Security audit findings addressed**: socket-file permission race closed via `umask(0077)` around `SocketServer` bind; `username` stripped from the `list` IPC response (session-lookup cache key unchanged); per-frame 64 KiB cap added on inbound NDJSON.

## v4.2.0

### Completed

- [x] **Wire-serialization pipeline** — new `PhpOpcua\Client\Wire` namespace on `opcua-client` provides a `JSON_THROW_ON_ERROR`-strict typed-envelope codec. Every built-in DTO declares a stable `wireTypeId`; the `WireTypeRegistry` rejects unregistered `__t` discriminators at decode time, enforcing an explicit type allowlist on every IPC frame.
- [x] **Transport abstraction** in `src/Ipc/` — `TransportInterface` + `AbstractStreamTransport` + `UnixSocketTransport` + `TcpLoopbackTransport`. `WireMessageCodec` handles NDJSON framing with 16 MiB / 32-level caps. All transports open streams in binary mode (Windows-safe).
- [x] **Describe + invoke generic dispatch** — `CommandHandler` exposes two new commands; `ManagedClient::__call()` proxies arbitrary method names (third-party modules work out of the box); `hasMethod` / `hasModule` / `getRegisteredMethods` / `getLoadedModules` resolve from a cached describe round-trip.
- [x] `ManagedClient`'s four NodeManagement methods (`addNodes`, `deleteNodes`, `addReferences`, `deleteReferences`) now delegate through `invoke` instead of throwing `BadMethodCallException`.
- [x] **Windows transport wiring** — `TransportFactory` routes `unix://` / `tcp://` / scheme-less endpoints through the matching `TransportInterface`; `SocketConnection::send()` delegates there. `SessionManagerDaemon` listener uses `React\Socket\SocketServer` and accepts either scheme, with a construction-time loopback-only guard for TCP bindings. `config/defaults.php` picks per-OS default via `TransportFactory::defaultEndpoint()`. CI unit-test matrix mirrors `opcua-client` (Linux, macOS, Windows × PHP 8.2–8.5).

### Refactoring

- [x] **Config object** — `$config` array consumption in `CommandHandler::handleOpen()` now goes through a typed readonly `PhpOpcua\SessionManager\Daemon\SessionConfig` DTO with `fromArray` / `toArray` / `sanitized()` helpers. The wire format stays a plain JSON object for backwards compatibility; conversion happens at the boundary.
- [x] **CommandHandler method dispatch** — the 200-line `match` previously living inside `CommandHandler::deserializeParams()` is now a `PhpOpcua\SessionManager\Serialization\ParamDeserializerRegistry` that delegates to one or more `ParamDeserializerInterface` implementations. The shipped behaviour lives in `BuiltInParamDeserializer`; third-party modules can register their own deserializer via `CommandHandler::registerParamDeserializer()` without patching the command handler.

## Completed in v4.1.0

- [x] **ECC security policy support** — all daemon operations work with `ECC_nistP256`, `ECC_nistP384`, `ECC_brainpoolP256r1`, `ECC_brainpoolP384r1` (auto-generated ECC certificates, EccEncryptedSecret for username/password)
- [x] Bumped `php-opcua/opcua-client` dependency from `^4.0` to `^4.1`
- [x] Security support expanded from 6 to **10 policies** (6 RSA + 4 ECC)
- [x] Updated all documentation (README, doc/, llms.txt, llms-full.txt, llms-skills.md)

## Completed in v4.0.0

- [x] Trust store support — certificate trust store for managing trusted/rejected certificates
- [x] Event dispatcher — hook into session lifecycle events (created, expired, closed, error)
- [x] ClientBuilder pattern — `ClientBuilder::create()->connect()` replaces `new Client()`
- [x] Write auto-detection — `write($nodeId, $value)` infers the OPC UA type automatically
- [x] Rebranding — all packages moved from `gianfriaur/*` to `php-opcua/*` organization

## Won't Do (by design)

### Windows-native named-pipe transport

Windows Named Pipes (`\\.\pipe\<name>`) were evaluated as an alternative to TCP
loopback on Windows and **deliberately not pursued**. TCP loopback is the
supported Windows IPC transport; the rationale follows so future contributors
don't re-open the investigation without new information.

**Why named pipes would be attractive.** They are the native Windows IPC
primitive (no port binding, no firewall surface, ACL-based access control,
~20–30 % lower latency than TCP loopback on micro-benchmarks).

**Why they are not worth the implementation cost.**

- **No pure-PHP server-side path.** `React\Socket\SocketServer` only supports
  `tcp://`, `tls://`, and `unix://`. PHP has no userland API to *create* a
  named-pipe server (`CreateNamedPipeA`/`ConnectNamedPipe`/overlapped
  `ReadFile`/`WriteFile`). The only options are FFI against `kernel32.dll`
  (requires `ffi.enable=true` in `php.ini`, off by default in most Windows PHP
  builds) or a PECL extension (e.g. `win32-service`), both of which add a
  runtime dependency and a non-trivial maintenance surface.
- **No ReactPHP event-loop integration.** The event loop multiplexes via
  `stream_select`, which operates on file descriptors. Windows `HANDLE`s
  returned by `CreateNamedPipe` are not file descriptors — integrating them
  requires either `IoCompletionPort` (more FFI) or user-space polling with
  `PeekNamedPipe`, which reintroduces most of the latency that named pipes
  were supposed to save.
- **No security win in practice.** The named-pipe ACL advantage is nominal:
  both transports go through the daemon's `authToken` (hash-equals compare),
  and the TCP path is already bound to loopback with a construction-time
  guard on both client and daemon. Trust posture is effectively the same as
  every database driver that talks to `127.0.0.1`.
- **Maintenance + CI cost.** A working named-pipe transport would need a
  Windows CI runner with FFI enabled and a bespoke test harness — nontrivial
  infra to keep green for a feature whose practical benefit is negligible.

**When this decision might be revisited.** If a concrete user reports a policy
constraint that specifically forbids `127.0.0.1` bindings (e.g. a hardened
Windows deployment with no loopback allowance), or if a reliable FFI-based
pipe library lands in the PHP ecosystem and gets battle-tested, this entry
can be reopened.

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
