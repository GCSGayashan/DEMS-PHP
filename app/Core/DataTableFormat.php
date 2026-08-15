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
        return '<span class="badge bg-' . $class . '">' . e($status !== '' ? $status : '—') . '</span>';
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

    public static function actionForm(string $path, string $label, string $class): string
    {
        return '<form class="d-inline" method="post" action="' . e(url($path)) . '">'
            . Csrf::field()
            . '<button class="btn btn-sm ' . e($class) . '">' . e($label) . '</button></form>';
    }
}
