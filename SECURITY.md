# Security Policy

## Supported Versions

| Version | Supported |
|---------|-----------|
| 4.x     | Yes       |
| 3.x     | No        |
| 2.x     | No        |
| 1.x     | No        |

## Reporting a Vulnerability

If you discover a security vulnerability in this library, please report it responsibly.

**Do not open a public issue.** Instead, send an email to [gianfri.aur@gmail.com](mailto:gianfri.aur@gmail.com) with:

- A description of the vulnerability
- Steps to reproduce
- The affected version(s)
- Any potential impact assessment

You should receive an acknowledgment within 48 hours. From there, we'll work together to understand the scope and develop a fix before any public disclosure.

## Scope

This policy covers the `php-opcua/opcua-session-manager` library itself. For vulnerabilities in dependencies or related packages, please report them to the respective maintainers:

- [opcua-client](https://github.com/php-opcua/opcua-client)
- [laravel-opcua](https://github.com/php-opcua/laravel-opcua)
- [uanetstandard-test-suite](https://github.com/php-opcua/uanetstandard-test-suite)

## Security Considerations

The session manager daemon runs as a long-lived process handling OPC UA connections for multiple PHP clients. Security is enforced at multiple levels:

### IPC Layer

- **Authentication** — shared-secret token validated with timing-safe `hash_equals()`. Use `OPCUA_AUTH_TOKEN` env var or `--auth-token-file` (never `--auth-token` in production — visible in `ps`)
- **Socket permissions** — `0600` by default (owner-only read/write). Adjust with `--socket-mode`
- **Method whitelist** — only 37 documented OPC UA operations allowed. Setters, `connect`, `disconnect`, and PHP magic methods are blocked
- **Input limits** — 1MB max request size, 30s per-connection timeout, 50 max concurrent IPC connections

### Credential Protection

- Passwords and private key paths are stripped from session metadata immediately after connection
- The `list` command never exposes sensitive fields
- Error messages are truncated and file paths replaced with `[path]`

### OPC UA Layer

When deploying in production:

- Use `SecurityPolicy::Basic256Sha256` or stronger
- Use `SecurityMode::SignAndEncrypt`
- Provide proper CA-signed certificates (don't rely on auto-generated self-signed certs)
- Restrict certificate directories with `--allowed-cert-dirs`
- Keep PHP and OpenSSL up to date
