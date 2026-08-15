<?php

declare(strict_types=1);

namespace App\Services\LegacyLocation;

use App\Core\NumberService;
use App\Services\LocationHierarchyEffectiveDatePolicy;
use PDO;
use RuntimeException;
use Throwable;

final class LegacyGnByIdRepairService
{
    public const SOURCE_SYSTEM = 'AGRARIANADMIN_HR';
    public const SOURCE_TABLE = 'tbl_gnd';

    private const LOCATION_TYPE = 'GN_DIVISION';
    private const NUMBER_CATEGORY = 'LOCATION_GN_DIVISION';

    private const ASC_RELATIONSHIP = 'ASC_GN_DIVISION';
    private const ARPA_RELATIONSHIP = 'ARPA_GN_DIVISION';

    private const EXPECTED_GN_COUNT = 14016;
    private const EXPECTED_ASC_COUNT = 566;
    private const EXPECTED_ARPA_COUNT = 10396;

    public function __construct(
        private readonly PDO $source,
        private readonly PDO $target,
        private readonly bool $dryRun,
        private readonly int $batchSize = 500,
    ) {
        if ($batchSize < 1 || $batchSize > 5000) {
            throw new RuntimeException('Batch size must be between 1 and 5000.');
        }
    }

    public function run(): array
    {
        $this->assertReady();

        $sourceRows = $this->loadSourceRows();
        $sourceAscByCode = $this->loadSourceAscByCode();
        $sourceArpaAscCode = $this->loadSourceArpaAscCode();

        $targetAscRefs = $this->targetReferenceMap(
            'tbl_asc',
            'ASC'
        );

        $targetArpaRefs = $this->targetReferenceMap(
            'tbl_arpa',
            'ARPA_DIVISION'
        );

        $errors = [];

        $mapping = $this->buildExpectedMapping(
            $sourceRows,
            $sourceAscByCode,
            $sourceArpaAscCode,
            $targetAscRefs,
            $targetArpaRefs,
            $errors,
        );

        $before = $this->targetMetrics();
        $originalGnRefs = $this->currentGnReferenceMap();

        $plans = $this->buildRepairPlans(
            $sourceRows,
            $errors
        );

        $summary = [
            'mode' => $this->dryRun ? 'DRY_RUN' : 'EXECUTE',
            'baseline_date' => LocationHierarchyEffectiveDatePolicy::BASELINE_DATE,

            'source_gn_count' => count($sourceRows),
            'current_gn_count' => $before['gn_locations'],

            'legacy_reference_count' => $before['legacy_references'],
            'distinct_referenced_gn_count' => $before['distinct_locations'],

            'duplicate_target_gn_count' => $before['duplicate_targets'],
            'references_requiring_separation' => $before['references_requiring_separation'],

            'new_gn_locations_required' => count($plans),
            'retained_gn_locations_to_correct' => $before['distinct_locations'],

            'expected_final_gn_count' => count($sourceRows),
            'expected_asc_gn_relationships' => count($sourceRows),
            'expected_arpa_gn_relationships' => count($sourceRows),

            'legacy_arpa_asc_mismatch_count' => count($mapping['warnings']),
            'legacy_arpa_asc_mismatch_gnd_ids' => array_column(
                $mapping['warnings'],
                'gnd_id'
            ),

            'errors' => count($errors),
            'error_messages' => $errors,

            'new_gn_locations_created' => 0,
            'gn_locations_corrected' => 0,

            'asc_gn_relationships_created' => 0,
            'arpa_gn_relationships_created' => 0,

            'final_gn_count' => $before['gn_locations'],
            'final_distinct_referenced_gn_count' => $before['distinct_locations'],
            'remaining_duplicate_references' => $before['references_requiring_separation'],

            'final_correct_lcode_count' => 0,
            'final_asc_gn_relationship_count' => 0,
            'final_arpa_gn_relationship_count' => 0,
        ];

        if (!$this->dryRun && $errors === []) {
            $this->target->beginTransaction();

            try {
                $created = 0;

                foreach (array_chunk($plans, $this->batchSize) as $chunk) {
                    foreach ($chunk as $plan) {
                        if ($this->separateReference($plan)) {
                            $created++;
                        }
                    }
                }

                $corrected = $this->synchronizeAllGnLocations(
                    $sourceRows
                );

                $relationshipCounts = $this->reconcileBaselineRelationships(
                    $sourceRows,
                    $mapping['expected'],
                    $originalGnRefs
                );

                $final = $this->validateFinalState(
                    $sourceRows,
                    $mapping['expected']
                );

                if ($final['errors'] !== []) {
                    throw new RuntimeException(
                        'Final GN repair validation failed: '
                        . implode(' | ', $final['errors'])
                    );
                }

                $this->target->commit();

                $summary['new_gn_locations_created'] = $created;
                $summary['gn_locations_corrected'] = $corrected;

                $summary['asc_gn_relationships_created'] =
                    $relationshipCounts['asc_created'];

                $summary['arpa_gn_relationships_created'] =
                    $relationshipCounts['arpa_created'];

                $summary['final_gn_count'] =
                    $final['metrics']['gn_locations'];

                $summary['final_distinct_referenced_gn_count'] =
                    $final['metrics']['distinct_locations'];

                $summary['remaining_duplicate_references'] =
                    $final['metrics']['references_requiring_separation'];

                $summary['final_correct_lcode_count'] =
                    $final['correct_lcode_count'];

                $summary['final_asc_gn_relationship_count'] =
                    $final['asc_relationship_count'];

                $summary['final_arpa_gn_relationship_count'] =
                    $final['arpa_relationship_count'];
            } catch (Throwable $exception) {
                if ($this->target->inTransaction()) {
                    $this->target->rollBack();
                }

                throw $exception;
            }
        }

        $summary['report_path'] = $this->writeReport($summary);

        return $summary;
    }

