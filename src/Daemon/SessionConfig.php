<?php

declare(strict_types=1);

namespace PhpOpcua\SessionManager\Daemon;

/**
 * Typed view of the `config` associative array exchanged on `open` IPC requests.
 *
 * Wraps every configuration knob that `ManagedClient` can forward to the
 * daemon's `ClientBuilder` under a single readonly DTO so that
 * {@see CommandHandler::handleOpen()} consumes typed fields instead of
 * `$config['...']` lookups. The wire format remains a plain JSON object for
 * backwards compatibility — conversion happens at the boundary via
 * {@see self::fromArray()} and {@see self::toArray()}.
 */
final readonly class SessionConfig
{
    public const SENSITIVE_FIELDS = [
        'password',
        'clientKeyPath',
        'userKeyPath',
        'caCertPath',
    ];

    /**
     * @param ?float $opcuaTimeout Transport timeout in seconds for OPC UA requests.
     * @param ?int $autoRetry Automatic reconnect retries on failure.
     * @param ?int $batchSize Client-side batching size for readMulti / writeMulti.
     * @param ?int $defaultBrowseMaxDepth Default recursion depth for browseRecursive.
     * @param ?string $securityPolicy SecurityPolicy URI (`SecurityPolicy::from()` compatible value).
     * @param ?int $securityMode SecurityMode enum value.
     * @param ?string $username Basic auth username.
     * @param ?string $password Basic auth password (sensitive — stripped from sanitized views).
     * @param ?string $clientCertPath Filesystem path to the client certificate PEM.
     * @param ?string $clientKeyPath Filesystem path to the client private key PEM (sensitive).
     * @param ?string $caCertPath Filesystem path to the optional CA certificate PEM (sensitive).
     * @param ?string $userCertPath Filesystem path to the user-token certificate PEM.
     * @param ?string $userKeyPath Filesystem path to the user-token private key PEM (sensitive).
     * @param ?string $trustStorePath Filesystem path of the server-certificate trust store.
     * @param ?string $trustPolicy TrustPolicy enum value.
     * @param ?bool $autoAccept Enable TOFU auto-accept of previously unseen server certificates.
     * @param ?bool $autoAcceptForce Force auto-accept even when a previously seen server certificate mismatches.
     * @param ?bool $autoDetectWriteType Infer the OPC UA type on write() when not specified.
     * @param ?bool $readMetadataCache Cache metadata attribute reads on the underlying Client.
     */
    public function __construct(
        public ?float $opcuaTimeout = null,
        public ?int $autoRetry = null,
        public ?int $batchSize = null,
        public ?int $defaultBrowseMaxDepth = null,
        public ?string $securityPolicy = null,
        public ?int $securityMode = null,
        public ?string $username = null,
        public ?string $password = null,
        public ?string $clientCertPath = null,
        public ?string $clientKeyPath = null,
        public ?string $caCertPath = null,
        public ?string $userCertPath = null,
        public ?string $userKeyPath = null,
        public ?string $trustStorePath = null,
        public ?string $trustPolicy = null,
        public ?bool $autoAccept = null,
        public ?bool $autoAcceptForce = null,
        public ?bool $autoDetectWriteType = null,
        public ?bool $readMetadataCache = null,
    ) {
    }

    /**
     * Build a {@see self} from the raw `$config` array exchanged on the wire.
     * Unknown keys are silently ignored (forwards-compatible).
     *
     * @param array<string, mixed> $data
     * @return self
     */
    public static function fromArray(array $data): self
    {
        return new self(
            opcuaTimeout: isset($data['opcuaTimeout']) ? (float) $data['opcuaTimeout'] : null,
            autoRetry: isset($data['autoRetry']) ? (int) $data['autoRetry'] : null,
            batchSize: isset($data['batchSize']) ? (int) $data['batchSize'] : null,
            defaultBrowseMaxDepth: isset($data['defaultBrowseMaxDepth']) ? (int) $data['defaultBrowseMaxDepth'] : null,
            securityPolicy: isset($data['securityPolicy']) ? (string) $data['securityPolicy'] : null,
            securityMode: isset($data['securityMode']) ? (int) $data['securityMode'] : null,
            username: isset($data['username']) ? (string) $data['username'] : null,
            password: isset($data['password']) ? (string) $data['password'] : null,
            clientCertPath: isset($data['clientCertPath']) ? (string) $data['clientCertPath'] : null,
            clientKeyPath: isset($data['clientKeyPath']) ? (string) $data['clientKeyPath'] : null,
            caCertPath: isset($data['caCertPath']) ? (string) $data['caCertPath'] : null,
            userCertPath: isset($data['userCertPath']) ? (string) $data['userCertPath'] : null,
            userKeyPath: isset($data['userKeyPath']) ? (string) $data['userKeyPath'] : null,
            trustStorePath: isset($data['trustStorePath']) ? (string) $data['trustStorePath'] : null,
            trustPolicy: isset($data['trustPolicy']) ? (string) $data['trustPolicy'] : null,
            autoAccept: isset($data['autoAccept']) ? (bool) $data['autoAccept'] : null,
            autoAcceptForce: isset($data['autoAcceptForce']) ? (bool) $data['autoAcceptForce'] : null,
            autoDetectWriteType: isset($data['autoDetectWriteType']) ? (bool) $data['autoDetectWriteType'] : null,
            readMetadataCache: isset($data['readMetadataCache']) ? (bool) $data['readMetadataCache'] : null,
        );
    }

    /**
     * Serialize back to the wire array shape, keeping only the fields that were set.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $out = [];
        foreach (get_object_vars($this) as $key => $value) {
            if ($value !== null) {
                $out[$key] = $value;
            }
        }

        return $out;
    }

    /**
     * Return a copy of this config with {@see self::SENSITIVE_FIELDS} nulled
     * out, suitable for the session-lookup cache key. `username` is preserved
     * here (part of session identity); user-facing stripping lives in
     * {@see CommandHandler::sanitizeConfig()}.
     *
     * @return self
     */
    public function sanitized(): self
    {
        return new self(
            opcuaTimeout: $this->opcuaTimeout,
            autoRetry: $this->autoRetry,
            batchSize: $this->batchSize,
            defaultBrowseMaxDepth: $this->defaultBrowseMaxDepth,
            securityPolicy: $this->securityPolicy,
            securityMode: $this->securityMode,
            username: $this->username,
            password: null,
            clientCertPath: $this->clientCertPath,
            clientKeyPath: null,
            caCertPath: null,
            userCertPath: $this->userCertPath,
            userKeyPath: null,
            trustStorePath: $this->trustStorePath,
            trustPolicy: $this->trustPolicy,
            autoAccept: $this->autoAccept,
            autoAcceptForce: $this->autoAcceptForce,
            autoDetectWriteType: $this->autoDetectWriteType,
            readMetadataCache: $this->readMetadataCache,
        );
    }
}
