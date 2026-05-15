---
eyebrow: 'Docs · Recipes'
lede:    'Wire a secured session — Basic256Sha256 with a real certificate, or ECC for the experimental path. The daemon respects --allowed-cert-dirs, so deployment discipline starts with where the certificate files live.'

see_also:
  - { href: '../daemon/security-hardening.md',   meta: '6 min' }
  - { href: '../managed-client/session-reuse.md', meta: '5 min' }
  - { href: 'https://github.com/php-opcua/opcua-client/blob/master/docs/security/overview.md', meta: 'external', label: 'opcua-client — security' }

prev: { label: 'Healthcheck and monitoring', href: './healthcheck-and-monitoring.md' }
next: { label: 'Recovery and reconnect',     href: './recovery-and-reconnect.md' }
---

# Secure connection with ECC

A secured OPC UA session needs three things: a security policy, a
security mode, and a client certificate. With the session manager
in the picture, two more concerns enter: where the daemon is
allowed to load the certificate from, and how the configuration
flows from the application to the daemon.

This recipe walks through both the standard RSA case and the ECC
variant. **For production today, prefer RSA** — the ECC
implementation is per-spec but unproven against commercial
servers. See [`opcua-client` — security](https://github.com/php-opcua/opcua-client/blob/master/docs/security/overview.md).

## 1 — Place certificates in a restricted directory

The daemon's `--allowed-cert-dirs` flag is the directory traversal
guard. Set it at deploy time; never point at the application's
working directory.

<!-- @code-block language="bash" label="terminal — cert layout" -->
```bash
sudo mkdir -p /etc/opcua/certs
sudo chown opcua:opcua /etc/opcua/certs
sudo chmod 0700 /etc/opcua/certs

# Drop the client cert + key into it (RSA case here)
sudo install -m 0600 -o opcua -g opcua client.pem /etc/opcua/certs/client.pem
sudo install -m 0600 -o opcua -g opcua client.key /etc/opcua/certs/client.key
```
<!-- @endcode-block -->

Start the daemon with the restriction:

<!-- @code-block language="bash" label="terminal — daemon with cert restriction" -->
```bash
vendor/bin/opcua-session-manager \
    --socket /var/run/opcua/sessions.sock \
    --allowed-cert-dirs /etc/opcua/certs \
    --auth-token-file /etc/opcua/daemon.token
```
<!-- @endcode-block -->

Any `open` command with a `clientCertPath` outside
`/etc/opcua/certs/...` is rejected — the daemon refuses to load
the file. This prevents the IPC peer from coercing the daemon
into loading arbitrary host files.

## 2 — RSA configuration on the application side

<!-- @code-block language="php" label="examples/secure-rsa.php" -->
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
    ->setClientCertificate(
        certPath: '/etc/opcua/certs/client.pem',
        keyPath:  '/etc/opcua/certs/client.key',
    )
    ->setUserCredentials('integrations', getenv('OPCUA_PASSWORD'))
    ->setTrustStorePath('/var/lib/opcua/trust')
    ->setTrustPolicy(TrustPolicy::FingerprintAndExpiry)
    ->autoAccept(true);   // TOFU — accept the server cert on first contact

$client->connect('opc.tcp://plc.local:4840');
```
<!-- @endcode-block -->

What is happening on the wire:

- The `setClientCertificate()` call records the path on the
  `ManagedClient`. The path is sent to the daemon as part of the
  `open` command's config.
- The daemon's `CommandHandler` canonicalises the path and
  verifies it sits under `/etc/opcua/certs`. Rejection raises
  `InvalidArgumentException("<label> is not in an allowed
  directory: <path>")`, which reaches the client as
  `DaemonException("[InvalidArgumentException] …")`.
- If accepted, the daemon's `ClientBuilder` loads the cert,
  negotiates the OPC UA `Basic256Sha256 + SignAndEncrypt`
  endpoint, exchanges nonces, derives session keys.
- All subsequent OPC UA traffic between the daemon and the
  server is signed and encrypted; the IPC traffic between the
  application and the daemon is **not** encrypted, but local
  trust covers it.

## 3 — ECC variant

The ECC policies (`EccNistP256`, `EccNistP384`, `EccBrainpoolP256r1`,
`EccBrainpoolP384r1`) work the same way at the application level
— swap the policy enum and the certificate.

<!-- @code-block language="bash" label="terminal — generate ECC cert" -->
```bash
# ECC client cert (NIST P-256)
openssl ecparam -name prime256v1 -genkey -noout -out client-ecc.key
openssl req -new -key client-ecc.key -out client-ecc.csr \
    -subj "/CN=opcua-client"
openssl x509 -req -in client-ecc.csr \
    -CA ca.pem -CAkey ca.key -CAcreateserial \
    -out client-ecc.pem -days 730 \
    -extfile <(printf '%s\n' \
        "subjectAltName=URI:urn:opcua-client" \
        "extendedKeyUsage=clientAuth,serverAuth")

sudo install -m 0600 -o opcua -g opcua client-ecc.pem /etc/opcua/certs/
sudo install -m 0600 -o opcua -g opcua client-ecc.key /etc/opcua/certs/
```
<!-- @endcode-block -->

<!-- @code-block language="php" label="examples/secure-ecc.php" -->
```php
$client = (new ManagedClient('/var/run/opcua/sessions.sock'))
    ->setSecurityPolicy(SecurityPolicy::EccNistP256)
    ->setSecurityMode(SecurityMode::SignAndEncrypt)
    ->setClientCertificate(
        certPath: '/etc/opcua/certs/client-ecc.pem',
        keyPath:  '/etc/opcua/certs/client-ecc.key',
    );

$client->connect('opc.tcp://opcua-test-server:4848');   // ECC endpoint
```
<!-- @endcode-block -->

The curve in the cert **must match** the policy: P-256 cert with
`EccNistP256`, P-384 with `EccNistP384`, etc. Mixing them raises
`SecurityException`.

<!-- @callout variant="warning" -->
ECC support is **experimental in practice**. The implementation
follows OPC UA 1.05.4 and is tested against UA-.NETStandard, but
no commercial OPC UA server vendor ships ECC endpoints in
production firmware. For real deployments today, stay on RSA. See
[`opcua-client` — security](https://github.com/php-opcua/opcua-client/blob/master/docs/security/overview.md).
<!-- @endcallout -->

## 4 — Server certificate trust

The daemon validates the server's certificate against the trust
store configured via `setTrustStorePath()`. Three policies:

| Policy                       | Decision                                                  |
| ---------------------------- | --------------------------------------------------------- |
| `TrustPolicy::Fingerprint`   | SHA-256 fingerprint must be in the store                  |
| `TrustPolicy::FingerprintAndExpiry` | Fingerprint match + within `notBefore`/`notAfter` |
| `TrustPolicy::Full`          | Full X.509 chain validation against the CA bundle         |

For initial setup, `autoAccept(true)` is the TOFU shortcut — the
daemon records the server's cert on first connect and enforces it
thereafter. Disable `autoAccept` once you have the fingerprint
captured.

## 5 — Session reuse with secured sessions

**Only some** security fields participate in the session key
(see [ManagedClient · Session
reuse](../managed-client/session-reuse.md) for the full table):

- `securityPolicy`, `securityMode` — **in key**
- `clientCertPath` — **in key**
- `userCertPath` — **in key**
- `username` — **in key**
- `trustStorePath`, `trustPolicy`, `autoAccept`, `autoAcceptForce` — **in key**
- `password`, `clientKeyPath`, `caCertPath`, `userKeyPath` — **NOT in key** (nulled by `SessionConfig::sanitized()`)

Two clients with identical *keyed* security configuration share
the daemon session — even if they pass different `password` or
key-path values. Two clients with any difference in a *keyed*
field — even different path strings pointing at the same file —
get separate sessions. Canonicalise paths and pin
`username`/`clientCertPath`/`userCertPath` in your client factory.

## What can go wrong

| Symptom                                        | Cause                                                |
| ---------------------------------------------- | ---------------------------------------------------- |
| `DaemonException: [InvalidArgumentException] <label> is not in an allowed directory` | `clientCertPath` is outside `--allowed-cert-dirs` |
| `SecurityException: certificate parse failed`  | Cert file unreadable, malformed, or wrong format     |
| `SecurityException: curve mismatch`            | ECC policy curve does not match the cert's curve     |
| `UntrustedCertificateException`                | Server cert not in trust store, `autoAccept` off     |
| Sessions not reusing across workers            | Path strings differ — canonicalise in factory        |

## Operational checklist

<!-- @steps -->
- **Certs in a restricted directory** owned by the daemon user, `0600` mode.
- **`--allowed-cert-dirs`** points at exactly that directory.
- **Trust store path** is writable by the daemon user; pre-populate
  the expected server certs or run `autoAccept` once and disable.
- **Auth token** is in `OPCUA_AUTH_TOKEN`, not on the CLI.
- **Session key fields canonicalised** in the application factory
  so reuse works.
<!-- @endsteps -->
