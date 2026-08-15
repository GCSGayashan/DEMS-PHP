<?php
declare(strict_types=1);
namespace App\Core;

final class SessionManager
{
    public static function start(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            return;
        }

        self::configure();
        session_start();
    }

    public static function configure(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            return;
        }

        $options = self::cookieOptions();
        ini_set('session.use_strict_mode', '1');
        ini_set('session.use_only_cookies', '1');
        ini_set('session.gc_maxlifetime', (string)max(
            1,
            (int)config('app.session_absolute_timeout', 28800)
        ));

        session_name((string)config('app.session_name', 'DEMSPHPSESSID'));
        session_set_cookie_params($options);
    }

    public static function cookieOptions(): array
    {
        return self::cookieOptionsFor(
            (string)config('app.env', 'production'),
            (string)config('app.url', ''),
            $_SERVER
        );
    }

    public static function cookieOptionsFor(string $environment, string $appUrl, array $server = []): array
    {
        $environment = strtolower(trim($environment));
        $urlScheme = strtolower((string)parse_url($appUrl, PHP_URL_SCHEME));
        $requestIsHttps = strtolower((string)($server['HTTPS'] ?? '')) === 'on'
            || (string)($server['HTTPS'] ?? '') === '1'
            || (string)($server['SERVER_PORT'] ?? '') === '443'
            || strtolower((string)($server['HTTP_X_FORWARDED_PROTO'] ?? '')) === 'https';

        // Production fails closed. Local HTTP development remains supported.
        $secure = $environment === 'production' || $urlScheme === 'https' || $requestIsHttps;

        return [
            'lifetime' => 0,
            'path' => '/',
            'domain' => '',
            'secure' => $secure,
            'httponly' => true,
            'samesite' => 'Lax',
        ];
    }
}
