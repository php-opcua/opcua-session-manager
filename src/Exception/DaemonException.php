<?php

declare(strict_types=1);

namespace PhpOpcua\SessionManager\Exception;

use RuntimeException;

/**
 * Thrown when communication with the daemon fails (socket not found, connection error, timeout, invalid response).
 */
class DaemonException extends RuntimeException
{
}
