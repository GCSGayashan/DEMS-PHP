<?php
declare(strict_types=1);
namespace App\Services;

use PDO;

final class NotificationBackfillService
{
    public function __construct(private readonly PDO $pdo){}
    /** @return array<string,int> */
    public function run(bool $execute=false):array
    {
        $counts=['officers'=>0,'office_assignments'=>0,'arpa'=>0,'arpa_end_terminal_skipped'=>0,'users'=>0,'roles'=>0,'scopes'=>0,'locations'=>0,'offices'=>0,'notifications_created'=>0];$w=new WorkflowNotificationService($this->pdo);
        $apply=function(array $rows,string $key,callable $callback)use(&$counts,$execute):void{$counts[$key]=count($rows);if($execute)foreach($rows as $row)$counts['notifications_created']+=(int)$callback($row);};
        $apply($this->pdo->query("SELECT id,workflow_origin_role_code,workflow_scope_location_id,submitted_by FROM officer WHERE approval_status='SUBMITTED'")->fetchAll(),'officers',fn($r)=>$w->actionForPermission('officer.approve',$r['workflow_scope_location_id']?:null,'OFFICER','New Officer Awaiting Approval','A submitted Officer record is ready for review.','OFFICER',$r['id'],'APPROVAL','/hr/officers/'.$r['id'],$r['submitted_by']?:'',array_values(array_filter([$r['workflow_origin_role_code']==='DISTRICT_SUBJECT_OFFICER'?'DISTRICT_ADMIN':($r['workflow_origin_role_code']==='NATIONAL_SUBJECT_OFFICER'?'NATIONAL_ADMIN':null)]))));
        $apply($this->pdo->query("SELECT a.id,a.submitted_by,o.linked_location_id FROM officer_office_assignment a JOIN office o ON o.id=a.office_id WHERE a.approval_status='SUBMITTED'")->fetchAll(),'office_assignments',fn($r)=>$w->actionForPermission('officer.office-assignment.approve',$r['linked_location_id']?:null,'OFFICER','Office Assignment Awaiting Approval','An Officer Office Assignment is ready for review.','OFFICER_OFFICE_ASSIGNMENT',$r['id'],'APPROVAL','/hr/officers/office-assignments/'.$r['id'].'/review',$r['submitted_by']?:''));
        foreach(['division'=>'arpa_division_appointment_request','subject'=>'arpa_subject_assignment_request'] as $entity=>$table){
            $rows=$this->pdo->query("SELECT id,asc_location_id,workflow_status,created_by,updated_by,request_type FROM {$table} WHERE record_origin='NATIVE' AND workflow_status IN('SUBMITTED','ASC_VERIFIED','ASC_APPROVED','DISTRICT_VERIFIED','DISTRICT_APPROVED','NATIONAL_VERIFIED','RETURNED')")->fetchAll();$counts['arpa']+=count($rows);
            foreach($rows as $r){
                if(self::isDivisionEndAfterTerminalAscApproval($entity,(string)$r['request_type'],(string)$r['workflow_status'])){$counts['arpa_end_terminal_skipped']++;continue;}
                if(!$execute)continue;
                $map=self::arpaActionFor($entity,(string)$r['request_type'],(string)$r['workflow_status']);
                if($map!==null){$counts['notifications_created']+=$w->actionForPermission($map[0],$r['asc_location_id'],'ARPA_APPOINTMENT','ARPA Workflow Action Required','An ARPA workflow item requires action.',strtoupper('ARPA_'.$entity.'_REQUEST'),$r['id'],$r['workflow_status'],'/hr/arpa-appointments/requests/'.$entity.'/'.$r['id'],$r['updated_by']?:$r['created_by'],$map[1],$r['workflow_status']!=='SUBMITTED');}
                elseif(self::isArpaCorrection((string)$r['workflow_status'])&&!empty($r['created_by'])){$w->actionForUser($r['created_by'],'ARPA_APPOINTMENT','ARPA Request Returned for Correction','Correction is required.',strtoupper('ARPA_'.$entity.'_REQUEST'),$r['id'],'CORRECTION','/hr/arpa-appointments/requests/'.$entity.'/'.$r['id'].'/edit',$r['updated_by']?:$r['created_by']);$counts['notifications_created']++;}
            }
        }
        $generic=[
            'users'=>["SELECT id,requested_by actor,NULL location_id FROM system_user WHERE approval_status='SUBMITTED'",'user.approve','SYSTEM_USER','User Account Awaiting Approval','/access-management/account-requests'],
            'roles'=>["SELECT uar.id,uar.submitted_by actor,uas.location_id FROM user_account_role uar LEFT JOIN user_account_scope uas ON uas.role_assignment_id=uar.id WHERE uar.approval_status='SUBMITTED'",'user.assign-role','USER_ROLE','User Role Assignment Awaiting Approval','/access-management/role-assignments'],
            'scopes'=>["SELECT id,submitted_by actor,location_id FROM user_account_scope WHERE approval_status='SUBMITTED'",'user.assign-scope','USER_SCOPE','User Scope Assignment Awaiting Approval','/access-management/scope-assignments'],
            'locations'=>["SELECT id,created_by actor,id location_id FROM location WHERE approval_status='SUBMITTED'",'location.approve','LOCATION','Location Awaiting Approval',null],
            'offices'=>["SELECT id,submitted_by actor,linked_location_id location_id FROM office WHERE approval_status='SUBMITTED'",'office.approve','OFFICE','Office Awaiting Approval',null],
        ];
        foreach($generic as $key=>[$sql,$permission,$type,$title,$url]){$rows=$this->pdo->query($sql)->fetchAll();$apply($rows,$key,function($r)use($w,$permission,$type,$title,$url){return $w->actionForPermission($permission,$r['location_id']?:null,$type==='USER_ROLE'||$type==='USER_SCOPE'||$type==='SYSTEM_USER'?'ACCESS_MANAGEMENT':'ORGANIZATION',$title,'A submitted workflow item is ready for review.',$type,$r['id'],'APPROVAL',$url??('/'.strtolower($type).'s/'.$r['id']),$r['actor']?:'');});}
        return $counts;
    }

