<?php
declare(strict_types=1);

namespace App\Services\LegacyLocation;

use App\Core\NumberService;
use PDO;
use RuntimeException;
use Throwable;

final class LegacyLocationMigrationService
{
    public const SOURCE_SYSTEM = 'AGRARIANADMIN_HR';

    private const TYPE_ORDER = ['province', 'district', 'asc', 'arpa', 'gn'];

    private const EXPECTED = [
        'province' => 9,
        'district' => 25,
        'asc' => 566,
        'arpa' => 10396,
        'gn' => 14016,
    ];

    private const SPECS = [
        'province' => [
            'table' => 'tbl_province', 'id' => 'auto_id', 'code' => 'pro_code',
            'name_en' => 'pro_name', 'name_si' => 'pro_sname', 'name_ta' => 'pro_tname',
            'status' => 'pro_status', 'created' => 'created_at', 'type' => 'PROVINCE',
            'category' => 'LOCATION_PROVINCE', 'parent_type' => null, 'parent_field' => null,
            'relationship' => null,
        ],
        'district' => [
            'table' => 'tbl_district', 'id' => 'auto_id', 'code' => 'dis_code',
            'name_en' => 'dis_name', 'name_si' => 'dis_sname', 'name_ta' => 'dis_tname',
            'status' => 'dis_status', 'created' => 'created_at', 'type' => 'DISTRICT',
            'category' => 'LOCATION_DISTRICT', 'parent_type' => 'province', 'parent_field' => 'pro_id',
            'relationship' => 'PROVINCE_DISTRICT',
        ],
        'asc' => [
            'table' => 'tbl_asc', 'id' => 'auto_id', 'code' => 'asc_code',
            'name_en' => 'asc_name', 'name_si' => 'asc_sname', 'name_ta' => 'asc_tname',
            'status' => 'asc_status', 'created' => 'created_at', 'type' => 'ASC',
            'category' => 'LOCATION_ASC', 'parent_type' => 'district', 'parent_field' => 'dis_id',
            'relationship' => 'DISTRICT_ASC',
        ],
        'arpa' => [
            'table' => 'tbl_arpa', 'id' => 'auto_id', 'code' => 'arpa_code',
            'name_en' => 'arpa_name', 'name_si' => 'arpa_sname', 'name_ta' => 'arpa_tname',
            'status' => 'arpa_status', 'created' => 'created_at', 'type' => 'ARPA_DIVISION',
            'category' => 'LOCATION_ARPA_DIVISION', 'parent_type' => 'asc', 'parent_field' => 'asc_id',
            'relationship' => 'ASC_ARPA_DIVISION',
        ],
        'gn' => [
            'table' => 'tbl_gnd', 'id' => 'gnd_id', 'code' => 'gnd_code',
            'name_en' => 'gnd_name', 'name_si' => 'gnd_sname', 'name_ta' => 'gnd_tname',
            'status' => 'gnd_status', 'created' => null, 'type' => 'GN_DIVISION',
            'category' => 'LOCATION_GN_DIVISION', 'parent_type' => null, 'parent_field' => null,
            'relationship' => null,
        ],
    ];

    private PDO $source;
    private PDO $target;
    private bool $dryRun;
    private ?string $selectedType;
    private int $batchSize;
    private string $fallbackDate;
    private string $runId;
    private array $sourceRows = [];
    private array $sourceById = [];
    private array $locationMap = [];
    private array $locationTypes = [];
    private array $targetByCode = [];
    private array $targetByName = [];
    private array $referenceMap = [];
    private array $relationshipMap = [];
    private array $approvedRelationshipMap = [];
    private array $issues = [];
    private array $reportRows = [];
    private array $simulatedNumbers = [];
    private array $stats = [];
    private int $relationshipsCreated = 0;
    private int $relationshipsMatched = 0;
    private ?string $reportPath = null;

