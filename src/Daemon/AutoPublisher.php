<?php

declare(strict_types=1);

namespace PhpOpcua\SessionManager\Daemon;

use Closure;
use PhpOpcua\Client\Exception\ConnectionException;
use Psr\Log\LoggerInterface;
use React\EventLoop\LoopInterface;
use React\EventLoop\TimerInterface;
use Throwable;

/**
 * Manages automatic publish cycles for sessions with active subscriptions.
 *
 * For each session that has at least one subscription, a self-rescheduling one-shot timer
 * calls {@see \PhpOpcua\Client\Client::publish()} periodically. The client's internal
 * {@see \PhpOpcua\Client\Client\ManagesSubscriptionsTrait::dispatchPublishEvents()} dispatches
 * PSR-14 events (DataChangeReceived, EventNotificationReceived, etc.) automatically.
 *
 * Acknowledgements are tracked internally and sent on each subsequent publish call.
 */
class AutoPublisher
{
    private const MAX_CONSECUTIVE_ERRORS = 5;

    /** @var array<string, TimerInterface|null> */
    private array $timers = [];

    /** @var array<string, array{subscriptionId: int, sequenceNumber: int}[]> */
    private array $pendingAcks = [];

    /** @var array<string, int> */
    private array $errorCounts = [];

    /**
     * @param SessionStore $store The in-memory session registry.
     * @param LoopInterface $loop The ReactPHP event loop.
     * @param LoggerInterface $logger Logger for auto-publish lifecycle events.
     * @param Closure(Session): bool $recoveryCallback Called on ConnectionException to attempt session recovery.
     */
    public function __construct(
        private readonly SessionStore    $store,
        private readonly LoopInterface   $loop,
        private readonly LoggerInterface $logger,
        private readonly Closure         $recoveryCallback,
    )
    {
    }

    /**
     * Start automatic publishing for a session.
     *
     * Idempotent — calling this on a session that is already active has no effect.
     *
     * @param string $sessionId
     * @return void
     */
    public function startSession(string $sessionId): void
    {
        if (isset($this->timers[$sessionId])) {
            return;
        }

        $this->logger->info('Auto-publish started for session {id}', ['id' => $sessionId]);
        $this->scheduleNext($sessionId, 0.0);
    }

    /**
     * Stop automatic publishing for a session and clean up internal state.
     *
     * @param string $sessionId
     * @return void
     */
    public function stopSession(string $sessionId): void
    {
        if (isset($this->timers[$sessionId])) {
            $this->loop->cancelTimer($this->timers[$sessionId]);
        }

        unset($this->timers[$sessionId], $this->pendingAcks[$sessionId], $this->errorCounts[$sessionId]);

        $this->logger->info('Auto-publish stopped for session {id}', ['id' => $sessionId]);
    }

    /**
     * Check whether auto-publish is currently active for a session.
     *
     * @param string $sessionId
     * @return bool
     */
    public function isActive(string $sessionId): bool
    {
        return isset($this->timers[$sessionId]);
    }

    /**
     * Stop automatic publishing for all active sessions.
     *
     * @return void
     */
    public function stopAll(): void
    {
        foreach (array_keys($this->timers) as $sessionId) {
            $this->stopSession($sessionId);
        }
    }

    /**
     * Schedule the next publish cycle for a session after a delay.
     *
     * @param string $sessionId
     * @param float $delay Delay in seconds before the next publish cycle.
     * @return void
     */
    private function scheduleNext(string $sessionId, float $delay): void
    {
        $this->timers[$sessionId] = $this->loop->addTimer($delay, function () use ($sessionId) {
            unset($this->timers[$sessionId]);
            $this->publishCycle($sessionId);
        });
    }

    /**
     * Execute a single publish cycle for a session.
     *
     * Calls {@see \PhpOpcua\Client\Client::publish()} with accumulated acknowledgements,
     * processes the result, and schedules the next cycle. On connection failure, attempts
     * recovery via the configured callback. On repeated errors, stops auto-publish.
     *
     * @param string $sessionId
     * @return void
     */
    private function publishCycle(string $sessionId): void
    {
        try {
            $session = $this->store->get($sessionId);
        } catch (Throwable) {
            return;
        }

        if (!$session->hasSubscriptions()) {
            $this->stopSession($sessionId);
            return;
        }

        $acks = $this->pendingAcks[$sessionId] ?? [];
        $this->pendingAcks[$sessionId] = [];

        try {
            $result = $session->client->publish($acks);

            $session->touch();
            $this->pendingAcks[$sessionId][] = [
                'subscriptionId' => $result->subscriptionId,
                'sequenceNumber' => $result->sequenceNumber,
            ];
            $this->errorCounts[$sessionId] = 0;

            $this->scheduleNextBasedOnResult($sessionId, $session, $result->moreNotifications);
        } catch (ConnectionException $e) {
            $this->handleConnectionError($sessionId, $session, $e);
        } catch (Throwable $e) {
            $this->handleGenericError($sessionId, $e);
        }
    }

    /**
     * Schedule the next publish based on whether more notifications are available.
     *
     * @param string $sessionId
     * @param Session $session
     * @param bool $moreNotifications
     * @return void
     */
    private function scheduleNextBasedOnResult(string $sessionId, Session $session, bool $moreNotifications): void
    {
        if ($moreNotifications) {
            $this->scheduleNext($sessionId, 0.01);
            return;
        }

        $this->scheduleNext($sessionId, $session->getMinPublishingInterval() * 0.75);
    }

    /**
     * Handle a connection error during publish by attempting session recovery.
     *
     * @param string $sessionId
     * @param Session $session
     * @param ConnectionException $e
     * @return void
     */
    private function handleConnectionError(string $sessionId, Session $session, ConnectionException $e): void
    {
        $this->logger->warning('Auto-publish connection error for session {id}: {message}', [
            'id' => $sessionId,
            'message' => $e->getMessage(),
        ]);

        $this->pendingAcks[$sessionId] = [];

        try {
            $recovered = ($this->recoveryCallback)($session);
        } catch (Throwable) {
            $recovered = false;
        }

        if ($recovered && $session->hasSubscriptions()) {
            $this->scheduleNext($sessionId, 1.0);
            return;
        }

        $this->logger->error('Auto-publish recovery failed for session {id}, stopping', ['id' => $sessionId]);
        $this->stopSession($sessionId);
    }

    /**
     * Handle a non-connection error during publish with exponential backoff.
     *
     * @param string $sessionId
     * @param Throwable $e
     * @return void
     */
    private function handleGenericError(string $sessionId, Throwable $e): void
    {
        $this->errorCounts[$sessionId] = ($this->errorCounts[$sessionId] ?? 0) + 1;

        $this->logger->warning('Auto-publish error for session {id} ({count}/{max}): {message}', [
            'id' => $sessionId,
            'count' => $this->errorCounts[$sessionId],
            'max' => self::MAX_CONSECUTIVE_ERRORS,
            'message' => $e->getMessage(),
        ]);

        if ($this->errorCounts[$sessionId] >= self::MAX_CONSECUTIVE_ERRORS) {
            $this->logger->error('Auto-publish stopped for session {id} after {max} consecutive errors', [
                'id' => $sessionId,
                'max' => self::MAX_CONSECUTIVE_ERRORS,
            ]);
            $this->stopSession($sessionId);
            return;
        }

        $this->scheduleNext($sessionId, 5.0);
    }
}
