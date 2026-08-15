<?php

declare(strict_types=1);

namespace App\Services\LegacyLocation;

use App\Core\NumberService;
use PDO;
use RuntimeException;
use Throwable;

final class LegacyGnMigrationRepairService
{
    public const SOURCE_SYSTEM = 'AGRARIANADMIN_HR';
    public const SOURCE_TABLE = 'tbl_gnd';

    private const LOCATION_TYPE = 'GN_DIVISION';
    private const NUMBER_CATEGORY = 'LOCATION_GN_DIVISION';

    public function __construct(
        private PDO $source,
        private PDO $target,
        private bool $dryRun,
        private int $batchSize = 500,
        private string $fallbackDate = '2026-08-10'
    ) {
        if ($batchSize < 1 || $batchSize > 5000) {
            throw new RuntimeException('Batch size must be between 1 and 5000.');
        }
    }

    public function run(): array
    {
        $this->assertReady();
        $sourceRows = $this->loadSourceRows();
        $before = $this->targetMetrics();
        $errors = [];
        $plans = $this->buildRepairPlans($sourceRows, $errors);
        $created = 0;
        $allocated = 0;

        if (!$this->dryRun && $errors === []) {
            foreach (array_chunk($plans, $this->batchSize) as $chunk) {
                $this->target->beginTransaction();
                try {
                    foreach ($chunk as $plan) {
                        if ($this->repairReference($plan)) {
                            $created++;
                            $allocated++;
                        }
                    }
                    $this->target->commit();
                } catch (Throwable $exception) {
                    if ($this->target->inTransaction()) {
                        $this->target->rollBack();
                    }
                    throw $exception;
                }
            }
        }

        $after = $this->dryRun ? $before : $this->targetMetrics();
        $summary = [
            'mode' => $this->dryRun ? 'DRY_RUN' : 'EXECUTE',
            'source_gn_count' => count($sourceRows),
            'current_gn_count' => $before['gn_locations'],
            'legacy_reference_count' => $before['legacy_references'],
            'distinct_referenced_gn_count' => $before['distinct_locations'],
            'duplicate_target_gn_count' => $before['duplicate_targets'],
            'references_requiring_separation' => $before['references_requiring_separation'],
            'new_gn_locations_required' => count($plans),
            'new_gn_locations_created' => $created,
            'new_dad_numbers_allocated' => $allocated,
            'remaining_duplicate_references' => $after['references_requiring_separation'],
            'final_gn_count' => $after['gn_locations'],
            'final_distinct_referenced_gn_count' => $after['distinct_locations'],
            'errors' => count($errors),
            'error_messages' => $errors,
            'relationship_validation' => $this->validateCurrentHierarchy(),
        ];
        $summary['report_path'] = $this->writeReport($summary);

        return $summary;
    }

    private function assertReady(): void
    {
        foreach (['location', 'location_type', 'legacy_location_reference', 'number_category', 'number_allocation'] as $table) {
            $statement = $this->target->prepare(
                'SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name=?'
            );
            $statement->execute([$table]);
            if ((int)$statement->fetchColumn() !== 1) {
                throw new RuntimeException("Required target table is missing: {$table}");
            }
        }

        $statement = $this->target->prepare('SELECT COUNT(*) FROM location_type WHERE system_key=? AND active=1');
        $statement->execute([self::LOCATION_TYPE]);
        if ((int)$statement->fetchColumn() !== 1) {
            throw new RuntimeException('Active GN_DIVISION Location Type is required.');
        }

        $statement = $this->target->prepare('SELECT category_code FROM number_category WHERE category_key=? AND active=1');
        $statement->execute([self::NUMBER_CATEGORY]);
        if ((string)$statement->fetchColumn() !== '70008') {
            throw new RuntimeException('GN enterprise number category 70008 is not configured correctly.');
        }
    }

    private function loadSourceRows(): array
    {
        $rows = $this->source->query(
            'SELECT gnd_id, arpa_id, dis_code, asc_code, gnd_ocode, gnd_code, gnd_lcode,
                    gnd_name, gnd_sname, gnd_tname, gnd_status
             FROM tbl_gnd
             ORDER BY gnd_id'
        )->fetchAll();
        $byId = [];
        foreach ($rows as $row) {
            $byId[(string)$row['gnd_id']] = $row;
        }

        return $byId;
    }