    public function __construct(
        PDO $source,
        PDO $target,
        bool $dryRun,
        ?string $selectedType = null,
        int $batchSize = 500,
        ?string $fallbackDate = null
    ) {
        $selectedType = $selectedType !== null ? strtolower($selectedType) : null;
        if ($selectedType !== null && !in_array($selectedType, self::TYPE_ORDER, true)) {
            throw new RuntimeException('Unsupported migration type: ' . $selectedType);
        }
        if ($batchSize < 1 || $batchSize > 10000) {
            throw new RuntimeException('Batch size must be between 1 and 10000.');
        }
        $parsedFallback = $fallbackDate !== null ? \DateTimeImmutable::createFromFormat('!Y-m-d', $fallbackDate) : false;
        if ($parsedFallback === false || $parsedFallback->format('Y-m-d') !== $fallbackDate) {
            throw new RuntimeException('LEGACY_LOCATION_EFFECTIVE_FROM must be a valid YYYY-MM-DD value.');
        }

        $this->source = $source;
        $this->target = $target;
        $this->dryRun = $dryRun;
        $this->selectedType = $selectedType;
        $this->batchSize = $batchSize;
        $this->fallbackDate = $fallbackDate;
        $this->runId = LegacyLocationRules::uuid();

        foreach (self::TYPE_ORDER as $type) {
            $this->stats[$type] = ['source' => 0, 'matched' => 0, 'created' => 0, 'skipped' => 0];
        }
    }

    public function run(): array
    {
        $this->assertTargetReady();
        $this->createRun();
        $ownsSourceTransaction = false;

        try {
            if (!$this->source->inTransaction()) {
                $this->source->exec('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ');
                $this->source->exec('SET TRANSACTION READ ONLY');
                $this->source->beginTransaction();
                $ownsSourceTransaction = true;
            }
            $this->loadSource();
            $this->loadTargetState();
            $this->validateCurrentHierarchy();

            foreach (self::TYPE_ORDER as $type) {
                $this->migrateType($type);
                if (in_array($type, ['district', 'asc', 'arpa'], true)) {
                    $this->createPrimaryRelationships($type);
                }
            }

            $this->persistReferences();
            $this->persistIssues();

            $summary = $this->buildSummary();
            $this->reportPath = $this->writeReport($summary);
            $summary['report_path'] = $this->reportPath;
            $this->completeRun($summary);
            if ($ownsSourceTransaction && $this->source->inTransaction()) {
                $this->source->commit();
            }
            return $summary;
        } catch (Throwable $e) {
            if ($ownsSourceTransaction && $this->source->inTransaction()) {
                $this->source->rollBack();
            }
            $this->failRun($e);
            throw $e;
        }
    }

    private function assertTargetReady(): void
    {
        foreach (['legacy_migration_run', 'legacy_migration_issue', 'legacy_location_reference'] as $table) {
            $stmt = $this->target->prepare(
                'SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name=?'
            );
            $stmt->execute([$table]);
            if ((int)$stmt->fetchColumn() === 0) {
                throw new RuntimeException("Target migration table {$table} is missing. Run php bin/migrate.php first.");
            }
        }

        $requiredTypes = array_column(self::SPECS, 'type');
        $rows = $this->target->query('SELECT id, system_key FROM location_type WHERE active=1')->fetchAll();
        foreach ($rows as $row) {
            $this->locationTypes[$row['system_key']] = $row['id'];
        }
        foreach ($requiredTypes as $type) {
            if (!isset($this->locationTypes[$type])) {
                throw new RuntimeException("Required target Location Type is missing or inactive: {$type}");
            }
        }

        $categories = $this->target->query("SELECT category_key, category_code, next_value FROM number_category WHERE active=1 AND category_key LIKE 'LOCATION_%'")->fetchAll();
        foreach ($categories as $category) {
            $this->simulatedNumbers[$category['category_key']] = [
                'code' => $category['category_code'],
                'next' => (int)$category['next_value'],
            ];
        }
        foreach (array_column(self::SPECS, 'category') as $category) {
            if (!isset($this->simulatedNumbers[$category])) {
                throw new RuntimeException("Required number category is missing or inactive: {$category}");
            }
        }
    }

