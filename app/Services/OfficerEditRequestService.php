<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\{Audit,Auth,ScopeService};
use DomainException;
use PDO;
use Throwable;

final class OfficerEditRequestService
{
    private const EDITABLE_FIELDS=[
        'nic','nic_normalized','nic_match_key','employee_number','title_id','name_with_initials',
        'full_name_en','full_name_si','full_name_ta','date_of_birth','expected_retirement_date',
        'gender','civil_status_id','permanent_address','temporary_address','primary_mobile',
        'alternative_mobile','personal_email','official_email','initial_appointment_date',
        'appointment_nature_id','primary_designation_id','class_id','arpa_service_permanency',
        'service_permanented_date','officer_status_id','effective_from','photograph_path',
    ];

    private const FIELD_LABELS=[
        'nic'=>'NIC','employee_number'=>'Employee Number','title_id'=>'Title',
        'name_with_initials'=>'Name with Initials','full_name_en'=>'Full Name (English)',
        'full_name_si'=>'Full Name (Sinhala)','full_name_ta'=>'Full Name (Tamil)',
        'date_of_birth'=>'Date of Birth','expected_retirement_date'=>'Expected Retirement Date',
        'gender'=>'Gender','civil_status_id'=>'Civil Status','permanent_address'=>'Permanent Address',
        'temporary_address'=>'Temporary Address','primary_mobile'=>'Telephone Number',
        'alternative_mobile'=>'WhatsApp Number','personal_email'=>'Personal Email',
        'official_email'=>'Official Email','initial_appointment_date'=>'First Appointment Date',
        'appointment_nature_id'=>'Appointment Nature','primary_designation_id'=>'Primary Designation',
        'class_id'=>'Class','arpa_service_permanency'=>'Service Permanency',
        'service_permanented_date'=>'Permanented Date','officer_status_id'=>'Officer Status',
        'effective_from'=>'Effective From','photograph_path'=>'Photograph',
    ];

    private const REFERENCE_FIELDS=[
        'title_id'=>'hr_title','civil_status_id'=>'civil_status',
        'appointment_nature_id'=>'appointment_nature','primary_designation_id'=>'designation',
        'class_id'=>'officer_class','officer_status_id'=>'officer_status',
    ];

    public function __construct(private readonly PDO $pdo){}

    public function canInitiate(string $officerId,string $actorId):bool
    {
        try{
            $context=$this->initiationContext($officerId,$actorId);
            $s=$this->pdo->prepare("SELECT approval_status FROM officer WHERE id=?");$s->execute([$officerId]);if((string)$s->fetchColumn()!=='APPROVED')return false;
            $s=$this->pdo->prepare("SELECT COUNT(*) FROM officer_edit_request WHERE officer_id=? AND request_level=? AND workflow_status='SUBMITTED'");$s->execute([$officerId,$context['request_level']]);
            return (int)$s->fetchColumn()===0;
        }catch(DomainException){return false;}
    }

    /** @return array<string,mixed>|null */
    public function returnedForMaker(string $officerId,string $actorId):?array
    {
        if(!Auth::isCurrentUser($actorId)||!Auth::can('officer.edit-request'))return null;
        $context=$this->context($actorId);if($context===null)return null;
        $s=$this->pdo->prepare("SELECT * FROM officer_edit_request WHERE officer_id=? AND submitted_by=? AND workflow_status='RETURNED' ORDER BY returned_at DESC,id DESC LIMIT 1");
        $s->execute([$officerId,$actorId]);$row=$s->fetch();if(!$row)return null;
        try{$this->assertRequestContext($row,$context,true);}catch(DomainException){return null;}
        $row['proposed']=json_decode((string)$row['proposed_json'],true,512,JSON_THROW_ON_ERROR);
        return $row;
    }

