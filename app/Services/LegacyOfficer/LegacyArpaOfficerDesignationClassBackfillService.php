<?php
declare(strict_types=1);

namespace App\Services\LegacyOfficer;

use PDO;
use RuntimeException;
use Throwable;

final class LegacyArpaOfficerDesignationClassBackfillService
{
    public const SOURCE_SYSTEM = 'AGRARIANADMIN_HR';
    public const SOURCE_TABLE = 'tbl_officer';
    public const DESIGNATION_KEY = 'ARPA_OFFICER';
    public const DESIGNATION_DAD = '72003-0000003';
    public const DESIGNATION_NAME = 'Agriculture Research and Production Assistant';

    private PDO $source;
    private PDO $target;
    private bool $dryRun;
    private int $batchSize;
    private array $designation = [];
    private array $classes = [];
    private array $plans = [];
    private array $reportRows = [];
    private array $protectedBefore = [];
    private array $stats = [
        'legacy_references_found' => 0,
        'officers_found' => 0,
        'designation_target_found' => 0,
        'would_set_designation' => 0,
        'already_correct_designation' => 0,
        'designation_after_execution' => 0,
        'class_i' => 0,
        'class_ii' => 0,
        'class_iii' => 0,
        'class_null_select' => 0,
        'unknown_grades' => 0,
        'would_update' => 0,
        'updated' => 0,
        'skipped' => 0,
        'warnings' => 0,
        'errors' => 0,
    ];

    public function __construct(PDO $source, PDO $target, bool $dryRun, int $batchSize = 500)
    {
        if ($batchSize < 1 || $batchSize > 10000) {
            throw new RuntimeException('Batch size must be between 1 and 10000.');
        }
        $this->source = $source;
        $this->target = $target;
        $this->dryRun = $dryRun;
        $this->batchSize = $batchSize;
    }

    public function run(): array
    {
        $this->assertReady();
        $this->protectedBefore = $this->protectedState();
        $ownsSourceTransaction = false;
        try {
            if (!$this->source->inTransaction()) {
                $this->source->exec('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ');
                $this->source->exec('SET TRANSACTION READ ONLY');
                $this->source->beginTransaction();
                $ownsSourceTransaction = true;
            }
            $this->buildPlans();
            if (!$this->dryRun) {
                $this->executePlans();
            }
            $protectedAfter = $this->protectedState();
            if ($protectedAfter !== $this->protectedBefore) {
                throw new RuntimeException('Backfill changed an out-of-scope or identity/numbering table.');
            }
            $summary = $this->summary($protectedAfter);
            $summary['report_path'] = $this->writeReport($summary);
            if ($ownsSourceTransaction && $this->source->inTransaction()) {
                $this->source->commit();
            }
            return $summary;
        } catch (Throwable $e) {
            if ($ownsSourceTransaction && $this->source->inTransaction()) {
                $this->source->rollBack();
            }
            throw $e;
        }
    }

    private function assertReady(): void
    {
        foreach (['officer', 'designation', 'officer_class', 'legacy_officer_reference'] as $table) {
            if (!$this->tableExists($this->target, $table)) {
                throw new RuntimeException("Target table {$table} is missing. Run php bin/migrate.php first.");
            }
        }
        if (!$this->tableExists($this->source, self::SOURCE_TABLE)) {
            throw new RuntimeException('Legacy source table tbl_officer is missing.');
        }
        $stmt = $this->target->prepare('SELECT id,dad_number,system_key,name_en FROM designation WHERE system_key=?');
        $stmt->execute([self::DESIGNATION_KEY]);
        $rows = $stmt->fetchAll();
        if (count($rows) !== 1) {
            throw new RuntimeException('Exactly one ARPA_OFFICER designation is required.');
        }
        $this->designation = $rows[0];
        if ($this->designation['dad_number'] !== self::DESIGNATION_DAD || $this->designation['name_en'] !== self::DESIGNATION_NAME) {
            throw new RuntimeException('ARPA_OFFICER designation identity/name is not configured by migration 012.');
        }
        $this->stats['designation_target_found'] = 1;
        $classRows = $this->target->query("SELECT id,dad_number,system_key,name_en FROM officer_class WHERE system_key IN ('CLASS_I','CLASS_II','CLASS_III')")->fetchAll();
        foreach ($classRows as $class) {
            $this->classes[(string)$class['system_key']] = $class;
        }
        foreach (['CLASS_I', 'CLASS_II', 'CLASS_III'] as $key) {
            if (!isset($this->classes[$key])) {
                throw new RuntimeException("Required Officer Class is missing: {$key}");
            }
        }
    }

