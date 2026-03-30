<?php

declare(strict_types=1);

namespace PhpOpcua\SessionManager\Daemon;

use PhpOpcua\SessionManager\Exception\SessionNotFoundException;

/**
 * In-memory registry for active OPC UA sessions with expiration support.
 */
class SessionStore
{
    /** @var array<string, Session> */
    private array $sessions = [];

    /**
     * @param Session $session
     * @return void
     */
    public function create(Session $session): void
    {
        $this->sessions[$session->id] = $session;
    }

    /**
     * @param string $id
     * @return Session
     *
     * @throws SessionNotFoundException If no session exists with the given ID.
     */
    public function get(string $id): Session
    {
        if (!isset($this->sessions[$id])) {
            throw new SessionNotFoundException("Session not found: {$id}");
        }

        return $this->sessions[$id];
    }

    /**
     * @param string $id
     * @return void
     */
    public function remove(string $id): void
    {
        unset($this->sessions[$id]);
    }

    /**
     * @param string $id
     * @return void
     *
     * @throws SessionNotFoundException If no session exists with the given ID.
     */
    public function touch(string $id): void
    {
        $this->get($id)->touch();
    }

    /**
     * @param int $timeout
     * @return Session[]
     */
    public function getExpired(int $timeout): array
    {
        $expired = [];
        foreach ($this->sessions as $session) {
            if ($session->isExpired($timeout)) {
                $expired[] = $session;
            }
        }

        return $expired;
    }

    /**
     * @return int
     */
    public function count(): int
    {
        return count($this->sessions);
    }

    /**
     * @param string $endpointUrl
     * @param array $config Sanitized config to match against.
     * @return ?Session
     */
    public function findByEndpointAndConfig(string $endpointUrl, array $config): ?Session
    {
        foreach ($this->sessions as $session) {
            if ($session->endpointUrl === $endpointUrl && $session->config === $config) {
                return $session;
            }
        }

        return null;
    }

    /**
     * @return Session[]
     */
    public function all(): array
    {
        return array_values($this->sessions);
    }
}
