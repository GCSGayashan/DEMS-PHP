<?php
declare(strict_types=1);
namespace App\Core;

final class ScopeService
{
    public static function hasArpaStageScope(string $userId,string $stage):bool
    {
        $stage=strtoupper($stage);$profile=self::scopeProfile($userId);
        if($profile['level']==='SYSTEM')return true;
        if($stage==='NATIONAL')return $profile['level']==='NATIONAL';
        if(!in_array($stage,['ASC','DISTRICT'],true))return false;
        $mode=$stage==='DISTRICT'?"uas.scope_mode='INCLUDE_CHILDREN'":"uas.scope_mode IN('EXACT','INCLUDE_CHILDREN')";
        $stmt=Database::pdo()->prepare("SELECT COUNT(*) FROM user_account_scope uas JOIN location l ON l.id=uas.location_id JOIN location_type lt ON lt.id=l.location_type_id AND lt.system_key=? WHERE uas.user_id=? AND {$mode} AND uas.active=1 AND uas.approval_status='APPROVED' AND uas.effective_from<=CURRENT_DATE() AND (uas.effective_to IS NULL OR uas.effective_to>=CURRENT_DATE())");
        $stmt->execute([$stage,$userId]);return (int)$stmt->fetchColumn()>0;
    }

    public static function arpaWorkflowScopeCte():string
    {
        return "WITH RECURSIVE workflow_scope_seeds(stage,id) AS (
                    SELECT CAST(lt.system_key AS CHAR(16)),uas.location_id
                    FROM user_account_scope uas
                    JOIN location l ON l.id=uas.location_id
                    JOIN location_type lt ON lt.id=l.location_type_id
                    WHERE uas.user_id=? AND uas.active=1 AND uas.approval_status='APPROVED'
                      AND uas.effective_from<=CURRENT_DATE() AND (uas.effective_to IS NULL OR uas.effective_to>=CURRENT_DATE())
                      AND ((lt.system_key='ASC' AND uas.scope_mode IN('EXACT','INCLUDE_CHILDREN'))
                           OR (lt.system_key='DISTRICT' AND uas.scope_mode='INCLUDE_CHILDREN'))
                ), workflow_scope_locations(stage,id) AS (
                    SELECT stage,id FROM workflow_scope_seeds
                    UNION DISTINCT
                    SELECT wsl.stage,lr.child_location_id
                    FROM workflow_scope_locations wsl
                    JOIN location_relationship lr ON lr.parent_location_id=wsl.id
                    WHERE wsl.stage='DISTRICT' AND lr.active=1 AND lr.approval_status='APPROVED'
                      AND lr.effective_from<=CURRENT_DATE() AND (lr.effective_to IS NULL OR lr.effective_to>=CURRENT_DATE())
                ) ";
    }

    public static function scopeProfile(string $userId): array
    {
        $pdo=Database::pdo();
        $system=$pdo->prepare("SELECT COUNT(*) FROM user_account_role uar JOIN application_role r ON r.id=uar.role_id WHERE uar.user_id=? AND uar.active=1 AND uar.approval_status='APPROVED' AND uar.effective_from<=CURRENT_DATE() AND (uar.effective_to IS NULL OR uar.effective_to>=CURRENT_DATE()) AND r.role_level='SYSTEM' AND r.active=1 AND r.approval_status='APPROVED'");$system->execute([$userId]);
        if((int)$system->fetchColumn()>0)return ['level'=>'SYSTEM','enterprise'=>true,'scopes'=>[],'primary'=>null];
        $stmt=$pdo->prepare("SELECT uas.id,uas.scope_type,uas.scope_mode,uas.location_id,l.dad_number,l.name_en,lt.system_key location_type FROM user_account_scope uas LEFT JOIN location l ON l.id=uas.location_id LEFT JOIN location_type lt ON lt.id=l.location_type_id WHERE uas.user_id=? AND uas.active=1 AND uas.approval_status='APPROVED' AND uas.effective_from<=CURRENT_DATE() AND (uas.effective_to IS NULL OR uas.effective_to>=CURRENT_DATE()) ORDER BY FIELD(uas.scope_mode,'NATIONAL','INCLUDE_CHILDREN','EXACT'),l.name_en");$stmt->execute([$userId]);$scopes=$stmt->fetchAll();
        foreach($scopes as $scope)if($scope['scope_mode']==='NATIONAL')return ['level'=>'NATIONAL','enterprise'=>true,'scopes'=>$scopes,'primary'=>$scope];
        $levels=array_unique(array_filter(array_column($scopes,'location_type')));$level=in_array('DISTRICT',$levels,true)?'DISTRICT':(in_array('ASC',$levels,true)?'ASC':'RESTRICTED');
        return ['level'=>$level,'enterprise'=>false,'scopes'=>$scopes,'primary'=>$scopes[0]??null];
    }

    public static function canAccessArpaStage(string $userId, string $stage, string $ascLocationId, ?string $effectiveDate = null): bool
    {
        $stage = strtoupper($stage);
        $date = $effectiveDate ?: date('Y-m-d');
        $pdo = Database::pdo();

        $system = $pdo->prepare("SELECT COUNT(*) FROM user_account_role uar JOIN application_role r ON r.id=uar.role_id WHERE uar.user_id=? AND uar.active=1 AND uar.approval_status='APPROVED' AND uar.effective_from<=? AND (uar.effective_to IS NULL OR uar.effective_to>=?) AND r.role_level='SYSTEM' AND r.active=1 AND r.approval_status='APPROVED'");
        $system->execute([$userId,$date,$date]);
        if ((int)$system->fetchColumn() > 0) return true;

        if ($stage === 'NATIONAL') {
            $national = $pdo->prepare("SELECT COUNT(*) FROM user_account_scope WHERE user_id=? AND scope_mode='NATIONAL' AND active=1 AND approval_status='APPROVED' AND effective_from<=? AND (effective_to IS NULL OR effective_to>=?)");
            $national->execute([$userId,$date,$date]);
            return (int)$national->fetchColumn() > 0;
        }

        if (!in_array($stage, ['ASC','DISTRICT'], true)) return false;
        $requiredType = $stage;
        $sql = "WITH RECURSIVE ancestors(id) AS (
                    SELECT ?
                    UNION DISTINCT
                    SELECT lr.parent_location_id
                    FROM location_relationship lr
                    JOIN ancestors a ON a.id=lr.child_location_id
                    WHERE lr.active=1 AND lr.approval_status='APPROVED'
                      AND lr.effective_from<=? AND (lr.effective_to IS NULL OR lr.effective_to>=?)
                )
                SELECT COUNT(*)
                FROM user_account_scope uas
                JOIN location l ON l.id=uas.location_id
                JOIN location_type lt ON lt.id=l.location_type_id AND lt.system_key=?
                WHERE uas.user_id=? AND uas.location_id IN (SELECT id FROM ancestors)
                  AND uas.active=1 AND uas.approval_status='APPROVED'
                  AND uas.effective_from<=? AND (uas.effective_to IS NULL OR uas.effective_to>=?)
                  AND (uas.scope_mode='INCLUDE_CHILDREN' OR (uas.scope_mode='EXACT' AND uas.location_id=?))";
        $stmt=$pdo->prepare($sql);
        $stmt->execute([$ascLocationId,$date,$date,$requiredType,$userId,$date,$date,$ascLocationId]);
        return (int)$stmt->fetchColumn() > 0;
    }

    public static function requiresGeographicRestriction(string $userId): bool
    {
        $pdo = Database::pdo();
        $role = $pdo->prepare(
            "SELECT COUNT(*) FROM user_account_role uar
             JOIN application_role r ON r.id=uar.role_id
             WHERE uar.user_id=? AND uar.active=1 AND uar.approval_status='APPROVED'
               AND uar.effective_from<=CURRENT_DATE() AND (uar.effective_to IS NULL OR uar.effective_to>=CURRENT_DATE())
               AND r.active=1 AND r.approval_status='APPROVED' AND r.role_level='SYSTEM'"
        );
        $role->execute([$userId]);
        if ((int)$role->fetchColumn() > 0) {
            return false;
        }

        $national = $pdo->prepare(
            "SELECT COUNT(*) FROM user_account_scope
             WHERE user_id=? AND active=1 AND approval_status='APPROVED' AND scope_mode='NATIONAL'
               AND effective_from<=CURRENT_DATE() AND (effective_to IS NULL OR effective_to>=CURRENT_DATE())"
        );
        $national->execute([$userId]);
        return (int)$national->fetchColumn() === 0;
    }

    /**
     * Reusable MySQL 8 CTE for server-side geographic list scoping.
     * The returned parameter is bound before ordinary WHERE parameters.
     */
    public static function visibleLocationsCte(): string
    {
        return "WITH RECURSIVE scope_seeds(id) AS (
                    SELECT DISTINCT uas.location_id
                    FROM user_account_scope uas
                    WHERE uas.user_id=? AND uas.location_id IS NOT NULL
                      AND uas.active=1 AND uas.approval_status='APPROVED'
                      AND uas.effective_from<=CURRENT_DATE()
                      AND (uas.effective_to IS NULL OR uas.effective_to>=CURRENT_DATE())
                ), scope_descendants(id) AS (
                    SELECT id FROM scope_seeds
                    UNION DISTINCT
                    SELECT lr.child_location_id
                    FROM location_relationship lr
                    JOIN scope_descendants d ON d.id=lr.parent_location_id
                    WHERE lr.active=1 AND lr.approval_status='APPROVED'
                      AND lr.effective_from<=CURRENT_DATE()
                      AND (lr.effective_to IS NULL OR lr.effective_to>=CURRENT_DATE())
                ), scope_ancestors(id) AS (
                    SELECT id FROM scope_seeds
                    UNION DISTINCT
                    SELECT lr.parent_location_id
                    FROM location_relationship lr
                    JOIN scope_ancestors a ON a.id=lr.child_location_id
                    WHERE lr.active=1 AND lr.approval_status='APPROVED'
                      AND lr.effective_from<=CURRENT_DATE()
                      AND (lr.effective_to IS NULL OR lr.effective_to>=CURRENT_DATE())
                ), visible_locations(id) AS (
                    SELECT id FROM scope_descendants
                    UNION DISTINCT SELECT id FROM scope_ancestors
                ) ";
    }

    public static function canAccessLocation(string $userId, string $locationId, ?string $effectiveDate = null): bool
    {
        if (!self::requiresGeographicRestriction($userId)) return true;
        $date = $effectiveDate ?: date('Y-m-d');
        $pdo = Database::pdo();
        $sql=self::visibleLocationsCteForDate()." SELECT COUNT(*) FROM visible_locations WHERE id=?";
        $stmt=$pdo->prepare($sql);$stmt->execute([$userId,$date,$date,$date,$date,$date,$date,$locationId]);
        return (int)$stmt->fetchColumn()>0;
    }

    /** @return array<int,array{id:string,dad_number:string,name_en:string}> */
    public static function scopedLocations(string $userId, string $type): array
    {
        $pdo=Database::pdo();
        $restricted=self::requiresGeographicRestriction($userId);
        $sql=($restricted?self::visibleLocationsCte():'').
            "SELECT l.id,l.dad_number,l.name_en FROM location l ".
            ($restricted?'JOIN visible_locations vl ON vl.id=l.id ':'').
            "JOIN location_type t ON t.id=l.location_type_id WHERE t.system_key=? AND l.approval_status='APPROVED' AND l.operational_status='ACTIVE' ORDER BY l.name_en";
        $stmt=$pdo->prepare($sql);$stmt->execute($restricted?[$userId,$type]:[$type]);
        return $stmt->fetchAll();
    }

    /** Scope predicate for the current Officer directory. Office Assignment is authoritative. */
    public static function currentOfficerAccess(string $userId, string $officerExpression): array
    {
        if (!self::requiresGeographicRestriction($userId)) return ['with'=>'','params'=>[],'where'=>[]];
        $profile=self::scopeProfile($userId);
        $with=$profile['level']==='ASC'
            ? "WITH scoped_locations(id) AS (SELECT DISTINCT s.location_id FROM user_account_scope s WHERE s.user_id=? AND s.scope_type='ASC' AND s.scope_mode='EXACT' AND s.active=1 AND s.approval_status='APPROVED' AND s.effective_from<=CURRENT_DATE() AND (s.effective_to IS NULL OR s.effective_to>=CURRENT_DATE())), scoped_offices(id) AS (SELECT o.id FROM office o JOIN office_type ot ON ot.id=o.office_type_id AND ot.system_key='ASC_OFFICE' JOIN scoped_locations sl ON sl.id=o.linked_location_id) "
            : self::visibleLocationsCte().", scoped_locations(id) AS (SELECT id FROM visible_locations), scoped_offices(id) AS (SELECT o.id FROM office o JOIN scoped_locations sl ON sl.id=o.linked_location_id) ";
        return [
            'with'=>$with,
            'params'=>[$userId],
            'where'=>["EXISTS (SELECT 1 FROM officer_office_assignment ooa JOIN office ofc ON ofc.id=ooa.office_id JOIN scoped_offices sof ON sof.id=ofc.id WHERE ooa.officer_id={$officerExpression} AND ooa.active=1 AND ooa.approval_status='APPROVED' AND ooa.effective_from<=CURRENT_DATE() AND (ooa.effective_to IS NULL OR ooa.effective_to>=CURRENT_DATE()) AND ofc.operational_status='ACTIVE' AND ofc.approval_status='APPROVED')"],
        ];
    }

    /** @return array<int,array<string,mixed>> */
    public static function scopedOffices(string $userId): array
    {
        $pdo=Database::pdo();$profile=self::scopeProfile($userId);
        if ($profile['enterprise']) return $pdo->query("SELECT o.id,o.dad_number,o.name_en,ot.system_key office_type,l.name_en location_name FROM office o JOIN office_type ot ON ot.id=o.office_type_id LEFT JOIN location l ON l.id=o.linked_location_id WHERE o.approval_status='APPROVED' AND o.operational_status='ACTIVE' ORDER BY ot.display_order,o.name_en")->fetchAll();
        if ($profile['level']==='ASC') {
            $sql="SELECT DISTINCT o.id,o.dad_number,o.name_en,ot.system_key office_type,l.name_en location_name FROM office o JOIN office_type ot ON ot.id=o.office_type_id AND ot.system_key='ASC_OFFICE' JOIN location l ON l.id=o.linked_location_id JOIN user_account_scope s ON s.location_id=l.id WHERE s.user_id=? AND s.scope_type='ASC' AND s.scope_mode='EXACT' AND s.active=1 AND s.approval_status='APPROVED' AND s.effective_from<=CURRENT_DATE() AND (s.effective_to IS NULL OR s.effective_to>=CURRENT_DATE()) AND o.approval_status='APPROVED' AND o.operational_status='ACTIVE' ORDER BY o.name_en";
        } else {
            $sql=self::visibleLocationsCte()." SELECT DISTINCT o.id,o.dad_number,o.name_en,ot.system_key office_type,l.name_en location_name FROM office o JOIN office_type ot ON ot.id=o.office_type_id JOIN location l ON l.id=o.linked_location_id JOIN visible_locations vl ON vl.id=l.id WHERE ot.system_key IN('DISTRICT_OFFICE','ASC_OFFICE') AND o.approval_status='APPROVED' AND o.operational_status='ACTIVE' ORDER BY ot.display_order,o.name_en";
        }
        $s=$pdo->prepare($sql);$s->execute([$userId]);return $s->fetchAll();
    }

    public static function canAccessOffice(string $userId,string $officeId):bool
    {
        foreach(self::scopedOffices($userId) as $office) if((string)$office['id']===$officeId)return true;
        return false;
    }

    public static function canAccessOfficer(string $userId,string $officerId):bool
    {
        if (!self::requiresGeographicRestriction($userId)) return true;
        $access=self::currentOfficerAccess($userId,'o.id');
        $stmt=Database::pdo()->prepare($access['with'].' SELECT COUNT(*) FROM officer o WHERE o.id=? AND '.implode(' AND ',$access['where']));
        $stmt->execute(array_merge($access['params'],[$officerId]));
        return (int)$stmt->fetchColumn()>0;
    }

    private static function visibleLocationsCteForDate():string
    {
        return "WITH RECURSIVE scope_seeds(id) AS (SELECT DISTINCT location_id FROM user_account_scope WHERE user_id=? AND location_id IS NOT NULL AND active=1 AND approval_status='APPROVED' AND effective_from<=? AND (effective_to IS NULL OR effective_to>=?)),scope_descendants(id) AS (SELECT id FROM scope_seeds UNION DISTINCT SELECT lr.child_location_id FROM location_relationship lr JOIN scope_descendants d ON d.id=lr.parent_location_id WHERE lr.active=1 AND lr.approval_status='APPROVED' AND lr.effective_from<=? AND (lr.effective_to IS NULL OR lr.effective_to>=?)),scope_ancestors(id) AS (SELECT id FROM scope_seeds UNION DISTINCT SELECT lr.parent_location_id FROM location_relationship lr JOIN scope_ancestors a ON a.id=lr.child_location_id WHERE lr.active=1 AND lr.approval_status='APPROVED' AND lr.effective_from<=? AND (lr.effective_to IS NULL OR lr.effective_to>=?)),visible_locations(id) AS (SELECT id FROM scope_descendants UNION DISTINCT SELECT id FROM scope_ancestors)";
    }
}