    /** @param array<string,mixed> $data */
    public function submit(string $officerId,array $data,int $expectedVersion,string $actorId,?string $returnedRequestId=null):string
    {
        return $this->transaction(function()use($officerId,$data,$expectedVersion,$actorId,$returnedRequestId):string{
            $context=$this->initiationContext($officerId,$actorId);
            $s=$this->pdo->prepare('SELECT * FROM officer WHERE id=? FOR UPDATE');$s->execute([$officerId]);$officer=$s->fetch();
            if(!$officer)throw new DomainException('Officer record was not found.');
            if((string)$officer['approval_status']!=='APPROVED')throw new DomainException('Only an approved Officer profile can use the edit-request workflow.');
            if((int)$officer['version']!==$expectedVersion)throw new DomainException('The Officer changed after this edit form was opened. Reload the profile and try again.');

            $level=(string)$context['request_level'];$district=$context['district_location_id'];$request=null;
            if($returnedRequestId!==null&&$returnedRequestId!==''){
                $q=$this->pdo->prepare('SELECT * FROM officer_edit_request WHERE id=? FOR UPDATE');$q->execute([$returnedRequestId]);$request=$q->fetch();
                if(!$request||(string)$request['officer_id']!==$officerId||(string)$request['workflow_status']!=='RETURNED'||(string)$request['submitted_by']!==$actorId)throw new DomainException('Returned Officer edit request was not found.');
                $this->assertRequestContext($request,$this->context($actorId)??[],true);
                if((int)$request['officer_version']!==(int)$officer['version'])throw new DomainException('Officer information changed after this edit request was submitted. Review the latest Officer data before resubmitting.');
                $prior=json_decode((string)$request['proposed_json'],true,512,JSON_THROW_ON_ERROR);
                $data=array_replace($prior,$data);
            }

            $candidate=$this->candidate($officer,$data);
            (new OfficerAdminDirectEditService($this->pdo))->validateProposedUpdate($officerId,$candidate);
            [$before,$proposed]=$this->changes($officer,$candidate);
            if($proposed===[])throw new DomainException('No Officer profile changes were provided.');

            $pending=$this->pdo->prepare("SELECT id FROM officer_edit_request WHERE officer_id=? AND request_level=? AND workflow_status='SUBMITTED' AND id<>COALESCE(?, '') LIMIT 1 FOR UPDATE");
            $pending->execute([$officerId,$level,$returnedRequestId]);if($pending->fetchColumn())throw new DomainException('This Officer already has a submitted edit request at the same approval level.');

            if($request){
                $id=(string)$request['id'];
                $u=$this->pdo->prepare("UPDATE officer_edit_request SET before_json=?,proposed_json=?,workflow_status='SUBMITTED',submitted_at=NOW(),reviewed_by=NULL,reviewed_at=NULL,review_remarks=NULL,returned_by=NULL,returned_at=NULL,return_reason=NULL,rejected_by=NULL,rejected_at=NULL,rejection_reason=NULL,updated_at=NOW(),version=version+1 WHERE id=? AND workflow_status='RETURNED'");
                $u->execute([$this->json($before),$this->json($proposed),$id]);if($u->rowCount()!==1)throw new DomainException('The returned Officer edit request changed while it was being resubmitted.');
                $action='officer.edit-request.resubmit';
            }else{
                $id=$this->uuid();
                $i=$this->pdo->prepare("INSERT INTO officer_edit_request(id,officer_id,request_level,district_location_id,officer_version,before_json,proposed_json,workflow_status,submitted_by,submitted_at) VALUES(?,?,?,?,?,?,?,'SUBMITTED',?,NOW())");
                $i->execute([$id,$officerId,$level,$district,$officer['version'],$this->json($before),$this->json($proposed),$actorId]);
                $action='officer.edit-request.submit';
            }

            Audit::record($action,'OFFICER_EDIT_REQUEST',$id,['request_id'=>$id,'officer_id'=>$officerId,'officer_dad_number'=>$officer['dad_number'],'request_level'=>$level,'district_location_id'=>$district,'before'=>$before,'proposed'=>$proposed,'working_context'=>$this->auditContext($context)]);
            $notice=new WorkflowNotificationService($this->pdo);$notice->completeStage('OFFICER_EDIT_REQUEST',$id,'CORRECTION',$actorId,'Officer edit request resubmitted');
            $roles=$level==='DISTRICT'?['DISTRICT_ADMIN']:['NATIONAL_ADMIN'];
            $notice->actionForPermission('officer.edit-approve',$district,'OFFICER','Officer Profile Edit Awaiting Approval','Officer profile edit awaiting approval.','OFFICER_EDIT_REQUEST',$id,'APPROVAL','/hr/officer-edit-requests/'.$id,$actorId,$roles);
            return $id;
        });
    }