    private function targetMetrics(): array
    {
        $statement = $this->target->prepare(
            'SELECT COUNT(*)
             FROM location l
             JOIN location_type lt ON lt.id=l.location_type_id
             WHERE lt.system_key=?'
        );
        $statement->execute([self::LOCATION_TYPE]);
        $gnLocations = (int)$statement->fetchColumn();

        $statement = $this->target->prepare(
            'SELECT COUNT(*) AS refs, COUNT(DISTINCT location_id) AS locations
             FROM legacy_location_reference
             WHERE source_system=? AND source_table=?'
        );
        $statement->execute([self::SOURCE_SYSTEM, self::SOURCE_TABLE]);
        $references = $statement->fetch() ?: [];

        $statement = $this->target->prepare(
            'SELECT COUNT(*) AS duplicate_targets, COALESCE(SUM(reference_count - 1), 0) AS extra_references
             FROM (
                SELECT location_id, COUNT(*) AS reference_count
                FROM legacy_location_reference
                WHERE source_system=? AND source_table=?
                GROUP BY location_id
                HAVING COUNT(*) > 1
             ) duplicate_groups'
        );
        $statement->execute([self::SOURCE_SYSTEM, self::SOURCE_TABLE]);
        $duplicates = $statement->fetch() ?: [];

        return [
            'gn_locations' => $gnLocations,
            'legacy_references' => (int)($references['refs'] ?? 0),
            'distinct_locations' => (int)($references['locations'] ?? 0),
            'duplicate_targets' => (int)($duplicates['duplicate_targets'] ?? 0),
            'references_requiring_separation' => (int)($duplicates['extra_references'] ?? 0),
        ];
    }

    private function buildRepairPlans(array $sourceRows, array &$errors): array
    {
        $statement = $this->target->prepare(
            'SELECT r.id AS reference_id, r.location_id, r.legacy_id,
                    l.official_code, l.name_en, l.name_si, l.name_ta, l.operational_status
             FROM legacy_location_reference r
             JOIN location l ON l.id=r.location_id
             JOIN location_type lt ON lt.id=l.location_type_id
             WHERE r.source_system=? AND r.source_table=? AND lt.system_key=?
             ORDER BY r.location_id, r.id'
        );
        $statement->execute([self::SOURCE_SYSTEM, self::SOURCE_TABLE, self::LOCATION_TYPE]);
        $groups = [];
        foreach ($statement->fetchAll() as $reference) {
            $groups[(string)$reference['location_id']][] = $reference;
        }

        $plans = [];
        foreach ($groups as $locationId => $references) {
            if (count($references) < 2) {
                continue;
            }

            $ownerId = $this->chooseOwnerLegacyId($references, $sourceRows);
            foreach ($references as $reference) {
                $legacyId = (string)$reference['legacy_id'];
                if ($legacyId === $ownerId) {
                    continue;
                }
                if (!isset($sourceRows[$legacyId])) {
                    $errors[] = "Legacy GN {$legacyId} referenced by {$locationId} is missing from tbl_gnd.";
                    continue;
                }
                $plans[] = [
                    'reference_id' => (int)$reference['reference_id'],
                    'old_location_id' => $locationId,
                    'legacy_id' => $legacyId,
                    'source' => $sourceRows[$legacyId],
                ];
            }
        }

        return $plans;
    }

    private function chooseOwnerLegacyId(array $references, array $sourceRows): string
    {
        $scored = [];
        foreach ($references as $reference) {
            $legacyId = (string)$reference['legacy_id'];
            $source = $sourceRows[$legacyId] ?? [];
            $score = 0;
            foreach ([
                ['gnd_code', 'official_code'],
                ['gnd_name', 'name_en'],
                ['gnd_sname', 'name_si'],
                ['gnd_tname', 'name_ta'],
            ] as [$sourceField, $targetField]) {
                if (LegacyLocationRules::clean($source[$sourceField] ?? null) !== null
                    && LegacyLocationRules::clean($source[$sourceField] ?? null) === LegacyLocationRules::clean($reference[$targetField] ?? null)
                ) {
                    $score++;
                }
            }
            $scored[] = ['legacy_id' => $legacyId, 'score' => $score, 'reference_id' => (int)$reference['reference_id']];
        }
        usort($scored, static fn (array $left, array $right): int =>
            ($right['score'] <=> $left['score']) ?: ($left['reference_id'] <=> $right['reference_id'])
        );

        return (string)$scored[0]['legacy_id'];
    }

