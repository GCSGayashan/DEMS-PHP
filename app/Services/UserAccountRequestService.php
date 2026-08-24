<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\CredentialService;
use App\Core\ScopeService;
use DomainException;
use PDO;
use Throwable;

final class UserAccountRequestService
{
    public const SOURCE_OFFICER = 'EXISTING_OFFICER';
    public const SOURCE_MANUAL = 'MANUAL_NO_OFFICER';

    public function __construct(private readonly PDO $pdo) {}

    /** @return array{user_id:string,role_assignment_id:?string} */
    public function request(string $actorId, array $data): array
    {
        $policy = new UserAccessManagementService($this->pdo);
        $policy->assertActorPermissions($actorId, ['user.request']);

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

        $officerId = null;
        $displayName = '';
        $identityType = 'STAFF';
        $roleId = null;
        $locationId = null;
        $effectiveFrom = null;
        $validated = null;
        if ($source === self::SOURCE_OFFICER) {
            $officerId = trim((string)($data['officer_id'] ?? ''));
            if ($officerId === '') {
                throw new DomainException('Select an approved Officer.');
            }
            if (!ScopeService::canAccessOfficer($actorId, $officerId)) {
                throw new DomainException('You can only select an Officer within your current access.');
            }
        } else {
            $policy->assertActorPermissions($actorId, ['user.assign-role', 'user.assign-scope']);
            $displayName = $this->requiredText($data['full_name'] ?? null, 'Full Name', 255);
            $roleId = trim((string)($data['role_id'] ?? ''));
            $locationId = $this->optional($data['location_id'] ?? null);
            $effectiveFrom = $this->date((string)(($data['effective_from'] ?? '') ?: UserAccessManagementService::OPERATIONAL_ACCESS_BASELINE_DATE));
            if ($effectiveFrom < UserAccessManagementService::OPERATIONAL_ACCESS_BASELINE_DATE) {
                throw new DomainException('The start date cannot be before 01 January 2025.');
            }
            $validated = $policy->validateAccountRequestAssignment($actorId, $roleId, $locationId, $effectiveFrom);
            $identityType = (string)$validated['role']['role_code'] === 'FARMER' ? 'FARMER' : 'STAFF';
        }

        return $this->transaction(function () use ($actorId, $source, $username, $passwordHash, $mfaMethod, $officerId, $displayName, $identityType, $roleId, $locationId, $effectiveFrom, $validated, $policy): array {
            $collision = $this->pdo->prepare('SELECT COUNT(*) FROM system_user WHERE username=? FOR UPDATE');
            $collision->execute([$username]);
            if ((int)$collision->fetchColumn() > 0) {
                throw new DomainException('That username is already in use.');
            }

            if ($source === self::SOURCE_OFFICER) {
                $officer = $this->pdo->prepare("SELECT id,name_with_initials FROM officer WHERE id=? AND approval_status='APPROVED' FOR UPDATE");
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
            }

            $userId = $this->uuid();
            $this->pdo->prepare("INSERT INTO system_user
                    (id,officer_id,identity_type,username,display_name,historical_identity,identity_source,password_hash,
                     account_status,approval_status,enabled,mfa_method,password_setup_required,mfa_enrolled,
                     requested_by,requested_at,submitted_by,submitted_at,created_at)
                    VALUES(?,?,?,?,?,0,?,?,'REQUESTED','SUBMITTED',0,?,1,0,?,NOW(),?,NOW(),NOW())")
                ->execute([$userId, $officerId, $identityType, $username, $displayName, $source, $passwordHash, $mfaMethod, $actorId, $actorId]);

            $roleAssignmentId = null;
            if ($source === self::SOURCE_MANUAL) {
                $roleAssignmentId = $this->createInitialAssignment($actorId, $userId, (string)$roleId, (string)$effectiveFrom, (array)$validated);
            }

            $audit = [
                'creation_source' => $source,
                'username' => $username,
                'identity_type' => $identityType,
                'officer_id' => $officerId,
                'role_assignment_id' => $roleAssignmentId,
                'role_code' => $validated['role']['role_code'] ?? null,
                'scope_type' => $validated['scope_type'] ?? null,
                'scope_mode' => $validated['scope_mode'] ?? null,
                'location_id' => $validated['location_id'] ?? null,
                'effective_from' => $effectiveFrom,
            ];
            $this->recordAudit($actorId, 'user.request', $userId, $audit);
            $this->recordAudit($actorId, 'user.submit', $userId, $audit);
            return ['user_id' => $userId, 'role_assignment_id' => $roleAssignmentId];
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
            if ((string)$user['identity_source'] === self::SOURCE_MANUAL && count($assignmentIds) !== 1) {
                throw new DomainException('The user request does not have one valid initial role and location.');
            }
            foreach ($assignmentIds as $assignmentId) {
                $policy->approveAssignment($actorId, $assignmentId);
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

    private function recordAudit(string $actorId, string $action, string $targetId, array $details): void
    {
        $this->pdo->prepare('INSERT INTO audit_event(actor_user_id,action_key,target_type,target_id,details_json,severity,created_at) VALUES(?,?,\'SYSTEM_USER\',?,?,\'INFO\',NOW())')
            ->execute([$actorId, $action, $targetId, json_encode($details, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)]);
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
        if ($owned) {
            $this->pdo->beginTransaction();
        }
        try {
            $result = $callback();
            if ($owned) {
                $this->pdo->commit();
            }
            return $result;
        } catch (Throwable $exception) {
            if ($owned && $this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $exception;
        }
    }
}