    /** @return array<int,array<string,mixed>> */
    public function listForActor(string $actorId):array
    {
        if(!Auth::isCurrentUser($actorId)||(!Auth::can('officer.edit-request')&&!Auth::can('officer.edit-approve')))throw new DomainException('Officer edit request access is not permitted.');
        $context=$this->context($actorId)??throw new DomainException('Select an Active Working Context.');$role=(string)$context['role_code'];
        $where=[];$params=[];
        if($role==='DISTRICT_ADMIN'){$where[]="r.request_level='DISTRICT'";$where[]='r.district_location_id=?';$params[]=(string)$context['location_id'];}
        elseif($role==='DISTRICT_SUBJECT_OFFICER'){$where[]='r.submitted_by=?';$params[]=$actorId;$where[]="r.request_level='DISTRICT'";$where[]='r.district_location_id=?';$params[]=(string)$context['location_id'];}
        elseif($role==='NATIONAL_ADMIN'){$where[]="r.request_level='NATIONAL'";}
        elseif($role==='NATIONAL_SUBJECT_OFFICER'){$where[]='r.submitted_by=?';$params[]=$actorId;$where[]="r.request_level='NATIONAL'";}
        else throw new DomainException('The current working context cannot access Officer edit requests.');
        $sql="SELECT r.*,o.dad_number,o.name_with_initials,o.nic,ofc.dad_number office_dad,ofc.name_en office_name,u.display_name submitted_by_name,u.username submitted_by_username
                FROM officer_edit_request r JOIN officer o ON o.id=r.officer_id
                LEFT JOIN office ofc ON ofc.id=o.primary_office_id JOIN system_user u ON u.id=r.submitted_by
                WHERE ".implode(' AND ',$where).' ORDER BY FIELD(r.workflow_status,\'SUBMITTED\',\'RETURNED\',\'APPROVED\',\'REJECTED\'),r.submitted_at DESC,r.id';
        $s=$this->pdo->prepare($sql);$s->execute($params);return $s->fetchAll();
    }

    /** @return array<string,mixed> */
    public function review(string $requestId,string $actorId):array
    {
        $row=$this->requestRow($requestId);$context=$this->approverContext($row,$actorId);
        $row['before']=json_decode((string)$row['before_json'],true,512,JSON_THROW_ON_ERROR);
        $row['proposed']=json_decode((string)$row['proposed_json'],true,512,JSON_THROW_ON_ERROR);
        $row['changes']=$this->presentChanges($row['before'],$row['proposed']);
        $row['can_decide']=(string)$row['workflow_status']==='SUBMITTED'&&(string)$row['submitted_by']!==$actorId;
        $row['working_context']=$this->auditContext($context);
        return $row;
    }

