<?php

declare(strict_types=1);

namespace Gianfriaur\OpcuaSessionManager\Daemon;

use Gianfriaur\OpcuaPhpClient\Client;

/**
 * Session object holding an OPC UA Client, its endpoint URL, configuration, last-used timestamp, and tracked subscription IDs.
 */
class Session
{
    /** @var int[] */
    private array $subscriptionIds = [];

    /**
     * @param string $id
     * @param Client $client
     * @param string $endpointUrl
     * @param array $config
     * @param float $lastUsed
     */
    public function __construct(
        public readonly string $id,
        public readonly Client $client,
        public readonly string $endpointUrl,
        public readonly array  $config,
        public float           $lastUsed,
    )
    {
    }

    /**
     * @return void
     */
    public function touch(): void
    {
        $this->lastUsed = microtime(true);
    }

    /**
     * @param int $timeout
     * @return bool
     */
    public function isExpired(int $timeout): bool
    {
        return (microtime(true) - $this->lastUsed) > $timeout;
    }

    /**
     * @param int $subscriptionId
     * @return void
     */
    public function addSubscription(int $subscriptionId): void
    {
        $this->subscriptionIds[$subscriptionId] = $subscriptionId;
    }

    /**
     * @param int $subscriptionId
     * @return void
     */
    public function removeSubscription(int $subscriptionId): void
    {
        unset($this->subscriptionIds[$subscriptionId]);
    }

    /**
     * @return int[]
     */
    public function getSubscriptionIds(): array
    {
        return array_values($this->subscriptionIds);
    }

    /**
     * @return bool
     */
    public function hasSubscriptions(): bool
    {
        return count($this->subscriptionIds) > 0;
    }
}