    private function repairReference(array $plan): bool
    {
        $lock = $this->target->prepare(
            'SELECT location_id FROM legacy_location_reference
             WHERE id=? AND source_system=? AND source_table=? AND legacy_id=? FOR UPDATE'
        );
        $lock->execute([$plan['reference_id'], self::SOURCE_SYSTEM, self::SOURCE_TABLE, $plan['legacy_id']]);
        $currentLocationId = $lock->fetchColumn();
        if ($currentLocationId === false || (string)$currentLocationId !== (string)$plan['old_location_id']) {
            return false;
        }

        $source = $plan['source'];
        $locationType = $this->target->prepare('SELECT id FROM location_type WHERE system_key=? AND active=1');
        $locationType->execute([self::LOCATION_TYPE]);
        $locationTypeId = (string)$locationType->fetchColumn();
        $dadNumber = NumberService::nextUsing($this->target, self::NUMBER_CATEGORY);
        $locationId = LegacyLocationRules::uuid();
        $name = LegacyLocationRules::clean($source['gnd_name'] ?? null);
        if ($name === null) {
            throw new RuntimeException("GN {$plan['legacy_id']} has no English name.");
        }
        $status = LegacyLocationRules::normalizeStatus($source['gnd_status'] ?? null) ?? 'INACTIVE';
        $officialCode = LegacyLocationRules::clean($source['gnd_lcode'] ?? null)
            ?? LegacyLocationRules::clean($source['gnd_code'] ?? null)
            ?? LegacyLocationRules::clean($source['gnd_ocode'] ?? null);

        $insert = $this->target->prepare(
            "INSERT INTO location
             (id, dad_number, location_type_id, official_code, name_en, name_si, name_ta,
              effective_from, effective_to, operational_status, approval_status, created_at, version)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, NULL, ?, 'APPROVED', NOW(), 0)"
        );
        $insert->execute([
            $locationId,
            $dadNumber,
            $locationTypeId,
            $officialCode,
            $name,
            LegacyLocationRules::clean($source['gnd_sname'] ?? null),
            LegacyLocationRules::clean($source['gnd_tname'] ?? null),
            $this->fallbackDate,
            $status,
        ]);

        $update = $this->target->prepare(
            'UPDATE legacy_location_reference
             SET location_id=?, legacy_code=?, legacy_payload_json=?
             WHERE id=? AND location_id=?'
        );
        $update->execute([
            $locationId,
            $officialCode,
            json_encode($source, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR),
            $plan['reference_id'],
            $plan['old_location_id'],
        ]);
        if ($update->rowCount() !== 1) {
            throw new RuntimeException("GN {$plan['legacy_id']} reference could not be separated safely.");
        }

        return true;
    }

    private function validateCurrentHierarchy(): array
    {
        return [
            'district_without_province' => (int)$this->source->query(
                'SELECT COUNT(*) FROM tbl_district d LEFT JOIN tbl_province p ON p.auto_id=d.pro_id WHERE p.auto_id IS NULL'
            )->fetchColumn(),
            'asc_without_district' => (int)$this->source->query(
                'SELECT COUNT(*) FROM tbl_asc a LEFT JOIN tbl_district d ON d.auto_id=a.dis_id WHERE d.auto_id IS NULL'
            )->fetchColumn(),
            'arpa_without_asc' => (int)$this->source->query(
                'SELECT COUNT(*) FROM tbl_arpa ar LEFT JOIN tbl_asc a ON a.auto_id=ar.asc_id WHERE a.auto_id IS NULL'
            )->fetchColumn(),
        ];
    }

    private function writeReport(array $summary): string
    {
        $directory = BASE_PATH . '/storage/reports';
        if (!is_dir($directory) && !mkdir($directory, 0770, true) && !is_dir($directory)) {
            throw new RuntimeException('Unable to create GN repair report directory.');
        }
        $base = $directory . '/gn-migration-repair-' . date('Ymd-His');
        $path = $base . '.csv';
        for ($suffix = 1; is_file($path); $suffix++) {
            $path = $base . '-' . $suffix . '.csv';
        }
        $handle = fopen($path, 'xb');
        if ($handle === false) {
            throw new RuntimeException('Unable to create GN repair report.');
        }
        fwrite($handle, "\xEF\xBB\xBF");
        fputcsv($handle, ['metric', 'value'], ',', '"', '');
        foreach ($summary as $key => $value) {
            if ($key === 'report_path') {
                continue;
            }
            if (is_array($value)) {
                $value = json_encode($value, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
            }
            fputcsv($handle, [$key, $value], ',', '"', '');
        }
        fclose($handle);

        return $path;
    }
}
