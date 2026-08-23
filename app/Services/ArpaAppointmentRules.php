<?php
declare(strict_types=1);

namespace App\Services;

use DomainException;

final class ArpaAppointmentRules
{
    public const PERMANENCIES = ['PERMANENT_IN_SERVICE', 'NOT_PERMANENT_IN_SERVICE'];
    public const APPOINTMENT_TYPES = ['PERMANENT', 'ACTING', 'DUTY_COVERING', 'ATTEND_TO_DUTY'];
    public const SUBJECT_KINDS = ['NORMAL', 'AGRARIAN_BANK', 'SALES_SHOP', 'SITHAMU'];
    public const EXCLUSIVE_SUBJECT_KINDS = ['AGRARIAN_BANK', 'SALES_SHOP', 'SITHAMU'];

    public static function assertAppointmentTypeAllowed(string $permanency, string $type, bool $hasPermanent): void
    {
        if (!in_array($permanency, self::PERMANENCIES, true)) {
            throw new DomainException('Service permanency must be recorded before an assignment is created.');
        }
        if (!in_array($type, self::APPOINTMENT_TYPES, true)) {
            throw new DomainException('Unsupported ARPA Division appointment type.');
        }
        if ($type !== 'PERMANENT' && !$hasPermanent) {
            throw new DomainException('Assign a Permanent ARPA Division to this officer first.');
        }
        if ($permanency === 'PERMANENT_IN_SERVICE' && $type === 'ATTEND_TO_DUTY') {
            throw new DomainException('Attend to the Duty can only be assigned to an officer who is not Permanent In Service.');
        }
        if ($permanency === 'NOT_PERMANENT_IN_SERVICE' && $type === 'ACTING') {
            throw new DomainException('Acting can only be assigned to an officer who is Permanent In Service.');
        }
    }

    /** @return list<string> */
    public static function allowedAppointmentTypes(
        string $permanency,
        bool $hasPermanent,
        bool $permanentConflict,
        bool $actingConflict,
        bool $attendToDutyConflict
    ): array {
        if (!in_array($permanency, self::PERMANENCIES, true)) return [];

        $allowed = [];
        if (!$permanentConflict) $allowed[] = 'PERMANENT';
        if (!$hasPermanent) return $allowed;

        if ($permanency === 'PERMANENT_IN_SERVICE' && !$actingConflict) $allowed[] = 'ACTING';
        $allowed[] = 'DUTY_COVERING';
        if ($permanency === 'NOT_PERMANENT_IN_SERVICE' && !$attendToDutyConflict) $allowed[] = 'ATTEND_TO_DUTY';
        return $allowed;
    }

    public static function subjectIsExclusive(string $kind): bool
    {
        return in_array($kind, self::EXCLUSIVE_SUBJECT_KINDS, true);
    }

    public static function intervalsOverlap(string $startA, ?string $endA, string $startB, ?string $endB): bool
    {
        $endA ??= '9999-12-31';
        $endB ??= '9999-12-31';
        return $startA <= $endB && $startB <= $endA;
    }

    /** @return array{status:string,stage:string} */
    public static function transition(string $status, string $action, string $stage): array
    {
        $action = strtoupper($action);
        $stage = strtoupper($stage);
        $next = match ([$status, $action, $stage]) {
            ['CREATED', 'SUBMIT', 'CREATOR'], ['RETURNED', 'SUBMIT', 'CREATOR'] => 'SUBMITTED',
            ['SUBMITTED', 'VERIFY', 'ASC'] => 'ASC_VERIFIED',
            ['ASC_VERIFIED', 'APPROVE', 'ASC'] => 'ASC_APPROVED',
            ['ASC_APPROVED', 'VERIFY', 'DISTRICT'] => 'DISTRICT_VERIFIED',
            ['DISTRICT_VERIFIED', 'APPROVE', 'DISTRICT'] => 'DISTRICT_APPROVED',
            ['DISTRICT_APPROVED', 'VERIFY', 'NATIONAL'] => 'NATIONAL_VERIFIED',
            ['NATIONAL_VERIFIED', 'APPROVE', 'NATIONAL'] => 'NATIONAL_APPROVED',
            default => null,
        };
        if ($next !== null) {
            return ['status' => $next, 'stage' => $stage];
        }
        if (in_array($action,['RETURN_FOR_CORRECTION','REJECT'],true) && self::isReviewStatus($status)) {
            if (self::reviewStage($status) !== $stage) {
                throw new DomainException("{$status} belongs to the " . self::reviewStage($status) . ' review stage.');
            }
            return ['status' => 'RETURNED', 'stage' => $stage];
        }
        throw new DomainException("Action {$action} is not valid from {$status} at {$stage} stage.");
    }

    public static function isReviewStatus(string $status): bool
    {
        return in_array($status, [
            'SUBMITTED', 'ASC_VERIFIED', 'ASC_APPROVED', 'DISTRICT_VERIFIED',
            'DISTRICT_APPROVED', 'NATIONAL_VERIFIED',
        ], true);
    }

    public static function reviewStage(string $status): string
    {
        return match ($status) {
            'SUBMITTED', 'ASC_VERIFIED' => 'ASC',
            'ASC_APPROVED', 'DISTRICT_VERIFIED' => 'DISTRICT',
            'DISTRICT_APPROVED', 'NATIONAL_VERIFIED' => 'NATIONAL',
            default => throw new DomainException("{$status} is not awaiting review."),
        };
    }

    public static function operationalStatus(string $effectiveFrom, ?string $effectiveTo, ?string $today = null): string
    {
        $today ??= date('Y-m-d');
        if ($effectiveTo !== null && $effectiveTo < $today) {
            return 'ENDED';
        }
        if ($effectiveFrom > $today) {
            return 'SCHEDULED';
        }
        return 'ACTIVE';
    }
}
