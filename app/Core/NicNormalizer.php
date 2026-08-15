<?php
declare(strict_types=1);

namespace App\Core;

final class NicNormalizer
{
    public static function normalize(?string $value): ?string
    {
        $normalized = strtoupper(trim((string)$value));
        if ($normalized === '') {
            return null;
        }

        // Repair whitespace only at the old-NIC V/X boundary. No numeric
        // character is ever inserted, deleted, or changed.
        $normalized = preg_replace('/^(\d{9})\s+([VX])(\s*\/)?$/', '$1$2$3', $normalized) ?? $normalized;

        // A trailing slash is discarded only when the resulting value is
        // already a structurally valid NIC.
        if (str_ends_with($normalized, '/')) {
            $withoutSlash = rtrim(substr($normalized, 0, -1));
            if (self::isValid($withoutSlash)) {
                $normalized = $withoutSlash;
            }
        }

        return $normalized;
    }

    public static function isValid(?string $normalized): bool
    {
        return $normalized !== null
            && preg_match('/^(?:\d{9}[VX]|\d{12})$/', $normalized) === 1;
    }

    /**
     * Match both representations of a valid Sri Lankan NIC using the
     * canonical 12-digit representation. Invalid legacy values deliberately
     * have no match key; their raw and normalized values remain traceable.
     */
    public static function matchKey(?string $normalized): ?string
    {
        if (!self::isValid($normalized)) {
            return null;
        }
        if (strlen($normalized) === 12) {
            return $normalized;
        }
        return '19' . substr($normalized, 0, 5) . '0' . substr($normalized, 5, 4);
    }
}