    /** @return array{0:string,1:array<int,string>}|null */
    public static function arpaActionFor(string $entity,string $requestType,string $status):?array
    {
        if($entity==='division'&&$requestType==='END'){
            return match($status){
                'SUBMITTED'=>['arpa.appointment.asc-verify',['ASC_SUBJECT_OFFICER']],
                'ASC_VERIFIED'=>['arpa.appointment.asc-approve',['ASC_ADMIN']],
                default=>null,
            };
        }
        return match($status){
            'SUBMITTED'=>['arpa.appointment.asc-verify',['ASC_SUBJECT_OFFICER']],
            'ASC_VERIFIED'=>['arpa.appointment.asc-approve',['ASC_ADMIN']],
            'ASC_APPROVED'=>['arpa.appointment.district-verify',['DISTRICT_SUBJECT_OFFICER']],
            'DISTRICT_VERIFIED'=>['arpa.appointment.district-approve',['DISTRICT_ADMIN']],
            'DISTRICT_APPROVED'=>['arpa.appointment.national-verify',['NATIONAL_SUBJECT_OFFICER']],
            'NATIONAL_VERIFIED'=>['arpa.appointment.national-approve',['NATIONAL_ADMIN']],
            default=>null,
        };
    }

    public static function isDivisionEndAfterTerminalAscApproval(string $entity,string $requestType,string $status):bool
    {
        return $entity==='division'&&$requestType==='END'&&in_array($status,['ASC_APPROVED','DISTRICT_VERIFIED','DISTRICT_APPROVED','NATIONAL_VERIFIED'],true);
    }

    public static function isArpaCorrection(string $status):bool{return $status==='RETURNED';}
}
