<?php

declare(strict_types=1);

namespace PhpOpcua\SessionManager\Daemon;

use PhpOpcua\Client\Client;

/**
 * Session object holding an OPC UA Client, its endpoint URL, configuration, last-used timestamp, and tracked subscription IDs.
 */
class Session
{
    /** @var array<int, int> */
    private array $subscriptionIds = [];

    /** @var array<int, float> subscriptionId => publishingInterval in milliseconds */
    private array $subscriptionIntervals = [];

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
     * @param float $publishingInterval The subscription's revised publishing interval in milliseconds.
     * @return void
     */
    public function addSubscription(int $subscriptionId, float $publishingInterval = 500.0): void
    {
        $this->subscriptionIds[$subscriptionId] = $subscriptionId;
        $this->subscriptionIntervals[$subscriptionId] = $publishingInterval;
    }

    /**
     * @param int $subscriptionId
     * @return void
     */
    public function removeSubscription(int $subscriptionId): void
    {
        unset($this->subscriptionIds[$subscriptionId], $this->subscriptionIntervals[$subscriptionId]);
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

    /**
     * Return the minimum publishing interval across all active subscriptions, in seconds.
     *
     * Falls back to 0.5 seconds when no subscriptions are tracked.
     *
     * @return float
     */
    public function getMinPublishingInterval(): float
    {
        if (empty($this->subscriptionIntervals)) {
            return 0.5;
        }

        return min($this->subscriptionIntervals) / 1000.0;
    }
}
