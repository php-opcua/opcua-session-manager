<?php

declare(strict_types=1);

namespace PhpOpcua\SessionManager\Ipc;

use PhpOpcua\SessionManager\Exception\DaemonException;

/**
 * Build a client-side {@see TransportInterface} from an endpoint URI.
 *
 * Supported URI forms:
 *
 * - `unix:///absolute/path/to.sock` — explicit Unix-domain socket
 * - `tcp://127.0.0.1:9990` — explicit TCP loopback
 * - `/absolute/path/to.sock` or any scheme-less string — interpreted as a Unix
 *   socket path (backwards-compatible default used throughout the pre-v4.2.0
 *   IPC API)
 *
 * The factory also exposes {@see self::defaultEndpoint()} which picks a
 * sensible per-platform default: Unix socket on Linux/macOS, TCP loopback on
 * Windows (where `react/socket` Unix-socket support is still partial).
 */
final class TransportFactory
{
    public const DEFAULT_TCP_PORT = 9990;
    public const DEFAULT_UNIX_PATH = '/tmp/opcua-session-manager.sock';

    /**
     * Maximum byte length the kernel allocates for `sun_path` in
     * `struct sockaddr_un`. Linux/Solaris use 108, BSD/macOS 104.
     */
    public const MAX_UNIX_PATH_LINUX = 108;
    public const MAX_UNIX_PATH_DARWIN = 104;

    /**
     * Validate that a Unix-domain socket path fits within the platform's
     * `sun_path` capacity.
     *
     * @param string $path Absolute filesystem path the daemon will bind to.
     * @throws DaemonException If the path exceeds the platform-specific limit.
     */
    public static function assertUnixPathFits(string $path): void
    {
        $max = PHP_OS_FAMILY === 'Darwin'
            ? self::MAX_UNIX_PATH_DARWIN
            : self::MAX_UNIX_PATH_LINUX;
        
        $usable = $max - 1;
        $length = strlen($path);

        if ($length > $usable) {
            throw new DaemonException(sprintf(
                'Unix socket path is too long: %d bytes, but the %s kernel limits sun_path to %d bytes (usable %d). '
                . 'The kernel silently truncates longer paths, which breaks chmod() and reconnect. '
                . 'Set OPCUA_SOCKET_PATH (or pass --socket) to a shorter path, e.g. /tmp/opcua-session.sock. Got: %s',
                $length,
                PHP_OS_FAMILY,
                $max,
                $usable,
                $path,
            ));
        }
    }

    /**
     * Build a {@see TransportInterface} for `$endpoint`.
     *
     * @param string $endpoint Endpoint URI (`unix://…`, `tcp://host:port`) or a scheme-less Unix path.
     * @param float $timeout Connect + read timeout in seconds.
     * @return TransportInterface
     * @throws DaemonException If the URI scheme is unsupported or the TCP host is not loopback.
     */
    public static function create(string $endpoint, float $timeout = 30.0): TransportInterface
    {
        if (self::hasScheme($endpoint, 'unix://')) {
            return new UnixSocketTransport(substr($endpoint, strlen('unix://')), $timeout);
        }

        if (self::hasScheme($endpoint, 'tcp://')) {
            [$host, $port] = self::parseTcpAuthority(substr($endpoint, strlen('tcp://')));

            return new TcpLoopbackTransport($host, $port, $timeout);
        }

        if (str_contains($endpoint, '://')) {
            throw new DaemonException(sprintf(
                'Unsupported transport scheme in "%s". Allowed: unix://, tcp://.',
                $endpoint,
            ));
        }

        return new UnixSocketTransport($endpoint, $timeout);
    }

    /**
     * Platform-aware default endpoint URI.
     *
     * Returns `unix:///tmp/opcua-session-manager.sock` on Unix-like platforms
     * and `tcp://127.0.0.1:9990` on Windows.
     *
     * @return string
     */
    public static function defaultEndpoint(): string
    {
        if (self::isWindows()) {
            return sprintf('tcp://127.0.0.1:%d', self::DEFAULT_TCP_PORT);
        }

        return 'unix://' . self::DEFAULT_UNIX_PATH;
    }

    /**
     * Whether a caller-supplied endpoint targets a Unix-domain socket.
     *
     * Treats scheme-less paths as Unix for backwards compatibility with the
     * pre-v4.2.0 `--socket /tmp/foo.sock` convention.
     *
     * @param string $endpoint
     * @return bool
     */
    public static function isUnixEndpoint(string $endpoint): bool
    {
        if (self::hasScheme($endpoint, 'unix://')) {
            return true;
        }

        return ! str_contains($endpoint, '://');
    }

    /**
     * Resolve an endpoint URI to the absolute Unix socket path it points to,
     * or `null` for non-Unix transports.
     *
     * Useful for lifecycle bookkeeping (PID files, socket file cleanup) that
     * only applies to Unix-domain sockets.
     *
     * @param string $endpoint
     * @return ?string
     */
    public static function toUnixPath(string $endpoint): ?string
    {
        if (self::hasScheme($endpoint, 'unix://')) {
            return substr($endpoint, strlen('unix://'));
        }

        if (! str_contains($endpoint, '://')) {
            return $endpoint;
        }

        return null;
    }

    /**
     * @param string $endpoint
     * @param string $scheme
     * @return bool
     */
    private static function hasScheme(string $endpoint, string $scheme): bool
    {
        return str_starts_with($endpoint, $scheme);
    }

    /**
     * @param string $authority
     * @return array{0: string, 1: int}
     * @throws DaemonException If the authority cannot be parsed.
     */
    private static function parseTcpAuthority(string $authority): array
    {
        if ($authority === '') {
            throw new DaemonException('TCP endpoint missing host:port.');
        }

        if (str_starts_with($authority, '[')) {
            $close = strpos($authority, ']');
            if ($close === false) {
                throw new DaemonException(sprintf('Malformed IPv6 TCP endpoint: %s', $authority));
            }
            $host = substr($authority, 1, $close - 1);
            $rest = substr($authority, $close + 1);
            if (! str_starts_with($rest, ':')) {
                throw new DaemonException(sprintf('TCP endpoint missing port: %s', $authority));
            }
            $port = (int) substr($rest, 1);
        } else {
            $colon = strrpos($authority, ':');
            if ($colon === false) {
                throw new DaemonException(sprintf('TCP endpoint missing port: %s', $authority));
            }
            $host = substr($authority, 0, $colon);
            $port = (int) substr($authority, $colon + 1);
        }

        if ($host === '' || $port <= 0 || $port > 65535) {
            throw new DaemonException(sprintf('Invalid TCP endpoint host/port: %s', $authority));
        }

        return [$host, $port];
    }

    /**
     * @return bool
     */
    private static function isWindows(): bool
    {
        return stripos(PHP_OS_FAMILY, 'Windows') !== false;
    }
}