    public function approve(string $requestId,string $actorId):void
    {
        $this->transaction(function()use($requestId,$actorId):void{
            $request=$this->lockedRequest($requestId);$context=$this->approverContext($request,$actorId);
            if((string)$request['workflow_status']!=='SUBMITTED')throw new DomainException('Only a submitted Officer edit request can be approved.');
            if((string)$request['submitted_by']===$actorId)throw new DomainException('Maker cannot approve their own Officer edit request.');
            $s=$this->pdo->prepare('SELECT * FROM officer WHERE id=? FOR UPDATE');$s->execute([$request['officer_id']]);$officer=$s->fetch();if(!$officer)throw new DomainException('Officer record was not found.');
            if((int)$officer['version']!==(int)$request['officer_version'])throw new DomainException('Officer information changed after this edit request was submitted. Review the latest Officer data before approving.');
            $proposed=json_decode((string)$request['proposed_json'],true,512,JSON_THROW_ON_ERROR);$before=json_decode((string)$request['before_json'],true,512,JSON_THROW_ON_ERROR);
            $candidate=$this->candidate($officer,$proposed);(new OfficerAdminDirectEditService($this->pdo))->validateProposedUpdate((string)$officer['id'],$candidate);
            $set=[];$params=[];foreach($proposed as $field=>$value){if(!in_array($field,self::EDITABLE_FIELDS,true))throw new DomainException('Officer edit request contains an unsupported field.');$set[]=$field.'=?';$params[]=$value;}
            $params[]=$actorId;$params[]=$officer['id'];$params[]=$officer['version'];
            $u=$this->pdo->prepare('UPDATE officer SET '.implode(',',$set).',updated_by=?,updated_at=NOW(),version=version+1 WHERE id=? AND version=?');$u->execute($params);if($u->rowCount()!==1)throw new DomainException('Officer information changed while the edit request was being approved.');
            $requestUpdate=$this->pdo->prepare("UPDATE officer_edit_request SET workflow_status='APPROVED',reviewed_by=?,reviewed_at=NOW(),approved_by=?,approved_at=NOW(),updated_at=NOW(),version=version+1 WHERE id=? AND workflow_status='SUBMITTED'");
            $requestUpdate->execute([$actorId,$actorId,$requestId]);
            if($requestUpdate->rowCount()!==1)throw new DomainException('Officer edit request changed while it was being approved.');
            $after=$this->officer((string)$officer['id']);
            Audit::record('officer.edit-request.approve','OFFICER_EDIT_REQUEST',$requestId,['request_id'=>$requestId,'officer_id'=>$officer['id'],'officer_dad_number'=>$officer['dad_number'],'request_level'=>$request['request_level'],'district_location_id'=>$request['district_location_id'],'before'=>$before,'proposed'=>$proposed,'applied'=>array_intersect_key($after,array_flip(array_keys($proposed))),'working_context'=>$this->auditContext($context)]);
            Audit::record('officer.edit-request.apply','OFFICER',(string)$officer['id'],['request_id'=>$requestId,'request_level'=>$request['request_level'],'before'=>$before,'applied'=>array_intersect_key($after,array_flip(array_keys($proposed)))]);
            $notice=new WorkflowNotificationService($this->pdo);$notice->completeStage('OFFICER_EDIT_REQUEST',$requestId,'APPROVAL',$actorId,'Officer profile edit approved');$notice->information((string)$request['submitted_by'],'OFFICER','Officer Profile Edit Approved','Your Officer profile edit request has been approved.','OFFICER_EDIT_REQUEST',$requestId,'/hr/officers/'.$officer['id'],$actorId);
        });
    }

    public function returnForCorrection(string $requestId,string $reason,string $actorId):void
    {
        $this->decide($requestId,$reason,$actorId,'RETURNED');
    }

    public function reject(string $requestId,string $reason,string $actorId):void
    {
        $this->decide($requestId,$reason,$actorId,'REJECTED');
    }

    private function decide(string $requestId,string $reason,string $actorId,string $decision):void
    {
        $reason=trim($reason);if($reason==='')throw new DomainException(($decision==='RETURNED'?'Return':'Rejection').' reason is required.');
        $this->transaction(function()use($requestId,$reason,$actorId,$decision):void{
            $request=$this->lockedRequest($requestId);$context=$this->approverContext($request,$actorId);
            if((string)$request['workflow_status']!=='SUBMITTED')throw new DomainException('Only a submitted Officer edit request can be reviewed.');
            if((string)$request['submitted_by']===$actorId)throw new DomainException('Maker cannot review their own Officer edit request.');
            if($decision==='RETURNED'){
                $sql="UPDATE officer_edit_request SET workflow_status='RETURNED',reviewed_by=?,reviewed_at=NOW(),review_remarks=?,returned_by=?,returned_at=NOW(),return_reason=?,updated_at=NOW(),version=version+1 WHERE id=? AND workflow_status='SUBMITTED'";$params=[$actorId,$reason,$actorId,$reason,$requestId];$action='officer.edit-request.return';$title='Officer Profile Edit Returned';$message='Your Officer profile edit request was returned for correction.';$url='/hr/officers/'.$request['officer_id'].'/edit';
            }else{
                $sql="UPDATE officer_edit_request SET workflow_status='REJECTED',reviewed_by=?,reviewed_at=NOW(),review_remarks=?,rejected_by=?,rejected_at=NOW(),rejection_reason=?,updated_at=NOW(),version=version+1 WHERE id=? AND workflow_status='SUBMITTED'";$params=[$actorId,$reason,$actorId,$reason,$requestId];$action='officer.edit-request.reject';$title='Officer Profile Edit Rejected';$message='Your Officer profile edit request was rejected.';$url='/hr/officer-edit-requests/'.$requestId;
            }
            $u=$this->pdo->prepare($sql);$u->execute($params);if($u->rowCount()!==1)throw new DomainException('Officer edit request changed while it was being reviewed.');
            Audit::record($action,'OFFICER_EDIT_REQUEST',$requestId,['request_id'=>$requestId,'officer_id'=>$request['officer_id'],'request_level'=>$request['request_level'],'district_location_id'=>$request['district_location_id'],'reason'=>$reason,'working_context'=>$this->auditContext($context)]);
            $notice=new WorkflowNotificationService($this->pdo);$notice->completeStage('OFFICER_EDIT_REQUEST',$requestId,'APPROVAL',$actorId,$decision==='RETURNED'?'Returned for correction':'Rejected');
            if($decision==='RETURNED')$notice->actionForUser((string)$request['submitted_by'],'OFFICER',$title,$message,'OFFICER_EDIT_REQUEST',$requestId,'CORRECTION',$url,$actorId);else $notice->information((string)$request['submitted_by'],'OFFICER',$title,$message,'OFFICER_EDIT_REQUEST',$requestId,$url,$actorId);
        });
    }

