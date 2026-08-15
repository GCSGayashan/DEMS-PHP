<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\ScopeService;
use PDO;

final class ArpaWorkflowQueuePolicy
{
    private const PROFILES = [
        'arpa.appointment.asc-verify' => ['status'=>'SUBMITTED','action'=>'VERIFY','stage'=>'ASC','result'=>'ASC_VERIFIED','label'=>'ASC verification'],
        'arpa.appointment.asc-approve' => ['status'=>'ASC_VERIFIED','action'=>'APPROVE','stage'=>'ASC','result'=>'ASC_APPROVED','label'=>'ASC approval'],
        'arpa.appointment.district-verify' => ['status'=>'ASC_APPROVED','action'=>'VERIFY','stage'=>'DISTRICT','result'=>'DISTRICT_VERIFIED','label'=>'District verification'],
        'arpa.appointment.district-approve' => ['status'=>'DISTRICT_VERIFIED','action'=>'APPROVE','stage'=>'DISTRICT','result'=>'DISTRICT_APPROVED','label'=>'District approval'],
        'arpa.appointment.national-verify' => ['status'=>'DISTRICT_APPROVED','action'=>'VERIFY','stage'=>'NATIONAL','result'=>'NATIONAL_VERIFIED','label'=>'National verification'],
        'arpa.appointment.national-approve' => ['status'=>'NATIONAL_VERIFIED','action'=>'APPROVE','stage'=>'NATIONAL','result'=>'NATIONAL_APPROVED','label'=>'National approval'],
    ];

    public function __construct(private readonly PDO $pdo) {}

    /** @return array<int,array<string,string>> */
    public function profiles(string $userId): array
    {
        $permissions=$this->permissions($userId);$profiles=[];
        foreach(self::PROFILES as $permission=>$profile){
            if(isset($permissions[$permission])&&ScopeService::hasArpaStageScope($userId,$profile['stage']))$profiles[]=$profile+['permission'=>$permission];
        }
        return $profiles;
    }

    public function canUseWorkflowQueues(string $userId): bool
    {
        return $this->profiles($userId)!==[];
    }

    public function canCorrectReturnedRequest(string $userId,string $ascLocationId,?string $effectiveDate=null):bool
    {
        foreach($this->profiles($userId) as $profile){
            if($profile['permission']==='arpa.appointment.asc-verify'){
                return ScopeService::canAccessArpaStage($userId,'ASC',$ascLocationId,$effectiveDate);
            }
        }
        return false;
    }

    /**
     * Produces one reusable scope CTE and a role/stage predicate for request queues.
     * System users remain enterprise-scoped; National actions require an explicit
     * NATIONAL scope for all non-system users.
     *
     * @return array{with:string,params:array<int,string>,where:string,profiles:array<int,array<string,string>>}
     */
    public function requestAccess(string $userId,string $alias='r'): array
    {
        $profiles=$this->profiles($userId);
        if($profiles===[])return ['with'=>'','params'=>[],'where'=>'1=0','profiles'=>[]];
        $system=ScopeService::scopeProfile($userId)['level']==='SYSTEM';
        $scoped=array_values(array_filter($profiles,fn(array $p):bool=>!$system&&$p['stage']!=='NATIONAL'));
        $with=$scoped===[]?'':ScopeService::arpaWorkflowScopeCte();
        $params=$scoped===[]?[]:[$userId];$clauses=[];
        foreach($profiles as $profile){
            $scope=($system||$profile['stage']==='NATIONAL')?'1=1':"EXISTS(SELECT 1 FROM workflow_scope_locations wsl WHERE wsl.stage='{$profile['stage']}' AND wsl.id={$alias}.asc_location_id)";
            $statuses=$profile['stage']==='ASC'&&$profile['action']==='VERIFY'?"{$alias}.workflow_status IN('SUBMITTED','RETURNED')":"{$alias}.workflow_status='{$profile['status']}'";
            $clauses[]="({$statuses} AND {$scope})";
        }
        return ['with'=>$with,'params'=>$params,'where'=>'('.implode(' OR ',$clauses).')','profiles'=>$profiles];
    }

    /** @return array{with:string,params:array<int,string>,where:string,profiles:array<int,array<string,string>>} */
    public function completedAccess(string $userId,string $requestAlias='r',string $eventAlias='w'):array
    {
        $profiles=$this->profiles($userId);
        if($profiles===[])return ['with'=>'','params'=>[],'where'=>'1=0','profiles'=>[]];
        $system=ScopeService::scopeProfile($userId)['level']==='SYSTEM';
        $scoped=array_values(array_filter($profiles,fn(array $p):bool=>!$system&&$p['stage']!=='NATIONAL'));
        $with=$scoped===[]?'':ScopeService::arpaWorkflowScopeCte();
        $params=$scoped===[]?[]:[$userId];$clauses=[];
        foreach($profiles as $profile){
            $scope=($system||$profile['stage']==='NATIONAL')?'1=1':"EXISTS(SELECT 1 FROM workflow_scope_locations wsl WHERE wsl.stage='{$profile['stage']}' AND wsl.id={$requestAlias}.asc_location_id)";
            $cycle="{$eventAlias}.id>COALESCE((SELECT MAX(boundary.id) FROM arpa_appointment_workflow_action boundary WHERE boundary.request_id={$requestAlias}.id AND boundary.action IN('RETURN_FOR_CORRECTION','REJECT')),0)";
            $clauses[]="({$eventAlias}.action='{$profile['action']}' AND {$eventAlias}.stage='{$profile['stage']}' AND {$cycle} AND {$scope})";
        }
        return ['with'=>$with,'params'=>$params,'where'=>'('.implode(' OR ',$clauses).')','profiles'=>$profiles];
    }

    public function actionableCount(string $userId): int
    {
        $access=$this->requestAccess($userId,'q');
        $sql=$access['with']."SELECT COUNT(*) FROM (
            SELECT workflow_status,asc_location_id FROM arpa_division_appointment_request WHERE record_origin='NATIVE' AND legacy_history_only=0
            UNION ALL
            SELECT workflow_status,asc_location_id FROM arpa_subject_assignment_request WHERE record_origin='NATIVE' AND legacy_history_only=0
        ) q WHERE {$access['where']}";
        $stmt=$this->pdo->prepare($sql);$stmt->execute($access['params']);return (int)$stmt->fetchColumn();
    }

    /** @return array<string,true> */
    private function permissions(string $userId): array
    {
        $stmt=$this->pdo->prepare("SELECT DISTINCT p.permission_key FROM user_account_role uar JOIN application_role r ON r.id=uar.role_id JOIN application_role_permission rp ON rp.role_id=r.id JOIN application_permission p ON p.id=rp.permission_id WHERE uar.user_id=? AND uar.active=1 AND uar.approval_status='APPROVED' AND uar.effective_from<=CURRENT_DATE() AND (uar.effective_to IS NULL OR uar.effective_to>=CURRENT_DATE()) AND r.active=1 AND r.approval_status='APPROVED' AND p.active=1");
        $stmt->execute([$userId]);return array_fill_keys($stmt->fetchAll(PDO::FETCH_COLUMN),true);
    }
}