    private function buildPlans(): void
    {
        $stmt = $this->target->prepare(
            'SELECT r.legacy_officer_id,r.officer_id,o.dad_number,o.primary_designation_id,o.class_id
             FROM legacy_officer_reference r
             LEFT JOIN officer o ON o.id=r.officer_id
             WHERE r.source_system=? AND r.source_table=? ORDER BY r.legacy_officer_id'
        );
        $stmt->execute([self::SOURCE_SYSTEM, self::SOURCE_TABLE]);
        $references = $stmt->fetchAll();
        $this->stats['legacy_references_found'] = count($references);

        $grades = $this->loadGrades(array_column($references, 'legacy_officer_id'));
        foreach ($references as $reference) {
            $legacyId = (string)$reference['legacy_officer_id'];
            $officerId = trim((string)($reference['officer_id'] ?? ''));
            if ($officerId === '' || $reference['dad_number'] === null) {
                $this->stats['skipped']++;
                $this->stats['errors']++;
                $this->reportRows[] = $this->reportRow($reference, null, null, 'SKIPPED', 'Referenced target Officer is missing.');
                continue;
            }
            $this->stats['officers_found']++;
            if (!array_key_exists($legacyId, $grades)) {
                $this->stats['skipped']++;
                $this->stats['errors']++;
                $this->reportRows[] = $this->reportRow($reference, null, null, 'SKIPPED', 'Legacy tbl_officer master is missing.');
                continue;
            }

            $grade = $grades[$legacyId];
            $classKey = LegacyOfficerGradeMapper::classKey($grade);
            if ($classKey !== null) {
                $stat = strtolower($classKey);
                $this->stats[$stat]++;
            } elseif (LegacyOfficerGradeMapper::isSelect($grade)) {
                $this->stats['class_null_select']++;
            } else {
                $this->stats['unknown_grades']++;
                $this->stats['warnings']++;
            }
            $desiredClassId = $classKey !== null ? (string)$this->classes[$classKey]['id'] : null;
            $designationCorrect = (string)($reference['primary_designation_id'] ?? '') === (string)$this->designation['id'];
            $classCorrect = $this->sameNullable($reference['class_id'] ?? null, $desiredClassId);
            if ($designationCorrect) {
                $this->stats['already_correct_designation']++;
            } else {
                $this->stats['would_set_designation']++;
            }
            $this->stats['designation_after_execution']++;
            $needsUpdate = !$designationCorrect || !$classCorrect;
            if ($needsUpdate) {
                $this->stats['would_update']++;
            }
            $message = LegacyOfficerGradeMapper::isUnknown($grade)
                ? 'Unknown/blank legacy grade; class remains NULL.'
                : '';
            $reportIndex = count($this->reportRows);
            $this->reportRows[] = $this->reportRow($reference, $grade, $classKey, $needsUpdate ? 'WOULD_UPDATE' : 'ALREADY_CORRECT', $message);
            $this->plans[] = [
                'officer_id' => $officerId,
                'class_id' => $desiredClassId,
                'needs_update' => $needsUpdate,
                'report_index' => $reportIndex,
            ];
        }
    }

    private function loadGrades(array $legacyIds): array
    {
        $grades = [];
        foreach (array_chunk($legacyIds, 1000) as $chunk) {
            if ($chunk === []) {
                continue;
            }
            $placeholders = implode(',', array_fill(0, count($chunk), '?'));
            $stmt = $this->source->prepare("SELECT officer_id,grade FROM tbl_officer WHERE officer_id IN ({$placeholders})");
            $stmt->execute(array_values($chunk));
            foreach ($stmt->fetchAll() as $row) {
                $grades[(string)$row['officer_id']] = $row['grade'];
            }
        }
        return $grades;
    }

