<?php

declare(strict_types=1);

namespace Gianfriaur\OpcuaSessionManager\Daemon;

use Gianfriaur\OpcuaPhpClient\Client;

class Session
{
    public function __construct(
        public readonly string $id,
        public readonly Client $client,
        public readonly string $endpointUrl,
        public readonly array $config,
        public float $lastUsed,
    ) {
    }

    public function touch(): void
    {
        $this->lastUsed = microtime(true);
    }

    public function isExpired(int $timeout): bool
    {
        return (microtime(true) - $this->lastUsed) > $timeout;
    }
}
