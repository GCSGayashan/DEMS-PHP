<?php

declare(strict_types=1);

use App\Core\Database;
use App\Services\Arpa2025NonPermanentClosureRepairService;

require dirname(__DIR__) . '/bootstrap.php';

final class Arpa2025NonPermanentClosureRepairTest
{
    private PDO $pdo;
    private int $assertions = 0;

    public function run(): int
    {
        $this->pdo = Database::pdo();

        $before = $this->databaseState();

        $service = new Arpa2025NonPermanentClosureRepairService($this->pdo);
        $result = $service->dryRun();

        $this->population($result);
        $this->stateSpecificAssertions($result);
        $this->byType($result);
        $this->endReason($result);
        $this->staticSafetyCoverage();

        $after = $this->databaseState();

        $this->same(
            $before,
            $after,
            'dry-run leaves appointment, closure, correction and audit data unchanged'
        );

        echo "Arpa2025NonPermanentClosureRepairTest: {$this->assertions} assertions passed.\n";

        return 0;
    }

    private function population(array $result): void
    {
        $summary = $result['summary'];

        $this->same(
            3537,
            $summary['non_permanent_2025'],
            '2025 non-permanent population remains fixed'
        );

        $this->same(
            1137,
            $summary['permanent_2025'],
            '2025 Permanent population remains fixed'
        );

        $this->same(
            213,
            $summary['closed_before_target'],
            '213 earlier 2025 closures remain preserved'
        );

        $this->same(
            0,
            $summary['invalid_date_ranges'],
            'repair population contains no invalid date ranges'
        );

        $this->same(
            0,
            $summary['earlier_closures_outside_2025'],
            'all 213 preserved earlier closures end within calendar year 2025'
        );

        $this->same(
            [],
            $result['errors'],
            'dry-run has no validation errors'
        );

        $this->same(
            true,
            in_array($result['state'], ['READY', 'ALREADY_APPLIED'], true),
            'database is either approved pre-repair state or approved post-repair state'
        );
    }

    private function stateSpecificAssertions(array $result): void
    {
        $summary = $result['summary'];

        if ($result['state'] === 'READY') {
            $this->same(
                3309,
                $summary['open_without_closure'],
                'READY state has exactly 3309 appointments requiring new closures'
            );

            $this->same(
                8,
                $summary['already_target_date'],
                'READY state preserves 8 existing 2025-12-31 closures'
            );

            $this->same(
                7,
                $summary['closed_after_target'],
                'READY state has exactly 7 later closures requiring correction'
            );

            $this->same(
                3316,
                $summary['rows_requiring_change'],
                'READY write set is exactly 3316 appointments'
            );

            return;
        }

        $this->same(
            0,
            $summary['open_without_closure'],
            'post-repair state has no open 2025 non-permanent appointments'
        );

        $this->same(
            3324,
            $summary['already_target_date'],
            'post-repair state has exactly 3324 appointments ending 2025-12-31'
        );

        $this->same(
            0,
            $summary['closed_after_target'],
            'post-repair state has no 2025 non-permanent closure after year-end'
        );

        $this->same(
            0,
            $summary['rows_requiring_change'],
            'post-repair state has no remaining repair candidates'
        );
    }

    private function byType(array $result): void
    {
        $byType = $result['by_type'];

        $this->same(
            3283,
            $byType['ACTING']['total'] ?? null,
            'Acting 2025 population remains fixed'
        );

        $this->same(
            204,
            $byType['ACTING']['closed_before_target'] ?? null,
            '204 earlier Acting closures remain preserved'
        );

        $this->same(
            237,
            $byType['DUTY_COVERING']['total'] ?? null,
            'Duty Covering 2025 population remains fixed'
        );

        $this->same(
            8,
            $byType['DUTY_COVERING']['closed_before_target'] ?? null,
            '8 earlier Duty Covering closures remain preserved'
        );

        $this->same(
            17,
            $byType['ATTEND_TO_DUTY']['total'] ?? null,
            'Attend to Duty 2025 population remains fixed'
        );

        $this->same(
            1,
            $byType['ATTEND_TO_DUTY']['closed_before_target'] ?? null,
            '1 earlier Attend to Duty closure remains preserved'
        );

        if ($result['state'] === 'READY') {
            $this->same(
                3066,
                $byType['ACTING']['open_without_closure'] ?? null,
                'READY Acting open count is fixed'
            );

            $this->same(
                7,
                $byType['ACTING']['closed_after_target'] ?? null,
                'all seven later closures are Acting appointments'
            );

            $this->same(
                227,
                $byType['DUTY_COVERING']['open_without_closure'] ?? null,
                'READY Duty Covering open count is fixed'
            );

            $this->same(
                16,
                $byType['ATTEND_TO_DUTY']['open_without_closure'] ?? null,
                'READY Attend to Duty open count is fixed'
            );
        }
    }

    private function endReason(array $result): void
    {
        $reason = $result['end_reason'];

        if ($reason['exists']) {
            $this->same(
                'END_OF_APPOINTMENT_PERIOD',
                $reason['system_key'],
                'year-end repair uses the dedicated normalized reason'
            );

            $this->same(
                0,
                $reason['service_terminating'],
                'year-end reason does not terminate officer service'
            );

            $this->same(
                1,
                $reason['active'],
                'year-end reason is active'
            );

            return;
        }

        $this->same(
            'READY',
            $result['state'],
            'missing year-end reason is allowed only before migration 045'
        );

        $this->same(
            true,
            $reason['migration_required'] ?? false,
            'dry-run explicitly reports migration 045 prerequisite'
        );
    }

