<?php

declare(strict_types=1);

return [
    'socket_path' => '/tmp/opcua-session-manager.sock',
    'timeout' => 600,
    'cleanup_interval' => 30,
    'auth_token' => null,
    'auth_token_file' => null,
    'max_sessions' => 100,
    'socket_mode' => 0600,
    'allowed_cert_dirs' => null,
];
