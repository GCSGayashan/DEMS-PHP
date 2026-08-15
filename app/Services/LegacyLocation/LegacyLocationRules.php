<?php
declare(strict_types=1);

namespace App\Services\LegacyLocation;

use DateTimeImmutable;

final class LegacyLocationRules
{
    public static function clean(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }
        $value = trim((string)$value);
        return $value === '' ? null : $value;
    }

    public static function normalizeCode(mixed $value): ?string
    {
        $value = self::clean($value);
        if ($value === null) {
            return null;
        }
        return strtoupper((string)preg_replace('/\s+/u', '', $value));
    }

    public static function normalizeName(mixed $value): ?string
    {
        $value = self::clean($value);
        if ($value === null) {
            return null;
        }
        $value = (string)preg_replace('/\s+/u', ' ', $value);
        return function_exists('mb_strtolower') ? mb_strtolower($value, 'UTF-8') : strtolower($value);
    }

    public static function normalizeStatus(mixed $value): ?string
    {
        $value = strtolower(trim((string)$value));
        if (in_array($value, ['1', 'true', 'yes', 'y', 'active', 'a', 'enabled'], true)) {
            return 'ACTIVE';
        }
        if (in_array($value, ['0', 'false', 'no', 'n', 'inactive', 'i', 'disabled'], true)) {
            return 'INACTIVE';
        }
        return null;
    }

    public static function effectiveDate(mixed $value, string $fallback): string
    {
        $value = self::clean($value);
        if ($value === null || str_starts_with($value, '0000-00-00')) {
            return $fallback;
        }
        $date = DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', $value)
            ?: DateTimeImmutable::createFromFormat('!Y-m-d', $value);
        $errors = DateTimeImmutable::getLastErrors();
        if (!$date || (is_array($errors) && ($errors['warning_count'] > 0 || $errors['error_count'] > 0))) {
            return $fallback;
        }
        return $date->format('Y-m-d');
    }

    public static function uuid(): string
    {
        $bytes = random_bytes(16);
        $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
        $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);
        $hex = bin2hex($bytes);
        return sprintf('%s-%s-%s-%s-%s', substr($hex, 0, 8), substr($hex, 8, 4), substr($hex, 12, 4), substr($hex, 16, 4), substr($hex, 20));
    }
}
