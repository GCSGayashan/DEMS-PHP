<?php
declare(strict_types=1);

namespace App\Services\LegacyOfficer;

use App\Core\NicNormalizer;
use App\Core\NumberService;
use DateTimeImmutable;
use PDO;
use RuntimeException;
use Throwable;

final class LegacyArpaOfficerMigrationService
{
    public const SOURCE_SYSTEM = 'AGRARIANADMIN_HR';
    public const SOURCE_TABLE = 'tbl_officer';
    public const SELECTOR_TABLE = 'tbl_officer_apoint_2026';
    public const SELECTOR_LEVEL = 'ARPA Division';
    public const NUMBER_CATEGORY = 'OFFICER';

    private PDO $source;
    private PDO $target;
    private bool $dryRun;
    private int $batchSize;
    private ?string $fallbackDate;
    private string $runId;
    private array $rows = [];
    private array $plans = [];
    private array $issues = [];
    private array $reportRows = [];
    private array $references = [];
    private array $targetByNormalizedNic = [];
    private array $targetByMatchKey = [];
    private array $duplicateNics = [];
    private array $duplicateEmails = [];
    private array $missingMasterIds = [];
    private array $statusIds = [];
    private string $arpaDesignationId;
    private array $classIds = [];
    private array $targetEmails = [];
    private array $simulatedNumber = [];
    private array $protectedBefore = [];
    private array $stats;
    private ?string $reportPath = null;
    private ?array $selectedLegacyIds;
    private string $selectionName;
    private array $sourceAliases;

    public function __construct(PDO $source, PDO $target, bool $dryRun, int $batchSize = 500, ?string $fallbackDate = null, ?array $selectedLegacyIds = null, string $selectionName = 'arpa-officer', array $sourceAliases = [])
    {
        if ($batchSize < 1 || $batchSize > 10000) {
            throw new RuntimeException('Batch size must be between 1 and 10000.');
        }
        $parsed = $fallbackDate !== null ? DateTimeImmutable::createFromFormat('!Y-m-d', $fallbackDate) : null;
        if ($fallbackDate !== null && ($parsed === false || $parsed->format('Y-m-d') !== $fallbackDate)) {
            throw new RuntimeException('LEGACY_OFFICER_EFFECTIVE_FROM must be a valid YYYY-MM-DD value.');
        }
        $this->source = $source;
        $this->target = $target;
        $this->dryRun = $dryRun;
        $this->batchSize = $batchSize;
        $this->fallbackDate = $fallbackDate;
        $this->runId = self::uuid();
        $this->selectedLegacyIds = $selectedLegacyIds === null ? null : array_values(array_unique(array_map('strval', $selectedLegacyIds)));
        $this->selectionName = preg_replace('/[^a-z0-9-]+/','-',strtolower($selectionName)) ?: 'arpa-officer';
        $this->sourceAliases = array_map('strval',$sourceAliases);
        $this->stats = [
            'source_appointment_rows' => 0,
            'distinct_source_officers' => 0,
            'matched_source_officer_masters' => 0,
            'existing_legacy_references' => 0,
            'already_migrated' => 0,
            'would_create' => 0,
            'would_update' => 0,
            'created' => 0,
            'updated' => 0,
            'skipped' => 0,
            'missing_nic' => 0,
            'duplicate_nic' => 0,
            'invalid_nic' => 0,
            'safely_cleaned_nic' => 0,
            'invalid_nic_fields_nulled' => 0,
            'missing_nic_fields_nulled' => 0,
            'duplicate_nic_fields_nulled' => 0,
            'missing_dob' => 0,
            'invalid_dob' => 0,
            'invalid_gender' => 0,
            'invalid_gender_values' => [],
            'invalid_gender_fields_nulled' => 0,
            'missing_name' => 0,
            'missing_name_with_initials' => 0,
            'missing_full_name' => 0,
            'missing_address' => 0,
            'missing_phone' => 0,
            'missing_email' => 0,
            'missing_email_fields_nulled' => 0,
            'invalid_email' => 0,
            'invalid_email_fields_nulled' => 0,
            'duplicate_email' => 0,
            'shared_email_fields_nulled' => 0,
            'missing_address_fields_nulled' => 0,
            'missing_initial_appointment_date' => 0,
            'invalid_initial_appointment_date' => 0,
            'initial_appointment_date_fields_nulled' => 0,
            'legacy_active' => 0,
            'legacy_inactive' => 0,
            'class_i' => 0,
            'class_ii' => 0,
            'class_iii' => 0,
            'class_null_select' => 0,
            'unknown_grades' => 0,
            'service_permanent' => 0,
            'service_not_permanent' => 0,
            'service_permanency_unknown' => 0,
        ];
    }

