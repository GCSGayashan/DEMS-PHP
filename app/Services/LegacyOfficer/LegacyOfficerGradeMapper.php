<?php
declare(strict_types=1);

namespace App\Services\LegacyOfficer;

final class LegacyOfficerGradeMapper
{
    public static function classKey(mixed $grade): ?string
    {
        return match (trim((string)$grade)) {
            'Grade1', 'Grade 1' => 'CLASS_I',
            'Grade2', 'Grade 2' => 'CLASS_II',
            'Grade3', 'Grade 3' => 'CLASS_III',
            default => null,
        };
    }

    public static function isSelect(mixed $grade): bool
    {
        return trim((string)$grade) === 'Select';
    }

    public static function isUnknown(mixed $grade): bool
    {
        return self::classKey($grade) === null && !self::isSelect($grade);
    }
}
