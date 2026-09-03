<?php
declare(strict_types=1);
namespace App\Core;

final class ScopeService
{
    public static function hasArpaStageScope(string $userId,string $stage):bool
    {
        $stage=strtoupper($stage);$profile=self::scopeProfile($userId);
        if(Auth::isCurrentUser($userId)&&Auth::activeContextForUser($userId)===null)return false;
        if($profile['level']==='SYSTEM')return true;
        if($stage==='NATIONAL')return $profile['level']==='NATIONAL';
        if(!in_array($stage,['ASC','DISTRICT'],true))return false;
        if (Auth::activeContextForUser($userId) !== null) {
            return $profile['level'] === $stage;
        }
        $mode=$stage==='DISTRICT'?"uas.scope_mode='INCLUDE_CHILDREN'":"uas.scope_mode IN('EXACT','INCLUDE_CHILDREN')";
        $stmt=Database::pdo()->prepare("SELECT COUNT(*) FROM user_account_scope uas JOIN user_account_role uar ON uar.id=uas.role_assignment_id AND uar.user_id=uas.user_id JOIN application_role r ON r.id=uar.role_id AND r.active=1 AND r.approval_status='APPROVED' JOIN location l ON l.id=uas.location_id JOIN location_type lt ON lt.id=l.location_type_id AND lt.system_key=? WHERE uas.user_id=? AND {$mode} AND uas.active=1 AND uas.approval_status='APPROVED' AND uas.effective_from<=CURRENT_DATE() AND (uas.effective_to IS NULL OR uas.effective_to>=CURRENT_DATE()) AND uar.active=1 AND uar.approval_status='APPROVED' AND uar.effective_from<=CURRENT_DATE() AND (uar.effective_to IS NULL OR uar.effective_to>=CURRENT_DATE())");
        $stmt->execute([$stage,$userId]);return (int)$stmt->fetchColumn()>0;
    }