    private function assertReady(): void
    {
        foreach ([
            'location',
            'location_type',
            'location_relationship',
            'legacy_location_reference',
            'number_category',
            'number_allocation',
        ] as $table) {
            $statement = $this->target->prepare(
                'SELECT COUNT(*)
                 FROM information_schema.tables
                 WHERE table_schema=DATABASE()
                   AND table_name=?'
            );

            $statement->execute([$table]);

            if ((int)$statement->fetchColumn() !== 1) {
                throw new RuntimeException(
                    "Required target table is missing: {$table}"
                );
            }
        }

        $statement = $this->target->prepare(
            'SELECT COUNT(*)
             FROM location_type
             WHERE system_key=?
               AND active=1'
        );

        $statement->execute([
            self::LOCATION_TYPE,
        ]);

        if ((int)$statement->fetchColumn() !== 1) {
            throw new RuntimeException(
                'Active GN_DIVISION Location Type is required.'
            );
        }

        $statement = $this->target->prepare(
            'SELECT category_code
             FROM number_category
             WHERE category_key=?
               AND active=1'
        );

        $statement->execute([
            self::NUMBER_CATEGORY,
        ]);

        if ((string)$statement->fetchColumn() !== '70008') {
            throw new RuntimeException(
                'GN enterprise number category 70008 is not configured correctly.'
            );
        }
    }

    private function loadSourceRows(): array
    {
        $rows = $this->source->query(
            'SELECT
                gnd_id,
                arpa_id,
                dis_code,
                asc_code,
                gnd_ocode,
                gnd_code,
                gnd_lcode,
                gnd_name,
                gnd_sname,
                gnd_tname,
                gnd_status
             FROM tbl_gnd
             ORDER BY gnd_id'
        )->fetchAll();

        $byId = [];

        foreach ($rows as $row) {
            $byId[(string)$row['gnd_id']] = $row;
        }

        return $byId;
    }

    private function loadSourceAscByCode(): array
    {
        $rows = $this->source->query(
            'SELECT auto_id, asc_code
             FROM tbl_asc
             ORDER BY auto_id'
        )->fetchAll();

        $map = [];

        foreach ($rows as $row) {
            $code = LegacyLocationRules::normalizeCode(
                $row['asc_code'] ?? null
            );

            if ($code === null) {
                continue;
            }

            if (isset($map[$code])) {
                throw new RuntimeException(
                    "Duplicate legacy ASC code detected: {$code}"
                );
            }

            $map[$code] = (string)$row['auto_id'];
        }

        return $map;
    }

    private function loadSourceArpaAscCode(): array
    {
        $rows = $this->source->query(
            'SELECT
                ar.auto_id,
                a.asc_code
             FROM tbl_arpa ar
             LEFT JOIN tbl_asc a
               ON a.auto_id=ar.asc_id
             ORDER BY ar.auto_id'
        )->fetchAll();

        $map = [];

        foreach ($rows as $row) {
            $map[(string)$row['auto_id']] =
                LegacyLocationRules::normalizeCode(
                    $row['asc_code'] ?? null
                );
        }

        return $map;
    }

    private function targetReferenceMap(
        string $sourceTable,
        string $expectedLocationType
    ): array {
        $statement = $this->target->prepare(
            'SELECT
                r.legacy_id,
                r.location_id,
                lt.system_key
             FROM legacy_location_reference r
             JOIN location l
               ON l.id=r.location_id
             JOIN location_type lt
               ON lt.id=l.location_type_id
             WHERE r.source_system=?
               AND r.source_table=?
             ORDER BY r.id'
        );

        $statement->execute([
            self::SOURCE_SYSTEM,
            $sourceTable,
        ]);

        $map = [];

        foreach ($statement->fetchAll() as $row) {
            if ((string)$row['system_key'] !== $expectedLocationType) {
                throw new RuntimeException(
                    "Legacy reference {$sourceTable}/{$row['legacy_id']} "
                    . "points to {$row['system_key']} "
                    . "instead of {$expectedLocationType}."
                );
            }

            $legacyId = (string)$row['legacy_id'];

            if (
                isset($map[$legacyId])
                && $map[$legacyId] !== (string)$row['location_id']
            ) {
                throw new RuntimeException(
                    "Legacy reference {$sourceTable}/{$legacyId} "
                    . 'points to multiple target Locations.'
                );
            }

            $map[$legacyId] = (string)$row['location_id'];
        }

        return $map;
    }

