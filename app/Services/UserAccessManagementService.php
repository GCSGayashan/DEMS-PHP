<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Auth;
use DomainException;
use PDO;
use Throwable;

final class UserAccessManagementService
{
    public const OPERATIONAL_ACCESS_BASELINE_DATE = '2025-01-01';
    private const TECHNICAL_MANAGER_ROLES = ['SYSTEM_ADMIN', 'SECURITY_ADMIN', 'USER_ADMIN'];
    private const OPERATIONAL_ROLE_RANKS = [
        'FARMER' => 10,
        'ARPA_OFFICER' => 20,
        'ASC_SUBJECT_OFFICER' => 30,
        'ASC_ADMIN' => 40,
        'DISTRICT_SUBJECT_OFFICER' => 50,
        'DISTRICT_ADMIN' => 60,
        'NATIONAL_SUBJECT_OFFICER' => 70,
        'NATIONAL_ADMIN' => 80,
    ];
    private const USER_MANAGEMENT_MINIMUM_RANK = 30;
    private array $authorityCache = [];
    private array $districtDescendantsCache = [];

    public function __construct(private readonly PDO $pdo) {}

    /** @return array{kind:string,is_system_admin:bool,actor_role_code:?string,actor_rank:?int,district_ids:array<int,string>,asc_ids:array<int,string>} */
    public function authority(string $actorId, ?string $date = null): array
    {
        $date ??= date('Y-m-d');
        $context = Auth::activeContextForUser($actorId);
        if ($context === null && Auth::isCurrentUser($actorId)) {
            throw new DomainException('Choose a role and office before managing users.');
        }
        $cacheKey = $actorId . '|' . $date . '|' . ($context['role_assignment_id'] ?? 'aggregate') . '|' . ($context['scope_assignment_id'] ?? '');
        if (isset($this->authorityCache[$cacheKey])) {
            return $this->authorityCache[$cacheKey];
        }
        $stmt = $this->pdo->prepare("SELECT uar.id,r.role_code,r.role_level,
                    uas.id scope_assignment_id,uas.scope_type,uas.scope_mode,uas.location_id
                FROM user_account_role uar
                JOIN system_user su ON su.id=uar.user_id
                  AND su.enabled=1 AND su.account_status='ACTIVE' AND su.approval_status='APPROVED'
                JOIN application_role r ON r.id=uar.role_id
                LEFT JOIN user_account_scope uas
                  ON uas.role_assignment_id=uar.id AND uas.user_id=uar.user_id
                 AND uas.active=1 AND uas.approval_status='APPROVED'
                 AND uas.effective_from<=? AND (uas.effective_to IS NULL OR uas.effective_to>=?)
                WHERE uar.user_id=? AND uar.active=1 AND uar.approval_status='APPROVED'
                  AND uar.effective_from<=? AND (uar.effective_to IS NULL OR uar.effective_to>=?)
                  AND r.active=1 AND r.approval_status='APPROVED'");
        $stmt->execute([$date,$date,$actorId,$date,$date]);
        $assignments = $stmt->fetchAll();
        if ($context !== null) {
            $scopeId = $context['scope_assignment_id'] === null ? null : (string)$context['scope_assignment_id'];
            $assignments = array_values(array_filter($assignments, static function (array $assignment) use ($context, $scopeId): bool {
                if ((string)$assignment['id'] !== (string)$context['role_assignment_id']) {
                    return false;
                }
                $assignmentScopeId = $assignment['scope_assignment_id'] ?? null;
                return $scopeId === null ? $assignmentScopeId === null : (string)$assignmentScopeId === $scopeId;
            }));
        }
        $roleCodes = array_column($assignments, 'role_code');

        if (array_intersect(self::TECHNICAL_MANAGER_ROLES, $roleCodes) !== []) {
            return $this->authorityCache[$cacheKey] = [
                'kind' => 'SYSTEM',
                'is_system_admin' => in_array('SYSTEM_ADMIN', $roleCodes, true),
                'actor_role_code' => null,
                'actor_rank' => null,
                'district_ids' => [],
                'asc_ids' => [],
            ];
        }

        foreach ($assignments as $assignment) {
            $roleCode = (string)$assignment['role_code'];
            $rank = self::OPERATIONAL_ROLE_RANKS[$roleCode] ?? null;
            if ($rank === null || $rank < self::USER_MANAGEMENT_MINIMUM_RANK) {
                continue;
            }

            if (in_array($roleCode, ['NATIONAL_ADMIN', 'NATIONAL_SUBJECT_OFFICER'], true)
                && $assignment['scope_type'] === 'NATIONAL'
                && $assignment['scope_mode'] === 'NATIONAL') {
                return $this->authorityCache[$cacheKey] = [
                    'kind' => $roleCode === 'NATIONAL_ADMIN' ? 'NATIONAL' : 'NATIONAL_SUBJECT',
                    'is_system_admin' => false,
                    'actor_role_code' => $roleCode,
                    'actor_rank' => $rank,
                    'district_ids' => [],
                    'asc_ids' => [],
                ];
            }

            if (in_array($roleCode, ['DISTRICT_ADMIN', 'DISTRICT_SUBJECT_OFFICER'], true)
                && $assignment['scope_type'] === 'DISTRICT'
                && $assignment['scope_mode'] === 'INCLUDE_CHILDREN'
                && $assignment['location_id'] !== null) {
                return $this->authorityCache[$cacheKey] = [
                    'kind' => $roleCode === 'DISTRICT_ADMIN' ? 'DISTRICT' : 'DISTRICT_SUBJECT',
                    'is_system_admin' => false,
                    'actor_role_code' => $roleCode,
                    'actor_rank' => $rank,
                    'district_ids' => [(string)$assignment['location_id']],
                    'asc_ids' => [],
                ];
            }

            if (in_array($roleCode, ['ASC_ADMIN', 'ASC_SUBJECT_OFFICER'], true)
                && $assignment['scope_type'] === 'ASC'
                && $assignment['scope_mode'] === 'EXACT'
                && $assignment['location_id'] !== null) {
                return $this->authorityCache[$cacheKey] = [
                    'kind' => $roleCode === 'ASC_ADMIN' ? 'ASC' : 'ASC_SUBJECT',
                    'is_system_admin' => false,
                    'actor_role_code' => $roleCode,
                    'actor_rank' => $rank,
                    'district_ids' => [],
                    'asc_ids' => [(string)$assignment['location_id']],
                ];
            }
        }

        throw new DomainException('You do not have an active approved role for User Management.');
    }

    public function assertActorPermissions(string $actorId, array $permissions, ?string $date = null): void
    {
        $date ??= date('Y-m-d');
        if ($permissions === []) {
            return;
        }
        $placeholders = implode(',', array_fill(0, count($permissions), '?'));
        $context = Auth::activeContextForUser($actorId);
        if ($context === null && Auth::isCurrentUser($actorId)) {
            throw new DomainException('Choose a role and office before managing users.');
        }
        $assignmentClause = $context === null ? '' : ' AND uar.id=?';
        $sql = "SELECT DISTINCT p.permission_key
                FROM user_account_role uar
                JOIN system_user su ON su.id=uar.user_id
                  AND su.enabled=1 AND su.account_status='ACTIVE' AND su.approval_status='APPROVED'
                JOIN application_role r ON r.id=uar.role_id
                JOIN application_role_permission rp ON rp.role_id=r.id
                JOIN application_permission p ON p.id=rp.permission_id
                WHERE uar.user_id=? AND uar.active=1 AND uar.approval_status='APPROVED'
                  AND uar.effective_from<=? AND (uar.effective_to IS NULL OR uar.effective_to>=?)
                  AND r.active=1 AND r.approval_status='APPROVED' AND p.active=1
                  {$assignmentClause}
                  AND p.permission_key IN ({$placeholders})";
        $stmt = $this->pdo->prepare($sql);
        $params = [$actorId, $date, $date];
        if ($context !== null) {
            $params[] = (string)$context['role_assignment_id'];
        }
        $stmt->execute(array_merge($params, $permissions));
        $actual = array_column($stmt->fetchAll(), 'permission_key');
        foreach ($permissions as $permission) {
            if (!in_array($permission, $actual, true)) {
                throw new DomainException('You do not have permission to perform this action.');
            }
        }
    }

    /** @return array<int,array<string,mixed>> */
    public function manageableRoles(string $actorId): array
    {
        $authority = $this->authority($actorId);
        $roles = $this->pdo->query("SELECT id,role_code,role_name,role_level FROM application_role WHERE active=1 AND assignable=1 AND approval_status='APPROVED' ORDER BY FIELD(role_level,'SYSTEM','NATIONAL','DISTRICT','ASC','ARPA','FARMER','CUSTOM'),role_name")->fetchAll();
        return array_values(array_filter($roles, fn(array $role): bool => $this->roleAllowed($authority, $role)));
    }

    /** @return array<int,array<string,mixed>> */
    public function manageableLocations(string $actorId): array
    {
        $date = date('Y-m-d');
        $authority = $this->authority($actorId, $date);
        if ($authority['kind'] === 'SYSTEM') {
            return $this->locationRows(['DISTRICT', 'ASC'], $date);
        }
        if (in_array($authority['kind'], ['NATIONAL', 'NATIONAL_SUBJECT'], true)) {
            return $this->locationRows(['DISTRICT', 'ASC'], $date);
        }

        $roots = $this->authorityRootLocationIds($authority);
        $placeholders = implode(',', array_fill(0, count($roots), '?'));
        $sql = "WITH RECURSIVE descendants(id) AS (
                    SELECT id FROM location WHERE id IN ({$placeholders})
                    UNION DISTINCT
                    SELECT lr.child_location_id
                    FROM location_relationship lr
                    JOIN descendants d ON d.id=lr.parent_location_id
                    WHERE lr.active=1 AND lr.approval_status='APPROVED'
                      AND lr.effective_from<=? AND (lr.effective_to IS NULL OR lr.effective_to>=?)
                )
                SELECT l.id,l.dad_number,l.name_en,lt.system_key location_type
                FROM descendants d
                JOIN location l ON l.id=d.id
                JOIN location_type lt ON lt.id=l.location_type_id
                WHERE lt.system_key IN ('DISTRICT','ASC')
                  AND l.operational_status='ACTIVE' AND l.approval_status='APPROVED'
                  AND l.effective_from<=? AND (l.effective_to IS NULL OR l.effective_to>=?)
                ORDER BY FIELD(lt.system_key,'DISTRICT','ASC'),l.name_en";
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute(array_merge($roots, [$date,$date,$date,$date]));
        return $stmt->fetchAll();
    }

