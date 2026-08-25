<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\CredentialService;
use App\Core\NicNormalizer;
use App\Core\NumberService;
use App\Core\ScopeService;
use DomainException;
use PDO;
use Throwable;

final class UserAccountRequestService
{
    public const SOURCE_OFFICER = 'EXISTING_OFFICER';
    public const SOURCE_MANUAL = 'MANUAL_NO_OFFICER';

    public function __construct(private readonly PDO $pdo) {}

    /** @return array{user_id:string,officer_id:?string,role_assignment_id:string,office_assignment_id:?string} */
    public function request(string $actorId, array $data): array
    {
        $policy = new UserAccessManagementService($this->pdo);
        $policy->assertActorPermissions($actorId, ['user.request', 'user.assign-role', 'user.assign-scope']);

        $source = strtoupper(trim((string)($data['account_source'] ?? self::SOURCE_OFFICER)));
        if (!in_array($source, [self::SOURCE_OFFICER, self::SOURCE_MANUAL], true)) {
            throw new DomainException('Select a valid account source.');
        }
        $username = strtolower(CredentialService::validateOperationalUsername((string)($data['username'] ?? '')));
        if (preg_match('/^[a-z0-9._-]{5,50}$/', $username) !== 1) {
            throw new DomainException('Username may contain only letters, numbers, dots, underscores, and hyphens.');
        }
        $passwordHash = CredentialService::hashTemporaryPassword((string)($data['temporary_password'] ?? ''));
        $mfaMethod = strtoupper(trim((string)($data['mfa_method'] ?? 'AUTHENTICATOR_APP')));
        if (!in_array($mfaMethod, ['AUTHENTICATOR_APP', 'SMS_OTP'], true)) {
            throw new DomainException('Select a valid security method.');
        }

        $roleId = trim((string)($data['role_id'] ?? ''));
        $locationId = $this->optional($data['location_id'] ?? null);
        $effectiveFrom = $this->date((string)(($data['effective_from'] ?? '') ?: UserAccessManagementService::OPERATIONAL_ACCESS_BASELINE_DATE));
        if ($effectiveFrom < UserAccessManagementService::OPERATIONAL_ACCESS_BASELINE_DATE) {
            throw new DomainException('The start date cannot be before 01 January 2025.');
        }
        $validated = $policy->validateAccountRequestAssignment($actorId, $roleId, $locationId, $effectiveFrom);
        $identityType = (string)$validated['role']['role_code'] === 'FARMER' ? 'FARMER' : 'STAFF';

        $officerId = null;
        $displayName = '';
        $nic = null;
        $designationId = null;
        $officerStatusId = null;
        if ($source === self::SOURCE_OFFICER) {
            if ($identityType === 'FARMER') {
                throw new DomainException('Farmer accounts cannot be linked to an Officer record.');
            }
            $officerId = trim((string)($data['officer_id'] ?? ''));
            if ($officerId === '') {
                throw new DomainException('Select an approved Officer.');
            }
            if (!ScopeService::canAccessOfficer($actorId, $officerId)) {
                throw new DomainException('You can only select an Officer within your current access.');
            }
        } else {
            $displayName = $this->requiredText($data['full_name'] ?? null, 'Full Name', 255);
            if ($identityType === 'STAFF') {
                $nic = NicNormalizer::normalize((string)($data['nic'] ?? ''));
                if (!NicNormalizer::isValid($nic)) {
                    throw new DomainException('Enter a valid NIC for the new Officer.');
                }
                $designationId = trim((string)($data['primary_designation_id'] ?? ''));
                if ($designationId === '') {
                    throw new DomainException('Select the Designation.');
                }
                $officerStatusId = trim((string)($data['officer_status_id'] ?? ''));
                if ($officerStatusId === '') {
                    throw new DomainException('Select the Officer Status.');
                }
            }
        }

        return $this->transaction(function () use ($actorId, $source, $username, $passwordHash, $mfaMethod, $officerId, $displayName, $identityType, $roleId, $locationId, $effectiveFrom, $validated, $policy, $nic, $designationId, $officerStatusId): array {
            $collision = $this->pdo->prepare('SELECT COUNT(*) FROM system_user WHERE username=? FOR UPDATE');
            $collision->execute([$username]);
            if ((int)$collision->fetchColumn() > 0) {
                throw new DomainException('That username is already in use.');
            }

            // Revalidate role and geography inside the write transaction so a
            // stale browser selection cannot survive an authority change.
            $validated = $policy->validateAccountRequestAssignment($actorId, $roleId, $locationId, $effectiveFrom);
            $officeAssignmentId = null;

            if ($source === self::SOURCE_OFFICER) {
                $officer = $this->pdo->prepare("SELECT id,name_with_initials,nic FROM officer WHERE id=? AND approval_status='APPROVED' FOR UPDATE");
                $officer->execute([$officerId]);
                $officerRow = $officer->fetch();
                if (!$officerRow) {
                    throw new DomainException('The selected approved Officer was not found.');
                }
                $linked = $this->pdo->prepare('SELECT COUNT(*) FROM system_user WHERE officer_id=? FOR UPDATE');
                $linked->execute([$officerId]);
                if ((int)$linked->fetchColumn() > 0) {
                    throw new DomainException('This Officer already has a user account.');
                }
                $displayName = (string)$officerRow['name_with_initials'];
                $nic = $officerRow['nic'];
            } elseif ($identityType === 'STAFF') {
                $created = $this->createOfficerAndOfficeAssignment(
                    $actorId,
                    $displayName,
                    (string)$nic,
                    (string)$designationId,
                    (string)$officerStatusId,
                    $effectiveFrom,
                    $validated
                );
                $officerId = $created['officer_id'];
                $officeAssignmentId = $created['office_assignment_id'];
            }

            $userId = $this->uuid();
            $this->pdo->prepare("INSERT INTO system_user
                    (id,officer_id,identity_type,username,display_name,historical_identity,identity_source,password_hash,
                     account_status,approval_status,enabled,mfa_method,password_setup_required,mfa_enrolled,
                     requested_by,requested_at,submitted_by,submitted_at,created_at)
                    VALUES(?,?,?,?,?,0,?,?,'REQUESTED','SUBMITTED',0,?,1,0,?,NOW(),?,NOW(),NOW())")
                ->execute([$userId, $officerId, $identityType, $username, $displayName, $source, $passwordHash, $mfaMethod, $actorId, $actorId]);

            $roleAssignmentId = $this->createInitialAssignment($actorId, $userId, $roleId, $effectiveFrom, $validated);

            $audit = [
                'creation_source' => $source,
                'username' => $username,
                'identity_type' => $identityType,
                'officer_id' => $officerId,
                'officer_office_assignment_id' => $officeAssignmentId,
                'nic' => $nic,
                'role_assignment_id' => $roleAssignmentId,
                'role_code' => $validated['role']['role_code'] ?? null,
                'scope_type' => $validated['scope_type'] ?? null,
                'scope_mode' => $validated['scope_mode'] ?? null,
                'location_id' => $validated['location_id'] ?? null,
                'effective_from' => $effectiveFrom,
            ];
            $this->recordAudit($actorId, 'user.request', $userId, $audit);
            $this->recordAudit($actorId, 'user.submit', $userId, $audit);
            return [
                'user_id' => $userId,
                'officer_id' => $officerId,
                'role_assignment_id' => $roleAssignmentId,
                'office_assignment_id' => $officeAssignmentId,
            ];
        });
    }

    public function approve(string $actorId, string $userId): void
    {
        $policy = new UserAccessManagementService($this->pdo);
        $policy->assertActorPermissions($actorId, ['user.approve']);
        $this->transaction(function () use ($actorId, $userId, $policy): void {
            $stmt = $this->pdo->prepare('SELECT * FROM system_user WHERE id=? FOR UPDATE');
            $stmt->execute([$userId]);
            $user = $stmt->fetch();
            if (!$user || (string)$user['approval_status'] !== 'SUBMITTED') {
                throw new DomainException('Only submitted user requests can be approved.');
            }
            if ((string)$user['requested_by'] === $actorId) {
                throw new DomainException('You cannot approve a user request you created.');
            }

            $roles = $this->pdo->prepare("SELECT id FROM user_account_role WHERE user_id=? AND approval_status='SUBMITTED' ORDER BY created_at,id FOR UPDATE");
            $roles->execute([$userId]);
            $assignmentIds = array_map('strval', $roles->fetchAll(PDO::FETCH_COLUMN));
            $combinedRequest = (string)$user['identity_source'] === self::SOURCE_MANUAL || $assignmentIds !== [];
            if ($combinedRequest && count($assignmentIds) !== 1) {
                throw new DomainException('The user request does not have one valid initial role and location.');
            }
            foreach ($assignmentIds as $assignmentId) {
                $policy->approveAssignment($actorId, $assignmentId);
            }

            if ((string)$user['identity_source'] === self::SOURCE_MANUAL && (string)$user['identity_type'] === 'STAFF') {
                $this->approveCreatedOfficer($actorId, $user);
            }

            $this->pdo->prepare("UPDATE system_user SET approval_status='APPROVED',account_status='ACTIVE',enabled=1,
                    approved_by=?,approved_at=NOW(),activated_by=?,activated_at=NOW(),updated_at=NOW() WHERE id=?")
                ->execute([$actorId, $actorId, $userId]);
            $this->recordAudit($actorId, 'user.approve', $userId, ['role_assignment_ids' => $assignmentIds]);
        });
    }

    /** @return array{with:string,where:string,params:array<int,string>} */
    public function pendingVisibility(string $actorId): array
    {
        $authority = (new UserAccessManagementService($this->pdo))->authority($actorId);
        if ($authority['kind'] === 'SYSTEM') {
            return ['with' => '', 'where' => '1=1', 'params' => []];
        }
        return ['with' => '', 'where' => 'su.requested_by=?', 'params' => [$actorId]];
    }

    /** @return array{officer_id:string,office_assignment_id:string} */
    private function createOfficerAndOfficeAssignment(
        string $actorId,
        string $name,
        string $nic,
        string $designationId,
        string $officerStatusId,
        string $effectiveFrom,
        array $validated
    ): array {
        $matchKey = NicNormalizer::matchKey($nic);
        $duplicate = $this->pdo->prepare('SELECT id FROM officer WHERE nic_normalized=? OR nic_match_key=? LIMIT 1 FOR UPDATE');
        $duplicate->execute([$nic, $matchKey]);
        if ($duplicate->fetchColumn() !== false) {
            throw new DomainException('An Officer record already exists for this NIC. Use Existing Approved Officer.');
        }

        $designation = $this->pdo->prepare("SELECT id,system_key,name_en FROM designation WHERE id=? AND active=1 AND approval_status='APPROVED' FOR UPDATE");
        $designation->execute([$designationId]);
        $designationRow = $designation->fetch();
        if (!$designationRow) {
            throw new DomainException('Select a valid Designation.');
        }

        $status = $this->pdo->prepare("SELECT id,system_key,name_en FROM officer_status WHERE id=? AND active=1 AND approval_status='APPROVED' FOR UPDATE");
        $status->execute([$officerStatusId]);
        $statusRow = $status->fetch();
        if (!$statusRow) {
            throw new DomainException('Select a valid Officer Status.');
        }

        $office = $this->resolveInitialOffice($validated, $effectiveFrom);
        $officerId = $this->uuid();
        $dadNumber = NumberService::nextUsing($this->pdo, 'OFFICER');
        $this->pdo->prepare("INSERT INTO officer
                (id,dad_number,nic,nic_normalized,nic_match_key,name_with_initials,primary_designation_id,officer_status_id,effective_from,
                 operational_status,approval_status,created_by,created_at,submitted_by,submitted_at)
                VALUES(?,?,?,?,?,?,?,?,?,'INACTIVE','SUBMITTED',?,NOW(),?,NOW())")
            ->execute([$officerId, $dadNumber, $nic, $nic, $matchKey, $name, $designationId, $officerStatusId, $effectiveFrom, $actorId, $actorId]);

        $reason = 'Initial Office for user account request';
        $officeAssignmentId = (new OfficerOfficeAssignmentService($this->pdo))->create([
            'officer_id' => $officerId,
            'office_id' => $office['id'],
            'effective_from' => $effectiveFrom,
            'is_primary' => $effectiveFrom <= date('Y-m-d') ? 1 : 0,
            'reason' => $reason,
            'remarks' => 'Created with the initial DEMS user account request.',
        ], $actorId);

        $details = [
            'dad_number' => $dadNumber,
            'nic' => $nic,
            'designation_id' => $designationId,
            'designation' => $designationRow['system_key'],
            'officer_status_id' => $officerStatusId,
            'officer_status' => $statusRow['system_key'],
            'office_id' => $office['id'],
            'office_assignment_id' => $officeAssignmentId,
            'effective_from' => $effectiveFrom,
            'creation_source' => self::SOURCE_MANUAL,
        ];
        $this->recordAudit($actorId, 'officer.create', $officerId, $details, 'OFFICER');
        $this->recordAudit($actorId, 'workflow.submit', $officerId, $details, 'OFFICER');
        return ['officer_id' => $officerId, 'office_assignment_id' => $officeAssignmentId];
    }

    private function approveCreatedOfficer(string $actorId, array $user): void
    {
        $officerId = trim((string)($user['officer_id'] ?? ''));
        if ($officerId === '') {
            throw new DomainException('The staff user request does not have its required Officer record.');
        }
        $officer = $this->pdo->prepare("SELECT o.*,os.system_key officer_status_key FROM officer o JOIN officer_status os ON os.id=o.officer_status_id WHERE o.id=? FOR UPDATE");
        $officer->execute([$officerId]);
        $officerRow = $officer->fetch();
        if (!$officerRow || (string)$officerRow['approval_status'] !== 'SUBMITTED'
            || (string)$officerRow['created_by'] !== (string)$user['requested_by']) {
            throw new DomainException('The Officer registration linked to this user request is not valid for approval.');
        }

        $assignments = $this->pdo->prepare("SELECT id FROM officer_office_assignment WHERE officer_id=? AND approval_status='SUBMITTED' AND created_by=? AND reason='Initial Office for user account request' ORDER BY created_at,id FOR UPDATE");
        $assignments->execute([$officerId, $user['requested_by']]);
        $officeAssignmentIds = array_map('strval', $assignments->fetchAll(PDO::FETCH_COLUMN));
        if (count($officeAssignmentIds) !== 1) {
            throw new DomainException('The Officer registration does not have one valid initial Office assignment.');
        }

        $operationalStatus = (string)$officerRow['officer_status_key'] === 'ACTIVE'
            && (string)$officerRow['effective_from'] <= date('Y-m-d') ? 'ACTIVE' : 'INACTIVE';
        $this->pdo->prepare("UPDATE officer SET approval_status='APPROVED',operational_status=?,approved_by=?,approved_at=NOW(),updated_by=?,version=version+1 WHERE id=?")
            ->execute([$operationalStatus, $actorId, $actorId, $officerId]);
        (new OfficerOfficeAssignmentService($this->pdo))->approve($officeAssignmentIds[0], $actorId);
        $this->recordAudit($actorId, 'officer.approve', $officerId, [
            'office_assignment_id' => $officeAssignmentIds[0],
            'operational_status' => $operationalStatus,
        ], 'OFFICER');
    }

    /** @return array{id:string,dad_number:string,name_en:string} */
    private function resolveInitialOffice(array $validated, string $effectiveFrom): array
    {
        $roleLevel = (string)$validated['role']['role_level'];
        $locationId = $validated['location_id'];
        $officeType = null;
        $officeLocationId = null;

        if ($roleLevel === 'NATIONAL') {
            $officeType = 'HEAD_OFFICE';
        } elseif ($roleLevel === 'DISTRICT') {
            $officeType = 'DISTRICT_OFFICE';
            $officeLocationId = $locationId;
        } elseif ($roleLevel === 'ASC') {
            $officeType = 'ASC_OFFICE';
            $officeLocationId = $locationId;
        } elseif ($roleLevel === 'ARPA') {
            $parents = $this->pdo->prepare("SELECT DISTINCT p.id
                FROM location_relationship lr
                JOIN location p ON p.id=lr.parent_location_id
                JOIN location_type pt ON pt.id=p.location_type_id AND pt.system_key='ASC'
                WHERE lr.child_location_id=? AND lr.active=1 AND lr.approval_status='APPROVED'
                  AND lr.effective_from<=? AND (lr.effective_to IS NULL OR lr.effective_to>=?)
                  AND p.operational_status='ACTIVE' AND p.approval_status='APPROVED'
                  AND p.effective_from<=? AND (p.effective_to IS NULL OR p.effective_to>=?)");
            $parents->execute([$locationId, $effectiveFrom, $effectiveFrom, $effectiveFrom, $effectiveFrom]);
            $parentIds = array_map('strval', $parents->fetchAll(PDO::FETCH_COLUMN));
            if (count($parentIds) !== 1) {
                throw new DomainException('The selected ARPA Division does not have one valid Agrarian Service Center.');
            }
            $officeType = 'ASC_OFFICE';
            $officeLocationId = $parentIds[0];
        } else {
            throw new DomainException('The selected staff role does not have a supported initial Office.');
        }

        $locationClause = $officeLocationId === null ? 'o.linked_location_id IS NULL' : 'o.linked_location_id=?';
        $params = $officeLocationId === null
            ? [$officeType, $effectiveFrom, $effectiveFrom]
            : [$officeType, $officeLocationId, $effectiveFrom, $effectiveFrom];
        $statement = $this->pdo->prepare("SELECT o.id,o.dad_number,o.name_en
            FROM office o
            JOIN office_type ot ON ot.id=o.office_type_id AND ot.system_key=? AND ot.active=1
            WHERE {$locationClause} AND o.operational_status='ACTIVE' AND o.approval_status='APPROVED'
              AND o.effective_from<=? AND (o.effective_to IS NULL OR o.effective_to>=?)
            ORDER BY o.id FOR UPDATE");
        $statement->execute($params);
        $offices = $statement->fetchAll();
        if (count($offices) !== 1) {
            throw new DomainException('The selected role and location do not have one valid Office assignment.');
        }
        return $offices[0];
    }

    private function recordAudit(string $actorId, string $action, string $targetId, array $details, string $targetType = 'SYSTEM_USER'): void
    {
        $this->pdo->prepare("INSERT INTO audit_event(actor_user_id,action_key,target_type,target_id,details_json,severity,created_at) VALUES(?,?,?,?,?,'INFO',NOW())")
            ->execute([$actorId, $action, $targetType, $targetId, json_encode($details, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)]);
    }

    private function createInitialAssignment(string $actorId, string $userId, string $roleId, string $effectiveFrom, array $validated): string
    {
        $assignmentId = $this->uuid();
        $reason = 'Initial role for user account request';
        $this->pdo->prepare("INSERT INTO user_account_role
                (id,user_id,role_id,effective_from,approval_status,active,reason,created_by,created_at,submitted_by,submitted_at)
                VALUES(?,?,?,?,'SUBMITTED',0,?,?,NOW(),?,NOW())")
            ->execute([$assignmentId, $userId, $roleId, $effectiveFrom, $reason, $actorId, $actorId]);
        $scopeId = null;
        if ($validated['scope_type'] !== null) {
            $scopeId = $this->uuid();
            $this->pdo->prepare("INSERT INTO user_account_scope
                    (id,user_id,role_assignment_id,scope_type,scope_mode,location_id,effective_from,approval_status,active,reason,created_by,created_at,submitted_by,submitted_at)
                    VALUES(?,?,?,?,?,?,?,'SUBMITTED',0,?,?,NOW(),?,NOW())")
                ->execute([$scopeId, $userId, $assignmentId, $validated['scope_type'], $validated['scope_mode'], $validated['location_id'], $effectiveFrom, $reason, $actorId, $actorId]);
        }
        $details = [
            'user_id' => $userId,
            'role_id' => $roleId,
            'role_code' => $validated['role']['role_code'],
            'scope_assignment_id' => $scopeId,
            'scope_type' => $validated['scope_type'],
            'scope_mode' => $validated['scope_mode'],
            'location_id' => $validated['location_id'],
            'effective_from' => $effectiveFrom,
            'source' => self::SOURCE_MANUAL,
        ];
        $this->recordAudit($actorId, 'user.role.assign', $assignmentId, $details);
        $this->recordAudit($actorId, 'user.role.submit', $assignmentId, $details);
        return $assignmentId;
    }

    private function requiredText(mixed $value, string $label, int $maxLength): string
    {
        $value = trim((string)$value);
        if ($value === '') {
            throw new DomainException("{$label} is required.");
        }
        $length = function_exists('mb_strlen') ? mb_strlen($value, 'UTF-8') : strlen($value);
        if ($length > $maxLength) {
            throw new DomainException("{$label} is too long.");
        }
        return $value;
    }

    private function date(string $value): string
    {
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $value);
        if (!$date || $date->format('Y-m-d') !== $value) {
            throw new DomainException('Enter a valid start date.');
        }
        return $value;
    }

    private function optional(mixed $value): ?string
    {
        $value = trim((string)$value);
        return $value === '' ? null : $value;
    }

    private function uuid(): string
    {
        $hex = bin2hex(random_bytes(16));
        return substr($hex, 0, 8) . '-' . substr($hex, 8, 4) . '-4' . substr($hex, 13, 3) . '-'
            . dechex((hexdec($hex[16]) & 3) | 8) . substr($hex, 17, 3) . '-' . substr($hex, 20);
    }

    private function transaction(callable $callback): mixed
    {
        $owned = !$this->pdo->inTransaction();
        $savepoint = 'user_account_request_operation';
        if ($owned) {
            $this->pdo->beginTransaction();
        } else {
            $this->pdo->exec("SAVEPOINT {$savepoint}");
        }
        try {
            $result = $callback();
            if ($owned) {
                $this->pdo->commit();
            } else {
                $this->pdo->exec("RELEASE SAVEPOINT {$savepoint}");
            }
            return $result;
        } catch (Throwable $exception) {
            if ($owned && $this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            } elseif ($this->pdo->inTransaction()) {
                $this->pdo->exec("ROLLBACK TO SAVEPOINT {$savepoint}");
                $this->pdo->exec("RELEASE SAVEPOINT {$savepoint}");
            }
            throw $exception;
        }
    }
}
