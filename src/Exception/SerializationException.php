<?php

declare(strict_types=1);

namespace PhpOpcua\SessionManager\Exception;

use RuntimeException;

/**
 * Thrown when a value cannot be serialized or deserialized for IPC transport.
 */
class SerializationException extends RuntimeException
{
}
