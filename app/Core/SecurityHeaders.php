<?php
declare(strict_types=1);
namespace App\Core;

final class SecurityHeaders
{
    private static ?string $nonce = null;

    public static function apply(): void
    {
        foreach (self::headersFor(
            (string)config('app.env', 'production'),
            (string)config('app.url', ''),
            self::nonce()
        ) as $name => $value) {
            header($name . ': ' . $value);
        }
    }

    public static function nonce(): string
    {
        return self::$nonce ??= self::generateNonce();
    }

    public static function generateNonce(): string
    {
        return base64_encode(random_bytes(24));
    }

    public static function headersFor(string $environment, string $appUrl, string $nonce): array
    {
        $headers = [
            'Content-Security-Policy' => self::contentSecurityPolicy($nonce),
            'X-Frame-Options' => 'SAMEORIGIN',
            'X-Content-Type-Options' => 'nosniff',
            'Referrer-Policy' => 'strict-origin-when-cross-origin',
            'Permissions-Policy' => 'camera=(), microphone=(), geolocation=()',
            'Cache-Control' => 'no-store, no-cache, must-revalidate, private',
            'Pragma' => 'no-cache',
            'Expires' => '0',
        ];

        if (strtolower(trim($environment)) === 'production'
            && strtolower((string)parse_url($appUrl, PHP_URL_SCHEME)) === 'https') {
            $headers['Strict-Transport-Security'] = 'max-age=31536000; includeSubDomains';
        }

        return $headers;
    }

    public static function contentSecurityPolicy(string $nonce): string
    {
        if (!preg_match('/^[A-Za-z0-9+\/=]+$/', $nonce)) {
            throw new \InvalidArgumentException('Invalid CSP nonce.');
        }

        return implode('; ', [
            "default-src 'self'",
            "base-uri 'self'",
            "object-src 'none'",
            "frame-ancestors 'self'",
            "form-action 'self'",
            "script-src 'self' 'nonce-{$nonce}' https://cdn.jsdelivr.net",
            "script-src-attr 'none'",
            "style-src 'self' 'unsafe-inline' https://cdn.jsdelivr.net",
            "font-src 'self' https://cdn.jsdelivr.net data:",
            "img-src 'self' data:",
            "connect-src 'self'",
        ]);
    }
}