    private function createRun(): void
    {
        $stmt = $this->target->prepare(
            'INSERT INTO legacy_migration_run
             (id, source_system, started_at, status, dry_run, selected_type, batch_size)
             VALUES (?, ?, NOW(), ?, ?, ?, ?)'
        );
        $stmt->execute([$this->runId, self::SOURCE_SYSTEM, 'RUNNING', $this->dryRun ? 1 : 0, $this->selectedType, $this->batchSize]);
    }

    private function loadSource(): void
    {
        foreach (self::SPECS as $type => $spec) {
            $sql = sprintf('SELECT * FROM `%s` ORDER BY `%s`', $spec['table'], $spec['id']);
            $rows = $this->source->query($sql)->fetchAll();
            $this->sourceRows[$type] = $rows;
            $this->sourceById[$type] = [];
            foreach ($rows as $row) {
                $this->sourceById[$type][(string)$row[$spec['id']]] = $row;
            }
            $this->stats[$type]['source'] = count($rows);
        }
    }

    private function loadTargetState(): void
    {
        $locations = $this->target->query(
            'SELECT l.id, l.dad_number, l.official_code, l.name_en, l.effective_from, lt.system_key AS location_type
             FROM location l JOIN location_type lt ON lt.id=l.location_type_id'
        )->fetchAll();
        foreach ($locations as $location) {
            $this->indexTargetLocation($location);
        }

        $refs = $this->target->prepare(
            'SELECT source_table, legacy_id, location_id FROM legacy_location_reference WHERE source_system=?'
        );
        $refs->execute([self::SOURCE_SYSTEM]);
        foreach ($refs->fetchAll() as $ref) {
            $this->referenceMap[$this->sourceKey($ref['source_table'], $ref['legacy_id'])] = $ref['location_id'];
        }

        $relationships = $this->target->query(
            'SELECT parent_location_id, child_location_id, relationship_type, effective_from,
                    approval_status, active
             FROM location_relationship'
        )->fetchAll();
        foreach ($relationships as $relationship) {
            $key = $this->relationshipKey(
                $relationship['parent_location_id'],
                $relationship['child_location_id'],
                $relationship['relationship_type'],
                $relationship['effective_from']
            );
            $this->relationshipMap[$key] = true;
            if ($relationship['approval_status'] === 'APPROVED' && (int)$relationship['active'] === 1) {
                $this->approvedRelationshipMap[$key] = true;
            }
        }
    }

    private function validateCurrentHierarchy(): void
    {
        foreach ([
            ['type' => 'district', 'parent' => 'province', 'field' => 'pro_id'],
            ['type' => 'asc', 'parent' => 'district', 'field' => 'dis_id'],
            ['type' => 'arpa', 'parent' => 'asc', 'field' => 'asc_id'],
        ] as $relationship) {
            $spec = self::SPECS[$relationship['type']];
            foreach ($this->sourceRows[$relationship['type']] as $row) {
                $parentId = (string)($row[$relationship['field']] ?? '');
                if (!isset($this->sourceById[$relationship['parent']][$parentId])) {
                    $this->issue(
                        $spec['table'],
                        (string)$row[$spec['id']],
                        'MISSING_PARENT',
                        'ERROR',
                        sprintf('%s references a missing authoritative %s.', $spec['type'], self::SPECS[$relationship['parent']]['type']),
                        $row
                    );
                }
            }
        }
    }

    private function migrateType(string $type): void
    {
        $spec = self::SPECS[$type];
        $active = $this->selectedType === null || $this->selectedType === $type;
        $chunks = array_chunk($this->sourceRows[$type], $this->batchSize);

        foreach ($chunks as $chunk) {
            if ($active && !$this->dryRun) {
                $this->target->beginTransaction();
            }
            try {
                foreach ($chunk as $offset => $row) {
                    if ($active && !$this->dryRun) {
                        $this->target->exec('SAVEPOINT legacy_location_row');
                    }
                    try {
                        $this->migrateRow($type, $spec, $row, $active);
                    } catch (Throwable $e) {
                        if ($active && !$this->dryRun) {
                            $this->target->exec('ROLLBACK TO SAVEPOINT legacy_location_row');
                        }
                        $legacyId = (string)($row[$spec['id']] ?? '');
                        $this->stats[$type]['skipped']++;
                        $this->issue($spec['table'], $legacyId, 'CONFLICT', 'ERROR', 'Row migration failed: ' . $e->getMessage(), $row);
                        $this->addLocationReport($type, $row, null, 'SKIPPED', 'CONFLICT', $e->getMessage());
                    }
                }
                if ($active && !$this->dryRun) {
                    $this->target->commit();
                }
            } catch (Throwable $e) {
                if ($active && !$this->dryRun && $this->target->inTransaction()) {
                    $this->target->rollBack();
                }
                throw $e;
            }
        }
    }

