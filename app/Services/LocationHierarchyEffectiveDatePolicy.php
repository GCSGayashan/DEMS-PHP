<?php
declare(strict_types=1);

namespace App\Services;

use DateTimeImmutable;
use DomainException;

/** Department-wide business-effective baseline for Location Master data. */
final class LocationHierarchyEffectiveDatePolicy
{
    public const BASELINE_DATE = '2024-01-05';

    public static function validationDate(string $businessDate): string
    {
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $businessDate);
        if (!$date || $date->format('Y-m-d') !== $businessDate) {
            throw new DomainException('Business date must be a valid date.');
        }
        return max($businessDate, self::BASELINE_DATE);
    }

    public static function validationDateSql(string $businessDateColumn): string
    {
        if (preg_match('/^[A-Za-z][A-Za-z0-9_]*\.[A-Za-z][A-Za-z0-9_]*$/', $businessDateColumn) !== 1) {
            throw new DomainException('Invalid business date expression.');
        }
        return "GREATEST({$businessDateColumn},'" . self::BASELINE_DATE . "')";
    }

    public static function relationshipAtSql(string $alias, string $validationDateSql): string
    {
        if (preg_match('/^[A-Za-z][A-Za-z0-9_]*$/', $alias) !== 1) {
            throw new DomainException('Invalid relationship alias.');
        }
        $prior = $alias . '_prior';
        return "{$alias}.active=1 AND {$alias}.approval_status='APPROVED'
            AND (
                {$alias}.effective_from<={$validationDateSql}
                OR NOT EXISTS(
                    SELECT 1 FROM location_relationship {$prior}
                    WHERE {$prior}.child_location_id={$alias}.child_location_id
                      AND {$prior}.relationship_type={$alias}.relationship_type
                      AND {$prior}.active=1
                      AND {$prior}.approval_status='APPROVED'
                      AND {$prior}.effective_from<{$alias}.effective_from
                )
            )
            AND ({$alias}.effective_to IS NULL OR {$alias}.effective_to>={$validationDateSql})";
    }
}
