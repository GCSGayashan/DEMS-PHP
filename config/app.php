<?php
return [
    'name' => env('APP_NAME', 'DEMS'),
    'env' => env('APP_ENV', 'production'),
    'debug' => env('APP_DEBUG', false),
    'url' => env('APP_URL', 'http://localhost/DEMS-PHP/public'),
    'timezone' => env('APP_TIMEZONE', 'Asia/Colombo'),
    'session_name' => env('SESSION_NAME', 'DEMSPHPSESSID'),
    'session_idle_timeout' => (int)env('SESSION_IDLE_TIMEOUT', 1800),
    'session_absolute_timeout' => (int)env('SESSION_ABSOLUTE_TIMEOUT', 28800),
    'login_max_attempts' => (int)env('LOGIN_MAX_ATTEMPTS', 5),
    'login_attempt_window_seconds' => (int)env('LOGIN_ATTEMPT_WINDOW_SECONDS', 900),
    'login_block_seconds' => (int)env('LOGIN_BLOCK_SECONDS', 900),
];