    private function migrateRow(string $type, array $spec, array $row, bool $active): void
    {
        $legacyId = (string)$row[$spec['id']];
        $parent = $this->resolveParent($spec, $row);
        $match = $this->findTargetMatch($spec, $row, $parent);

        if ($match['ambiguous']) {
            if ($active) {
                $this->stats[$type]['skipped']++;
                $this->issue($spec['table'], $legacyId, 'DUPLICATE_TARGET_MATCH', 'ERROR', 'Multiple target Locations matched; automatic merge was skipped.', $row);
                $this->addLocationReport($type, $row, null, 'SKIPPED', 'DUPLICATE_TARGET_MATCH', 'Multiple target Locations matched.');
            }
            return;
        }
        if ($match['location'] !== null) {
            $this->locationMap[$this->sourceKey($spec['table'], $legacyId)] = $match['location'];
            if ($active) {
                $this->stats[$type]['matched']++;
                $this->addLocationReport($type, $row, $match['location'], 'MATCHED_EXISTING');
            }
            return;
        }
        if (!$active) {
            return;
        }

        $nameEn = LegacyLocationRules::clean($row[$spec['name_en']] ?? null);
        if ($nameEn === null) {
            $this->stats[$type]['skipped']++;
            $this->issue($spec['table'], $legacyId, 'MISSING_NAME', 'ERROR', 'English name is required by the target Location schema; record was skipped.', $row);
            $this->addLocationReport($type, $row, null, 'SKIPPED', 'MISSING_NAME', 'English name is required by target schema.');
            return;
        }
        if ($spec['parent_type'] !== null && $parent === null) {
            $this->stats[$type]['skipped']++;
            $this->issue($spec['table'], $legacyId, 'MISSING_PARENT', 'ERROR', 'Authoritative parent could not be resolved in the target; record was skipped.', $row);
            $this->addLocationReport($type, $row, null, 'SKIPPED', 'MISSING_PARENT', 'Authoritative parent could not be resolved.');
            return;
        }

        $code = $this->officialCode($type, $row);
        if ($code === null) {
            $this->issue($spec['table'], $legacyId, 'INVALID_CODE', 'WARNING', 'No usable official code was supplied; Location was migrated with a NULL official code.', $row);
        }
        $status = LegacyLocationRules::normalizeStatus($row[$spec['status']] ?? null);
        if ($status === null) {
            $status = 'INACTIVE';
            $this->issue($spec['table'], $legacyId, 'CONFLICT', 'WARNING', 'Unrecognized legacy status was safely normalized to INACTIVE.', $row);
        }
        $effective = LegacyLocationRules::effectiveDate($spec['created'] ? ($row[$spec['created']] ?? null) : null, $this->fallbackDate);

        if ($this->dryRun) {
            $dadNumber = $this->nextSimulatedNumber($spec['category']);
            $location = [
                'id' => 'dry-run:' . $spec['table'] . ':' . $legacyId,
                'dad_number' => $dadNumber,
                'official_code' => $code,
                'name_en' => $nameEn,
                'location_type' => $spec['type'],
                'effective_from' => $effective,
            ];
            $statusLabel = 'WOULD_CREATE';
        } else {
            $dadNumber = NumberService::nextUsing($this->target, $spec['category']);
            $locationId = LegacyLocationRules::uuid();
            $stmt = $this->target->prepare(
                "INSERT INTO location
                 (id, dad_number, location_type_id, official_code, name_en, name_si, name_ta,
                  effective_from, operational_status, approval_status, created_by, created_at)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 'APPROVED', NULL, NOW())"
            );
            $stmt->execute([
                $locationId,
                $dadNumber,
                $this->locationTypes[$spec['type']],
                $code,
                $nameEn,
                LegacyLocationRules::clean($row[$spec['name_si']] ?? null),
                LegacyLocationRules::clean($row[$spec['name_ta']] ?? null),
                $effective,
                $status,
            ]);
            $location = [
                'id' => $locationId,
                'dad_number' => $dadNumber,
                'official_code' => $code,
                'name_en' => $nameEn,
                'location_type' => $spec['type'],
                'effective_from' => $effective,
            ];
            $this->indexTargetLocation($location);
            $statusLabel = 'CREATED';
        }

