<?php

declare(strict_types=1);

namespace Gianfriaur\OpcuaSessionManager\Exception;

use RuntimeException;

/**
 * Thrown when a session ID cannot be found in the session store.
 */
class SessionNotFoundException extends RuntimeException
{
}