    private function buildExpectedMapping(
        array $sourceRows,
        array $sourceAscByCode,
        array $sourceArpaAscCode,
        array $targetAscRefs,
        array $targetArpaRefs,
        array &$errors,
    ): array {
        if (count($sourceRows) !== self::EXPECTED_GN_COUNT) {
            $errors[] =
                'Expected '
                . self::EXPECTED_GN_COUNT
                . ' legacy GN rows but found '
                . count($sourceRows)
                . '.';
        }

        if (count($sourceAscByCode) !== self::EXPECTED_ASC_COUNT) {
            $errors[] =
                'Expected '
                . self::EXPECTED_ASC_COUNT
                . ' unique legacy ASC codes but found '
                . count($sourceAscByCode)
                . '.';
        }

        if (count($targetAscRefs) !== self::EXPECTED_ASC_COUNT) {
            $errors[] =
                'Expected '
                . self::EXPECTED_ASC_COUNT
                . ' target ASC references but found '
                . count($targetAscRefs)
                . '.';
        }

        if (count($targetArpaRefs) !== self::EXPECTED_ARPA_COUNT) {
            $errors[] =
                'Expected '
                . self::EXPECTED_ARPA_COUNT
                . ' target ARPA references but found '
                . count($targetArpaRefs)
                . '.';
        }

        $expected = [];
        $warnings = [];
        $seenLocalCodes = [];

        foreach ($sourceRows as $gndId => $row) {
            $localCode = LegacyLocationRules::normalizeCode(
                $row['gnd_lcode'] ?? null
            );

            if ($localCode === null) {
                $errors[] = "GN {$gndId} has no gnd_lcode.";
            } elseif (isset($seenLocalCodes[$localCode])) {
                $errors[] =
                    "Duplicate gnd_lcode {$localCode} found for GN "
                    . $seenLocalCodes[$localCode]
                    . " and {$gndId}.";
            } else {
                $seenLocalCodes[$localCode] = $gndId;
            }

            $ascCode = LegacyLocationRules::normalizeCode(
                $row['asc_code'] ?? null
            );

            $legacyAscId =
                $ascCode === null
                    ? null
                    : ($sourceAscByCode[$ascCode] ?? null);

            $targetAscId =
                $legacyAscId === null
                    ? null
                    : ($targetAscRefs[$legacyAscId] ?? null);

            if ($targetAscId === null) {
                $errors[] =
                    "GN {$gndId} cannot resolve ASC from asc_code "
                    . (string)($row['asc_code'] ?? '')
                    . '.';
            }

            $legacyArpaId = LegacyLocationRules::clean(
                $row['arpa_id'] ?? null
            );

            $targetArpaId =
                $legacyArpaId === null
                    ? null
                    : ($targetArpaRefs[$legacyArpaId] ?? null);

            if ($targetArpaId === null) {
                $errors[] =
                    "GN {$gndId} cannot resolve ARPA from arpa_id "
                    . (string)($row['arpa_id'] ?? '')
                    . '.';
            }

            $arpaAscCode =
                $legacyArpaId === null
                    ? null
                    : ($sourceArpaAscCode[$legacyArpaId] ?? null);

            if (
                $ascCode !== null
                && $arpaAscCode !== null
                && $ascCode !== $arpaAscCode
            ) {
                $warnings[] = [
                    'gnd_id' => $gndId,
                    'gn_asc_code' => $ascCode,
                    'arpa_id' => $legacyArpaId,
                    'arpa_asc_code' => $arpaAscCode,
                ];
            }

            if (
                $targetAscId !== null
                && $targetArpaId !== null
            ) {
                $expected[$gndId] = [
                    'asc_location_id' => $targetAscId,
                    'arpa_location_id' => $targetArpaId,
                ];
            }
        }

        return [
            'expected' => $expected,
            'warnings' => $warnings,
        ];
    }
    private function targetMetrics(): array
    {
        $statement = $this->target->prepare(
            'SELECT COUNT(*)
             FROM location l
             JOIN location_type lt
               ON lt.id=l.location_type_id
             WHERE lt.system_key=?'
        );

        $statement->execute([
            self::LOCATION_TYPE,
        ]);

        $gnLocations = (int)$statement->fetchColumn();

        $statement = $this->target->prepare(
            'SELECT
                COUNT(*) AS refs,
                COUNT(DISTINCT location_id) AS locations
             FROM legacy_location_reference
             WHERE source_system=?
               AND source_table=?'
        );

        $statement->execute([
            self::SOURCE_SYSTEM,
            self::SOURCE_TABLE,
        ]);

        $references = $statement->fetch() ?: [];

        $statement = $this->target->prepare(
            'SELECT
                COUNT(*) AS duplicate_targets,
                COALESCE(SUM(reference_count - 1), 0)
                    AS extra_references
             FROM (
                SELECT
                    location_id,
                    COUNT(*) AS reference_count
                FROM legacy_location_reference
                WHERE source_system=?
                  AND source_table=?
                GROUP BY location_id
                HAVING COUNT(*) > 1
             ) duplicate_groups'
        );

        $statement->execute([
            self::SOURCE_SYSTEM,
            self::SOURCE_TABLE,
        ]);

        $duplicates = $statement->fetch() ?: [];

        return [
            'gn_locations' => $gnLocations,
            'legacy_references' =>
                (int)($references['refs'] ?? 0),
            'distinct_locations' =>
                (int)($references['locations'] ?? 0),
            'duplicate_targets' =>
                (int)($duplicates['duplicate_targets'] ?? 0),
            'references_requiring_separation' =>
                (int)($duplicates['extra_references'] ?? 0),
        ];
    }