    private function staticSafetyCoverage(): void
    {
        $servicePath =
            BASE_PATH .
            '/app/Services/Arpa2025NonPermanentClosureRepairService.php';

        $migrationPath =
            BASE_PATH .
            '/database/migrations/045_arpa_end_of_appointment_period_reason.sql';

        $service = (string)file_get_contents($servicePath);
        $migration = (string)file_get_contents($migrationPath);

        $reflection = new ReflectionClass(
            Arpa2025NonPermanentClosureRepairService::class
        );

        $typeConstant = $reflection->getReflectionConstant(
            'NON_PERMANENT_TYPES'
        );

        if ($typeConstant === false) {
            throw new RuntimeException(
                'NON_PERMANENT_TYPES constant was not found.'
            );
        }

        $repairTypes = $typeConstant->getValue();

        $this->same(
            [
                'ACTING',
                'DUTY_COVERING',
                'ATTEND_TO_DUTY',
            ],
            $repairTypes,
            'repair type allow-list contains exactly the three non-permanent appointment types'
        );

        $this->same(
            false,
            in_array('PERMANENT', $repairTypes, true),
            'Permanent is never part of the repair type allow-list'
        );

        $this->same(
            true,
            str_contains($service, 'c.effective_to>?'),
            'write selection includes only closures later than target date'
        );

        $this->same(
            true,
            str_contains($service, 'AND c.effective_to<=?'),
            'existing closures on or before target date are explicitly protected'
        );

        $this->same(
            true,
            str_contains($service, 'SET effective_to=?,') &&
            str_contains($service, 'end_reason_id=?,') &&
            str_contains($service, 'data_correction_id=?'),
            'later-closure correction records date, normalized reason and correction provenance'
        );

        $this->same(
            true,
            str_contains(
                $service,
                "'DATA_ISSUE_CORRECTION'"
            ),
            'new closure uses controlled data-correction provenance'
        );

        $this->same(
            true,
            str_contains(
                $service,
                "'arpa.appointment.2025-year-end-repair'"
            ),
            'repair writes a dedicated audit action'
        );

        $this->same(
            true,
            str_contains(
                $service,
                "'ARPA_APPOINTMENT_YEAR_END_REPAIR'"
            ),
            'repair audit target type is dedicated to the year-end repair'
        );

        $this->same(
            false,
            str_contains(
                $service,
                'UPDATE arpa_division_appointment SET'
            ),
            'repair never updates appointment master rows'
        );

        $this->same(
            false,
            str_contains(
                $service,
                'UPDATE arpa_division_appointment_request'
            ),
            'repair never updates appointment request rows'
        );

        $this->same(
            false,
            str_contains(
                $service,
                'INSERT INTO arpa_appointment_workflow_action'
            ),
            'repair never manufactures appointment workflow actions'
        );

        $this->same(
            true,
            str_contains(
                $migration,
                "'END_OF_APPOINTMENT_PERIOD'"
            ),
            'migration 045 defines the dedicated year-end reason'
        );

        $this->same(
            true,
            str_contains(
                $migration,
                "'End of Appointment Period'"
            ),
            'migration 045 defines the correct English reason name'
        );

        $this->same(
            true,
            preg_match(
                "/'End of Appointment Period'\s*,\s*0\s*,/s",
                $migration
            ) === 1,
            'migration 045 marks the reason as non-service-terminating'
        );
    }

    private function databaseState(): array
    {
        return [
            'appointments' => $this->fingerprint(
                'arpa_division_appointment',
                'id'
            ),
            'requests' => $this->fingerprint(
                'arpa_division_appointment_request',
                'id'
            ),
            'closures' => $this->fingerprint(
                'arpa_division_appointment_closure',
                'id'
            ),
            'corrections' => $this->fingerprint(
                'arpa_appointment_data_correction',
                'id'
            ),
            'workflow' => $this->fingerprint(
                'arpa_appointment_workflow_action',
                'id'
            ),
            'audit' => $this->fingerprint(
                'audit_event',
                'id'
            ),
        ];
    }

    private function fingerprint(string $table, string $idColumn): string
    {
        $allowed = [
            'arpa_division_appointment',
            'arpa_division_appointment_request',
            'arpa_division_appointment_closure',
            'arpa_appointment_data_correction',
            'arpa_appointment_workflow_action',
            'audit_event',
        ];

        if (!in_array($table, $allowed, true)) {
            throw new RuntimeException('Unsupported fingerprint table.');
        }

        $sql = "
            SELECT CONCAT(
                COUNT(*),
                ':',
                COALESCE(
                    BIT_XOR(
                        CRC32(CAST({$idColumn} AS CHAR))
                    ),
                    0
                )
            )
            FROM {$table}
        ";

        return (string)$this->pdo->query($sql)->fetchColumn();
    }

    private function same(
        mixed $expected,
        mixed $actual,
        string $message
    ): void {
        $this->assertions++;

        if ($expected !== $actual) {
            throw new RuntimeException(
                $message .
                ': expected ' .
                var_export($expected, true) .
                ', got ' .
                var_export($actual, true)
            );
        }
    }
}

exit((new Arpa2025NonPermanentClosureRepairTest())->run());
