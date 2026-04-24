<?php

declare(strict_types=1);

namespace PhpOpcua\SessionManager\Cli;

/**
 * Parses argv for `bin/opcua-session-manager` into a typed options array.
 *
 * Unit-testable: no I/O, no `exit()`, no global state. The caller decides what
 * to do with the returned action (`run` / `version` / `help`) and errors.
 */
final class ArgvParser
{
    private const VALUE_FLAGS = [
        '--socket',
        '--timeout',
        '--cleanup-interval',
        '--auth-token',
        '--auth-token-file',
        '--max-sessions',
        '--socket-mode',
        '--allowed-cert-dirs',
        '--log-file',
        '--log-level',
        '--cache-driver',
        '--cache-path',
        '--cache-ttl',
    ];

    /**
     * @param string[] $argv The raw `$argv` (including the script name at index 0).
     * @param array<string, mixed> $defaults The values from `config/defaults.php`.
     * @return array{
     *     action: 'run'|'version'|'help',
     *     options: array<string, mixed>,
     *     authTokenFromCli: bool,
     *     errors: string[]
     * }
     */
    public static function parse(array $argv, array $defaults): array
    {
        $options = $defaults;
        $authTokenFromCli = false;
        $errors = [];

        $args = array_slice($argv, 1);
        $count = count($args);

        for ($i = 0; $i < $count; $i++) {
            $flag = $args[$i];

            if ($flag === '--version' || $flag === '-v') {
                return [
                    'action' => 'version',
                    'options' => $options,
                    'authTokenFromCli' => $authTokenFromCli,
                    'errors' => $errors,
                ];
            }

            if ($flag === '--help' || $flag === '-h') {
                return [
                    'action' => 'help',
                    'options' => $options,
                    'authTokenFromCli' => $authTokenFromCli,
                    'errors' => $errors,
                ];
            }

            if (in_array($flag, self::VALUE_FLAGS, true)) {
                if (! isset($args[$i + 1])) {
                    $errors[] = "Missing value for option {$flag}.";

                    continue;
                }
                $value = $args[++$i];
                self::apply($flag, $value, $options);
                if ($flag === '--auth-token') {
                    $authTokenFromCli = true;
                }

                continue;
            }
        }

        return [
            'action' => 'run',
            'options' => $options,
            'authTokenFromCli' => $authTokenFromCli,
            'errors' => $errors,
        ];
    }

    /**
     * @param string $flag
     * @param string $value
     * @param array<string, mixed> $options
     * @return void
     */
    private static function apply(string $flag, string $value, array &$options): void
    {
        match ($flag) {
            '--socket' => $options['socket_path'] = $value,
            '--timeout' => $options['timeout'] = (int) $value,
            '--cleanup-interval' => $options['cleanup_interval'] = (int) $value,
            '--auth-token' => $options['auth_token'] = $value,
            '--auth-token-file' => $options['auth_token_file'] = $value,
            '--max-sessions' => $options['max_sessions'] = (int) $value,
            '--socket-mode' => $options['socket_mode'] = intval($value, 8),
            '--allowed-cert-dirs' => $options['allowed_cert_dirs'] = explode(',', $value),
            '--log-file' => $options['log_file'] = $value,
            '--log-level' => $options['log_level'] = $value,
            '--cache-driver' => $options['cache_driver'] = $value,
            '--cache-path' => $options['cache_path'] = $value,
            '--cache-ttl' => $options['cache_ttl'] = (int) $value,
        };
    }
}
