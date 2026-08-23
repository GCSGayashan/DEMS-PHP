<?php
declare(strict_types=1);

namespace App\Core;

final class DataTableFormat
{
    public static function text(mixed $value, string $empty = '—'): string
    {
        $value = trim((string)$value);
        return $value === '' ? '<span class="text-muted">' . e($empty) . '</span>' : e($value);
    }

    public static function yesNo(mixed $value): string
    {
        return (int)$value === 1 ? 'Yes' : 'No';
    }

    public static function enumLabel(mixed $value, string $empty = '—'): string
    {
        $value = trim((string)$value);
        if ($value === '') {
            return $empty;
        }

        return match (strtoupper($value)) {
            'PERMANENT_IN_SERVICE' => 'Permanent In Service',
            'NOT_PERMANENT_IN_SERVICE' => 'Not Permanent In Service',
            'TEMPORARY' => 'Temporary',
            'CONTRACT' => 'Contract',
            'CASUAL' => 'Casual',
            'RETIRED' => 'Retired',
            default => ucwords(strtolower(str_replace('_', ' ', $value))),
        };
    }

    public static function enumText(mixed $value, string $empty = '—'): string
    {
        return e(self::enumLabel($value, $empty));
    }

    public static function badge(mixed $value): string
    {
        $status = strtoupper(trim((string)$value));
        $class = match ($status) {
            'ACTIVE', 'APPROVED', 'COMPLETED', 'ENABLED' => 'success',
            'SUBMITTED', 'PENDING', 'REQUESTED', 'RUNNING', 'SCHEDULED' => 'warning text-dark',
            'RETURNED', 'FAILED', 'ERROR', 'SUSPENDED', 'BLOCKED' => 'danger',
            'WITHDRAWN', 'CLOSED', 'EXPIRED', 'ENDED', 'REJECTED' => 'dark',
            'DRAFT', 'CREATED', 'ASC_VERIFIED', 'ASC_APPROVED', 'DISTRICT_VERIFIED', 'DISTRICT_APPROVED', 'NATIONAL_VERIFIED' => 'info text-dark',
            default => 'secondary',
        };
        $label = match ($status) {
            'ASC_VERIFIED' => 'ASC Verified',
            'ASC_APPROVED' => 'ASC Approved',
            'DISTRICT_VERIFIED' => 'District Verified',
            'DISTRICT_APPROVED' => 'District Approved',
            'NATIONAL_VERIFIED' => 'National Verified',
            'NATIONAL_APPROVED' => 'National Approved',
            'DUTY_COVERING' => 'Duty Covering',
            'ATTEND_TO_DUTY' => 'Attend to the Duty',
            'RETURNED_FOR_CORRECTION' => 'Returned for Correction',
            'PASSWORD_SETUP_REQUIRED' => 'Password Change Required',
            'LEGACY_IMPORT' => 'Imported Record',
            'HISTORICAL_EXCEPTION' => 'Old Data Warning',
            'LEGACY_HISTORY_ONLY' => 'Old Record - History Only',
            default => self::enumLabel($status),
        };
        return '<span class="badge bg-' . $class . '">' . e($label !== '' ? $label : '—') . '</span>';
    }

    public static function date(mixed $value, string $empty = '—'): string
    {
        $value = trim((string)$value);
        if ($value === '' || str_starts_with($value, '0000-00-00')) {
            return e($empty);
        }
        return e(substr($value, 0, 10));
    }

    public static function dateTime(mixed $value, string $empty = '—'): string
    {
        $value = trim((string)$value);
        if ($value === '' || str_starts_with($value, '0000-00-00')) {
            return e($empty);
        }
        return e(substr(str_replace('T', ' ', $value), 0, 16));
    }

    public static function scopeType(mixed $value): string
    {
        return self::text(match (strtoupper(trim((string)$value))) {
            'ASC' => 'Agrarian Service Center',
            'ARPA', 'ARPA_DIVISION' => 'ARPA Division',
            'DISTRICT' => 'District',
            'NATIONAL' => 'National Level',
            default => ucwords(strtolower(str_replace('_', ' ', trim((string)$value)))),
        });
    }

    public static function scopeMode(mixed $value): string
    {
        return self::text(match (strtoupper(trim((string)$value))) {
            'EXACT' => 'This location only',
            'INCLUDE_CHILDREN' => 'Includes offices under this location',
            'NATIONAL' => 'Across the country',
            default => ucwords(strtolower(str_replace('_', ' ', trim((string)$value)))),
        });
    }

    public static function accessLocations(mixed $value): string
    {
        $label = str_replace(
            ['ARPA_DIVISION / EXACT', 'ASC / EXACT', 'DISTRICT / INCLUDE_CHILDREN', 'NATIONAL / NATIONAL'],
            ['ARPA Division', 'Agrarian Service Center', 'District', 'National Level'],
            trim((string)$value)
        );
        return self::text($label, 'None');
    }

    public static function actionForm(string $path, string $label, string $class): string
    {
        return '<form class="d-inline" method="post" action="' . e(url($path)) . '">'
            . Csrf::field()
            . '<button class="btn btn-sm ' . e($class) . '">' . e($label) . '</button></form>';
    }
}
