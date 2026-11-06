<?php
declare(strict_types=1);
namespace App\Core;

final class Csrf
{
    public static function token(): string
    {
        if (!is_string($_SESSION['_csrf'] ?? null) || $_SESSION['_csrf'] === '') {
            $_SESSION['_csrf'] = bin2hex(random_bytes(32));
        }
        return $_SESSION['_csrf'];
    }

    public static function field(): string
    {
        return '<input type="hidden" name="_csrf" value="' . e(self::token()) . '">';
    }

    public static function validate(): void
    {
        $expected = $_SESSION['_csrf'] ?? null;
        $token = $_POST['_csrf'] ?? null;
        if (!is_string($expected) || $expected === '' || !is_string($token) || $token === '' || !hash_equals($expected, $token)) {
            http_response_code(419);
            exit('Invalid CSRF token.');
        }
    }
}