    private function buildRepairPlans(
        array $sourceRows,
        array &$errors
    ): array {
        $statement = $this->target->prepare(
            'SELECT
                r.id AS reference_id,
                r.location_id,
                r.legacy_id,
                l.official_code,
                l.name_en,
                l.name_si,
                l.name_ta,
                l.operational_status
             FROM legacy_location_reference r
             JOIN location l
               ON l.id=r.location_id
             JOIN location_type lt
               ON lt.id=l.location_type_id
             WHERE r.source_system=?
               AND r.source_table=?
               AND lt.system_key=?
             ORDER BY r.location_id, r.id'
        );

        $statement->execute([
            self::SOURCE_SYSTEM,
            self::SOURCE_TABLE,
            self::LOCATION_TYPE,
        ]);

        $groups = [];

        foreach ($statement->fetchAll() as $reference) {
            $groups[
                (string)$reference['location_id']
            ][] = $reference;
        }

        $plans = [];

        foreach ($groups as $locationId => $references) {
            if (count($references) < 2) {
                continue;
            }

            $ownerId = $this->chooseOwnerLegacyId(
                $references,
                $sourceRows
            );

            foreach ($references as $reference) {
                $legacyId = (string)$reference['legacy_id'];

                if ($legacyId === $ownerId) {
                    continue;
                }

                if (!isset($sourceRows[$legacyId])) {
                    $errors[] =
                        "Legacy GN {$legacyId} referenced by "
                        . "{$locationId} is missing from tbl_gnd.";

                    continue;
                }

                $plans[] = [
                    'reference_id' =>
                        (int)$reference['reference_id'],

                    'old_location_id' =>
                        $locationId,

                    'legacy_id' =>
                        $legacyId,

                    'source' =>
                        $sourceRows[$legacyId],
                ];
            }
        }

        return $plans;
    }

    private function chooseOwnerLegacyId(
        array $references,
        array $sourceRows
    ): string {
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
                $sourceValue = LegacyLocationRules::clean(
                    $source[$sourceField] ?? null
                );

                $targetValue = LegacyLocationRules::clean(
                    $reference[$targetField] ?? null
                );

                if (
                    $sourceValue !== null
                    && $sourceValue === $targetValue
                ) {
                    $score++;
                }
            }

