<?php
declare(strict_types=1);

namespace App\Services;

use DomainException;
use PDO;

final class UserContextService
{
    private const SCOPED_LEVELS = ['NATIONAL','DISTRICT','ASC','ARPA','FARMER'];

    public function __construct(private readonly PDO $pdo) {}

    /** @return array<int,array<string,mixed>> */
    public function availableContexts(string $userId,?string $date=null):array
    {
        $date ??= date('Y-m-d');
        $stmt=$this->pdo->prepare("SELECT
                    uar.id role_assignment_id,uar.role_id,uar.effective_from role_effective_from,
                    uar.effective_to role_effective_to,r.role_code,r.role_name,r.role_level,
                    uas.id scope_assignment_id,uas.scope_type,uas.scope_mode,uas.location_id,
                    uas.effective_from scope_effective_from,uas.effective_to scope_effective_to,
                    l.id resolved_location_id,l.dad_number location_dad_number,l.name_en location_name
                FROM system_user su
                JOIN user_account_role uar ON uar.user_id=su.id
                JOIN application_role r ON r.id=uar.role_id
                LEFT JOIN user_account_scope uas
                  ON uas.role_assignment_id=uar.id AND uas.user_id=uar.user_id
                 AND uas.active=1 AND uas.approval_status='APPROVED'
                 AND uas.effective_from<=? AND (uas.effective_to IS NULL OR uas.effective_to>=?)
                LEFT JOIN location l ON l.id=uas.location_id
                WHERE su.id=? AND su.enabled=1 AND su.account_status='ACTIVE' AND su.approval_status='APPROVED'
                  AND uar.active=1 AND uar.approval_status='APPROVED'
                  AND uar.effective_from<=? AND (uar.effective_to IS NULL OR uar.effective_to>=?)
                  AND r.active=1 AND r.approval_status='APPROVED'
                ORDER BY FIELD(r.role_level,'SYSTEM','NATIONAL','DISTRICT','ASC','ARPA','FARMER','CUSTOM','LEGACY'),
                         r.role_name,l.name_en,uas.id");
        $stmt->execute([$date,$date,$userId,$date,$date]);
        $contexts=[];
        foreach($stmt->fetchAll() as $row){
            $scopeId=$row['scope_assignment_id']===null?null:(string)$row['scope_assignment_id'];
            if(in_array((string)$row['role_level'],self::SCOPED_LEVELS,true)&&$scopeId===null){
                continue;
            }
            if(!$this->compatibleScope((string)$row['role_level'],$scopeId,$row['scope_type'],$row['scope_mode'])){
                continue;
            }
            if(in_array((string)$row['role_level'],['DISTRICT','ASC','ARPA','FARMER'],true)
                && ($row['location_id']===null||$row['resolved_location_id']===null)){
                continue;
            }
            $roleFrom=(string)$row['role_effective_from'];$scopeFrom=$row['scope_effective_from'];
            $roleTo=$row['role_effective_to'];$scopeTo=$row['scope_effective_to'];
            $row['effective_from']=$scopeFrom===null?$roleFrom:max($roleFrom,(string)$scopeFrom);
            $row['effective_to']=$this->earliestDate($roleTo,$scopeTo);
            $row['location_label']=in_array((string)$row['role_level'],['SYSTEM','NATIONAL'],true)
                ? 'National'
                : ($row['location_name']!==null
                    ? trim((string)$row['location_dad_number'].' - '.(string)$row['location_name'],' -')
                    : 'No administrative scope');
            $contexts[]=$row;
        }
        return $contexts;
    }

    /** @return array<string,mixed>|null */
    public function resolve(string $userId,string $roleAssignmentId,?string $scopeAssignmentId,?string $date=null):?array
    {
        foreach($this->availableContexts($userId,$date) as $context){
            if((string)$context['role_assignment_id']!==$roleAssignmentId){
                continue;
            }
            $contextScope=$context['scope_assignment_id']===null?null:(string)$context['scope_assignment_id'];
            if($contextScope===$scopeAssignmentId){
                return $context;
            }
        }
        return null;
    }

    /** @return array<string,mixed> */
    public function select(string $userId,string $roleAssignmentId,?string $scopeAssignmentId):array
    {
        $context=$this->resolve($userId,$roleAssignmentId,$scopeAssignmentId);
        if($context===null){
            throw new DomainException('The selected role or office is no longer available.');
        }
        $_SESSION['access_context']=[
            'role_assignment_id'=>(string)$context['role_assignment_id'],
            'scope_assignment_id'=>$context['scope_assignment_id']===null?null:(string)$context['scope_assignment_id'],
        ];
        return $context;
    }

    public function clear():void
    {
        unset($_SESSION['access_context']);
    }

    private function earliestDate(mixed $first,mixed $second):?string
    {
        $dates=array_values(array_filter([$first,$second],static fn($value):bool=>$value!==null&&$value!==''));
        return $dates===[]?null:(string)min($dates);
    }

    private function compatibleScope(string $roleLevel,?string $scopeId,mixed $scopeType,mixed $scopeMode):bool
    {
        if($roleLevel==='SYSTEM')return $scopeId===null||($scopeType==='NATIONAL'&&$scopeMode==='NATIONAL');
        $expected=[
            'NATIONAL'=>['NATIONAL','NATIONAL'],
            'DISTRICT'=>['DISTRICT','INCLUDE_CHILDREN'],
            'ASC'=>['ASC','EXACT'],
            'ARPA'=>['ARPA_DIVISION','EXACT'],
            'FARMER'=>['ASC','EXACT'],
        ][$roleLevel]??null;
        return $expected===null?$scopeId===null:($scopeId!==null&&$scopeType===$expected[0]&&$scopeMode===$expected[1]);
    }
}
