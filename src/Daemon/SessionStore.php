<?php

declare(strict_types=1);

namespace Gianfriaur\OpcuaSessionManager\Daemon;

use Gianfriaur\OpcuaSessionManager\Exception\SessionNotFoundException;

class SessionStore
{
    /** @var array<string, Session> */
    private array $sessions = [];

    public function create(Session $session): void
    {
        $this->sessions[$session->id] = $session;
    }

    public function get(string $id): Session
    {
        if (!isset($this->sessions[$id])) {
            throw new SessionNotFoundException("Session not found: {$id}");
        }

        return $this->sessions[$id];
    }

    public function remove(string $id): void
    {
        unset($this->sessions[$id]);
    }

    public function touch(string $id): void
    {
        $this->get($id)->touch();
    }

    /**
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

    public function count(): int
    {
        return count($this->sessions);
    }

    /**
     * @return Session[]
     */
    public function all(): array
    {
        return array_values($this->sessions);
    }
}