    private function executePlans(): void
    {
        $update = $this->target->prepare(
            'UPDATE officer SET primary_designation_id=?,class_id=?,updated_at=NOW(),version=version+1 WHERE id=?'
        );
        foreach (array_chunk($this->plans, $this->batchSize) as $batch) {
            $this->target->beginTransaction();
            try {
                foreach ($batch as $plan) {
                    if (!$plan['needs_update']) {
                        continue;
                    }
                    $update->execute([$this->designation['id'], $plan['class_id'], $plan['officer_id']]);
                    if ($update->rowCount() !== 1) {
                        throw new RuntimeException('Target Officer disappeared during backfill: ' . $plan['officer_id']);
                    }
                    $this->stats['updated']++;
                    $this->reportRows[$plan['report_index']]['migration_status'] = 'UPDATED';
                }
                $this->target->commit();
            } catch (Throwable $e) {
                if ($this->target->inTransaction()) {
                    $this->target->rollBack();
                }
                throw $e;
            }
        }
    }

    private function protectedState(): array
    {
        $tables = [
            'officer', 'legacy_officer_reference', 'number_allocation', 'system_user',
            'application_role', 'application_permission', 'application_role_permission', 'user_account_role', 'user_account_scope',
            'officer_appointment', 'officer_appointment_history', 'officer_assignment',
            'office_assignment', 'arpa_assignment', 'officer_location_assignment',
        ];
        $state = [];
        foreach ($tables as $table) {
            $state[$table] = $this->tableExists($this->target, $table)
                ? (int)$this->target->query("SELECT COUNT(*) FROM `{$table}`")->fetchColumn()
                : 0;
        }
        foreach (['OFFICER', 'OFFICER_CLASS'] as $category) {
            $stmt = $this->target->prepare('SELECT next_value FROM number_category WHERE category_key=?');
            $stmt->execute([$category]);
            $state['number_category:' . $category] = (int)$stmt->fetchColumn();
        }
        return $state;
    }

    private function summary(array $protectedAfter): array
    {
        return array_merge($this->stats, [
            'mode' => $this->dryRun ? 'DRY_RUN' : 'EXECUTE',
            'status' => $this->stats['errors'] > 0 ? 'COMPLETED_WITH_ERRORS' : ($this->stats['warnings'] > 0 ? 'COMPLETED_WITH_WARNINGS' : 'COMPLETED'),
            'designation' => $this->designation,
            'classes' => $this->classes,
            'out_of_scope_unchanged' => $protectedAfter === $this->protectedBefore,
            'out_of_scope_counts' => $protectedAfter,
        ]);
    }

    private function writeReport(array $summary): string
    {
        $directory = BASE_PATH . '/storage/reports';
        if (!is_dir($directory) && !mkdir($directory, 0770, true) && !is_dir($directory)) {
            throw new RuntimeException('Unable to create backfill report directory.');
        }
        $path = $directory . '/arpa-officer-designation-class-backfill-' . date('Ymd-His') . '-' . bin2hex(random_bytes(4)) . '.csv';
        $handle = fopen($path, 'wb');
        if ($handle === false) {
            throw new RuntimeException('Unable to create backfill CSV report.');
        }
        $columns = ['legacy_officer_id', 'officer_id', 'dad_number', 'legacy_grade', 'class_system_key', 'migration_status', 'message'];
        fputcsv($handle, $columns, ',', '"', '');
        foreach ($this->reportRows as $row) {
            fputcsv($handle, array_map(static fn(string $column): mixed => $row[$column] ?? null, $columns), ',', '"', '');
        }
        fclose($handle);
        file_put_contents(substr($path, 0, -4) . '.json', json_encode($summary, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
        return $path;
    }

    private function reportRow(array $reference, mixed $grade, ?string $classKey, string $status, string $message): array
    {
        return [
            'legacy_officer_id' => $reference['legacy_officer_id'] ?? null,
            'officer_id' => $reference['officer_id'] ?? null,
            'dad_number' => $reference['dad_number'] ?? null,
            'legacy_grade' => $grade,
            'class_system_key' => $classKey,
            'migration_status' => $status,
            'message' => $message,
        ];
    }

    private function sameNullable(mixed $current, ?string $desired): bool
    {
        $current = $current === null || $current === '' ? null : (string)$current;
        return $current === $desired;
    }

    private function tableExists(PDO $pdo, string $table): bool
    {
        $stmt = $pdo->prepare('SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name=?');
        $stmt->execute([$table]);
        return (int)$stmt->fetchColumn() > 0;
    }
}