    /** @return array{request_level:string,district_location_id:?string,role_code:string,role_assignment_id:string,scope_assignment_id:?string,location_id:?string} */
    private function initiationContext(string $officerId,string $actorId):array
    {
        if(!Auth::isCurrentUser($actorId)||!Auth::can('officer.edit-request'))throw new DomainException('Officer profile edit requests are not permitted.');
        $context=$this->context($actorId)??throw new DomainException('Select an Active Working Context.');$role=(string)$context['role_code'];
        if($role==='DISTRICT_SUBJECT_OFFICER'){
            if((string)$context['role_level']!=='DISTRICT'||(string)$context['scope_type']!=='DISTRICT'||(string)$context['scope_mode']!=='INCLUDE_CHILDREN'||empty($context['location_id']))throw new DomainException('A current District Subject Officer working context is required.');
            if(!ScopeService::canAccessOfficer($actorId,$officerId))throw new DomainException('This Officer is outside your current District scope.');
            return $context+['request_level'=>'DISTRICT','district_location_id'=>(string)$context['location_id']];
        }
        if(in_array($role,['NATIONAL_SUBJECT_OFFICER','NATIONAL_ADMIN'],true)){
            if((string)$context['role_level']!=='NATIONAL'||(string)$context['scope_type']!=='NATIONAL'||(string)$context['scope_mode']!=='NATIONAL')throw new DomainException('A current National working context is required.');
            return $context+['request_level'=>'NATIONAL','district_location_id'=>null];
        }
        throw new DomainException('The current working context cannot submit Officer profile edits.');
    }

    /** @param array<string,mixed> $request @return array<string,mixed> */
    private function approverContext(array $request,string $actorId):array
    {
        if(!Auth::isCurrentUser($actorId)||!Auth::can('officer.edit-approve'))throw new DomainException('Officer edit approval is not permitted.');
        $context=$this->context($actorId)??throw new DomainException('Select an Active Working Context.');$this->assertRequestContext($request,$context,false);
        if(!ScopeService::canAccessOfficer($actorId,(string)$request['officer_id']))throw new DomainException('This Officer is outside your current approval scope.');
        return $context;
    }

    /** @param array<string,mixed> $request @param array<string,mixed> $context */
    private function assertRequestContext(array $request,array $context,bool $maker):void
    {
        $level=(string)$request['request_level'];$role=(string)($context['role_code']??'');
        if($level==='DISTRICT'){
            $expected=$maker?'DISTRICT_SUBJECT_OFFICER':'DISTRICT_ADMIN';
            if($role!==$expected||(string)($context['role_level']??'')!=='DISTRICT'||(string)($context['scope_type']??'')!=='DISTRICT'||(string)($context['scope_mode']??'')!=='INCLUDE_CHILDREN'||(string)($context['location_id']??'')!==(string)$request['district_location_id'])throw new DomainException('This Officer edit request is outside your current District context.');
            return;
        }
        $roles=$maker?['NATIONAL_SUBJECT_OFFICER','NATIONAL_ADMIN']:['NATIONAL_ADMIN'];
        if(!in_array($role,$roles,true)||(string)($context['role_level']??'')!=='NATIONAL'||(string)($context['scope_type']??'')!=='NATIONAL'||(string)($context['scope_mode']??'')!=='NATIONAL')throw new DomainException('This Officer edit request requires the appropriate National working context.');
    }