    public function run(): array
    {
        $this->assertTargetReady();
        $this->protectedBefore = $this->protectedCounts();
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
            $this->analyse();

            if (!$this->dryRun) {
                $this->createRun();
                $this->executePlans();
                $this->persistIssues();
            }

            $protectedAfter = $this->protectedCounts();
            if ($protectedAfter !== $this->protectedBefore) {
                throw new RuntimeException('Officer Master migration changed an out-of-scope table.');
            }

            $summary = $this->buildSummary($protectedAfter);
            $this->reportPath = $this->writeReports($summary);
            $summary['report_path'] = $this->reportPath;
            if (!$this->dryRun) {
                $this->completeRun($summary);
            }
            if ($ownsSourceTransaction && $this->source->inTransaction()) {
                $this->source->commit();
            }
            return $summary;
        } catch (Throwable $e) {
            if ($ownsSourceTransaction && $this->source->inTransaction()) {
                $this->source->rollBack();
            }
            if (!$this->dryRun) {
                $this->failRun($e);
            }
            throw $e;
        }
    }

    private function assertTargetReady(): void
    {
        foreach (['officer', 'officer_status', 'designation', 'officer_class', 'number_category', 'number_allocation', 'legacy_officer_migration_run', 'legacy_officer_reference', 'legacy_officer_migration_issue'] as $table) {
            if (!$this->tableExists($this->target, $table)) {
                throw new RuntimeException("Target table {$table} is missing. Run php bin/migrate.php first.");
            }
        }
        $statuses = $this->target->query("SELECT id,system_key FROM officer_status WHERE active=1 AND system_key IN ('ACTIVE','INACTIVE')")->fetchAll();
        foreach ($statuses as $status) {
            $this->statusIds[(string)$status['system_key']] = (string)$status['id'];
        }
        foreach (['ACTIVE', 'INACTIVE'] as $key) {
            if (!isset($this->statusIds[$key])) {
                throw new RuntimeException("Required officer status is missing or inactive: {$key}");
            }
        }
        $designation = $this->target->query("SELECT id FROM designation WHERE system_key='ARPA_OFFICER' AND active=1 AND approval_status='APPROVED'")->fetchAll(PDO::FETCH_COLUMN);
        if (count($designation) !== 1) {
            throw new RuntimeException('Exactly one approved active ARPA_OFFICER designation is required.');
        }
        $this->arpaDesignationId = (string)$designation[0];
        $classes = $this->target->query("SELECT id,system_key FROM officer_class WHERE system_key IN ('CLASS_I','CLASS_II','CLASS_III') AND active=1 AND approval_status='APPROVED'")->fetchAll();
        foreach ($classes as $class) {
            $this->classIds[(string)$class['system_key']] = (string)$class['id'];
        }
        foreach (['CLASS_I', 'CLASS_II', 'CLASS_III'] as $key) {
            if (!isset($this->classIds[$key])) {
                throw new RuntimeException("Required approved Officer Class is missing: {$key}");
            }
        }
        $stmt = $this->target->prepare('SELECT category_code,next_value FROM number_category WHERE category_key=? AND active=1');
        $stmt->execute([self::NUMBER_CATEGORY]);
        $category = $stmt->fetch();
        if (!$category || (string)$category['category_code'] !== '70045') {
            throw new RuntimeException('The active OFFICER enterprise number category must use category code 70045.');
        }
        $this->simulatedNumber = ['code' => (string)$category['category_code'], 'next' => (int)$category['next_value']];
    }

    private function loadSource(): void
    {
        foreach ([self::SOURCE_TABLE, self::SELECTOR_TABLE, 'tbl_designation'] as $table) {
            if (!$this->tableExists($this->source, $table)) {
                throw new RuntimeException("Legacy source table is missing: {$table}");
            }
        }
        if($this->selectedLegacyIds!==null){
            $this->stats['source_appointment_rows']=count($this->selectedLegacyIds);
            $this->stats['distinct_source_officers']=count($this->selectedLegacyIds);
            if($this->selectedLegacyIds===[]){$this->rows=[];$this->missingMasterIds=[];return;}
            $marks=implode(',',array_fill(0,count($this->selectedLegacyIds),'?'));
            $stmt=$this->source->prepare("SELECT o.*,d.designation_name AS legacy_designation_name FROM tbl_officer o LEFT JOIN tbl_designation d ON d.designation_id=o.designation_id WHERE o.officer_id IN ({$marks}) ORDER BY o.officer_id");
            $stmt->execute($this->selectedLegacyIds);$this->rows=$stmt->fetchAll();$this->stats['matched_source_officer_masters']=count($this->rows);
            $found=array_fill_keys(array_map(fn($r)=>(string)$r['officer_id'],$this->rows),true);$this->missingMasterIds=array_values(array_filter($this->selectedLegacyIds,fn($id)=>!isset($found[$id])));
            $this->prepareDuplicateFields();return;
        }
        $count = $this->source->prepare('SELECT COUNT(*) FROM tbl_officer_apoint_2026 WHERE officer_level=?');
        $count->execute([self::SELECTOR_LEVEL]);
        $this->stats['source_appointment_rows'] = (int)$count->fetchColumn();

        $distinct = $this->source->prepare('SELECT COUNT(DISTINCT officer_id) FROM tbl_officer_apoint_2026 WHERE officer_level=?');
        $distinct->execute([self::SELECTOR_LEVEL]);
        $this->stats['distinct_source_officers'] = (int)$distinct->fetchColumn();

        $stmt = $this->source->prepare(
            'SELECT o.*, d.designation_name AS legacy_designation_name
             FROM tbl_officer o
             JOIN (SELECT DISTINCT officer_id FROM tbl_officer_apoint_2026 WHERE officer_level=?) selected
               ON selected.officer_id=o.officer_id
             LEFT JOIN tbl_designation d ON d.designation_id=o.designation_id
             ORDER BY o.officer_id'
        );
        $stmt->execute([self::SELECTOR_LEVEL]);
        $this->rows = $stmt->fetchAll();
        $this->stats['matched_source_officer_masters'] = count($this->rows);
        $missing = $this->source->prepare(
            'SELECT DISTINCT a.officer_id FROM tbl_officer_apoint_2026 a
             LEFT JOIN tbl_officer o ON o.officer_id=a.officer_id
             WHERE a.officer_level=? AND o.officer_id IS NULL ORDER BY a.officer_id'
        );
        $missing->execute([self::SELECTOR_LEVEL]);
        $this->missingMasterIds = array_map('strval', $missing->fetchAll(PDO::FETCH_COLUMN));

        $this->prepareDuplicateFields();
    }

    private function prepareDuplicateFields(): void
    {
        $nicCounts = [];
        $emailCounts = [];
        foreach ($this->rows as $row) {
            $nic = NicNormalizer::normalize($row['nic'] ?? null);
            if (NicNormalizer::isValid($nic)) {
                $nicCounts[$nic] = ($nicCounts[$nic] ?? 0) + 1;
            }
            $email = $this->normalizeEmail($row['email_address'] ?? null);
            if ($email !== null) {
                $emailCounts[$email] = ($emailCounts[$email] ?? 0) + 1;
            }
        }
        $this->duplicateNics = array_filter($nicCounts, static fn(int $count): bool => $count > 1);
        $this->duplicateEmails = array_filter($emailCounts, static fn(int $count): bool => $count > 1);
        $this->stats['duplicate_nic'] = count($this->duplicateNics);
    }

    private function loadTargetState(): void
    {
        $refs = $this->target->prepare('SELECT legacy_officer_id,officer_id FROM legacy_officer_reference WHERE source_system=? AND source_table=?');
        $refs->execute([self::SOURCE_SYSTEM, self::SOURCE_TABLE]);
        foreach ($refs->fetchAll() as $ref) {
            $this->references[(string)$ref['legacy_officer_id']] = (string)$ref['officer_id'];
        }
        $this->stats['existing_legacy_references'] = count($this->references);

        $officers = $this->target->query('SELECT id,dad_number,nic_normalized,nic_match_key,personal_email FROM officer')->fetchAll();
        foreach ($officers as $officer) {
            $normalized = trim((string)($officer['nic_normalized'] ?? ''));
            if ($normalized !== '') {
                $this->targetByNormalizedNic[$normalized][] = $officer;
            }
            $matchKey = trim((string)($officer['nic_match_key'] ?? ''));
            if ($matchKey !== '') {
                $this->targetByMatchKey[$matchKey][] = $officer;
            }
            $email = $this->normalizeEmail($officer['personal_email'] ?? null);
            if ($email !== null) {
                $this->targetEmails[$email] = true;
            }
        }
    }

    private function analyse(): void
    {
        foreach ($this->missingMasterIds as $legacyId) {
            $this->stats['skipped']++;
            $issue = $this->issue($legacyId, 'MISSING_SOURCE_MASTER', 'ERROR', 'Selected appointment officer_id has no tbl_officer master row.', ['officer_id' => $legacyId]);
            $this->issues[] = $issue;
            $this->reportRows[] = [
                'legacy_officer_id' => $legacyId,
                'legacy_nic' => null,
                'name_with_initials' => null,
                'full_name_en' => null,
                'legacy_designation_id' => null,
                'legacy_designation_name' => null,
                'legacy_status' => null,
                'officer_id' => null,
                'dad_number' => null,
                'migration_status' => 'SKIP',
                'issue_types' => 'MISSING_SOURCE_MASTER',
                'issue_messages' => $issue['message'],
            ];
        }
        foreach ($this->rows as $row) {
            $legacyId = (string)$row['officer_id'];
            $rowIssues = [];
            $nicRaw = (string)($row['nic'] ?? '');
            $nicCandidate = NicNormalizer::normalize($nicRaw);
            $nic = NicNormalizer::isValid($nicCandidate) ? $nicCandidate : null;
            $matchKey = NicNormalizer::matchKey($nic);
            $nameInitials = $this->nullableText($row['name_with_initial'] ?? null);
            $fullName = $this->nullableText($row['full_name'] ?? null);
            $dob = $this->validDate($row['birth_day'] ?? null);
            $gender = $this->normalizeGender($row['gender'] ?? null);
            $address = $this->nullableText($row['residential_address'] ?? null);
            $phone = $this->nullableText($row['tp_no'] ?? null);
            $alternativeMobile = $this->nullableText($row['whatsapp_no'] ?? null);
            $sourceEmail = $this->normalizeEmail($row['email_address'] ?? null);
            $personalEmail = $sourceEmail;
            $initialDate = $this->validDate($row['first_appoint_date'] ?? null);
            $statusKey = match ((string)($row['officer_status'] ?? '')) {
                '1' => 'ACTIVE',
                '0' => 'INACTIVE',
                default => null,
            };
            $classKey = LegacyOfficerGradeMapper::classKey($row['grade'] ?? null);
            $classId = $classKey !== null ? $this->classIds[$classKey] : null;
            if ($classKey !== null) {
                $this->stats[strtolower($classKey)]++;
            } elseif (LegacyOfficerGradeMapper::isSelect($row['grade'] ?? null)) {
                $this->stats['class_null_select']++;
            } else {
                $this->stats['unknown_grades']++;
                $rowIssues[] = $this->issue($legacyId, 'UNKNOWN_GRADE', 'WARNING', 'Legacy grade is blank or unsupported; class_id remains NULL.', $row);
            }
            $servicePermanency=match(strtolower(trim((string)($row['permanent_or_not']??'')))){'yes'=>'PERMANENT_IN_SERVICE','no'=>'NOT_PERMANENT_IN_SERVICE',default=>null};
            if($servicePermanency==='PERMANENT_IN_SERVICE')$this->stats['service_permanent']++;
            elseif($servicePermanency==='NOT_PERMANENT_IN_SERVICE')$this->stats['service_not_permanent']++;
            else{$this->stats['service_permanency_unknown']++;$rowIssues[]=$this->issue($legacyId,'UNKNOWN_SERVICE_PERMANENCY','WARNING','Legacy permanent_or_not is unavailable/unsupported; Officer service permanency remains NULL.',$row);}

            if ($nicCandidate === null) {
                $this->stats['missing_nic']++;
                $this->stats['missing_nic_fields_nulled']++;
                $rowIssues[] = $this->issue($legacyId, 'MISSING_NIC', 'WARNING', 'Legacy Officer has no NIC; NIC fields remain NULL.', $row);
            } elseif (!NicNormalizer::isValid($nicCandidate)) {
                $this->stats['invalid_nic']++;
                $this->stats['invalid_nic_fields_nulled']++;
                $rowIssues[] = $this->issue($legacyId, 'INVALID_NIC', 'WARNING', 'NIC remains invalid after safe cleanup; target NIC fields remain NULL and the exact raw value is retained.', $row);
            } elseif (isset($this->duplicateNics[$nicCandidate]) && isset($this->sourceAliases[$legacyId])) {
                $nic = null;
                $matchKey = null;
                $rowIssues[] = $this->issue($legacyId, 'VERIFIED_SOURCE_IDENTITY_ALIAS', 'WARNING', 'This legacy ID is a verified alias of legacy Officer '.$this->sourceAliases[$legacyId].'; both source identities will reference one target Officer.', $row);
            } elseif (isset($this->duplicateNics[$nicCandidate]) && !in_array($legacyId,$this->sourceAliases,true)) {
                $this->stats['duplicate_nic_fields_nulled']++;
                $nic = null;
                $matchKey = null;
                $rowIssues[] = $this->issue($legacyId, 'DUPLICATE_SOURCE_NIC', 'WARNING', 'Canonical NIC is shared by multiple selected Officers; target NIC fields remain NULL for every conflict.', $row);
            } elseif ($nicCandidate !== strtoupper(trim($nicRaw))) {
                $this->stats['safely_cleaned_nic']++;
            }
            $sourceDob = trim((string)($row['birth_day'] ?? ''));
            if ($sourceDob === '') {
                $this->stats['missing_dob']++;
                $rowIssues[] = $this->issue($legacyId, 'MISSING_DOB', 'WARNING', 'Legacy Officer has no date of birth; date_of_birth remains NULL.', $row);
            } elseif ($dob === null) {
                $this->stats['invalid_dob']++;
                $rowIssues[] = $this->issue($legacyId, 'INVALID_DOB', 'WARNING', 'Legacy date of birth is invalid/zero; date_of_birth remains NULL.', $row);
            }
            if ($gender === null) {
                $sourceGender = trim((string)($row['gender'] ?? ''));
                $this->stats['invalid_gender']++;
                $this->stats['invalid_gender_fields_nulled']++;
                $this->stats['invalid_gender_values'][$sourceGender === '' ? '(empty)' : $sourceGender] = ($this->stats['invalid_gender_values'][$sourceGender === '' ? '(empty)' : $sourceGender] ?? 0) + 1;
                $rowIssues[] = $this->issue($legacyId, 'INVALID_GENDER', 'WARNING', 'Unrecognized legacy gender retained as NULL: ' . ($sourceGender === '' ? '(empty)' : $sourceGender), $row);
            }
            if ($nameInitials === null) {
                $this->stats['missing_name_with_initials']++;
                $rowIssues[] = $this->issue($legacyId, 'MISSING_NAME', 'WARNING', 'Legacy name_with_initial is missing; name_with_initials remains NULL.', $row);
            }
            if ($fullName === null) {
                $this->stats['missing_full_name']++;
                $rowIssues[] = $this->issue($legacyId, 'MISSING_NAME', 'WARNING', 'Legacy full_name is missing; full_name_en remains NULL.', $row);
            }
            if ($nameInitials === null || $fullName === null) {
                $this->stats['missing_name']++;
            }
            if ($address === null) {
                $this->stats['missing_address']++;
                $this->stats['missing_address_fields_nulled']++;
                $rowIssues[] = $this->issue($legacyId, 'MISSING_ADDRESS', 'WARNING', 'Legacy residential address is missing; permanent_address remains NULL.', $row);
            }
            if ($phone === null) {
                $this->stats['missing_phone']++;
                $rowIssues[] = $this->issue($legacyId, 'MISSING_PHONE', 'WARNING', 'Legacy primary phone is missing; primary_mobile remains NULL.', $row);
            }
            if ($sourceEmail === null) {
                if ($this->nullableText($row['email_address'] ?? null) === null) {
                    $this->stats['missing_email']++;
                    $this->stats['missing_email_fields_nulled']++;
                    $rowIssues[] = $this->issue($legacyId, 'MISSING_EMAIL', 'WARNING', 'Legacy email is missing; personal_email remains NULL.', $row);
                } else {
                    $this->stats['invalid_email']++;
                    $this->stats['invalid_email_fields_nulled']++;
                    $rowIssues[] = $this->issue($legacyId, 'INVALID_EMAIL', 'WARNING', 'Legacy email is invalid; personal_email remains NULL and the source value is retained in traceability.', $row);
                }
            } elseif (isset($this->duplicateEmails[$sourceEmail])) {
                $this->stats['duplicate_email']++;
                $this->stats['shared_email_fields_nulled']++;
                $personalEmail = null;
                $rowIssues[] = $this->issue($legacyId, 'DUPLICATE_SOURCE_EMAIL', 'WARNING', 'Legacy email is shared by multiple selected Officers; personal_email remains NULL and the source value is retained in traceability.', $row);
            } elseif (isset($this->targetEmails[$sourceEmail])) {
                $personalEmail = null;
                $rowIssues[] = $this->issue($legacyId, 'DUPLICATE_TARGET_EMAIL', 'WARNING', 'Legacy email already exists on a target Officer; personal_email remains NULL and the source value is retained in traceability.', $row);
            }
            $sourceInitial = trim((string)($row['first_appoint_date'] ?? ''));
            if ($sourceInitial === '') {
                $this->stats['missing_initial_appointment_date']++;
                $this->stats['initial_appointment_date_fields_nulled']++;
                $rowIssues[] = $this->issue($legacyId, 'MISSING_INITIAL_APPOINTMENT_DATE', 'WARNING', 'Legacy first appointment date is missing; initial_appointment_date remains NULL.', $row);
            } elseif ($initialDate === null) {
                $this->stats['invalid_initial_appointment_date']++;
                $this->stats['initial_appointment_date_fields_nulled']++;
                $rowIssues[] = $this->issue($legacyId, 'INVALID_INITIAL_APPOINTMENT_DATE', 'WARNING', 'Legacy first appointment date is invalid/zero; initial_appointment_date remains NULL.', $row);
            }
            if ($statusKey === 'ACTIVE') {
                $this->stats['legacy_active']++;
            } elseif ($statusKey === 'INACTIVE') {
                $this->stats['legacy_inactive']++;
            } else {
                $rowIssues[] = $this->issue($legacyId, 'INVALID_STATUS', 'ERROR', 'Legacy officer_status is neither 1 nor 0.', $row);
            }

            $blocking = array_filter($rowIssues, static fn(array $issue): bool => $issue['severity'] === 'ERROR');
            $action = 'create';
            $targetOfficer = null;
            if (isset($this->references[$legacyId])) {
                $action = 'already_migrated';
                $this->stats['already_migrated']++;
            } elseif ($blocking !== []) {
                $action = 'skip';
                $this->stats['skipped']++;
            } elseif (isset($this->sourceAliases[$legacyId])) {
                $action='attach_source_alias';$this->stats['would_update']++;
                $primaryLegacyId=$this->sourceAliases[$legacyId];
                if(isset($this->references[$primaryLegacyId])){$targetOfficer=['id'=>$this->references[$primaryLegacyId],'dad_number'=>$this->targetOfficerDad($this->references[$primaryLegacyId])];}
            } else {
                $matches = [];
                if ($nic !== null) {
                    foreach ($this->targetByNormalizedNic[$nic] ?? [] as $candidate) {
                        $matches[(string)$candidate['id']] = $candidate;
                    }
                }
                if ($matchKey !== null) {
                    foreach ($this->targetByMatchKey[$matchKey] ?? [] as $candidate) {
                        $matches[(string)$candidate['id']] = $candidate;
                    }
                }
                if (count($matches) > 1) {
                    $action = 'skip';
                    $this->stats['skipped']++;
                    $rowIssues[] = $this->issue($legacyId, 'DUPLICATE_TARGET_MATCH', 'ERROR', 'NIC matching found more than one target Officer; automatic merge skipped.', $row);
                } elseif (count($matches) === 1) {
                    $action = 'attach_reference';
                    $targetOfficer = reset($matches);
                    $this->stats['would_update']++;
                } else {
                    $this->stats['would_create']++;
                }
            }

            foreach ($rowIssues as $issue) {
                $this->issues[] = $issue;
            }
            $mapped = [
                'legacy_id' => $legacyId,
                'nic_raw' => $nicRaw,
                'nic' => $nic,
                'nic_normalized' => $nic,
                'nic_match_key' => $matchKey,
                'name_with_initials' => $nameInitials,
                'full_name_en' => $fullName,
                'date_of_birth' => $dob,
                'gender' => $gender,
                'permanent_address' => $address,
                'primary_mobile' => $phone,
                'alternative_mobile' => $alternativeMobile,
                'personal_email' => $personalEmail,
                'initial_appointment_date' => $initialDate,
                'primary_designation_id' => $this->arpaDesignationId,
                'class_id' => $classId,
                'arpa_service_permanency'=>$servicePermanency,
                'officer_status_id' => $statusKey !== null ? $this->statusIds[$statusKey] : null,
                'operational_status' => $statusKey,
                'effective_from' => $this->effectiveDate($row['created_at'] ?? null),
                'legacy_designation_id' => $this->nullableText($row['designation_id'] ?? null),
                'legacy_designation_name' => $this->nullableText($row['legacy_designation_name'] ?? null),
                'legacy_officer_status' => $this->nullableText($row['officer_status'] ?? null),
                'payload' => $this->legacyPayload($row),
            ];
            $reportIndex = count($this->reportRows);
            $this->reportRows[] = [
                'legacy_officer_id' => $legacyId,
                'legacy_nic' => $mapped['nic_raw'],
                'canonical_nic' => $mapped['nic'],
                'name_with_initials' => $nameInitials,
                'full_name_en' => $fullName,
                'legacy_designation_id' => $mapped['legacy_designation_id'],
                'legacy_designation_name' => $mapped['legacy_designation_name'],
                'legacy_status' => $mapped['legacy_officer_status'],
                'legacy_grade' => $row['grade'] ?? null,
                'class_system_key' => $classKey,
                'officer_id' => $targetOfficer['id'] ?? ($this->references[$legacyId] ?? null),
                'dad_number' => $targetOfficer['dad_number'] ?? null,
                'migration_status' => strtoupper($action),
                'issue_types' => implode('|', array_column($rowIssues, 'issue_type')),
                'issue_messages' => implode(' | ', array_column($rowIssues, 'message')),
            ];
            $sourceAliasPrimary=$this->sourceAliases[$legacyId]??null;
            $this->plans[] = compact('action', 'mapped', 'reportIndex','sourceAliasPrimary');
        }
    }

    private function executePlans(): void
    {
        foreach (array_chunk($this->plans, $this->batchSize) as $batch) {
            $this->target->beginTransaction();
            try {
                foreach ($batch as $offset => $plan) {
                    if (!in_array($plan['action'], ['create', 'attach_reference','attach_source_alias'], true)) {
                        continue;
                    }
                    $savepoint = 'officer_' . $offset;
                    $this->target->exec("SAVEPOINT {$savepoint}");
                    try {
                        if ($plan['action'] === 'create') {
                            [$officerId, $dadNumber] = $this->insertOfficer($plan['mapped']);
                            $this->stats['created']++;
                        } elseif($plan['action']==='attach_reference') {
                            $matches = $this->targetByNormalizedNic[$plan['mapped']['nic_normalized']] ?? [];
                            if ($matches === [] && $plan['mapped']['nic_match_key'] !== null) {
                                $matches = $this->targetByMatchKey[$plan['mapped']['nic_match_key']] ?? [];
                            }
                            $officerId = (string)$matches[0]['id'];
                            $dadNumber = (string)$matches[0]['dad_number'];
                            $this->stats['updated']++;
                        } else {
                            $stmt=$this->target->prepare('SELECT r.officer_id,o.dad_number FROM legacy_officer_reference r JOIN officer o ON o.id=r.officer_id WHERE r.source_system=? AND r.source_table=? AND r.legacy_officer_id=?');
                            $stmt->execute([self::SOURCE_SYSTEM,self::SOURCE_TABLE,$plan['sourceAliasPrimary']]);$primary=$stmt->fetch();
                            if(!$primary)throw new RuntimeException('Verified primary legacy Officer reference is unavailable: '.$plan['sourceAliasPrimary']);
                            $officerId=(string)$primary['officer_id'];$dadNumber=(string)$primary['dad_number'];$this->stats['updated']++;
                        }
                        $this->insertReference($officerId, $plan['mapped']);
                        $this->reportRows[$plan['reportIndex']]['officer_id'] = $officerId;
                        $this->reportRows[$plan['reportIndex']]['dad_number'] = $dadNumber;
                        $this->reportRows[$plan['reportIndex']]['migration_status'] = $plan['action'] === 'create' ? 'CREATED' : ($plan['action']==='attach_source_alias'?'ATTACHED_VERIFIED_ALIAS':'MATCHED_EXISTING');
                    } catch (Throwable $e) {
                        $this->target->exec("ROLLBACK TO SAVEPOINT {$savepoint}");
                        if ($plan['action'] === 'create') {
                            $this->stats['created'] = max(0, $this->stats['created'] - 1);
                        } else {
                            $this->stats['updated'] = max(0, $this->stats['updated'] - 1);
                        }
                        $this->stats['skipped']++;
                        $issue = $this->issue($plan['mapped']['legacy_id'], 'INSERT_FAILED', 'ERROR', 'Officer/reference creation failed: ' . $e->getMessage(), $plan['mapped']['payload']);
                        $this->issues[] = $issue;
                        $this->reportRows[$plan['reportIndex']]['migration_status'] = 'ERROR';
                        $this->reportRows[$plan['reportIndex']]['issue_types'] .= ($this->reportRows[$plan['reportIndex']]['issue_types'] === '' ? '' : '|') . 'INSERT_FAILED';
                        $this->reportRows[$plan['reportIndex']]['issue_messages'] .= ($this->reportRows[$plan['reportIndex']]['issue_messages'] === '' ? '' : ' | ') . $issue['message'];
                    }
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

    private function insertOfficer(array $mapped): array
    {
        $dadNumber = NumberService::nextUsing($this->target, self::NUMBER_CATEGORY);
        $officerId = self::uuid();
        $stmt = $this->target->prepare(
            'INSERT INTO officer
             (id,dad_number,nic,nic_normalized,nic_match_key,employee_number,title_id,
              name_with_initials,full_name_en,full_name_si,full_name_ta,date_of_birth,
              expected_retirement_date,gender,civil_status_id,permanent_address,temporary_address,
              primary_mobile,alternative_mobile,personal_email,official_email,photograph_path,
               initial_appointment_date,appointment_nature_id,primary_designation_id,class_id,arpa_service_permanency,
              officer_status_id,primary_office_id,effective_from,effective_to,operational_status,
               approval_status,created_by,created_at,updated_by,updated_at,version)
             VALUES (?,?,?,?,?,NULL,NULL,?,?,NULL,NULL,?,NULL,?,NULL,?,NULL,?,?,?,NULL,NULL,?,NULL,?,?,?,?,NULL,?,NULL,?,\'APPROVED\',NULL,NOW(),NULL,NULL,0)'
        );
        $stmt->execute([
            $officerId,
            $dadNumber,
            $mapped['nic'],
            $mapped['nic_normalized'],
            $mapped['nic_match_key'],
            $mapped['name_with_initials'],
            $mapped['full_name_en'],
            $mapped['date_of_birth'],
            $mapped['gender'],
            $mapped['permanent_address'],
            $mapped['primary_mobile'],
            $mapped['alternative_mobile'],
            $mapped['personal_email'],
            $mapped['initial_appointment_date'],
            $mapped['primary_designation_id'],
            $mapped['class_id'],
            $mapped['arpa_service_permanency'],
            $mapped['officer_status_id'],
            $mapped['effective_from'],
            $mapped['operational_status'],
        ]);
        return [$officerId, $dadNumber];
    }

    private function insertReference(string $officerId, array $mapped): void
    {
        $stmt = $this->target->prepare(
            'INSERT INTO legacy_officer_reference
             (source_system,source_table,legacy_officer_id,officer_id,legacy_nic,
              legacy_designation_id,legacy_designation_name,legacy_officer_status,
              legacy_payload_json,migration_run_id,created_at)
             VALUES (?,?,?,?,?,?,?,?,?,?,NOW())'
        );
        $stmt->execute([
            self::SOURCE_SYSTEM,
            self::SOURCE_TABLE,
            $mapped['legacy_id'],
            $officerId,
            $mapped['nic_raw'],
            $mapped['legacy_designation_id'],
            $mapped['legacy_designation_name'],
            $mapped['legacy_officer_status'],
            json_encode($mapped['payload'], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE),
            $this->runId,
        ]);
    }

    private function targetOfficerDad(string $officerId): ?string
    {
        $stmt=$this->target->prepare('SELECT dad_number FROM officer WHERE id=?');$stmt->execute([$officerId]);$value=$stmt->fetchColumn();return $value===false?null:(string)$value;
    }

    private function createRun(): void
    {
        $this->target->prepare(
            'INSERT INTO legacy_officer_migration_run (id,source_system,source_table,started_at,status,batch_size)
             VALUES (?,?,?,NOW(),\'RUNNING\',?)'
        )->execute([$this->runId, self::SOURCE_SYSTEM, self::SOURCE_TABLE, $this->batchSize]);
    }

    private function persistIssues(): void
    {
        $stmt = $this->target->prepare(
            'INSERT INTO legacy_officer_migration_issue
             (migration_run_id,legacy_officer_id,issue_type,severity,message,source_payload_json,created_at)
             VALUES (?,?,?,?,?,?,NOW())'
        );
        foreach ($this->issues as $issue) {
            $stmt->execute([
                $this->runId,
                $issue['legacy_officer_id'],
                $issue['issue_type'],
                $issue['severity'],
                $issue['message'],
                json_encode($issue['payload'], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE),
            ]);
        }
    }

    private function completeRun(array $summary): void
    {
        $stmt = $this->target->prepare(
            'UPDATE legacy_officer_migration_run SET completed_at=NOW(),status=?,
             source_appointment_row_count=?,distinct_source_officer_count=?,matched_source_master_count=?,
             existing_reference_count=?,would_create_count=?,would_update_count=?,created_count=?,updated_count=?,
             skipped_count=?,warning_count=?,error_count=?,report_path=?,summary_json=?,zero_write_verification_json=? WHERE id=?'
        );
        $stmt->execute([
            $summary['status'], $this->stats['source_appointment_rows'], $this->stats['distinct_source_officers'],
            $this->stats['matched_source_officer_masters'], $this->stats['existing_legacy_references'],
            $this->stats['would_create'], $this->stats['would_update'], $this->stats['created'], $this->stats['updated'],
            $this->stats['skipped'], $summary['warnings'], $summary['errors'], $summary['report_path'],
            json_encode($summary, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
            json_encode($summary['out_of_scope_counts'], JSON_THROW_ON_ERROR), $this->runId,
        ]);
    }

    private function failRun(Throwable $e): void
    {
        try {
            $stmt = $this->target->prepare(
                "UPDATE legacy_officer_migration_run SET completed_at=NOW(),status='FAILED',error_count=error_count+1,
                 summary_json=JSON_OBJECT('error',?) WHERE id=?"
            );
            $stmt->execute([$e->getMessage(), $this->runId]);
        } catch (Throwable) {
            // Preserve the original migration error.
        }
    }

    private function buildSummary(array $protectedAfter): array
    {
        $warnings = count(array_filter($this->issues, static fn(array $issue): bool => $issue['severity'] === 'WARNING'));
        $errors = count(array_filter($this->issues, static fn(array $issue): bool => $issue['severity'] === 'ERROR'));
        $status = $errors > 0 ? 'COMPLETED_WITH_ERRORS' : ($warnings > 0 ? 'COMPLETED_WITH_WARNINGS' : 'COMPLETED');
        return array_merge($this->stats, [
            'run_id' => $this->runId,
            'mode' => $this->dryRun ? 'DRY_RUN' : 'EXECUTE',
            'status' => $status,
            'number_category' => self::NUMBER_CATEGORY,
            'number_category_code' => $this->simulatedNumber['code'],
            'warnings' => $warnings,
            'errors' => $errors,
            'error_messages' => array_values(array_map(static fn(array $issue): string => $issue['message'],array_filter($this->issues,static fn(array $issue): bool => $issue['severity']==='ERROR'))),
            'out_of_scope_counts' => [
                'before' => $this->protectedBefore,
                'after' => $protectedAfter,
                'unchanged' => $this->protectedBefore === $protectedAfter,
            ],
        ]);
    }

    private function writeReports(array $summary): string
    {
        $directory = BASE_PATH . '/storage/reports';
        if (!is_dir($directory) && !mkdir($directory, 0770, true) && !is_dir($directory)) {
            throw new RuntimeException('Unable to create migration report directory.');
        }
        $stamp = date('Ymd-His') . '-' . substr(str_replace('-', '', $this->runId), 0, 8);
        $csvPath = $directory . '/' . $this->selectionName . '-migration-' . $stamp . '.csv';
        $handle = fopen($csvPath, 'wb');
        if ($handle === false) {
            throw new RuntimeException('Unable to create Officer migration CSV report.');
        }
        $columns = ['legacy_officer_id', 'legacy_nic', 'canonical_nic', 'name_with_initials', 'full_name_en', 'legacy_designation_id', 'legacy_designation_name', 'legacy_status', 'legacy_grade', 'class_system_key', 'officer_id', 'dad_number', 'migration_status', 'issue_types', 'issue_messages'];
        fputcsv($handle, $columns, ',', '"', '');
        foreach ($this->reportRows as $row) {
            fputcsv($handle, array_map(static fn(string $column): mixed => $row[$column] ?? null, $columns), ',', '"', '');
        }
        fclose($handle);
        $jsonPath = substr($csvPath, 0, -4) . '.json';
        file_put_contents($jsonPath, json_encode($summary, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
        return $csvPath;
    }

    private function protectedCounts(): array
    {
        $tables = [
            'system_user', 'application_role', 'application_permission', 'user_account_role', 'user_account_scope',
            'office', 'location', 'location_relationship', 'officer_appointment',
            'officer_appointment_history', 'officer_assignment', 'office_assignment',
            'arpa_assignment', 'officer_location_assignment',
        ];
        $counts = [];
        foreach ($tables as $table) {
            $counts[$table] = $this->tableExists($this->target, $table)
                ? (int)$this->target->query("SELECT COUNT(*) FROM `{$table}`")->fetchColumn()
                : 0;
        }
        return $counts;
    }

    private function issue(string $legacyId, string $type, string $severity, string $message, array $payload): array
    {
        return [
            'legacy_officer_id' => $legacyId,
            'issue_type' => $type,
            'severity' => $severity,
            'message' => $message,
            'payload' => $this->legacyPayload($payload),
        ];
    }

    private function normalizeGender(mixed $value): ?string
    {
        return match (strtoupper(trim((string)$value))) {
            'MALE' => 'MALE',
            'FEMALE' => 'FEMALE',
            default => null,
        };
    }

    private function normalizeEmail(mixed $value): ?string
    {
        $email = strtolower(trim((string)$value));
        if ($email === '' || strlen($email) > 255 || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            return null;
        }
        return $email;
    }

    private function nullableText(mixed $value): ?string
    {
        $text = trim((string)$value);
        return $text === '' ? null : $text;
    }

    private function validDate(mixed $value): ?string
    {
        $date = trim((string)$value);
        if ($date === '' || str_starts_with($date, '0000-00-00')) {
            return null;
        }
        $parsed = DateTimeImmutable::createFromFormat('!Y-m-d', substr($date, 0, 10));
        return $parsed !== false && $parsed->format('Y-m-d') === substr($date, 0, 10) ? $parsed->format('Y-m-d') : null;
    }

    private function effectiveDate(mixed $createdAt): string
    {
        $sourceDate = $this->validDate($createdAt);
        if ($sourceDate !== null) {
            return $sourceDate;
        }
        if ($this->fallbackDate === null) {
            throw new RuntimeException('Legacy Officer has no valid created_at date and LEGACY_OFFICER_EFFECTIVE_FROM is not configured.');
        }
        return $this->fallbackDate;
    }

    private function legacyPayload(array $row): array
    {
        $allowed = [
            'officer_id', 'designation_id', 'legacy_designation_name', 'full_name', 'name_with_initial',
            'nic', 'residential_address', 'birth_day', 'gender', 'tp_no', 'whatsapp_no',
            'email_address', 'first_appoint_date', 'permanent_or_not', 'permanented_date', 'grade', 'officer_status', 'created_at', 'updated_at',
        ];
        return array_intersect_key($row, array_flip($allowed));
    }

    private function tableExists(PDO $pdo, string $table): bool
    {
        $stmt = $pdo->prepare('SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name=?');
        $stmt->execute([$table]);
        return (int)$stmt->fetchColumn() > 0;
    }

    private static function uuid(): string
    {
        $bytes = random_bytes(16);
        $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
        $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);
        $hex = bin2hex($bytes);
        return substr($hex, 0, 8) . '-' . substr($hex, 8, 4) . '-' . substr($hex, 12, 4) . '-' . substr($hex, 16, 4) . '-' . substr($hex, 20);
    }
}
