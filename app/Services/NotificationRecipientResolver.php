<?php
declare(strict_types=1);
namespace App\Services;

use PDO;

final class NotificationRecipientResolver
{
    public function __construct(private readonly PDO $pdo){}

    /** @return array<int,array<string,mixed>> */
    public function forPermission(string $permission,?string $targetLocationId,?string $excludeUserId=null,array $roleCodes=[]):array
    {
        $roleFilter=$roleCodes===[]?'':" AND r.role_code IN (".implode(',',array_fill(0,count($roleCodes),'?')).')';
        $params=[];$cte='';$scopePredicate="r.role_level='SYSTEM'";
        if($targetLocationId!==null&&$targetLocationId!==''){
            $cte="WITH RECURSIVE target_ancestors(id) AS (SELECT ? UNION DISTINCT SELECT lr.parent_location_id FROM location_relationship lr JOIN target_ancestors a ON a.id=lr.child_location_id WHERE lr.active=1 AND lr.approval_status='APPROVED' AND lr.effective_from<=CURRENT_DATE() AND (lr.effective_to IS NULL OR lr.effective_to>=CURRENT_DATE())) ";
            $params[]=$targetLocationId;
            $scopePredicate="r.role_level='SYSTEM' OR uas.scope_mode='NATIONAL' OR (uas.scope_mode='EXACT' AND uas.location_id=?) OR (uas.scope_mode='INCLUDE_CHILDREN' AND uas.location_id IN (SELECT id FROM target_ancestors))";
        }else{
            $scopePredicate="r.role_level='SYSTEM' OR uas.scope_mode='NATIONAL' OR r.role_level='NATIONAL'";
        }
        $params[]=$permission;
        if($targetLocationId!==null&&$targetLocationId!=='')$params[]=$targetLocationId;
        array_push($params,...$roleCodes);
        $sql=$cte."SELECT su.id user_id,uar.id role_assignment_id,uas.id scope_assignment_id,r.role_code,r.role_level,uas.scope_mode,uas.location_id
            FROM system_user su
            JOIN user_account_role uar ON uar.user_id=su.id AND uar.active=1 AND uar.approval_status='APPROVED' AND uar.effective_from<=CURRENT_DATE() AND (uar.effective_to IS NULL OR uar.effective_to>=CURRENT_DATE())
            JOIN application_role r ON r.id=uar.role_id AND r.active=1 AND r.approval_status='APPROVED'
            JOIN application_role_permission rp ON rp.role_id=r.id
            JOIN application_permission p ON p.id=rp.permission_id AND p.permission_key=? AND p.active=1
            LEFT JOIN user_account_scope uas ON uas.role_assignment_id=uar.id AND uas.user_id=su.id AND uas.active=1 AND uas.approval_status='APPROVED' AND uas.effective_from<=CURRENT_DATE() AND (uas.effective_to IS NULL OR uas.effective_to>=CURRENT_DATE())
            WHERE su.enabled=1 AND su.account_status='ACTIVE' AND ({$scopePredicate}) {$roleFilter}
            ORDER BY su.id,FIELD(COALESCE(uas.scope_mode,''),'EXACT','INCLUDE_CHILDREN','NATIONAL'),uar.id,uas.id";
        $s=$this->pdo->prepare($sql);$s->execute($params);$out=[];
        foreach($s->fetchAll() as $row){$id=(string)$row['user_id'];if($id===$excludeUserId||isset($out[$id]))continue;$out[$id]=$row;}
        return array_values($out);
    }
}