    /** @param array<string,mixed> $officer @param array<string,mixed> $data @return array<string,mixed> */
    private function candidate(array $officer,array $data):array
    {
        $candidate=[];foreach(self::EDITABLE_FIELDS as $field)$candidate[$field]=$officer[$field]??null;
        foreach($data as $field=>$value){if(!in_array($field,self::EDITABLE_FIELDS,true))throw new DomainException('Officer edit request contains an unsupported field.');$candidate[$field]=$value;}
        return $candidate;
    }

    /** @return array{0:array<string,mixed>,1:array<string,mixed>} */
    private function changes(array $officer,array $candidate):array
    {
        $before=[];$proposed=[];foreach(self::EDITABLE_FIELDS as $field){$old=$officer[$field]??null;$new=$candidate[$field]??null;if($old==$new)continue;$before[$field]=$old;$proposed[$field]=$new;}return [$before,$proposed];
    }

    /** @return array<int,array{field:string,label:string,current:string,proposed:string}> */
    private function presentChanges(array $before,array $proposed):array
    {
        $refs=[];foreach(self::REFERENCE_FIELDS as $field=>$table){foreach([$before[$field]??null,$proposed[$field]??null] as $id)if($id!==null&&$id!=='')$refs[$table][(string)$id]=null;}
        foreach($refs as $table=>$ids){$marks=implode(',',array_fill(0,count($ids),'?'));$s=$this->pdo->prepare("SELECT id,name_en FROM {$table} WHERE id IN({$marks})");$s->execute(array_keys($ids));foreach($s->fetchAll() as $row)$refs[$table][(string)$row['id']]=(string)$row['name_en'];}
        $rows=[];foreach($proposed as $field=>$value){if(in_array($field,['nic_normalized','nic_match_key','expected_retirement_date'],true))continue;$old=$before[$field]??null;$format=function(mixed $v)use($field,$refs):string{if($v===null||$v==='')return '—';if($field==='photograph_path')return 'Photograph uploaded';if(isset(self::REFERENCE_FIELDS[$field]))return $refs[self::REFERENCE_FIELDS[$field]][(string)$v]??'Unknown reference';if($field==='arpa_service_permanency')return ucwords(strtolower(str_replace('_',' ',(string)$v)));return (string)$v;};$rows[]=['field'=>$field,'label'=>self::FIELD_LABELS[$field]??ucwords(str_replace('_',' ',$field)),'current'=>$format($old),'proposed'=>$format($value)];}return $rows;
    }

    /** @return array<string,mixed> */
    private function requestRow(string $id):array
    {
        $s=$this->pdo->prepare("SELECT r.*,o.dad_number,o.name_with_initials,o.nic,o.primary_office_id,ofc.dad_number office_dad,ofc.name_en office_name,u.display_name submitted_by_name,u.username submitted_by_username FROM officer_edit_request r JOIN officer o ON o.id=r.officer_id LEFT JOIN office ofc ON ofc.id=o.primary_office_id JOIN system_user u ON u.id=r.submitted_by WHERE r.id=?");$s->execute([$id]);$row=$s->fetch();if(!$row)throw new DomainException('Officer edit request was not found.');return $row;
    }
    private function lockedRequest(string $id):array{$s=$this->pdo->prepare('SELECT * FROM officer_edit_request WHERE id=? FOR UPDATE');$s->execute([$id]);$r=$s->fetch();if(!$r)throw new DomainException('Officer edit request was not found.');return $r;}
    private function officer(string $id):array{$s=$this->pdo->prepare('SELECT * FROM officer WHERE id=?');$s->execute([$id]);return $s->fetch()?:[];}
    private function context(string $actorId):?array{return Auth::activeContextForUser($actorId);}
    private function auditContext(array $context):array{return ['role_code'=>$context['role_code']??null,'role_assignment_id'=>$context['role_assignment_id']??null,'scope_assignment_id'=>$context['scope_assignment_id']??null,'scope_location_id'=>$context['location_id']??null];}
    private function json(mixed $value):string{return json_encode($value,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);}
    private function uuid():string{return (string)$this->pdo->query('SELECT UUID()')->fetchColumn();}
    private function transaction(callable $work):mixed{$own=!$this->pdo->inTransaction();if($own)$this->pdo->beginTransaction();try{$result=$work();if($own)$this->pdo->commit();return $result;}catch(Throwable $e){if($own&&$this->pdo->inTransaction())$this->pdo->rollBack();throw $e;}}
}