    /** @return array<int,array{id:string,username:string}> */
    public function manageableUsers(string $actorId, bool $enabledOnly = true): array
    {
        $visibility = $this->activeUserVisibility($actorId);
        $where = ["su.identity_type<>'HISTORICAL'", $visibility['where']];
        if ($enabledOnly) {
            array_unshift($where, "su.enabled=1", "su.account_status='ACTIVE'", "su.approval_status='APPROVED'");
        }
        $sql = $visibility['with'] . 'SELECT su.id,su.username FROM system_user su WHERE ' . implode(' AND ', $where)
            . ' ORDER BY su.username';
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($visibility['params']);
        return $stmt->fetchAll();
    }

    /**
     * Returns a set-based predicate for an outer system_user alias named `su`.
     * Every effective target role must be lower than the active actor role and
     * must carry only compatible, role-linked scopes inside the actor boundary.
     *
     * @return array{with:string,where:string,params:array<int,string>}
     */
    public function activeUserVisibility(string $actorId, ?string $date = null): array
    {
        $date ??= date('Y-m-d');
        $authority = $this->authority($actorId, $date);
        $effectiveRole = "uar.active=1 AND uar.approval_status='APPROVED'
            AND uar.effective_from<=(SELECT as_of_date FROM user_visibility_date)
            AND (uar.effective_to IS NULL OR uar.effective_to>=(SELECT as_of_date FROM user_visibility_date))
            AND r.active=1 AND r.approval_status='APPROVED'";

        if ($authority['kind'] === 'SYSTEM') {
            $where = "EXISTS (
                SELECT 1 FROM user_account_role uar
                JOIN application_role r ON r.id=uar.role_id
                WHERE uar.user_id=su.id AND {$effectiveRole}
            )";
            if (!$authority['is_system_admin']) {
                $where .= " AND NOT EXISTS (
                    SELECT 1 FROM user_account_role uar
                    JOIN application_role r ON r.id=uar.role_id
                    WHERE uar.user_id=su.id AND {$effectiveRole} AND r.role_level='SYSTEM'
                )";
            }
            return [
                'with' => 'WITH user_visibility_date(as_of_date) AS (SELECT CAST(? AS DATE)) ',
                'where' => $where,
                'params' => [$date],
            ];
        }

        $actorRank = (int)($authority['actor_rank'] ?? 0);
        $allowedRoles = array_keys(array_filter(
            self::OPERATIONAL_ROLE_RANKS,
            static fn(int $rank): bool => $rank < $actorRank
        ));
        if ($allowedRoles === []) {
            return ['with' => '', 'where' => '1=0', 'params' => []];
        }

        $roleRows = implode(' UNION ALL ', array_fill(0, count($allowedRoles), 'SELECT ?'));
        $ctes = [
            'user_visibility_date(as_of_date) AS (SELECT CAST(? AS DATE))',
            "manageable_user_roles(role_code) AS ({$roleRows})",
        ];
        $params = array_merge([$date], $allowedRoles);
        $restrictLocations = in_array($authority['kind'], ['ASC', 'ASC_SUBJECT', 'DISTRICT', 'DISTRICT_SUBJECT'], true);
        if ($restrictLocations) {
            $roots = $this->authorityRootLocationIds($authority);
            if ($roots === []) {
                return ['with' => '', 'where' => '1=0', 'params' => []];
            }
            $rootPlaceholders = implode(',', array_fill(0, count($roots), '?'));
            $ctes[] = "manageable_user_locations(id) AS (
                SELECT l.id FROM location l
                WHERE l.id IN ({$rootPlaceholders})
                  AND l.operational_status='ACTIVE' AND l.approval_status='APPROVED'
                  AND l.effective_from<=(SELECT as_of_date FROM user_visibility_date)
                  AND (l.effective_to IS NULL OR l.effective_to>=(SELECT as_of_date FROM user_visibility_date))
                UNION DISTINCT
                SELECT lr.child_location_id
                FROM location_relationship lr
                JOIN manageable_user_locations mul ON mul.id=lr.parent_location_id
                WHERE lr.active=1 AND lr.approval_status='APPROVED'
                  AND lr.effective_from<=(SELECT as_of_date FROM user_visibility_date)
                  AND (lr.effective_to IS NULL OR lr.effective_to>=(SELECT as_of_date FROM user_visibility_date))
            )";
            array_push($params, ...$roots);
        }

        $scopeEffective = "uas.active=1 AND uas.approval_status='APPROVED'
            AND uas.effective_from<=(SELECT as_of_date FROM user_visibility_date)
            AND (uas.effective_to IS NULL OR uas.effective_to>=(SELECT as_of_date FROM user_visibility_date))";
        $locationBoundary = $restrictLocations
            ? ' AND uas.location_id IN (SELECT id FROM manageable_user_locations)'
            : '';
        $validLocation = "EXISTS (
            SELECT 1 FROM location l
            JOIN location_type lt ON lt.id=l.location_type_id
            WHERE l.id=uas.location_id AND lt.system_key=uas.scope_type
              AND l.operational_status='ACTIVE' AND l.approval_status='APPROVED'
              AND l.effective_from<=(SELECT as_of_date FROM user_visibility_date)
              AND (l.effective_to IS NULL OR l.effective_to>=(SELECT as_of_date FROM user_visibility_date))
        )";
        $compatibleScope = "(
            (r.role_code='FARMER' AND uas.scope_type='ASC' AND uas.scope_mode='EXACT'
                AND uas.location_id IS NOT NULL AND {$validLocation}{$locationBoundary})
            OR (r.role_code='ARPA_OFFICER' AND uas.scope_type='ARPA_DIVISION' AND uas.scope_mode='EXACT'
                AND uas.location_id IS NOT NULL AND {$validLocation}{$locationBoundary})
            OR (r.role_code IN ('ASC_SUBJECT_OFFICER','ASC_ADMIN') AND uas.scope_type='ASC' AND uas.scope_mode='EXACT'
                AND uas.location_id IS NOT NULL AND {$validLocation}{$locationBoundary})
            OR (r.role_code IN ('DISTRICT_SUBJECT_OFFICER','DISTRICT_ADMIN') AND uas.scope_type='DISTRICT' AND uas.scope_mode='INCLUDE_CHILDREN'
                AND uas.location_id IS NOT NULL AND {$validLocation}{$locationBoundary})
            OR (r.role_code IN ('NATIONAL_SUBJECT_OFFICER','NATIONAL_ADMIN') AND uas.scope_type='NATIONAL'
                AND uas.scope_mode='NATIONAL' AND uas.location_id IS NULL)
        )";
        $validScope = "SELECT 1 FROM user_account_scope uas
            WHERE uas.role_assignment_id=uar.id AND uas.user_id=uar.user_id
              AND {$scopeEffective} AND {$compatibleScope}";
        $invalidScope = "SELECT 1 FROM user_account_scope uas
            WHERE uas.role_assignment_id=uar.id AND uas.user_id=uar.user_id
              AND {$scopeEffective} AND NOT {$compatibleScope}";

        $where = "EXISTS (
                SELECT 1 FROM user_account_role uar
                JOIN application_role r ON r.id=uar.role_id
                WHERE uar.user_id=su.id AND {$effectiveRole}
            )
            AND NOT EXISTS (
                SELECT 1 FROM user_account_role uar
                JOIN application_role r ON r.id=uar.role_id
                WHERE uar.user_id=su.id AND {$effectiveRole}
                  AND (
                    NOT EXISTS (SELECT 1 FROM manageable_user_roles mur WHERE mur.role_code=r.role_code)
                    OR NOT EXISTS ({$validScope})
                    OR EXISTS ({$invalidScope})
                  )
            )";

        return [
            'with' => 'WITH RECURSIVE ' . implode(', ', $ctes) . ' ',
            'where' => $where,
            'params' => $params,
        ];
    }

    /**
     * Returns a set-based predicate for disabled users using their latest
     * approved role/scope history. Imported identities without DEMS access
     * assignments are evaluated from their preserved legacy role and location.
     *
     * @return array{with:string,where:string,params:array<int,string>}
     */
    public function inactiveUserVisibility(string $actorId, ?string $date = null): array
    {
        $date ??= date('Y-m-d');
        $authority = $this->authority($actorId, $date);
        $latestRole = "uar.approval_status='APPROVED' AND r.approval_status='APPROVED'
            AND NOT EXISTS (
                SELECT 1 FROM user_account_role newer
                WHERE newer.user_id=uar.user_id AND newer.approval_status='APPROVED'
                  AND COALESCE(newer.effective_to,'9999-12-31')>COALESCE(uar.effective_to,'9999-12-31')
            )";

        if ($authority['kind'] === 'SYSTEM') {
            $where = "(
                EXISTS (SELECT 1 FROM user_account_role uar JOIN application_role r ON r.id=uar.role_id
                        WHERE uar.user_id=su.id AND {$latestRole})
                OR (su.historical_identity=1 AND EXISTS (SELECT 1 FROM legacy_user_reference lurv WHERE lurv.system_user_id=su.id))
            )";
            if (!$authority['is_system_admin']) {
                $where .= " AND NOT EXISTS (
                    SELECT 1 FROM user_account_role uar JOIN application_role r ON r.id=uar.role_id
                    WHERE uar.user_id=su.id AND {$latestRole} AND r.role_level='SYSTEM'
                )";
            }
            return ['with' => '', 'where' => $where, 'params' => []];
        }

        $actorRank = (int)($authority['actor_rank'] ?? 0);
        $allowedRoles = array_keys(array_filter(
            self::OPERATIONAL_ROLE_RANKS,
            static fn(int $rank): bool => $rank < $actorRank
        ));
        if ($allowedRoles === []) {
            return ['with' => '', 'where' => '1=0', 'params' => []];
        }

        $roleRows = implode(' UNION ALL ', array_fill(0, count($allowedRoles), 'SELECT ?'));
        $ctes = [
            'inactive_user_visibility_date(as_of_date) AS (SELECT CAST(? AS DATE))',
            "manageable_inactive_roles(role_code) AS ({$roleRows})",
        ];
        $params = array_merge([$date], $allowedRoles);
        $restrictLocations = in_array($authority['kind'], ['ASC', 'ASC_SUBJECT', 'DISTRICT', 'DISTRICT_SUBJECT'], true);
        if ($restrictLocations) {
            $roots = $this->authorityRootLocationIds($authority);
            if ($roots === []) {
                return ['with' => '', 'where' => '1=0', 'params' => []];
            }
            $rootPlaceholders = implode(',', array_fill(0, count($roots), '?'));
            $ctes[] = "manageable_inactive_locations(id) AS (
                SELECT l.id FROM location l
                WHERE l.id IN ({$rootPlaceholders})
                  AND l.operational_status='ACTIVE' AND l.approval_status='APPROVED'
                  AND l.effective_from<=(SELECT as_of_date FROM inactive_user_visibility_date)
                  AND (l.effective_to IS NULL OR l.effective_to>=(SELECT as_of_date FROM inactive_user_visibility_date))
                UNION DISTINCT
                SELECT lr.child_location_id
                FROM location_relationship lr
                JOIN manageable_inactive_locations mil ON mil.id=lr.parent_location_id
                WHERE lr.active=1 AND lr.approval_status='APPROVED'
                  AND lr.effective_from<=(SELECT as_of_date FROM inactive_user_visibility_date)
                  AND (lr.effective_to IS NULL OR lr.effective_to>=(SELECT as_of_date FROM inactive_user_visibility_date))
            )";
            array_push($params, ...$roots);
        }

        $latestScope = "uas.approval_status='APPROVED' AND NOT EXISTS (
            SELECT 1 FROM user_account_scope newer_scope
            WHERE newer_scope.role_assignment_id=uas.role_assignment_id
              AND newer_scope.user_id=uas.user_id AND newer_scope.approval_status='APPROVED'
              AND COALESCE(newer_scope.effective_to,'9999-12-31')>COALESCE(uas.effective_to,'9999-12-31')
        )";
        $locationBoundary = $restrictLocations
            ? ' AND uas.location_id IN (SELECT id FROM manageable_inactive_locations)'
            : '';
        $validLocation = "EXISTS (
            SELECT 1 FROM location l JOIN location_type lt ON lt.id=l.location_type_id
            WHERE l.id=uas.location_id AND lt.system_key=uas.scope_type
              AND l.operational_status='ACTIVE' AND l.approval_status='APPROVED'
              AND l.effective_from<=(SELECT as_of_date FROM inactive_user_visibility_date)
              AND (l.effective_to IS NULL OR l.effective_to>=(SELECT as_of_date FROM inactive_user_visibility_date))
        )";
        $compatibleScope = "(
            (r.role_code='FARMER' AND uas.scope_type='ASC' AND uas.scope_mode='EXACT'
                AND uas.location_id IS NOT NULL AND {$validLocation}{$locationBoundary})
            OR (r.role_code='ARPA_OFFICER' AND uas.scope_type='ARPA_DIVISION' AND uas.scope_mode='EXACT'
                AND uas.location_id IS NOT NULL AND {$validLocation}{$locationBoundary})
            OR (r.role_code IN ('ASC_SUBJECT_OFFICER','ASC_ADMIN') AND uas.scope_type='ASC' AND uas.scope_mode='EXACT'
                AND uas.location_id IS NOT NULL AND {$validLocation}{$locationBoundary})
            OR (r.role_code IN ('DISTRICT_SUBJECT_OFFICER','DISTRICT_ADMIN') AND uas.scope_type='DISTRICT'
                AND uas.scope_mode='INCLUDE_CHILDREN' AND uas.location_id IS NOT NULL AND {$validLocation}{$locationBoundary})
            OR (r.role_code IN ('NATIONAL_SUBJECT_OFFICER','NATIONAL_ADMIN') AND uas.scope_type='NATIONAL'
                AND uas.scope_mode='NATIONAL' AND uas.location_id IS NULL)
        )";
        $validScope = "SELECT 1 FROM user_account_scope uas
            WHERE uas.role_assignment_id=uar.id AND uas.user_id=uar.user_id
              AND {$latestScope} AND {$compatibleScope}";
        $invalidScope = "SELECT 1 FROM user_account_scope uas
            WHERE uas.role_assignment_id=uar.id AND uas.user_id=uar.user_id
              AND {$latestScope} AND NOT {$compatibleScope}";
        $assignmentBranch = "EXISTS (
                SELECT 1 FROM user_account_role uar JOIN application_role r ON r.id=uar.role_id
                WHERE uar.user_id=su.id AND {$latestRole}
            )
            AND NOT EXISTS (
                SELECT 1 FROM user_account_role uar JOIN application_role r ON r.id=uar.role_id
                WHERE uar.user_id=su.id AND {$latestRole}
                  AND (
                    NOT EXISTS (SELECT 1 FROM manageable_inactive_roles mir WHERE mir.role_code=r.role_code)
                    OR NOT EXISTS ({$validScope})
                    OR EXISTS ({$invalidScope})
                  )
            )";

        $legacyRoleCode = "CASE LOWER(TRIM(lurv.legacy_role_name))
            WHEN 'farmer' THEN 'FARMER'
            WHEN 'arpa' THEN 'ARPA_OFFICER'
            WHEN 'asc subject officer' THEN 'ASC_SUBJECT_OFFICER'
            WHEN 'asc admin' THEN 'ASC_ADMIN'
            WHEN 'district subject officer' THEN 'DISTRICT_SUBJECT_OFFICER'
            WHEN 'district admin' THEN 'DISTRICT_ADMIN'
            WHEN 'head office subject officer' THEN 'NATIONAL_SUBJECT_OFFICER'
            WHEN 'head office admin' THEN 'NATIONAL_ADMIN'
            ELSE NULL END";
        $legacyLocation = $restrictLocations
            ? " AND EXISTS (
                    SELECT 1 FROM legacy_user_organization_context luc
                    WHERE luc.legacy_user_reference_id=lurv.id
                      AND luc.location_id IN (SELECT id FROM manageable_inactive_locations)
                )
                AND NOT EXISTS (
                    SELECT 1 FROM legacy_user_organization_context luc
                    WHERE luc.legacy_user_reference_id=lurv.id
                      AND (luc.location_id IS NULL OR luc.location_id NOT IN (SELECT id FROM manageable_inactive_locations))
                )"
            : '';
        $legacyBranch = "NOT EXISTS (
                SELECT 1 FROM user_account_role previous_role
                WHERE previous_role.user_id=su.id AND previous_role.approval_status='APPROVED'
            )
            AND EXISTS (
                SELECT 1 FROM legacy_user_reference lurv
                WHERE lurv.system_user_id=su.id
                  AND EXISTS (SELECT 1 FROM manageable_inactive_roles mir WHERE mir.role_code={$legacyRoleCode})
                  {$legacyLocation}
            )
            AND NOT EXISTS (
                SELECT 1 FROM legacy_user_reference lurv
                WHERE lurv.system_user_id=su.id
                  AND NOT EXISTS (SELECT 1 FROM manageable_inactive_roles mir WHERE mir.role_code={$legacyRoleCode})
            )";

        return [
            'with' => 'WITH RECURSIVE ' . implode(', ', $ctes) . ' ',
            'where' => "(({$assignmentBranch}) OR ({$legacyBranch}))",
            'params' => $params,
        ];
    }

    /** @return array<int,array{id:string,dad_number:string,name_en:string,official_code:?string,location_type:string}> */
    public function searchAssignableLocations(string $actorId,string $roleId,string $query='',int $limit=100,?string $date=null):array
    {
        $date ??= date('Y-m-d');
        $limit = max(1, min(100, $limit));
        $authority = $this->authority($actorId, $date);
        $stmt = $this->pdo->prepare("SELECT id,role_code,role_name,role_level,protected_role,assignable FROM application_role WHERE id=? AND active=1 AND assignable=1 AND approval_status='APPROVED'");
        $stmt->execute([$roleId]);
        $role = $stmt->fetch();
        if (!$role) {
            throw new DomainException('The selected role is not available.');
        }
        if (!$this->roleAllowed($authority, $role)) {
            throw new DomainException($this->roleDeniedMessage($authority));
        }
        $type = ['DISTRICT'=>'DISTRICT','ASC'=>'ASC','ARPA'=>'ARPA_DIVISION','FARMER'=>'ASC'][(string)$role['role_level']] ?? null;
        if ($type === null) {
            return [];
        }
        $query = trim($query);
        $searchSql = $query === '' ? '' : " AND CONCAT_WS(' ',l.dad_number,l.name_en,l.official_code) LIKE ?";
        $searchParams = $query === '' ? [] : ['%' . $query . '%'];

        if (in_array($authority['kind'], ['SYSTEM', 'NATIONAL', 'NATIONAL_SUBJECT'], true)) {
            $sql = "SELECT l.id,l.dad_number,l.name_en,l.official_code,lt.system_key location_type
                    FROM location l JOIN location_type lt ON lt.id=l.location_type_id
                    WHERE lt.system_key=? AND l.operational_status='ACTIVE' AND l.approval_status='APPROVED'
                      AND l.effective_from<=? AND (l.effective_to IS NULL OR l.effective_to>=?){$searchSql}
                    ORDER BY l.name_en,l.dad_number LIMIT {$limit}";
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute(array_merge([$type,$date,$date],$searchParams));
            return $stmt->fetchAll();
        }

        $roots = $this->authorityRootLocationIds($authority);
        $placeholders = implode(',', array_fill(0, count($roots), '?'));
        $sql = "WITH RECURSIVE descendants(id) AS (
                    SELECT id FROM location WHERE id IN ({$placeholders})
                    UNION DISTINCT
                    SELECT lr.child_location_id FROM location_relationship lr
                    JOIN descendants d ON d.id=lr.parent_location_id
                    WHERE lr.active=1 AND lr.approval_status='APPROVED'
                      AND lr.effective_from<=? AND (lr.effective_to IS NULL OR lr.effective_to>=?)
                )
                SELECT l.id,l.dad_number,l.name_en,l.official_code,lt.system_key location_type
                FROM descendants d JOIN location l ON l.id=d.id
                JOIN location_type lt ON lt.id=l.location_type_id
                WHERE lt.system_key=? AND l.operational_status='ACTIVE' AND l.approval_status='APPROVED'
                  AND l.effective_from<=? AND (l.effective_to IS NULL OR l.effective_to>=?){$searchSql}
                ORDER BY l.name_en,l.dad_number LIMIT {$limit}";
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute(array_merge($roots,[$date,$date,$type,$date,$date],$searchParams));
        return $stmt->fetchAll();
    }

    public function assertCanManageUser(string $actorId, string $targetUserId, ?string $date = null): void
    {
        if (!$this->canManageUser($actorId, $targetUserId, $date)) {
            throw new DomainException('You do not have permission to manage this user.');
        }
    }

    public function canManageUser(string $actorId, string $targetUserId, ?string $date = null): bool
    {
        $date ??= date('Y-m-d');
        try {
            $authority = $this->authority($actorId, $date);
        } catch (DomainException) {
            return false;
        }
        $account = $this->pdo->prepare('SELECT enabled,account_status,approval_status FROM system_user WHERE id=?');
        $account->execute([$targetUserId]);
        $accountRow = $account->fetch();
        if (!$accountRow) {
            return false;
        }
        if ((int)$accountRow['enabled'] !== 1 || (string)$accountRow['account_status'] !== 'ACTIVE') {
            try {
                $visibility = $this->inactiveUserVisibility($actorId, $date);
                $sql = $visibility['with'] . 'SELECT COUNT(*) FROM system_user su WHERE su.id=? AND '
                    . $visibility['where'];
                $stmt = $this->pdo->prepare($sql);
                $stmt->execute(array_merge($visibility['params'], [$targetUserId]));
                return (int)$stmt->fetchColumn() === 1;
            } catch (DomainException) {
                return false;
            }
        }
        $assignments = $this->effectiveAssignments($targetUserId, $date);
        if ($assignments === []) {
            return true;
        }
        foreach ($assignments as $assignment) {
            $level = (string)$assignment['role_level'];
            if ($authority['kind'] === 'SYSTEM') {
                if ($level === 'SYSTEM' && !$authority['is_system_admin']) {
                    return false;
                }
                continue;
            }
            if (!$this->roleAllowed($authority, $assignment)) {
                return false;
            }
            if ($level === 'CUSTOM') {
                continue;
            }
            $scopes = $this->effectiveScopes((string)$assignment['id'], $date);
            if ($scopes === []) {
                return false;
            }
            foreach ($scopes as $scope) {
                if (!$this->scopeMatchesRole($assignment, $scope, $authority, $date)) {
                    return false;
                }
            }
        }
        return true;
    }

    /**
     * Validates both role hierarchy and the role's own scope. The returned values
     * are safe to persist as one access assignment.
     *
     * @return array{role:array<string,mixed>,scope_type:?string,scope_mode:?string,location_id:?string}
     */
    public function validateAssignment(string $actorId, string $roleId, ?string $locationId, string $effectiveFrom): array
    {
        $effectiveFrom=$this->date($effectiveFrom);
        $authority = $this->authority($actorId, $effectiveFrom);
        $stmt = $this->pdo->prepare("SELECT id,role_code,role_name,role_level,protected_role,assignable FROM application_role WHERE id=? AND active=1 AND assignable=1 AND approval_status='APPROVED'");
        $stmt->execute([$roleId]);
        $role = $stmt->fetch();
        if (!$role) {
            throw new DomainException('The selected role is not available.');
        }
        if (!$this->roleAllowed($authority, $role)) {
            throw new DomainException($this->roleDeniedMessage($authority));
        }
        return ['role' => $role] + $this->validateScope($authority, (string)$role['role_level'], $locationId, $effectiveFrom);
    }

    /** @return array{role:array<string,mixed>,scope_type:?string,scope_mode:?string,location_id:?string} */
    public function validateRoleCodeAssignment(string $actorId, string $roleCode, ?string $locationId, string $effectiveFrom): array
    {
        $stmt = $this->pdo->prepare("SELECT id FROM application_role WHERE role_code=? AND active=1 AND assignable=1 AND approval_status='APPROVED'");
        $stmt->execute([$roleCode]);
        $roleId = $stmt->fetchColumn();
        if (!$roleId) {
            throw new DomainException('The selected role is not available.');
        }
        return $this->validateAssignment($actorId, (string)$roleId, $locationId, $effectiveFrom);
    }

    /** @return array{role_id:string,user_id:string,role_level:string,role_code:string} */
    public function assertCanManageRoleAssignment(string $actorId, string $assignmentId): array
    {
        $stmt = $this->pdo->prepare("SELECT uar.id,uar.user_id,uar.role_id,r.role_level,r.role_code FROM user_account_role uar JOIN application_role r ON r.id=uar.role_id WHERE uar.id=?");
        $stmt->execute([$assignmentId]);
        $assignment = $stmt->fetch();
        if (!$assignment) {
            throw new DomainException('The selected user role was not found.');
        }
        $authority = $this->authority($actorId);
        if (!$this->roleAllowed($authority, $assignment)) {
            throw new DomainException($this->roleDeniedMessage($authority));
        }
        if (in_array((string)$assignment['role_level'], ['NATIONAL', 'DISTRICT', 'ASC', 'ARPA'], true)) {
            $scopeStmt = $this->pdo->prepare('SELECT location_id FROM user_account_scope WHERE role_assignment_id=? AND user_id=?');
            $scopeStmt->execute([$assignmentId, $assignment['user_id']]);
            $scopes = $scopeStmt->fetchAll();
            if ($scopes === []) {
                throw new DomainException('This role does not have a valid location assigned.');
            }
            foreach ($scopes as $scope) {
                $this->validateAssignment($actorId, (string)$assignment['role_id'], $scope['location_id'], date('Y-m-d'));
            }
        }
        return $assignment;
    }

    /** @return array<string,mixed> */
    public function assertCanManageScopeAssignment(string $actorId, string $scopeId): array
    {
        $stmt = $this->pdo->prepare("SELECT uas.*,uar.role_id,r.role_level,r.role_code FROM user_account_scope uas JOIN user_account_role uar ON uar.id=uas.role_assignment_id AND uar.user_id=uas.user_id JOIN application_role r ON r.id=uar.role_id WHERE uas.id=?");
        $stmt->execute([$scopeId]);
        $scope = $stmt->fetch();
        if (!$scope) {
            throw new DomainException('The assigned location was not found.');
        }
        $this->assertCanManageRoleAssignment($actorId, (string)$scope['role_assignment_id']);
        $validated = $this->validateAssignment($actorId, (string)$scope['role_id'], $scope['location_id'], (string)$scope['effective_from']);
        if ($validated['scope_type'] !== $scope['scope_type'] || $validated['scope_mode'] !== $scope['scope_mode']) {
            throw new DomainException('The selected location is not valid for this role.');
        }
        return $scope;
    }

    /** @return array<int,string> */
    public function manageableRoleAssignmentIds(string $actorId): array
    {
        $ids = [];
        foreach ($this->pdo->query('SELECT id FROM user_account_role')->fetchAll() as $row) {
            try {
                $this->assertCanManageRoleAssignment($actorId, (string)$row['id']);
                $ids[] = (string)$row['id'];
            } catch (DomainException) {
            }
        }
        return $ids;
    }

    /** @return array<int,string> */
    public function manageableScopeAssignmentIds(string $actorId): array
    {
        $ids = [];
        foreach ($this->pdo->query('SELECT id FROM user_account_scope')->fetchAll() as $row) {
            try {
                $this->assertCanManageScopeAssignment($actorId, (string)$row['id']);
                $ids[] = (string)$row['id'];
            } catch (DomainException) {
            }
        }
        return $ids;
    }

    public function createDraftAssignment(string $actorId, string $userId, string $roleId, ?string $locationId, string $from, ?string $to, string $reason, ?string $reference, ?string $replacesAssignmentId = null): string
    {
        $this->assertActorPermissions($actorId, ['user.assign-role', 'user.assign-scope']);
        $from = $this->date($from);
        $this->assertTargetMayReceiveAssignment($actorId,$userId,$from);
        $to = $to === null || trim($to) === '' ? null : $this->date($to);
        if ($to !== null && $to < $from) {
            throw new DomainException('The end date cannot be before the start date.');
        }
        $reason = trim($reason);
        if ($reason === '') {
            throw new DomainException('Reason is required.');
        }
        $validated = $this->validateAssignment($actorId, $roleId, $locationId, $from);
        $replacesAssignmentId = $this->optional($replacesAssignmentId);
        if ($replacesAssignmentId !== null) {
            $old = $this->assertCanManageRoleAssignment($actorId, $replacesAssignmentId);
            if ((string)$old['user_id'] !== $userId) {
                throw new DomainException('A replacement assignment must belong to the same user.');
            }
            $oldStmt = $this->pdo->prepare('SELECT effective_from FROM user_account_role WHERE id=?');
            $oldStmt->execute([$replacesAssignmentId]);
            $oldFrom = (string)$oldStmt->fetchColumn();
            if ($from <= $oldFrom) {
                throw new DomainException('A replacement must start after the original assignment.');
            }
            $pending = $this->pdo->prepare("SELECT COUNT(*) FROM user_account_role WHERE replaces_assignment_id=? AND approval_status IN('DRAFT','SUBMITTED')");
            $pending->execute([$replacesAssignmentId]);
            if ((int)$pending->fetchColumn() > 0) {
                throw new DomainException('This assignment already has a pending transfer or role change.');
            }
        }
        $duplicateSql="SELECT COUNT(*) FROM user_account_role uar LEFT JOIN user_account_scope uas ON uas.role_assignment_id=uar.id AND uas.user_id=uar.user_id WHERE uar.user_id=? AND uar.role_id=? AND uar.approval_status IN('DRAFT','SUBMITTED','APPROVED') AND uar.effective_from<=COALESCE(?,'9999-12-31') AND (uar.effective_to IS NULL OR uar.effective_to>=?) AND ((? IS NULL AND uas.location_id IS NULL) OR uas.location_id=?)";
        $duplicateParams=[$userId,$roleId,$to,$from,$validated['location_id'],$validated['location_id']];
        if($replacesAssignmentId!==null){$duplicateSql.=' AND uar.id<>?';$duplicateParams[]=$replacesAssignmentId;}
        $duplicate=$this->pdo->prepare($duplicateSql);$duplicate->execute($duplicateParams);
        if((int)$duplicate->fetchColumn()>0)throw new DomainException('This user already has this role at the selected location for the same period.');
        $assignmentId = $this->uuid();

        $this->transaction(function () use ($actorId, $userId, $roleId, $from, $to, $reason, $reference, $validated, $assignmentId, $replacesAssignmentId): void {
            $this->pdo->prepare("INSERT INTO user_account_role(id,user_id,role_id,replaces_assignment_id,effective_from,effective_to,approval_status,active,reason,official_reference,created_by,created_at) VALUES(?,?,?,?,?,?,'DRAFT',0,?,?,?,NOW())")
                ->execute([$assignmentId, $userId, $roleId, $replacesAssignmentId, $from, $to, $reason, $this->optional($reference), $actorId]);
            if ($validated['scope_type'] !== null) {
                $this->pdo->prepare("INSERT INTO user_account_scope(id,user_id,role_assignment_id,scope_type,scope_mode,location_id,effective_from,effective_to,approval_status,active,reason,official_reference,created_by,created_at) VALUES(?,?,?,?,?,?,?,?, 'DRAFT',0,?,?,?,NOW())")
                    ->execute([$this->uuid(), $userId, $assignmentId, $validated['scope_type'], $validated['scope_mode'], $validated['location_id'], $from, $to, $reason, $this->optional($reference), $actorId]);
            }
        });
        return $assignmentId;
    }

    public function createSubmittedAssignment(string $actorId, string $userId, string $roleId, ?string $locationId, string $from, ?string $to, string $reason, ?string $reference, ?string $replacesAssignmentId = null): string
    {
        return $this->transaction(function () use ($actorId, $userId, $roleId, $locationId, $from, $to, $reason, $reference, $replacesAssignmentId): string {
            $id = $this->createDraftAssignment($actorId, $userId, $roleId, $locationId, $from, $to, $reason, $reference, $replacesAssignmentId);
            $this->submitAssignment($actorId, $id);
            return $id;
        });
    }

    public function endAssignment(string $actorId, string $assignmentId, string $effectiveTo, string $reason, ?string $reference): void
    {
        $this->assertActorPermissions($actorId, ['user.revoke-role', 'user.revoke-scope']);
        $assignment = $this->assertCanManageRoleAssignment($actorId, $assignmentId);
        $effectiveTo = $this->date($effectiveTo);
        $reason = trim($reason);
        if ($reason === '') {
            throw new DomainException('End reason is required.');
        }
        $stmt = $this->pdo->prepare('SELECT effective_from,effective_to,active FROM user_account_role WHERE id=? FOR UPDATE');
        $this->transaction(function () use ($stmt, $assignmentId, $effectiveTo, $reason, $reference, $actorId, $assignment): void {
            $stmt->execute([$assignmentId]);
            $current = $stmt->fetch();
            if (!$current || $effectiveTo < (string)$current['effective_from']) {
                throw new DomainException('The assignment end date cannot be before its start date.');
            }
            if ($current['effective_to'] !== null && $effectiveTo > (string)$current['effective_to']) {
                throw new DomainException('An ended assignment cannot be extended through this action.');
            }
            $active = $effectiveTo < date('Y-m-d') ? 0 : (int)$current['active'];
            $this->pdo->prepare("UPDATE user_account_scope SET effective_to=CASE WHEN effective_to IS NULL OR effective_to>? THEN ? ELSE effective_to END,active=CASE WHEN ?<CURRENT_DATE() THEN 0 ELSE active END,action_reason=CONCAT_WS(' | ',NULLIF(action_reason,''),?) WHERE role_assignment_id=? AND user_id=?")
                ->execute([$effectiveTo,$effectiveTo,$effectiveTo,$reason,$assignmentId,$assignment['user_id']]);
            $this->pdo->prepare("UPDATE user_account_role SET effective_to=?,active=?,reason=CONCAT_WS(' | ',NULLIF(reason,''),?),official_reference=COALESCE(?,official_reference) WHERE id=?")
                ->execute([$effectiveTo,$active,$reason,$this->optional($reference),$assignmentId]);
            $this->pdo->prepare("INSERT INTO audit_event(actor_user_id,action_key,target_type,target_id,details_json,severity,created_at) VALUES(?,'user.role.end','USER_ROLE',?,?,'INFO',NOW())")
                ->execute([$actorId,$assignmentId,json_encode(['effective_to'=>$effectiveTo,'reason'=>$reason,'official_reference'=>$this->optional($reference)],JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR)]);
        });
    }

    public function updateRoleEffectiveFrom(string $actorId, string $assignmentId, string $effectiveFrom): void
    {
        $this->assertActorPermissions($actorId, ['user.assign-role', 'user.assign-scope']);
        $effectiveFrom = $this->date($effectiveFrom);
        if ($effectiveFrom < self::OPERATIONAL_ACCESS_BASELINE_DATE) {
            throw new DomainException('The start date cannot be before 01 January 2025.');
        }
        $this->assertCanManageRoleAssignment($actorId, $assignmentId);

        $this->transaction(function () use ($actorId, $assignmentId, $effectiveFrom): void {
            $stmt = $this->pdo->prepare("SELECT uar.*,r.role_code,r.role_name,r.role_level,su.username,su.display_name,
                    su.enabled user_enabled,su.account_status user_account_status,su.approval_status user_approval_status
                FROM user_account_role uar
                JOIN application_role r ON r.id=uar.role_id
                JOIN system_user su ON su.id=uar.user_id
                WHERE uar.id=? FOR UPDATE");
            $stmt->execute([$assignmentId]);
            $assignment = $stmt->fetch();
            if (!$assignment) {
                throw new DomainException('The selected user role was not found.');
            }
            if ((int)$assignment['active'] !== 1 || (string)$assignment['approval_status'] !== 'APPROVED'
                || (int)$assignment['user_enabled'] !== 1 || (string)$assignment['user_account_status'] !== 'ACTIVE'
                || (string)$assignment['user_approval_status'] !== 'APPROVED') {
                throw new DomainException('Only a current approved user role can be updated.');
            }

            $oldEffectiveFrom = (string)$assignment['effective_from'];
            if ($effectiveFrom === $oldEffectiveFrom) {
                return;
            }
            if ($assignment['effective_to'] !== null && $effectiveFrom > (string)$assignment['effective_to']) {
                throw new DomainException('The start date cannot be after the end date.');
            }

            $scopeStmt = $this->pdo->prepare("SELECT uas.*,l.dad_number,l.name_en
                FROM user_account_scope uas
                LEFT JOIN location l ON l.id=uas.location_id
                WHERE uas.role_assignment_id=? AND uas.user_id=? FOR UPDATE");
            $scopeStmt->execute([$assignmentId, $assignment['user_id']]);
            $scopes = $scopeStmt->fetchAll();
            foreach ($scopes as $scope) {
                $scopeFrom = (string)$scope['effective_from'];
                $nextScopeFrom = $scopeFrom === $oldEffectiveFrom ? $effectiveFrom : $scopeFrom;
                if ($nextScopeFrom < $effectiveFrom) {
                    throw new DomainException("The location dates must be within the role's start and end dates.");
                }
                if ($scope['effective_to'] !== null && $nextScopeFrom > (string)$scope['effective_to']) {
                    throw new DomainException('The new start date would make an assigned location period invalid.');
                }
            }

            $overlap = $this->pdo->prepare("SELECT COUNT(DISTINCT other.id)
                FROM user_account_role other
                WHERE other.id<>? AND other.user_id=? AND other.role_id=?
                  AND other.approval_status IN ('DRAFT','SUBMITTED','APPROVED')
                  AND other.effective_from<=COALESCE(?,'9999-12-31')
                  AND (other.effective_to IS NULL OR other.effective_to>=?)
                  AND (
                    (NOT EXISTS (SELECT 1 FROM user_account_scope own_scope WHERE own_scope.role_assignment_id=?)
                     AND NOT EXISTS (SELECT 1 FROM user_account_scope other_scope WHERE other_scope.role_assignment_id=other.id))
                    OR EXISTS (
                        SELECT 1 FROM user_account_scope own_scope
                        JOIN user_account_scope other_scope ON other_scope.role_assignment_id=other.id
                          AND other_scope.scope_type=own_scope.scope_type
                          AND other_scope.scope_mode=own_scope.scope_mode
                          AND other_scope.location_id<=>own_scope.location_id
                        WHERE own_scope.role_assignment_id=?
                    )
                  )");
            $overlap->execute([
                $assignmentId,
                $assignment['user_id'],
                $assignment['role_id'],
                $assignment['effective_to'],
                $effectiveFrom,
                $assignmentId,
                $assignmentId,
            ]);
            if ((int)$overlap->fetchColumn() > 0) {
                throw new DomainException('The new start date overlaps another assignment for the same role and location.');
            }

            $updatedScopeIds = [];
            foreach ($scopes as $scope) {
                if ((string)$scope['effective_from'] === $oldEffectiveFrom) {
                    $updatedScopeIds[] = (string)$scope['id'];
                }
            }
            if ($updatedScopeIds !== []) {
                $placeholders = implode(',', array_fill(0, count($updatedScopeIds), '?'));
                $updateScopes = $this->pdo->prepare("UPDATE user_account_scope SET effective_from=?
                    WHERE role_assignment_id=? AND user_id=? AND effective_from=? AND id IN ({$placeholders})");
                $updateScopes->execute(array_merge(
                    [$effectiveFrom, $assignmentId, $assignment['user_id'], $oldEffectiveFrom],
                    $updatedScopeIds
                ));
            }
            $this->pdo->prepare('UPDATE user_account_role SET effective_from=? WHERE id=?')
                ->execute([$effectiveFrom, $assignmentId]);

            $locations = array_map(static fn(array $scope): array => [
                'scope_assignment_id' => (string)$scope['id'],
                'scope_type' => (string)$scope['scope_type'],
                'scope_mode' => (string)$scope['scope_mode'],
                'location_id' => $scope['location_id'] === null ? null : (string)$scope['location_id'],
                'location_dad_number' => $scope['dad_number'],
                'location_name' => $scope['name_en'],
            ], $scopes);
            $details = [
                'target_user_id' => (string)$assignment['user_id'],
                'username' => (string)$assignment['username'],
                'role_assignment_id' => $assignmentId,
                'role_code' => (string)$assignment['role_code'],
                'role_name' => (string)$assignment['role_name'],
                'old_effective_from' => $oldEffectiveFrom,
                'new_effective_from' => $effectiveFrom,
                'updated_scope_assignment_ids' => $updatedScopeIds,
                'location_context' => $locations,
            ];
            $this->pdo->prepare("INSERT INTO audit_event(actor_user_id,action_key,target_type,target_id,details_json,severity,created_at)
                    VALUES(?,'user.role.effective-from.update','USER_ROLE',?,?,'INFO',NOW())")
                ->execute([$actorId, $assignmentId, json_encode($details, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)]);
        });
    }

    public function submitAssignment(string $actorId,string $assignmentId):void
    {
        $this->assertActorPermissions($actorId,['user.assign-role','user.assign-scope']);
        $this->assertCanManageRoleAssignment($actorId,$assignmentId);
        $this->transaction(function()use($actorId,$assignmentId):void{
            $stmt=$this->pdo->prepare("UPDATE user_account_role SET approval_status='SUBMITTED',submitted_by=?,submitted_at=NOW() WHERE id=? AND created_by=? AND approval_status='DRAFT'");
            $stmt->execute([$actorId,$assignmentId,$actorId]);
            if($stmt->rowCount()!==1)throw new DomainException('Only the person who created this draft can submit it.');
            $this->pdo->prepare("UPDATE user_account_scope SET approval_status='SUBMITTED',submitted_by=?,submitted_at=NOW() WHERE role_assignment_id=? AND created_by=? AND approval_status='DRAFT'")->execute([$actorId,$assignmentId,$actorId]);
        });
    }

    public function approveAssignment(string $actorId,string $assignmentId):void
    {
        $this->assertActorPermissions($actorId,['user.assign-role','user.assign-scope']);
        $this->assertCanManageRoleAssignment($actorId,$assignmentId);
        $this->transaction(function()use($actorId,$assignmentId):void{
            $stmt=$this->pdo->prepare('SELECT created_by,approval_status,replaces_assignment_id,effective_from,user_id FROM user_account_role WHERE id=? FOR UPDATE');
            $stmt->execute([$assignmentId]);$row=$stmt->fetch();
            if(!$row||$row['approval_status']!=='SUBMITTED')throw new DomainException('Only submitted user roles can be approved.');
            if((string)$row['created_by']===$actorId)throw new DomainException('You cannot approve a user role you created.');
            $this->pdo->prepare("UPDATE user_account_role SET approval_status='APPROVED',active=1,approved_by=?,approved_at=NOW() WHERE id=?")->execute([$actorId,$assignmentId]);
            $this->pdo->prepare("UPDATE user_account_scope SET approval_status='APPROVED',active=1,approved_by=?,approved_at=NOW() WHERE role_assignment_id=? AND approval_status='SUBMITTED'")->execute([$actorId,$assignmentId]);
            if($row['replaces_assignment_id']){
                $oldId=(string)$row['replaces_assignment_id'];$old=$this->pdo->prepare('SELECT user_id,effective_from FROM user_account_role WHERE id=? FOR UPDATE');$old->execute([$oldId]);$oldRow=$old->fetch();
                if(!$oldRow||(string)$oldRow['user_id']!==(string)$row['user_id'])throw new DomainException('The replacement assignment no longer matches the original user.');
                $end=(new \DateTimeImmutable((string)$row['effective_from']))->modify('-1 day')->format('Y-m-d');
                if($end<(string)$oldRow['effective_from'])throw new DomainException('The replacement effective date would create an invalid original period.');
                $this->pdo->prepare("UPDATE user_account_scope SET effective_to=CASE WHEN effective_to IS NULL OR effective_to>? THEN ? ELSE effective_to END,active=CASE WHEN ?<CURRENT_DATE() THEN 0 ELSE active END,action_reason=CONCAT_WS(' | ',NULLIF(action_reason,''),'Replaced by approved assignment') WHERE role_assignment_id=?")->execute([$end,$end,$end,$oldId]);
                $this->pdo->prepare("UPDATE user_account_role SET effective_to=CASE WHEN effective_to IS NULL OR effective_to>? THEN ? ELSE effective_to END,active=CASE WHEN ?<CURRENT_DATE() THEN 0 ELSE active END,reason=CONCAT_WS(' | ',NULLIF(reason,''),'Replaced by approved assignment') WHERE id=?")->execute([$end,$end,$end,$oldId]);
            }
            $this->pdo->prepare("INSERT INTO audit_event(actor_user_id,action_key,target_type,target_id,details_json,severity,created_at) VALUES(?,'user.role.approve','USER_ROLE',?,?,'INFO',NOW())")->execute([$actorId,$assignmentId,json_encode(['replaces_assignment_id'=>$row['replaces_assignment_id']],JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR)]);
        });
    }

    private function roleAllowed(array $authority, array $role): bool
    {
        $level = (string)$role['role_level'];
        if ($authority['kind'] === 'SYSTEM') {
            return $level !== 'SYSTEM' || $authority['is_system_admin'];
        }
        $targetRank = self::OPERATIONAL_ROLE_RANKS[(string)$role['role_code']] ?? null;
        $actorRank = $authority['actor_rank'] ?? null;
        return $targetRank !== null && $actorRank !== null && $targetRank < $actorRank;
    }

    private function assertTargetMayReceiveAssignment(string $actorId,string $targetUserId,string $date):void
    {
        $authority=$this->authority($actorId,$date);
        if($authority['kind']==='SYSTEM')return;
        $assignments=$this->effectiveAssignments($targetUserId,$date);
        if($assignments!==[]&&!$this->canManageUser($actorId,$targetUserId,$date)){
            throw new DomainException('You do not have permission to assign a role to this user.');
        }
    }

    /** @return array{scope_type:?string,scope_mode:?string,location_id:?string} */
    private function validateScope(array $authority, string $roleLevel, ?string $locationId, string $date): array
    {
        $locationId = trim((string)$locationId);
        if (in_array($roleLevel, ['SYSTEM', 'NATIONAL'], true)) {
            if ($locationId !== '') {
                throw new DomainException('National roles do not use a specific location.');
            }
            return ['scope_type' => 'NATIONAL', 'scope_mode' => 'NATIONAL', 'location_id' => null];
        }
        if ($roleLevel === 'CUSTOM') {
            if ($locationId !== '') {
                throw new DomainException('This role does not use an assigned administrative location.');
            }
            return ['scope_type' => null, 'scope_mode' => null, 'location_id' => null];
        }
        $expected = ['DISTRICT' => 'DISTRICT', 'ASC' => 'ASC', 'ARPA' => 'ARPA_DIVISION', 'FARMER' => 'ASC'][$roleLevel] ?? null;
        if ($expected === null || $locationId === '') {
            throw new DomainException('Select a valid location for this role.');
        }
        $stmt = $this->pdo->prepare("SELECT COUNT(*) FROM location l JOIN location_type lt ON lt.id=l.location_type_id WHERE l.id=? AND lt.system_key=? AND l.operational_status='ACTIVE' AND l.approval_status='APPROVED' AND l.effective_from<=? AND (l.effective_to IS NULL OR l.effective_to>=?)");
        $stmt->execute([$locationId, $expected, $date, $date]);
        if ((int)$stmt->fetchColumn() !== 1) {
            throw new DomainException('The selected location is not available.');
        }
        if (!in_array($authority['kind'], ['SYSTEM', 'NATIONAL', 'NATIONAL_SUBJECT'], true)
            && !$this->withinAuthorityBoundary($locationId, $authority, $date)) {
            $boundary = in_array($authority['kind'], ['ASC', 'ASC_SUBJECT'], true) ? 'Agrarian Service Center' : 'District';
            throw new DomainException("You can only select locations within your {$boundary}.");
        }
        return [
            'scope_type' => $expected,
            'scope_mode' => $expected === 'DISTRICT' ? 'INCLUDE_CHILDREN' : 'EXACT',
            'location_id' => $locationId,
        ];
    }

    /** @return array<int,array<string,mixed>> */
    private function effectiveAssignments(string $userId, string $date): array
    {
        $stmt = $this->pdo->prepare("SELECT uar.id,uar.user_id,uar.role_id,r.role_code,r.role_level FROM user_account_role uar JOIN application_role r ON r.id=uar.role_id WHERE uar.user_id=? AND uar.active=1 AND uar.approval_status='APPROVED' AND uar.effective_from<=? AND (uar.effective_to IS NULL OR uar.effective_to>=?) AND r.active=1 AND r.approval_status='APPROVED'");
        $stmt->execute([$userId, $date, $date]);
        return $stmt->fetchAll();
    }

    /** @return array<int,array<string,mixed>> */
    private function effectiveScopes(string $assignmentId, string $date): array
    {
        $stmt = $this->pdo->prepare("SELECT uas.* FROM user_account_scope uas JOIN user_account_role uar ON uar.id=uas.role_assignment_id AND uar.user_id=uas.user_id WHERE uas.role_assignment_id=? AND uas.active=1 AND uas.approval_status='APPROVED' AND uas.effective_from<=? AND (uas.effective_to IS NULL OR uas.effective_to>=?)");
        $stmt->execute([$assignmentId, $date, $date]);
        return $stmt->fetchAll();
    }

    private function scopeMatchesRole(array $assignment, array $scope, array $authority, string $date): bool
    {
        $roleCode = (string)$assignment['role_code'];
        if (in_array($roleCode, ['NATIONAL_SUBJECT_OFFICER', 'NATIONAL_ADMIN'], true)) {
            return $scope['scope_type'] === 'NATIONAL'
                && $scope['scope_mode'] === 'NATIONAL'
                && $scope['location_id'] === null;
        }
        $expected = match ($roleCode) {
            'FARMER', 'ASC_SUBJECT_OFFICER', 'ASC_ADMIN' => ['ASC', 'EXACT'],
            'ARPA_OFFICER' => ['ARPA_DIVISION', 'EXACT'],
            'DISTRICT_SUBJECT_OFFICER', 'DISTRICT_ADMIN' => ['DISTRICT', 'INCLUDE_CHILDREN'],
            default => null,
        };
        $locationId = (string)($scope['location_id'] ?? '');
        if ($expected === null || $locationId === ''
            || $scope['scope_type'] !== $expected[0] || $scope['scope_mode'] !== $expected[1]
            || !$this->withinAuthorityBoundary($locationId, $authority, $date)) {
            return false;
        }
        $stmt = $this->pdo->prepare("SELECT COUNT(*) FROM location l
            JOIN location_type lt ON lt.id=l.location_type_id
            WHERE l.id=? AND lt.system_key=?
              AND l.operational_status='ACTIVE' AND l.approval_status='APPROVED'
              AND l.effective_from<=? AND (l.effective_to IS NULL OR l.effective_to>=?)");
        $stmt->execute([$locationId, $expected[0], $date, $date]);
        return (int)$stmt->fetchColumn() === 1;
    }

    private function assignmentHasScope(string $assignmentId, string $type, string $mode, ?string $locationId, string $date): bool
    {
        foreach ($this->effectiveScopes($assignmentId, $date) as $scope) {
            if ($scope['scope_type'] === $type && $scope['scope_mode'] === $mode
                && ($locationId === null || (string)$scope['location_id'] === $locationId)) {
                return true;
            }
        }
        return false;
    }

    private function withinAnyDistrict(string $locationId, array $districtIds, string $date): bool
    {
        if ($districtIds === []) {
            return false;
        }
        return in_array($locationId,$this->descendantLocationIds($districtIds,$date),true);
    }

    private function withinAuthorityBoundary(string $locationId, array $authority, string $date): bool
    {
        if (in_array($authority['kind'], ['DISTRICT', 'DISTRICT_SUBJECT'], true)) {
            return $this->withinAnyDistrict($locationId, $authority['district_ids'], $date);
        }
        if (in_array($authority['kind'], ['ASC', 'ASC_SUBJECT'], true)) {
            return in_array($locationId, $this->descendantLocationIds($authority['asc_ids'], $date), true);
        }
        return in_array($authority['kind'], ['SYSTEM', 'NATIONAL', 'NATIONAL_SUBJECT'], true);
    }

    /** @return array<int,string> */
    private function authorityRootLocationIds(array $authority): array
    {
        if (in_array($authority['kind'], ['DISTRICT', 'DISTRICT_SUBJECT'], true)) {
            return $authority['district_ids'];
        }
        if (in_array($authority['kind'], ['ASC', 'ASC_SUBJECT'], true)) {
            return $authority['asc_ids'];
        }
        return [];
    }

    private function roleDeniedMessage(array $authority): string
    {
        return $authority['kind'] === 'SYSTEM'
            ? 'You do not have permission to assign this role.'
            : 'You can only manage users below your user level.';
    }

    /** @return array<int,string> */
    private function descendantLocationIds(array $districtIds,string $date):array
    {
        sort($districtIds);
        $cacheKey=implode(',',$districtIds).'|'.$date;
        if(isset($this->districtDescendantsCache[$cacheKey])){
            return $this->districtDescendantsCache[$cacheKey];
        }
        $placeholders = implode(',', array_fill(0, count($districtIds), '?'));
        $sql = "WITH RECURSIVE descendants(id) AS (
                    SELECT id FROM location WHERE id IN ({$placeholders})
                    UNION DISTINCT
                    SELECT lr.child_location_id FROM location_relationship lr JOIN descendants d ON d.id=lr.parent_location_id
                    WHERE lr.active=1 AND lr.approval_status='APPROVED' AND lr.effective_from<=? AND (lr.effective_to IS NULL OR lr.effective_to>=?)
                ) SELECT id FROM descendants";
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute(array_merge($districtIds, [$date, $date]));
        return $this->districtDescendantsCache[$cacheKey]=array_map('strval',$stmt->fetchAll(PDO::FETCH_COLUMN));
    }

    /** @return array<int,array<string,mixed>> */
    private function locationRows(array $types,string $date): array
    {
        $placeholders = implode(',', array_fill(0, count($types), '?'));
        $stmt = $this->pdo->prepare("SELECT l.id,l.dad_number,l.name_en,lt.system_key location_type FROM location l JOIN location_type lt ON lt.id=l.location_type_id WHERE lt.system_key IN ({$placeholders}) AND l.operational_status='ACTIVE' AND l.approval_status='APPROVED' AND l.effective_from<=? AND (l.effective_to IS NULL OR l.effective_to>=?) ORDER BY FIELD(lt.system_key,'DISTRICT','ASC','ARPA_DIVISION'),l.name_en");
        $stmt->execute(array_merge($types,[$date,$date]));
        return $stmt->fetchAll();
    }

    private function date(string $value): string
    {
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $value);
        if (!$date || $date->format('Y-m-d') !== $value) {
            throw new DomainException('Enter a valid start date.');
        }
        return $value;
    }

    private function optional(?string $value): ?string
    {
        $value = trim((string)$value);
        return $value === '' ? null : $value;
    }

    private function uuid(): string
    {
        $hex = bin2hex(random_bytes(16));
        return substr($hex, 0, 8) . '-' . substr($hex, 8, 4) . '-4' . substr($hex, 13, 3) . '-' . dechex((hexdec($hex[16]) & 3) | 8) . substr($hex, 17, 3) . '-' . substr($hex, 20);
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