    public static function arpaWorkflowScopeCte(string $userId):string
    {
        $context=Auth::activeContextForUser($userId);
        $seedPredicate=$context===null?(Auth::isCurrentUser($userId)?'1=0 AND uas.user_id=?':'uas.user_id=?'):'uar.id=? AND uas.id=?';
        return "WITH RECURSIVE workflow_scope_seeds(stage,id) AS (
                    SELECT CAST(lt.system_key AS CHAR(16)),uas.location_id
                    FROM user_account_scope uas
                    JOIN user_account_role uar ON uar.id=uas.role_assignment_id AND uar.user_id=uas.user_id
                    JOIN location l ON l.id=uas.location_id
                    JOIN location_type lt ON lt.id=l.location_type_id
                    WHERE {$seedPredicate} AND uas.active=1 AND uas.approval_status='APPROVED'
                      AND uar.active=1 AND uar.approval_status='APPROVED' AND uar.effective_from<=CURRENT_DATE() AND (uar.effective_to IS NULL OR uar.effective_to>=CURRENT_DATE())
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

    /** @return array<int,string> */
    public static function arpaWorkflowScopeParams(string $userId):array
    {
        $context=Auth::activeContextForUser($userId);
        return $context===null
            ? [$userId]
            : [(string)$context['role_assignment_id'],(string)($context['scope_assignment_id']??'')];
    }

    public static function scopeProfile(string $userId): array
    {
        $context=Auth::activeContextForUser($userId);
        if($context!==null){
            $level=(string)$context['role_level'];
            if($level==='SYSTEM')return ['level'=>'SYSTEM','enterprise'=>true,'scopes'=>[],'primary'=>null];
            $scope=$context['scope_assignment_id']===null?null:[
                'id'=>$context['scope_assignment_id'],'scope_type'=>$context['scope_type'],
                'scope_mode'=>$context['scope_mode'],'location_id'=>$context['location_id'],
                'dad_number'=>$context['location_dad_number'],'name_en'=>$context['location_name'],
                'location_type'=>$context['scope_type'],
            ];
            if($level==='NATIONAL'||($scope['scope_mode']??null)==='NATIONAL')return ['level'=>'NATIONAL','enterprise'=>true,'scopes'=>$scope===null?[]:[$scope],'primary'=>$scope];
            $geographicLevel=in_array($level,['DISTRICT','ASC'],true)?$level:'RESTRICTED';
            return ['level'=>$geographicLevel,'enterprise'=>false,'scopes'=>$scope===null?[]:[$scope],'primary'=>$scope];
        }
        if(Auth::isCurrentUser($userId))return ['level'=>'RESTRICTED','enterprise'=>false,'scopes'=>[],'primary'=>null];
        $pdo=Database::pdo();
        $system=$pdo->prepare("SELECT COUNT(*) FROM user_account_role uar JOIN application_role r ON r.id=uar.role_id WHERE uar.user_id=? AND uar.active=1 AND uar.approval_status='APPROVED' AND uar.effective_from<=CURRENT_DATE() AND (uar.effective_to IS NULL OR uar.effective_to>=CURRENT_DATE()) AND r.role_level='SYSTEM' AND r.active=1 AND r.approval_status='APPROVED'");$system->execute([$userId]);
        if((int)$system->fetchColumn()>0)return ['level'=>'SYSTEM','enterprise'=>true,'scopes'=>[],'primary'=>null];
        $stmt=$pdo->prepare("SELECT uas.id,uas.scope_type,uas.scope_mode,uas.location_id,l.dad_number,l.name_en,lt.system_key location_type FROM user_account_scope uas JOIN user_account_role uar ON uar.id=uas.role_assignment_id AND uar.user_id=uas.user_id LEFT JOIN location l ON l.id=uas.location_id LEFT JOIN location_type lt ON lt.id=l.location_type_id WHERE uas.user_id=? AND uas.active=1 AND uas.approval_status='APPROVED' AND uas.effective_from<=CURRENT_DATE() AND (uas.effective_to IS NULL OR uas.effective_to>=CURRENT_DATE()) AND uar.active=1 AND uar.approval_status='APPROVED' AND uar.effective_from<=CURRENT_DATE() AND (uar.effective_to IS NULL OR uar.effective_to>=CURRENT_DATE()) ORDER BY FIELD(uas.scope_mode,'NATIONAL','INCLUDE_CHILDREN','EXACT'),l.name_en");$stmt->execute([$userId]);$scopes=$stmt->fetchAll();
        foreach($scopes as $scope)if($scope['scope_mode']==='NATIONAL')return ['level'=>'NATIONAL','enterprise'=>true,'scopes'=>$scopes,'primary'=>$scope];
        $levels=array_unique(array_filter(array_column($scopes,'location_type')));$level=in_array('DISTRICT',$levels,true)?'DISTRICT':(in_array('ASC',$levels,true)?'ASC':'RESTRICTED');
        return ['level'=>$level,'enterprise'=>false,'scopes'=>$scopes,'primary'=>$scopes[0]??null];
    }

    /**
     * Authorize an ARPA workflow action from the actor's currently selected,
     * currently effective Working Context. Appointment business dates must not
     * be used here; date-sensitive domain validation belongs to ARPA services.
     */
    public static function canAccessCurrentArpaStage(string $userId, string $stage, string $ascLocationId): bool
    {
        $stage = strtoupper($stage);
        $date = date('Y-m-d');
        $pdo = Database::pdo();

        $context=Auth::activeContextForUser($userId);
        if($context!==null){
            $profile=self::scopeProfile($userId);
            if($profile['level']==='SYSTEM')return true;
            if($stage==='NATIONAL')return $profile['level']==='NATIONAL';
            if(!in_array($stage,['ASC','DISTRICT'],true)||$profile['level']!==$stage)return false;
            return self::canAccessLocation($userId,$ascLocationId);
        }
        if(Auth::isCurrentUser($userId))return false;

        $system = $pdo->prepare("SELECT COUNT(*) FROM user_account_role uar JOIN application_role r ON r.id=uar.role_id WHERE uar.user_id=? AND uar.active=1 AND uar.approval_status='APPROVED' AND uar.effective_from<=? AND (uar.effective_to IS NULL OR uar.effective_to>=?) AND r.role_level='SYSTEM' AND r.active=1 AND r.approval_status='APPROVED'");
        $system->execute([$userId,$date,$date]);
        if ((int)$system->fetchColumn() > 0) return true;

        if ($stage === 'NATIONAL') {
            $national = $pdo->prepare("SELECT COUNT(*) FROM user_account_scope uas JOIN user_account_role uar ON uar.id=uas.role_assignment_id AND uar.user_id=uas.user_id WHERE uas.user_id=? AND uas.scope_mode='NATIONAL' AND uas.active=1 AND uas.approval_status='APPROVED' AND uas.effective_from<=? AND (uas.effective_to IS NULL OR uas.effective_to>=?) AND uar.active=1 AND uar.approval_status='APPROVED' AND uar.effective_from<=? AND (uar.effective_to IS NULL OR uar.effective_to>=?)");
            $national->execute([$userId,$date,$date,$date,$date]);
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
                JOIN user_account_role uar ON uar.id=uas.role_assignment_id AND uar.user_id=uas.user_id
                JOIN location l ON l.id=uas.location_id
                JOIN location_type lt ON lt.id=l.location_type_id AND lt.system_key=?
                WHERE uas.user_id=? AND uas.location_id IN (SELECT id FROM ancestors)
                  AND uas.active=1 AND uas.approval_status='APPROVED'
                  AND uas.effective_from<=? AND (uas.effective_to IS NULL OR uas.effective_to>=?)
                  AND uar.active=1 AND uar.approval_status='APPROVED'
                  AND uar.effective_from<=? AND (uar.effective_to IS NULL OR uar.effective_to>=?)
                  AND (uas.scope_mode='INCLUDE_CHILDREN' OR (uas.scope_mode='EXACT' AND uas.location_id=?))";
        $stmt=$pdo->prepare($sql);
        $stmt->execute([$ascLocationId,$date,$date,$requiredType,$userId,$date,$date,$date,$date,$ascLocationId]);
        return (int)$stmt->fetchColumn() > 0;
    }

    /**
     * @deprecated Use canAccessCurrentArpaStage(). The optional fourth argument
     * is intentionally ignored so historical appointment dates can never alter
     * current actor authorization in older callers.
     */
    public static function canAccessArpaStage(string $userId, string $stage, string $ascLocationId, ?string $appointmentEffectiveDate = null): bool
    {
        return self::canAccessCurrentArpaStage($userId, $stage, $ascLocationId);
    }

    public static function requiresGeographicRestriction(string $userId): bool
    {
        $context=Auth::activeContextForUser($userId);
        if($context!==null)return !self::scopeProfile($userId)['enterprise'];
        if(Auth::isCurrentUser($userId))return true;
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
            "SELECT COUNT(*) FROM user_account_scope uas JOIN user_account_role uar ON uar.id=uas.role_assignment_id AND uar.user_id=uas.user_id
             WHERE uas.user_id=? AND uas.active=1 AND uas.approval_status='APPROVED' AND uas.scope_mode='NATIONAL'
               AND uas.effective_from<=CURRENT_DATE() AND (uas.effective_to IS NULL OR uas.effective_to>=CURRENT_DATE())
               AND uar.active=1 AND uar.approval_status='APPROVED' AND uar.effective_from<=CURRENT_DATE() AND (uar.effective_to IS NULL OR uar.effective_to>=CURRENT_DATE())"
        );
        $national->execute([$userId]);
        return (int)$national->fetchColumn() === 0;
    }

    /**
     * Reusable MySQL 8 CTE for server-side geographic list scoping.
     * The returned parameter is bound before ordinary WHERE parameters.
     */
    public static function visibleLocationsCte(?string $userId=null): string
    {
        if($userId===null){$user=Auth::user();$userId=$user===null?null:(string)$user['id'];}
        $context=$userId===null?null:Auth::activeContextForUser($userId);
        $seedPredicate=$context===null?($userId!==null&&Auth::isCurrentUser($userId)?'1=0 AND uas.user_id=?':'uas.user_id=?'):'uar.id=? AND uas.id=?';
        return "WITH RECURSIVE scope_seeds(id) AS (
                    SELECT DISTINCT uas.location_id
                    FROM user_account_scope uas
                    JOIN user_account_role uar ON uar.id=uas.role_assignment_id AND uar.user_id=uas.user_id
                    WHERE {$seedPredicate} AND uas.location_id IS NOT NULL
                      AND uas.active=1 AND uas.approval_status='APPROVED'
                      AND uas.effective_from<=CURRENT_DATE()
                      AND (uas.effective_to IS NULL OR uas.effective_to>=CURRENT_DATE())
                      AND uar.active=1 AND uar.approval_status='APPROVED'
                      AND uar.effective_from<=CURRENT_DATE()
                      AND (uar.effective_to IS NULL OR uar.effective_to>=CURRENT_DATE())
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

    /** @return array<int,string> */
    public static function visibleLocationParams(string $userId):array
    {
        $context=Auth::activeContextForUser($userId);
        return $context===null
            ? [$userId]
            : [(string)$context['role_assignment_id'],(string)($context['scope_assignment_id']??'')];
    }

    public static function canAccessLocation(string $userId, string $locationId, ?string $effectiveDate = null): bool
    {
        if (!self::requiresGeographicRestriction($userId)) return true;
        $date = $effectiveDate ?: date('Y-m-d');
        $pdo = Database::pdo();
        $sql=self::visibleLocationsCteForDate($userId)." SELECT COUNT(*) FROM visible_locations WHERE id=?";
        $stmt=$pdo->prepare($sql);$stmt->execute(array_merge(self::visibleLocationDateParams($userId,$date),[$locationId]));
        return (int)$stmt->fetchColumn()>0;
    }

    /**
     * Assignment-aware authorization: the permission and geographic scope must
     * come from the same effective role assignment.
     */
    public static function canAccessLocationForPermission(string $userId,string $permission,string $locationId,?string $effectiveDate=null):bool
    {
        $date=$effectiveDate?:date('Y-m-d');$pdo=Database::pdo();
        $context=Auth::activeContextForUser($userId);
        if($context!==null){
            if(!Auth::can($permission))return false;
            if(in_array((string)$context['role_level'],['SYSTEM','NATIONAL'],true))return true;
            if($context['scope_assignment_id']===null||$context['location_id']===null)return false;
            if($context['scope_mode']==='EXACT')return (string)$context['location_id']===$locationId;
            if($context['scope_mode']!=='INCLUDE_CHILDREN')return false;
            $sql="WITH RECURSIVE target_ancestors(id) AS (SELECT ? UNION DISTINCT SELECT lr.parent_location_id FROM location_relationship lr JOIN target_ancestors a ON a.id=lr.child_location_id WHERE lr.active=1 AND lr.approval_status='APPROVED' AND lr.effective_from<=? AND (lr.effective_to IS NULL OR lr.effective_to>=?)) SELECT COUNT(*) FROM target_ancestors WHERE id=?";
            $stmt=$pdo->prepare($sql);$stmt->execute([$locationId,$date,$date,$context['location_id']]);
            return (int)$stmt->fetchColumn()>0;
        }
        if(Auth::isCurrentUser($userId))return false;
        $sql="WITH RECURSIVE target_ancestors(id) AS (
                SELECT ?
                UNION DISTINCT
                SELECT lr.parent_location_id FROM location_relationship lr JOIN target_ancestors a ON a.id=lr.child_location_id
                WHERE lr.active=1 AND lr.approval_status='APPROVED' AND lr.effective_from<=? AND (lr.effective_to IS NULL OR lr.effective_to>=?)
              )
              SELECT COUNT(*)
              FROM user_account_role uar
              JOIN application_role r ON r.id=uar.role_id
              JOIN application_role_permission rp ON rp.role_id=r.id
              JOIN application_permission p ON p.id=rp.permission_id AND p.permission_key=? AND p.active=1
              LEFT JOIN user_account_scope uas ON uas.role_assignment_id=uar.id AND uas.user_id=uar.user_id
                AND uas.active=1 AND uas.approval_status='APPROVED'
                AND uas.effective_from<=? AND (uas.effective_to IS NULL OR uas.effective_to>=?)
              WHERE uar.user_id=? AND uar.active=1 AND uar.approval_status='APPROVED'
                AND uar.effective_from<=? AND (uar.effective_to IS NULL OR uar.effective_to>=?)
                AND r.active=1 AND r.approval_status='APPROVED'
                AND (
                  r.role_level='SYSTEM'
                  OR uas.scope_mode='NATIONAL'
                  OR (uas.scope_mode='EXACT' AND uas.location_id=?)
                  OR (uas.scope_mode='INCLUDE_CHILDREN' AND uas.location_id IN (SELECT id FROM target_ancestors))
                )";
        $stmt=$pdo->prepare($sql);$stmt->execute([$locationId,$date,$date,$permission,$date,$date,$userId,$date,$date,$locationId]);
        return (int)$stmt->fetchColumn()>0;
    }

    /** @return array<int,array{id:string,dad_number:string,name_en:string}> */
    public static function scopedLocations(string $userId, string $type): array
    {
        $pdo=Database::pdo();
        $restricted=self::requiresGeographicRestriction($userId);
        $sql=($restricted?self::visibleLocationsCte($userId):'').
            "SELECT l.id,l.dad_number,l.name_en FROM location l ".
            ($restricted?'JOIN visible_locations vl ON vl.id=l.id ':'').
            "JOIN location_type t ON t.id=l.location_type_id WHERE t.system_key=? AND l.approval_status='APPROVED' AND l.operational_status='ACTIVE' ORDER BY l.name_en";
        $stmt=$pdo->prepare($sql);$stmt->execute($restricted?array_merge(self::visibleLocationParams($userId),[$type]):[$type]);
        return $stmt->fetchAll();
    }

    /** Scope predicate for the current Officer directory. Office Assignment is authoritative. */
    public static function currentOfficerAccess(string $userId, string $officerExpression): array
    {
        if (!self::requiresGeographicRestriction($userId)) return ['with'=>'','params'=>[],'where'=>[]];
        $profile=self::scopeProfile($userId);
        $locationSource=$profile['level']==='ASC'?'scope_seeds':'scope_descendants';
        $with=self::visibleLocationsCte($userId).", scoped_locations(id) AS (SELECT id FROM {$locationSource}), scoped_offices(id) AS (SELECT o.id FROM office o JOIN office_type ot ON ot.id=o.office_type_id JOIN scoped_locations sl ON sl.id=o.linked_location_id WHERE ".($profile['level']==='ASC'?"ot.system_key='ASC_OFFICE'":"ot.system_key IN('DISTRICT_OFFICE','ASC_OFFICE')").") ";
        return [
            'with'=>$with,
            'params'=>self::visibleLocationParams($userId),
            'where'=>["EXISTS (SELECT 1 FROM officer_office_assignment ooa JOIN office ofc ON ofc.id=ooa.office_id JOIN scoped_offices sof ON sof.id=ofc.id WHERE ooa.officer_id={$officerExpression} AND ooa.active=1 AND ooa.approval_status='APPROVED' AND ooa.effective_from<=CURRENT_DATE() AND (ooa.effective_to IS NULL OR ooa.effective_to>=CURRENT_DATE()) AND ofc.operational_status='ACTIVE' AND ofc.approval_status='APPROVED')"],
        ];
    }

    /** @return array<int,array<string,mixed>> */
    public static function scopedOffices(string $userId): array
    {
        $pdo=Database::pdo();$profile=self::scopeProfile($userId);
        if ($profile['enterprise']) return $pdo->query("SELECT o.id,o.dad_number,o.name_en,ot.system_key office_type,l.name_en location_name FROM office o JOIN office_type ot ON ot.id=o.office_type_id LEFT JOIN location l ON l.id=o.linked_location_id WHERE o.approval_status='APPROVED' AND o.operational_status='ACTIVE' ORDER BY ot.display_order,o.name_en")->fetchAll();
        if ($profile['level']==='ASC') {
            $sql=self::visibleLocationsCte($userId)." SELECT DISTINCT o.id,o.dad_number,o.name_en,ot.system_key office_type,l.name_en location_name FROM office o JOIN office_type ot ON ot.id=o.office_type_id AND ot.system_key='ASC_OFFICE' JOIN location l ON l.id=o.linked_location_id JOIN scope_seeds s ON s.id=l.id WHERE o.approval_status='APPROVED' AND o.operational_status='ACTIVE' ORDER BY o.name_en";
        } else {
            $sql=self::visibleLocationsCte($userId)." SELECT DISTINCT o.id,o.dad_number,o.name_en,ot.system_key office_type,l.name_en location_name FROM office o JOIN office_type ot ON ot.id=o.office_type_id JOIN location l ON l.id=o.linked_location_id JOIN visible_locations vl ON vl.id=l.id WHERE ot.system_key IN('DISTRICT_OFFICE','ASC_OFFICE') AND o.approval_status='APPROVED' AND o.operational_status='ACTIVE' ORDER BY ot.display_order,o.name_en";
        }
        $s=$pdo->prepare($sql);$s->execute(self::visibleLocationParams($userId));return $s->fetchAll();
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

    private static function visibleLocationsCteForDate(string $userId):string
    {
        $context=Auth::activeContextForUser($userId);
        $seedPredicate=$context===null?(Auth::isCurrentUser($userId)?'1=0 AND uas.user_id=?':'uas.user_id=?'):'uar.id=? AND uas.id=?';
        return "WITH RECURSIVE scope_seeds(id) AS (SELECT DISTINCT uas.location_id FROM user_account_scope uas JOIN user_account_role uar ON uar.id=uas.role_assignment_id AND uar.user_id=uas.user_id WHERE {$seedPredicate} AND uas.location_id IS NOT NULL AND uas.active=1 AND uas.approval_status='APPROVED' AND uas.effective_from<=? AND (uas.effective_to IS NULL OR uas.effective_to>=?) AND uar.active=1 AND uar.approval_status='APPROVED' AND uar.effective_from<=? AND (uar.effective_to IS NULL OR uar.effective_to>=?)),scope_descendants(id) AS (SELECT id FROM scope_seeds UNION DISTINCT SELECT lr.child_location_id FROM location_relationship lr JOIN scope_descendants d ON d.id=lr.parent_location_id WHERE lr.active=1 AND lr.approval_status='APPROVED' AND lr.effective_from<=? AND (lr.effective_to IS NULL OR lr.effective_to>=?)),scope_ancestors(id) AS (SELECT id FROM scope_seeds UNION DISTINCT SELECT lr.parent_location_id FROM location_relationship lr JOIN scope_ancestors a ON a.id=lr.child_location_id WHERE lr.active=1 AND lr.approval_status='APPROVED' AND lr.effective_from<=? AND (lr.effective_to IS NULL OR lr.effective_to>=?)),visible_locations(id) AS (SELECT id FROM scope_descendants UNION DISTINCT SELECT id FROM scope_ancestors)";
    }

    /** @return array<int,string> */
    private static function visibleLocationDateParams(string $userId,string $date):array
    {
        $context=Auth::activeContextForUser($userId);
        $seed=$context===null?[$userId]:[(string)$context['role_assignment_id'],(string)($context['scope_assignment_id']??'')];
        return array_merge($seed,[$date,$date,$date,$date,$date,$date,$date,$date]);
    }
}
