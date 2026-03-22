<?php

declare(strict_types=1);

namespace Gianfriaur\OpcuaSessionManager\Logging;

use Psr\Log\AbstractLogger;
use Psr\Log\LogLevel;
use Stringable;

/**
 * Minimal PSR-3 logger that writes formatted log lines to a stream (file, stderr, stdout).
 */
class StreamLogger extends AbstractLogger
{
    private const LEVEL_PRIORITY = [
        LogLevel::DEBUG => 0,
        LogLevel::INFO => 1,
        LogLevel::NOTICE => 2,
        LogLevel::WARNING => 3,
        LogLevel::ERROR => 4,
        LogLevel::CRITICAL => 5,
        LogLevel::ALERT => 6,
        LogLevel::EMERGENCY => 7,
    ];

    /** @var resource */
    private $stream;
    private int $minPriority;
    private bool $ownsStream;

    /**
     * @param string|resource $target File path or stream resource (e.g. STDERR, STDOUT).
     * @param string $minLevel Minimum log level to write. Messages below this level are discarded.
     */
    public function __construct(mixed $target = 'php://stderr', string $minLevel = LogLevel::DEBUG)
    {
        $this->minPriority = self::LEVEL_PRIORITY[$minLevel] ?? 0;

        if (is_resource($target)) {
            $this->stream = $target;
            $this->ownsStream = false;
        } else {
            $dir = dirname($target);
            if (!is_dir($dir)) {
                mkdir($dir, 0755, true);
            }
            $stream = fopen($target, 'a');
            if ($stream === false) {
                throw new \RuntimeException("Cannot open log file: {$target}");
            }
            $this->stream = $stream;
            $this->ownsStream = true;
        }
    }

    /**
     * @param mixed $level
     * @param Stringable|string $message
     * @param array $context
     * @return void
     */
    public function log(mixed $level, Stringable|string $message, array $context = []): void
    {
        $priority = self::LEVEL_PRIORITY[$level] ?? 0;
        if ($priority < $this->minPriority) {
            return;
        }

        $message = $this->interpolate((string)$message, $context);
        $timestamp = date('Y-m-d H:i:s');
        $upperLevel = strtoupper((string)$level);
        $line = "[{$timestamp}] [{$upperLevel}] {$message}\n";

        fwrite($this->stream, $line);
    }

    /**
     * @param string $message
     * @param array $context
     * @return string
     */
    private function interpolate(string $message, array $context): string
    {
        $replacements = [];
        foreach ($context as $key => $value) {
            if (is_string($value) || is_numeric($value) || (is_object($value) && method_exists($value, '__toString'))) {
                $replacements['{' . $key . '}'] = (string)$value;
            }
        }

        return strtr($message, $replacements);
    }

    public function __destruct()
    {
        if ($this->ownsStream && is_resource($this->stream)) {
            fclose($this->stream);
        }
    }
}
