<?php
declare(strict_types=1);

namespace App\Services\LegacyAppointment;

/** Finalized cutover policy for reconciled legacy ARPA appointment records. */
final class LegacyArpaMigrationPolicy
{
    public const BASELINE_DATE = '2025-01-01';

    private const DETERMINISTIC_ASC_EVIDENCE = [
        'EXACT',
        'EXACT_LEGACY_LOCATION',
        'STRONG_DERIVED',
        'CURRENT_STATE_ONLY',
        'WORKFLOW_ACTOR_DERIVED',
    ];

    public static function periodClassification(?string $effectiveFrom, ?string $effectiveTo): ?string
    {
        if ($effectiveFrom === null || $effectiveFrom === '') {
            return null;
        }
        if ($effectiveFrom >= self::BASELINE_DATE) {
            return 'LEGACY_PERIOD';
        }
        if ($effectiveTo === null || $effectiveTo >= self::BASELINE_DATE) {
            return 'PRE_BASELINE_CARRIED_FORWARD';
        }
        return 'PRE_BASELINE_HISTORY';
    }

    public static function baselineLabel(?string $classification): string
    {
        return match ($classification) {
            'PRE_BASELINE_CARRIED_FORWARD' => 'PRE-2025 CARRIED FORWARD',
            'LEGACY_PERIOD' => '2025+ LEGACY',
            'PRE_BASELINE_HISTORY' => 'PRE-2025 HISTORY',
            default => 'DATE REVIEW REQUIRED',
        };
    }

    public static function carriedIntoBaseline(?string $effectiveFrom, ?string $effectiveTo): bool
    {
        return self::periodClassification($effectiveFrom, $effectiveTo) === 'PRE_BASELINE_CARRIED_FORWARD';
    }

    public static function permanentCoversDate(array $permanent, string $dependentDate): bool
    {
        $from = $permanent['effective_from'] ?? null;
        $to = $permanent['effective_to'] ?? null;
        return is_string($from)
            && $from !== ''
            && $from <= $dependentDate
            && ($to === null || $to === '' || $to >= $dependentDate);
    }

    public static function deterministicAsc(array $record): ?string
    {
        if (($record['special_resolution'] ?? null) === 'CONFIRMED'
            && !empty($record['special_asc'])) {
            return (string) $record['special_asc'];
        }

        $candidate = $record['special_candidate_asc'] ?? null;
        $count = (int) ($record['special_candidate_count'] ?? ($candidate ? 1 : 0));
        $evidence = (string) ($record['special_evidence_class'] ?? $record['location_confidence'] ?? '');
        if ($count !== 1 || !$candidate || !in_array($evidence, self::DETERMINISTIC_ASC_EVIDENCE, true)) {
            return null;
        }
        return (string) $candidate;
    }

    public static function specialResolutionState(array $record): string
    {
        if (($record['special_resolution'] ?? null) === 'CONFIRMED' && !empty($record['special_asc'])) {
            return 'HUMAN_CONFIRMED';
        }
        $candidate = $record['special_candidate_asc'] ?? null;
        $count = (int) ($record['special_candidate_count'] ?? ($candidate ? 1 : 0));
        if ($count > 1) {
            return 'AMBIGUOUS';
        }
        if ($candidate === null || $candidate === '') {
            return 'NO_CANDIDATE';
        }
        return self::deterministicAsc($record) !== null ? 'DETERMINISTIC' : 'AMBIGUOUS';
    }
}