            $scored[] = [
                'legacy_id' => $legacyId,
                'score' => $score,
                'reference_id' =>
                    (int)$reference['reference_id'],
            ];
        }

        usort(
            $scored,
            static fn(
                array $left,
                array $right
            ): int =>
                ($right['score'] <=> $left['score'])
                ?: (
                    $left['reference_id']
                    <=> $right['reference_id']
                )
        );

        return (string)$scored[0]['legacy_id'];
    }

    private function separateReference(array $plan): bool
    {
        $lock = $this->target->prepare(
            'SELECT location_id
             FROM legacy_location_reference
             WHERE id=?
               AND source_system=?
               AND source_table=?
               AND legacy_id=?
             FOR UPDATE'
        );

        $lock->execute([
            $plan['reference_id'],
            self::SOURCE_SYSTEM,
            self::SOURCE_TABLE,
            $plan['legacy_id'],
        ]);

        $currentLocationId = $lock->fetchColumn();

        if (
            $currentLocationId === false
            || (string)$currentLocationId
                !== (string)$plan['old_location_id']
        ) {
            return false;
        }

        $source = $plan['source'];

        $name = LegacyLocationRules::clean(
            $source['gnd_name'] ?? null
        );

        $officialCode = LegacyLocationRules::clean(
            $source['gnd_lcode'] ?? null
        );

        if ($name === null || $officialCode === null) {
            throw new RuntimeException(
                "GN {$plan['legacy_id']} "
                . 'is missing required name or gnd_lcode.'
            );
        }

        $locationType = $this->target->prepare(
            'SELECT id
             FROM location_type
             WHERE system_key=?
               AND active=1'
        );

        $locationType->execute([
            self::LOCATION_TYPE,
        ]);

        $locationTypeId =
            (string)$locationType->fetchColumn();

        $dadNumber = NumberService::nextUsing(
            $this->target,
            self::NUMBER_CATEGORY
        );

        $locationId = LegacyLocationRules::uuid();

        $status = LegacyLocationRules::normalizeStatus(
            $source['gnd_status'] ?? null
        ) ?? 'INACTIVE';

        $insert = $this->target->prepare(
            "INSERT INTO location
             (
                id,
                dad_number,
                location_type_id,
                official_code,
                name_en,
                name_si,
                name_ta,
                effective_from,
                effective_to,
                operational_status,
                approval_status,
                created_at,
                version
             )
             VALUES
             (
                ?, ?, ?, ?, ?, ?, ?, ?,
                NULL, ?, 'APPROVED', NOW(), 0
             )"
        );

        $insert->execute([
            $locationId,
            $dadNumber,
            $locationTypeId,
            $officialCode,
            $name,
            LegacyLocationRules::clean(
                $source['gnd_sname'] ?? null
            ),
            LegacyLocationRules::clean(
                $source['gnd_tname'] ?? null
            ),
            LocationHierarchyEffectiveDatePolicy::BASELINE_DATE,
            $status,
        ]);

        $update = $this->target->prepare(
            'UPDATE legacy_location_reference
             SET
                location_id=?,
                legacy_code=?,
                legacy_payload_json=?
             WHERE id=?
               AND location_id=?'
        );

        $update->execute([
            $locationId,
            $officialCode,
            json_encode(
                $source,
                JSON_UNESCAPED_UNICODE
                | JSON_INVALID_UTF8_SUBSTITUTE
                | JSON_THROW_ON_ERROR
            ),
            $plan['reference_id'],
            $plan['old_location_id'],
        ]);

        if ($update->rowCount() !== 1) {
            throw new RuntimeException(
                "GN {$plan['legacy_id']} "
                . 'reference could not be separated safely.'
            );
        }

        return true;
    }

    private function currentGnReferenceMap(): array
    {
        $statement = $this->target->prepare(
            'SELECT
                r.legacy_id,
                r.location_id
             FROM legacy_location_reference r
             JOIN location l
               ON l.id=r.location_id
             JOIN location_type lt
               ON lt.id=l.location_type_id
             WHERE r.source_system=?
               AND r.source_table=?
               AND lt.system_key=?
             ORDER BY r.id'
        );

        $statement->execute([
            self::SOURCE_SYSTEM,
            self::SOURCE_TABLE,
            self::LOCATION_TYPE,
        ]);

        $map = [];

        foreach ($statement->fetchAll() as $row) {
            $legacyId = (string)$row['legacy_id'];

            if (isset($map[$legacyId])) {
                throw new RuntimeException(
                    "Duplicate GN legacy reference "
                    . "found for gnd_id {$legacyId}."
                );
            }

            $map[$legacyId] =
                (string)$row['location_id'];
        }

        return $map;
    }

    private function synchronizeAllGnLocations(
        array $sourceRows
    ): int {
        $references = $this->currentGnReferenceMap();

        if (count($references) !== count($sourceRows)) {
            throw new RuntimeException(
                'GN reference count does not match '
                . 'source count after separation.'
            );
        }

        $updateLocation = $this->target->prepare(
            "UPDATE location
             SET
                official_code=?,
                name_en=?,
                name_si=?,
                name_ta=?,
                effective_from=?,
                operational_status=?,
                approval_status='APPROVED',
                version=version+1
             WHERE id=?"
        );

        $updateReference = $this->target->prepare(
            'UPDATE legacy_location_reference
             SET
                legacy_code=?,
                legacy_payload_json=?
             WHERE source_system=?
               AND source_table=?
               AND legacy_id=?
               AND location_id=?'
        );

        $corrected = 0;

        foreach ($sourceRows as $gndId => $source) {
            $locationId = $references[$gndId] ?? null;

            if ($locationId === null) {
                throw new RuntimeException(
                    "GN {$gndId} has no target "
                    . 'Location after separation.'
                );
            }

            $officialCode =
                LegacyLocationRules::clean(
                    $source['gnd_lcode'] ?? null
                );

            $name =
                LegacyLocationRules::clean(
                    $source['gnd_name'] ?? null
                );

            if ($officialCode === null || $name === null) {
                throw new RuntimeException(
                    "GN {$gndId} is missing "
                    . 'required name or gnd_lcode.'
                );
            }

            $status =
                LegacyLocationRules::normalizeStatus(
                    $source['gnd_status'] ?? null
                ) ?? 'INACTIVE';

            $updateLocation->execute([
                $officialCode,
                $name,
                LegacyLocationRules::clean(
                    $source['gnd_sname'] ?? null
                ),
                LegacyLocationRules::clean(
                    $source['gnd_tname'] ?? null
                ),
                LocationHierarchyEffectiveDatePolicy::BASELINE_DATE,
                $status,
                $locationId,
            ]);

            $updateReference->execute([
                $officialCode,
                json_encode(
                    $source,
                    JSON_UNESCAPED_UNICODE
                    | JSON_INVALID_UTF8_SUBSTITUTE
                    | JSON_THROW_ON_ERROR
                ),
                self::SOURCE_SYSTEM,
                self::SOURCE_TABLE,
                $gndId,
                $locationId,
            ]);

            $corrected++;
        }

        return $corrected;
    }

    private function desiredCollapsedRelationshipGroups(
        array $sourceRows,
        array $expected,
        array $originalGnRefs
    ): array {
        $groups = [
            self::ASC_RELATIONSHIP => [],
            self::ARPA_RELATIONSHIP => [],
        ];

        foreach ($sourceRows as $gndId => $_source) {
            $originalChildId = $originalGnRefs[$gndId] ?? null;
            $parents = $expected[$gndId] ?? null;

            if ($originalChildId === null || $parents === null) {
                throw new RuntimeException(
                    "GN {$gndId} cannot build its "
                    . 'relationship reconciliation plan.'
                );
            }

            $definitions = [
                self::ASC_RELATIONSHIP => [
                    $parents['asc_location_id'],
                ],
                self::ARPA_RELATIONSHIP => [
                    $parents['arpa_location_id'],
                ],
            ];

            foreach (
                $definitions
                as $relationshipType => $parentIds
            ) {
                foreach ($parentIds as $parentId) {
                    $key =
                        $parentId
                        . '|'
                        . $originalChildId;

                    if (
                        !isset(
                            $groups[$relationshipType][$key]
                        )
                    ) {
                        $groups[$relationshipType][$key] = [
                            'parent_location_id' =>
                                $parentId,
                            'original_child_location_id' =>
                                $originalChildId,
                            'gnd_ids' => [],
                        ];
                    }

                    $groups[
                        $relationshipType
                    ][
                        $key
                    ][
                        'gnd_ids'
                    ][] = (string)$gndId;
                }
            }
        }

        foreach ($groups as &$typeGroups) {
            foreach ($typeGroups as &$group) {
                $group['gnd_ids'] = array_values(
                    array_unique($group['gnd_ids'])
                );

                sort(
                    $group['gnd_ids'],
                    SORT_NATURAL
                );
            }

            unset($group);
        }

        unset($typeGroups);

        return $groups;
    }

    private function loadCurrentGnHierarchyRelationships(): array
    {
        $statement = $this->target->prepare(
            "SELECT
                lr.id,
                lr.parent_location_id,
                lr.child_location_id,
                lr.relationship_type,
                lr.effective_from,
                lr.effective_to,
                lr.approval_status,
                lr.active,
                lr.created_at
             FROM location_relationship lr
             JOIN location child_location
               ON child_location.id=lr.child_location_id
             JOIN location_type child_type
               ON child_type.id=child_location.location_type_id
             WHERE child_type.system_key=?
               AND lr.relationship_type IN (?, ?)
             ORDER BY
                lr.relationship_type,
                lr.parent_location_id,
                lr.child_location_id,
                lr.id"
        );

        $statement->execute([
            self::LOCATION_TYPE,
            self::ASC_RELATIONSHIP,
            self::ARPA_RELATIONSHIP,
        ]);

        return $statement->fetchAll();
    }

    private function reconcileBaselineRelationships(
        array $sourceRows,
        array $expected,
        array $originalGnRefs
    ): array {
        $finalGnRefs =
            $this->currentGnReferenceMap();

        $baseline =
            LocationHierarchyEffectiveDatePolicy::BASELINE_DATE;

        $desiredGroups =
            $this->desiredCollapsedRelationshipGroups(
                $sourceRows,
                $expected,
                $originalGnRefs
            );

        $existing = [
            self::ASC_RELATIONSHIP => [],
            self::ARPA_RELATIONSHIP => [],
        ];

        foreach (
            $this->loadCurrentGnHierarchyRelationships()
            as $row
        ) {
            if (
                (string)$row['effective_from'] !== $baseline
                || $row['effective_to'] !== null
                || (string)$row['approval_status']
                    !== 'APPROVED'
                || (int)$row['active'] !== 1
            ) {
                throw new RuntimeException(
                    'GN hierarchy relationship '
                    . $row['id']
                    . ' contains non-baseline or non-current '
                    . 'history. Automatic reconciliation '
                    . 'has been refused.'
                );
            }

            $relationshipType =
                (string)$row['relationship_type'];

            $key =
                (string)$row['parent_location_id']
                . '|'
                . (string)$row['child_location_id'];

            if (isset($existing[$relationshipType][$key])) {
                throw new RuntimeException(
                    'Duplicate existing '
                    . $relationshipType
                    . ' relationship for '
                    . $key
                    . '.'
                );
            }

            if (
                !isset(
                    $desiredGroups[
                        $relationshipType
                    ][
                        $key
                    ]
                )
            ) {
                throw new RuntimeException(
                    'Existing '
                    . $relationshipType
                    . ' relationship '
                    . $row['id']
                    . ' cannot be explained by the '
                    . 'authoritative legacy GN mapping.'
                );
            }

            $existing[
                $relationshipType
            ][
                $key
            ] = $row;
        }

        /*
         * ARPA_GN_DIVISION already exists in the current baseline,
         * so every collapsed source-derived ARPA/GN pair must have
         * one reusable relationship row.
         *
         * ASC_GN_DIVISION is new and therefore has no such
         * requirement.
         */
        foreach (
            $desiredGroups[self::ARPA_RELATIONSHIP]
            as $key => $_group
        ) {
            if (
                !isset(
                    $existing[
                        self::ARPA_RELATIONSHIP
                    ][
                        $key
                    ]
                )
            ) {
                throw new RuntimeException(
                    'Missing existing baseline '
                    . self::ARPA_RELATIONSHIP
                    . ' relationship for '
                    . $key
                    . '.'
                );
            }
        }

        $updateChild = $this->target->prepare(
            'UPDATE location_relationship
             SET child_location_id=?
             WHERE id=?
               AND child_location_id=?'
        );

        $insert = $this->target->prepare(
            "INSERT INTO location_relationship
             (
                id,
                parent_location_id,
                child_location_id,
                relationship_type,
                effective_from,
                effective_to,
                approval_status,
                active,
                created_at
             )
             VALUES
             (
                ?, ?, ?, ?, ?,
                NULL, 'APPROVED', 1, NOW()
             )"
        );

        $created = [
            self::ASC_RELATIONSHIP => 0,
            self::ARPA_RELATIONSHIP => 0,
        ];

        $repointed = [
            self::ASC_RELATIONSHIP => 0,
            self::ARPA_RELATIONSHIP => 0,
        ];

        foreach (
            $desiredGroups
            as $relationshipType => $typeGroups
        ) {
            foreach ($typeGroups as $key => $group) {
                $gndIds = $group['gnd_ids'];

                if ($gndIds === []) {
                    throw new RuntimeException(
                        'Empty relationship reconciliation '
                        . "group {$relationshipType}/{$key}."
                    );
                }

                $existingRow =
                    $existing[
                        $relationshipType
                    ][
                        $key
                    ] ?? null;

                $anchorGndId = null;

                if ($existingRow !== null) {
                    foreach ($gndIds as $candidateGndId) {
                        if (
                            ($finalGnRefs[$candidateGndId] ?? null)
                            ===
                            $group[
                                'original_child_location_id'
                            ]
                        ) {
                            $anchorGndId =
                                $candidateGndId;
                            break;
                        }
                    }

                    if ($anchorGndId === null) {
                        $anchorGndId = $gndIds[0];
                    }

                    $finalChildId =
                        $finalGnRefs[$anchorGndId]
                        ?? null;

                    if ($finalChildId === null) {
                        throw new RuntimeException(
                            "GN {$anchorGndId} has no final "
                            . 'Location during hierarchy '
                            . 'reconciliation.'
                        );
                    }

                    if (
                        $finalChildId
                        !==
                        (string)$existingRow[
                            'child_location_id'
                        ]
                    ) {
                        $updateChild->execute([
                            $finalChildId,
                            $existingRow['id'],
                            $existingRow[
                                'child_location_id'
                            ],
                        ]);

                        if ($updateChild->rowCount() !== 1) {
                            throw new RuntimeException(
                                'Existing relationship '
                                . $existingRow['id']
                                . ' could not be repointed '
                                . 'safely.'
                            );
                        }

                        $repointed[
                            $relationshipType
                        ]++;
                    }
                }

                foreach ($gndIds as $gndId) {
                    if (
                        $existingRow !== null
                        && $gndId === $anchorGndId
                    ) {
                        continue;
                    }

                    $finalChildId =
                        $finalGnRefs[$gndId] ?? null;

                    if ($finalChildId === null) {
                        throw new RuntimeException(
                            "GN {$gndId} has no final "
                            . 'Location during hierarchy '
                            . 'reconciliation.'
                        );
                    }

                    $insert->execute([
                        LegacyLocationRules::uuid(),
                        $group['parent_location_id'],
                        $finalChildId,
                        $relationshipType,
                        $baseline,
                    ]);

                    $created[
                        $relationshipType
                    ]++;
                }
            }
        }

        return [
            'asc_created' =>
                $created[self::ASC_RELATIONSHIP],

            'arpa_created' =>
                $created[self::ARPA_RELATIONSHIP],

            'asc_repointed' =>
                $repointed[self::ASC_RELATIONSHIP],

            'arpa_repointed' =>
                $repointed[self::ARPA_RELATIONSHIP],
        ];
    }
    private function validateFinalState(
        array $sourceRows,
        array $expected
    ): array {
        $errors = [];
        $metrics = $this->targetMetrics();

        $expectedCount = count($sourceRows);

        if ($metrics['gn_locations'] !== $expectedCount) {
            $errors[] =
                "Expected {$expectedCount} GN Locations, "
                . "found {$metrics['gn_locations']}.";
        }

        if ($metrics['legacy_references'] !== $expectedCount) {
            $errors[] =
                "Expected {$expectedCount} GN legacy references, "
                . "found {$metrics['legacy_references']}.";
        }

        if ($metrics['distinct_locations'] !== $expectedCount) {
            $errors[] =
                "Expected {$expectedCount} distinct referenced "
                . "GN Locations, found "
                . "{$metrics['distinct_locations']}.";
        }

        if (
            $metrics['references_requiring_separation']
            !== 0
        ) {
            $errors[] =
                'Expected no duplicate GN target references, '
                . 'found '
                . $metrics['references_requiring_separation']
                . ' extras.';
        }

        $gnRefs = $this->currentGnReferenceMap();

        $correctLcode = 0;

        $statement = $this->target->prepare(
            'SELECT official_code
             FROM location
             WHERE id=?'
        );

        foreach ($sourceRows as $gndId => $source) {
            $locationId = $gnRefs[$gndId] ?? null;

            if ($locationId === null) {
                $errors[] =
                    "GN {$gndId} has no final target Location.";
                continue;
            }

            $statement->execute([$locationId]);

            $actualCode =
                LegacyLocationRules::normalizeCode(
                    $statement->fetchColumn()
                );

            $expectedCode =
                LegacyLocationRules::normalizeCode(
                    $source['gnd_lcode'] ?? null
                );

            if ($actualCode === $expectedCode) {
                $correctLcode++;
            } elseif (count($errors) < 25) {
                $errors[] =
                    "GN {$gndId} final official_code "
                    . 'does not match gnd_lcode.';
            }
        }

        $ascMap = $this->baselineRelationshipMap(
            self::ASC_RELATIONSHIP
        );

        $arpaMap = $this->baselineRelationshipMap(
            self::ARPA_RELATIONSHIP
        );

        $ascCount = 0;
        $arpaCount = 0;

        foreach ($sourceRows as $gndId => $_source) {
            $childId = $gnRefs[$gndId] ?? null;
            $parents = $expected[$gndId] ?? null;

            if ($childId === null || $parents === null) {
                continue;
            }

            $ascParents = $ascMap[$childId] ?? [];
            $ascUnique = array_values(
                array_unique($ascParents)
            );

            if (
                count($ascParents) === 1
                && $ascUnique === [
                    $parents['asc_location_id']
                ]
            ) {
                $ascCount++;
            } elseif (count($errors) < 25) {
                $errors[] =
                    "GN {$gndId} does not have exactly "
                    . 'the expected ASC_GN_DIVISION '
                    . 'baseline relationship.';
            }

            $arpaParents = $arpaMap[$childId] ?? [];
            $arpaUnique = array_values(
                array_unique($arpaParents)
            );

            if (
                count($arpaParents) === 1
                && $arpaUnique === [
                    $parents['arpa_location_id']
                ]
            ) {
                $arpaCount++;
            } elseif (count($errors) < 25) {
                $errors[] =
                    "GN {$gndId} does not have exactly "
                    . 'the expected ARPA_GN_DIVISION '
                    . 'baseline relationship.';
            }
        }

        if ($correctLcode !== $expectedCount) {
            $errors[] =
                "Expected {$expectedCount} correct GN "
                . "official codes, found {$correctLcode}.";
        }

        if ($ascCount !== $expectedCount) {
            $errors[] =
                "Expected {$expectedCount} correct "
                . "ASC_GN_DIVISION mappings, "
                . "found {$ascCount}.";
        }

        if ($arpaCount !== $expectedCount) {
            $errors[] =
                "Expected {$expectedCount} correct "
                . "ARPA_GN_DIVISION mappings, "
                . "found {$arpaCount}.";
        }

        return [
            'errors' => $errors,
            'metrics' => $metrics,
            'correct_lcode_count' => $correctLcode,
            'asc_relationship_count' => $ascCount,
            'arpa_relationship_count' => $arpaCount,
        ];
    }
    private function baselineRelationshipMap(
        string $relationshipType
    ): array {
        $statement = $this->target->prepare(
            "SELECT
                lr.child_location_id,
                lr.parent_location_id
             FROM location_relationship lr
             JOIN legacy_location_reference r
               ON r.location_id=lr.child_location_id
             WHERE r.source_system=?
               AND r.source_table=?
               AND lr.relationship_type=?
               AND lr.effective_from=?
               AND lr.active=1
               AND lr.approval_status='APPROVED'
             ORDER BY
                lr.child_location_id,
                lr.parent_location_id"
        );

        $statement->execute([
            self::SOURCE_SYSTEM,
            self::SOURCE_TABLE,
            $relationshipType,
            LocationHierarchyEffectiveDatePolicy::BASELINE_DATE,
        ]);

        $map = [];

        foreach ($statement->fetchAll() as $row) {
            $map[
                (string)$row['child_location_id']
            ][] =
                (string)$row['parent_location_id'];
        }

        return $map;
    }

    private function writeReport(array $summary): string
    {
        $directory =
            BASE_PATH . '/storage/reports';

        if (
            !is_dir($directory)
            && !mkdir($directory, 0770, true)
            && !is_dir($directory)
        ) {
            throw new RuntimeException(
                'Unable to create GN by-ID repair '
                . 'report directory.'
            );
        }

        $base =
            $directory
            . '/gn-by-id-repair-'
            . date('Ymd-His');

        $path = $base . '.csv';

        for (
            $suffix = 1;
            is_file($path);
            $suffix++
        ) {
            $path =
                $base
                . '-'
                . $suffix
                . '.csv';
        }

        $handle = fopen($path, 'xb');

        if ($handle === false) {
            throw new RuntimeException(
                'Unable to create GN by-ID repair report.'
            );
        }

        fwrite(
            $handle,
            "\xEF\xBB\xBF"
        );

        fputcsv(
            $handle,
            ['metric', 'value'],
            ',',
            '"',
            ''
        );

        foreach ($summary as $key => $value) {
            if ($key === 'report_path') {
                continue;
            }

            if (is_array($value)) {
                $value = json_encode(
                    $value,
                    JSON_UNESCAPED_UNICODE
                    | JSON_INVALID_UTF8_SUBSTITUTE
                );
            }

            fputcsv(
                $handle,
                [$key, $value],
                ',',
                '"',
                ''
            );
        }

        fclose($handle);

        return $path;
    }
}