        $this->locationMap[$this->sourceKey($spec['table'], $legacyId)] = $location;
        $this->stats[$type]['created']++;
        $this->addLocationReport($type, $row, $location, $statusLabel);
    }

    private function resolveParent(array $spec, array $row): ?array
    {
        if ($spec['parent_type'] === null) {
            return null;
        }
        $parentSpec = self::SPECS[$spec['parent_type']];
        $legacyParentId = (string)($row[$spec['parent_field']] ?? '');
        return $this->locationMap[$this->sourceKey($parentSpec['table'], $legacyParentId)] ?? null;
    }

    private function findTargetMatch(array $spec, array $row, ?array $parent): array
    {
        $legacyId = (string)$row[$spec['id']];
        $refKey = $this->sourceKey($spec['table'], $legacyId);
        if (isset($this->referenceMap[$refKey])) {
            $location = $this->targetLocationById($this->referenceMap[$refKey]);
            if ($location !== null && $location['location_type'] === $spec['type']) {
                return ['location' => $location, 'ambiguous' => false];
            }
            if ($location !== null) {
                return ['location' => null, 'ambiguous' => true];
            }
        }

        // GN migration identity is the source-system/table/gnd_id reference.
        // Codes and names are metadata and must never merge independent GN rows.
        if ($spec['type'] === 'GN_DIVISION') {
            return ['location' => null, 'ambiguous' => false];
        }

        $code = LegacyLocationRules::normalizeCode($this->officialCode($this->typeFromSpec($spec), $row));
        if ($code !== null) {
            $candidates = array_values($this->targetByCode[$spec['type'] . '|' . $code] ?? []);
            if (count($candidates) === 1) {
                return ['location' => $candidates[0], 'ambiguous' => false];
            }
            if (count($candidates) > 1) {
                return ['location' => null, 'ambiguous' => true];
            }
        }

        $name = LegacyLocationRules::normalizeName($row[$spec['name_en']] ?? null);
        if ($name === null) {
            return ['location' => null, 'ambiguous' => false];
        }
        $candidates = array_values($this->targetByName[$spec['type'] . '|' . $name] ?? []);
        if ($spec['parent_type'] !== null) {
            if ($parent === null || str_starts_with($parent['id'], 'dry-run:')) {
                $candidates = [];
            } else {
                $candidates = array_values(array_filter($candidates, function (array $candidate) use ($parent, $spec): bool {
                    return $this->hasAnyEffectiveRelationship($parent['id'], $candidate['id'], $spec['relationship']);
                }));
            }
        }
        if (count($candidates) === 1) {
            return ['location' => $candidates[0], 'ambiguous' => false];
        }
        return ['location' => null, 'ambiguous' => count($candidates) > 1];
    }

    private function typeFromSpec(array $spec): string
    {
        foreach (self::SPECS as $type => $candidate) {
            if ($candidate['table'] === $spec['table']) {
                return $type;
            }
        }
        throw new RuntimeException('Unknown source specification.');
    }

    private function officialCode(string $type, array $row): ?string
    {
        if ($type === 'gn') {
            return LegacyLocationRules::clean($row['gnd_lcode'] ?? null)
                ?? LegacyLocationRules::clean($row['gnd_code'] ?? null)
                ?? LegacyLocationRules::clean($row['gnd_ocode'] ?? null);
        }
        return LegacyLocationRules::clean($row[self::SPECS[$type]['code']] ?? null);
    }

    private function createPrimaryRelationships(string $type): void
    {
        if ($this->selectedType !== null && $this->selectedType !== $type) {
            return;
        }
        $spec = self::SPECS[$type];
        $relations = [];
        foreach ($this->sourceRows[$type] as $row) {
            $child = $this->mapped($spec['table'], (string)$row[$spec['id']]);
            $parent = $this->resolveParent($spec, $row);
            if ($child !== null && $parent !== null) {
                $relations[] = [$parent, $child, $spec['relationship']];
            }
        }
        $this->createRelationships($relations);
    }

    private function createRelationships(array $relations): void
    {
        foreach (array_chunk($relations, $this->batchSize) as $chunk) {
            if (!$this->dryRun) {
                $this->target->beginTransaction();
            }
            try {
                foreach ($chunk as [$parent, $child, $relationshipType]) {
                    $effectiveFrom = max($parent['effective_from'] ?? $this->fallbackDate, $child['effective_from'] ?? $this->fallbackDate);
                    $key = $this->relationshipKey($parent['id'], $child['id'], $relationshipType, $effectiveFrom);
                    if (isset($this->relationshipMap[$key])) {
                        $this->relationshipsMatched++;
                        continue;
                    }
                    if (!$this->dryRun) {
                        $stmt = $this->target->prepare(
                            "INSERT IGNORE INTO location_relationship
                             (id, parent_location_id, child_location_id, relationship_type, effective_from,
                              effective_to, approval_status, active, created_at)
                             VALUES (?, ?, ?, ?, ?, NULL, 'APPROVED', 1, NOW())"
                        );
                        $stmt->execute([LegacyLocationRules::uuid(), $parent['id'], $child['id'], $relationshipType, $effectiveFrom]);
                        if ($stmt->rowCount() === 0) {
                            $this->relationshipsMatched++;
                            $this->relationshipMap[$key] = true;
                            continue;
                        }
                    }
                    $this->relationshipsCreated++;
                    $this->relationshipMap[$key] = true;
                    $this->approvedRelationshipMap[$key] = true;
                }
                if (!$this->dryRun) {
                    $this->target->commit();
                }
            } catch (Throwable $e) {
                if (!$this->dryRun && $this->target->inTransaction()) {
                    $this->target->rollBack();
                }
                throw $e;
            }
        }
    }

    private function persistReferences(): void
    {
        if ($this->dryRun) {
            return;
        }
        $rows = [];
        foreach (self::SPECS as $type => $spec) {
            if ($this->selectedType !== null && $this->selectedType !== $type) {
                continue;
            }
            foreach ($this->sourceRows[$type] as $sourceRow) {
                $legacyId = (string)$sourceRow[$spec['id']];
                $location = $this->mapped($spec['table'], $legacyId);
                if ($location !== null) {
                    $rows[] = [$location, $spec, $type, $sourceRow, $legacyId];
                }
            }
        }

        foreach (array_chunk($rows, $this->batchSize) as $chunk) {
            $this->target->beginTransaction();
            try {
                $stmt = $this->target->prepare(
                    'INSERT IGNORE INTO legacy_location_reference
                     (location_id, source_system, source_table, legacy_id, legacy_code, legacy_payload_json, created_at)
                     VALUES (?, ?, ?, ?, ?, ?, NOW())'
                );
                foreach ($chunk as [$location, $spec, $type, $sourceRow, $legacyId]) {
                    $stmt->execute([
                        $location['id'], self::SOURCE_SYSTEM, $spec['table'], $legacyId,
                        $this->officialCode($type, $sourceRow), $this->json($sourceRow),
                    ]);
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

    private function persistIssues(): void
    {
        if ($this->dryRun || $this->issues === []) {
            return;
        }
        $stmt = $this->target->prepare(
            'INSERT INTO legacy_migration_issue
             (migration_run_id, source_table, legacy_id, issue_type, severity, message,
              source_payload_json, resolved, created_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, 0, NOW())'
        );
        foreach (array_chunk($this->issues, $this->batchSize) as $chunk) {
            $this->target->beginTransaction();
            try {
                foreach ($chunk as $issue) {
                    $stmt->execute([
                        $this->runId, $issue['source_table'], $issue['legacy_id'], $issue['issue_type'],
                        $issue['severity'], $issue['message'], $this->json($issue['payload']),
                    ]);
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

    private function buildSummary(): array
    {
        $issueCounts = [];
        $warnings = 0;
        $errors = 0;
        foreach ($this->issues as $issue) {
            $issueCounts[$issue['issue_type']] = ($issueCounts[$issue['issue_type']] ?? 0) + 1;
            if ($issue['severity'] === 'ERROR') {
                $errors++;
            } else {
                $warnings++;
            }
        }
        ksort($issueCounts);

        $sourceTotal = array_sum(array_column($this->stats, 'source'));
        $matched = array_sum(array_column($this->stats, 'matched'));
        $created = array_sum(array_column($this->stats, 'created'));
        $skipped = array_sum(array_column($this->stats, 'skipped'));
        $expectedMatches = [];
        foreach (self::EXPECTED as $type => $count) {
            $expectedMatches[$type] = $this->stats[$type]['source'] === $count;
        }

        return [
            'run_id' => $this->runId,
            'mode' => $this->dryRun ? 'DRY_RUN' : 'EXECUTE',
            'selected_type' => $this->selectedType,
            'status' => ($warnings + $errors) > 0 ? 'COMPLETED_WITH_WARNINGS' : 'COMPLETED',
            'source_records' => $sourceTotal,
            'matched_existing' => $matched,
            $this->dryRun ? 'would_create' : 'created_new' => $created,
            'created_new' => $this->dryRun ? 0 : $created,
            'skipped' => $skipped,
            $this->dryRun ? 'relationships_would_create' : 'relationships_created' => $this->relationshipsCreated,
            'relationships_created' => $this->dryRun ? 0 : $this->relationshipsCreated,
            'relationships_matched_existing' => $this->relationshipsMatched,
            'warnings' => $warnings,
            'errors' => $errors,
            'issue_counts' => $issueCounts,
            'types' => $this->stats,
            'expected_source_counts_match' => $expectedMatches,
        ];
    }

    private function writeReport(array $summary): string
    {
        $directory = BASE_PATH . '/storage/reports';
        if (!is_dir($directory) && !mkdir($directory, 0770, true) && !is_dir($directory)) {
            throw new RuntimeException('Unable to create migration report directory.');
        }
        $base = $directory . '/location-migration-' . date('Ymd-His');
        $path = $base . '.csv';
        $suffix = 1;
        while (is_file($path)) {
            $path = $base . '-' . $suffix++ . '.csv';
        }
        $handle = fopen($path, 'xb');
        if ($handle === false) {
            throw new RuntimeException('Unable to create migration CSV report.');
        }
        fwrite($handle, "\xEF\xBB\xBF");
        fputcsv($handle, [
            'source_table', 'legacy_id', 'legacy_code', 'location_type', 'english_name',
            'new_location_id', 'dad_number', 'parent_dad_number', 'migration_status',
            'issue_type', 'issue_message',
        ], ',', '"', '');
        foreach ($this->reportRows as $row) {
            fputcsv($handle, $row, ',', '"', '');
        }
        fclose($handle);

        $jsonPath = preg_replace('/\.csv$/', '.json', $path);
        if (file_put_contents($jsonPath, json_encode($summary, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)) === false) {
            throw new RuntimeException('Unable to create migration JSON summary.');
        }
        return $path;
    }

    private function completeRun(array $summary): void
    {
        $migrated = [];
        foreach (self::TYPE_ORDER as $type) {
            $migrated[$type] = $this->stats[$type]['matched'] + $this->stats[$type]['created'];
        }
        $stmt = $this->target->prepare(
            'UPDATE legacy_migration_run SET completed_at=NOW(), status=?,
             province_source_count=?, province_migrated_count=?, district_source_count=?, district_migrated_count=?,
             ds_source_count=?, ds_migrated_count=?, asc_source_count=?, asc_migrated_count=?,
             arpa_source_count=?, arpa_migrated_count=?, gn_source_count=?, gn_migrated_count=?,
             relationship_count=?, warning_count=?, error_count=?, report_path=?, summary_json=? WHERE id=?'
        );
        $stmt->execute([
            $summary['status'],
            $this->stats['province']['source'], $migrated['province'],
            $this->stats['district']['source'], $migrated['district'],
            0, 0,
            $this->stats['asc']['source'], $migrated['asc'],
            $this->stats['arpa']['source'], $migrated['arpa'],
            $this->stats['gn']['source'], $migrated['gn'],
            $this->relationshipsCreated, $summary['warnings'], $summary['errors'],
            $this->reportPath, $this->json($summary), $this->runId,
        ]);
    }

    private function failRun(Throwable $e): void
    {
        try {
            if ($this->target->inTransaction()) {
                $this->target->rollBack();
            }
            $stmt = $this->target->prepare(
                "UPDATE legacy_migration_run SET completed_at=NOW(), status='FAILED', error_count=error_count+1,
                 summary_json=? WHERE id=?"
            );
            $stmt->execute([$this->json(['error' => $e->getMessage()]), $this->runId]);
        } catch (Throwable) {
            // Preserve the original migration exception.
        }
    }

    private function issue(string $sourceTable, ?string $legacyId, string $type, string $severity, string $message, array $payload): void
    {
        $this->issues[] = [
            'source_table' => $sourceTable,
            'legacy_id' => $legacyId,
            'issue_type' => $type,
            'severity' => $severity,
            'message' => $message,
            'payload' => $payload,
        ];
        $this->reportRows[] = [
            $sourceTable, $legacyId, '', '', '', '', '', '', 'ISSUE', $type, $message,
        ];
    }

    private function addLocationReport(string $type, array $row, ?array $location, string $status, string $issueType = '', string $issueMessage = ''): void
    {
        $spec = self::SPECS[$type];
        $parent = $this->resolveParent($spec, $row);
        $id = $location['id'] ?? '';
        if (str_starts_with($id, 'dry-run:')) {
            $id = '';
        }
        $this->reportRows[] = [
            $spec['table'],
            (string)$row[$spec['id']],
            $this->officialCode($type, $row) ?? '',
            $spec['type'],
            LegacyLocationRules::clean($row[$spec['name_en']] ?? null) ?? '',
            $id,
            $location['dad_number'] ?? '',
            $parent['dad_number'] ?? '',
            $status,
            $issueType,
            $issueMessage,
        ];
    }

    private function nextSimulatedNumber(string $category): string
    {
        $number = sprintf('%s-%07d', $this->simulatedNumbers[$category]['code'], $this->simulatedNumbers[$category]['next']);
        $this->simulatedNumbers[$category]['next']++;
        return $number;
    }

    private function indexTargetLocation(array $location): void
    {
        $code = LegacyLocationRules::normalizeCode($location['official_code'] ?? null);
        $name = LegacyLocationRules::normalizeName($location['name_en'] ?? null);
        if ($code !== null) {
            $this->targetByCode[$location['location_type'] . '|' . $code][$location['id']] = $location;
        }
        if ($name !== null) {
            $this->targetByName[$location['location_type'] . '|' . $name][$location['id']] = $location;
        }
    }

    private function targetLocationById(string $id): ?array
    {
        foreach ($this->targetByName as $locations) {
            if (isset($locations[$id])) {
                return $locations[$id];
            }
        }
        return null;
    }

    private function hasAnyEffectiveRelationship(string $parentId, string $childId, string $relationshipType): bool
    {
        $prefix = $parentId . '|' . $childId . '|' . $relationshipType . '|';
        foreach ($this->approvedRelationshipMap as $key => $_) {
            if (str_starts_with($key, $prefix)) {
                return true;
            }
        }
        return false;
    }

    private function mapped(string $table, string $legacyId): ?array
    {
        return $this->locationMap[$this->sourceKey($table, $legacyId)] ?? null;
    }

    private function sourceKey(string $table, string $legacyId): string
    {
        return $table . '|' . $legacyId;
    }

    private function relationshipKey(string $parent, string $child, string $type, string $effective): string
    {
        return $parent . '|' . $child . '|' . $type . '|' . $effective;
    }

    private function json(array $value): string
    {
        return json_encode($value, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR);
    }
}
