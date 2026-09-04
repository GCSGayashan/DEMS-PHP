<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\{Auth,ScopeService};
use DomainException;
use PDO;
use Throwable;

final class ArpaAppointmentDataIssueCorrectionService
{
    public const PERMISSION='arpa.appointment.data-issue.correct';
    public const CORRECTABLE_ISSUES=[
        'DIVISION_MULTIPLE_OPEN','OFFICER_MULTIPLE_PERMANENT','OFFICER_MULTIPLE_ACTING',
        'OFFICER_MULTIPLE_ATTEND_TO_DUTY','DEPENDENT_WITHOUT_PERMANENT',
        'PERMANENT_SERVICE_WITH_ATTEND_TO_DUTY','NON_PERMANENT_SERVICE_WITH_ACTING',
        'APPOINTMENT_OUTSIDE_ASC','INVALID_DATE_RANGE','ENDED_APPOINTMENT_WITHOUT_END_REASON',
        'LEGACY_HISTORICAL_EXCEPTION',
    ];
    public const ACTIONS=[
        'MARK_HISTORICAL_ONLY','SET_EFFECTIVE_TO','CORRECT_APPOINTMENT_TYPE',
        'CORRECT_ARPA_DIVISION','CORRECT_EFFECTIVE_FROM','CORRECT_END_REASON',
        'SELECT_CURRENT_RECORD','KEEP_AS_HISTORICAL_EXCEPTION',
        'RESOLVE_CANONICAL_ASSIGNMENT','CLEAR_EFFECTIVE_TO',
    ];

    public function __construct(private readonly PDO $pdo){}

    public function canCorrect(string $userId,string $ascLocationId):bool
    {
        $context=Auth::activeContextForUser($userId);
        if($context!==null){
            return $context['role_code']==='ASC_SUBJECT_OFFICER'
                && Auth::can(self::PERMISSION)
                && ScopeService::canAccessCurrentArpaStage($userId,'ASC',$ascLocationId);
        }
        $sql="SELECT COUNT(*) FROM user_account_role uar
              JOIN system_user su ON su.id=uar.user_id AND su.enabled=1 AND su.account_status='ACTIVE'
              JOIN application_role r ON r.id=uar.role_id AND r.role_code='ASC_SUBJECT_OFFICER' AND r.active=1 AND r.approval_status='APPROVED'
              JOIN application_role_permission rp ON rp.role_id=r.id
              JOIN application_permission p ON p.id=rp.permission_id AND p.permission_key=? AND p.active=1
              JOIN user_account_scope uas ON uas.role_assignment_id=uar.id AND uas.user_id=uar.user_id AND uas.scope_type='ASC' AND uas.scope_mode='EXACT' AND uas.location_id=?
              WHERE uar.user_id=? AND uar.active=1 AND uar.approval_status='APPROVED'
                AND uar.effective_from<=CURRENT_DATE() AND (uar.effective_to IS NULL OR uar.effective_to>=CURRENT_DATE())
                AND uas.active=1 AND uas.approval_status='APPROVED'
                AND uas.effective_from<=CURRENT_DATE() AND (uas.effective_to IS NULL OR uas.effective_to>=CURRENT_DATE())";
        $s=$this->pdo->prepare($sql);$s->execute([self::PERMISSION,$ascLocationId,$userId]);
        return (int)$s->fetchColumn()>0&&ScopeService::canAccessCurrentArpaStage($userId,'ASC',$ascLocationId);
    }

    public function issue(string $rowKey):?array
    {
        $sql='SELECT q.* FROM '.ArpaAppointmentReadService::issueSource().' q WHERE q.row_key=?';
        $s=$this->pdo->prepare($sql);$s->execute([$rowKey]);$row=$s->fetch();return $row?:null;
    }

    public function detail(string $rowKey,string $viewerId):array
    {
        $issue=$this->issue($rowKey);
        if(!$issue){
            $s=$this->pdo->prepare('SELECT issue_row_key FROM arpa_appointment_data_correction WHERE issue_row_key=? ORDER BY corrected_at DESC,id DESC LIMIT 1');$s->execute([$rowKey]);
            if(!$s->fetchColumn())throw new DomainException('Appointment data issue was not found.');
            $issue=$this->issueFromLedger($rowKey);
        }
        if(!ScopeService::canAccessLocation($viewerId,(string)$issue['asc_location_id'],date('Y-m-d')))throw new DomainException('You cannot view issues outside your assigned location.');
        $ids=$this->relatedIds((string)$issue['related_ids']);$appointments=$this->appointments($ids);
        $historicalRequest=$issue['issue_type']==='LEGACY_HISTORICAL_EXCEPTION'?$this->historicalRequest((string)$issue['related_ids']):null;
        $singleAsc=$appointments!==[]?$this->allAppointmentsBelongToAsc($appointments,(string)$issue['asc_location_id']):($historicalRequest!==null&&(string)$historicalRequest['asc_location_id']===(string)$issue['asc_location_id']);
        $presentation=ArpaAppointmentIssuePresentation::for((string)$issue['issue_type']);
        $hierarchyContexts=[];
        foreach($appointments as $appointment){
            if(empty($appointment['arpa_division_location_id'])||empty($appointment['asc_location_id'])||empty($appointment['effective_from']))continue;
            $context=(new ArpaAppointmentLocationPolicy())->hierarchyContext($this->pdo,(string)$appointment['arpa_division_location_id'],(string)$appointment['asc_location_id'],(string)$appointment['effective_from']);
            $context['appointment_id']=$appointment['id'];
            $context['recorded_asc_name']=$appointment['asc_name_snapshot'];
            $context['recorded_arpa_name']=$appointment['arpa_name_snapshot'];
            $hierarchyContexts[]=$context;
        }
        return [
            'issue'=>$issue,
            'presentation'=>$presentation,
            'appointments'=>$appointments,
            'historical_request'=>$historicalRequest,
            'hierarchy_contexts'=>$hierarchyContexts,
            'permanent_appointments'=>$issue['issue_type']==='DEPENDENT_WITHOUT_PERMANENT'?$this->permanentAppointments((string)$issue['officer_id']):[],
            'corrections'=>$this->corrections($rowKey),
            'end_reasons'=>$this->pdo->query('SELECT id,system_key,name_en FROM arpa_appointment_end_reason WHERE active=1 ORDER BY display_order,name_en')->fetchAll(),
            'arpa_divisions'=>$this->arpaDivisions((string)$issue['asc_location_id']),
            'officers'=>$historicalRequest!==null?$this->arpaOfficers((string)$issue['asc_location_id'],(string)$historicalRequest['officer_id']):[],
            'correctable'=>in_array($issue['issue_type'],self::CORRECTABLE_ISSUES,true)&&$singleAsc&&$this->canCorrect($viewerId,(string)$issue['asc_location_id']),
            'technical_details_allowed'=>$this->canViewTechnicalDetails($viewerId),
        ];
    }

    public function correct(string $rowKey,array $input,string $actorId):array
    {
        $reason=trim((string)($input['correction_reason']??''));
        if($reason==='')throw new DomainException('Reason for Correction is required.');
        $action=strtoupper(trim((string)($input['correction_action']??'')));
        if(!in_array($action,self::ACTIONS,true))throw new DomainException('Unsupported data-issue correction action.');
        return $this->transaction(function()use($rowKey,$input,$actorId,$reason,$action):array{
            $issue=$this->issue($rowKey);
            if(!$issue){
                $s=$this->pdo->prepare("SELECT COUNT(*) FROM arpa_appointment_data_correction WHERE issue_row_key=? AND resolution_status='RESOLVED_BY_CORRECTION'");$s->execute([$rowKey]);
                if((int)$s->fetchColumn()>0)throw new DomainException('This Data Issue has already been resolved.');
                throw new DomainException('The issue is no longer active. Refresh the diagnostic before correcting it.');
            }
            if(!in_array($issue['issue_type'],self::CORRECTABLE_ISSUES,true))throw new DomainException('This record cannot be corrected on this page. Use the normal appointment process.');
            if(!$this->canCorrect($actorId,(string)$issue['asc_location_id']))throw new DomainException('Only the assigned ASC Subject Officer can correct this issue.');
            if($action==='RESOLVE_CANONICAL_ASSIGNMENT')return $this->resolveHistoricalRequest($rowKey,$issue,$input,$actorId,$reason);
            if($issue['issue_type']==='LEGACY_HISTORICAL_EXCEPTION'&&$action==='KEEP_AS_HISTORICAL_EXCEPTION')return $this->keepHistoricalRequest($rowKey,$issue,$input,$actorId,$reason);
            $related=$this->relatedIds((string)$issue['related_ids']);
            if($related===[])throw new DomainException('No appointment record is linked to this issue.');
            $this->lockAppointments($related);
            $before=$this->appointments($related);
            if($before===[])throw new DomainException('The related appointment records no longer exist.');
            if(!$this->allAppointmentsBelongToAsc($before,(string)$issue['asc_location_id']))throw new DomainException('The records belong to more than one ASC. They must be reviewed separately.');
            $correctionId=$this->uuid();
            $selected=trim((string)($input['appointment_id']??''));
            if($selected!==''&&!in_array($selected,$related,true))throw new DomainException('The selected appointment is not part of this issue.');
            $target=$selected!==''?$this->appointment($selected):$before[0];
            $this->apply($action,$target,$before,$input,$correctionId);
            $after=$this->appointments($related);
            $remaining=$this->issue($rowKey)!==null;
            $resolution=$action==='KEEP_AS_HISTORICAL_EXCEPTION'?'KEPT_HISTORICAL_EXCEPTION':($remaining?'REVIEWED_UNRESOLVED':'RESOLVED_BY_CORRECTION');
            $origins=array_values(array_unique(array_column($before,'record_origin')));$origin=count($origins)===1?$origins[0]:'MIXED';
            $requestId=$target['request_id']??null;
            $this->pdo->prepare('INSERT INTO arpa_appointment_data_correction(id,issue_row_key,issue_type,officer_id,appointment_id,request_id,related_appointment_ids_json,asc_location_id,corrected_by,correction_action,resolution_status,correction_reason,remarks,evidence_reference,before_json,after_json,record_origin,legacy_source_references_json) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)')
                ->execute([$correctionId,$rowKey,$issue['issue_type'],$issue['officer_id'],$target['id']??null,$requestId,$this->json($related),$issue['asc_location_id'],$actorId,$action,$resolution,$reason,$this->nullText($input['remarks']??null),$this->nullText($input['evidence_reference']??null),$this->json($before),$this->json($after),$origin,$this->json($this->legacyReferences($before))]);
            $this->pdo->prepare("INSERT INTO audit_event(actor_user_id,action_key,target_type,target_id,details_json,severity,source_ip) VALUES(?,'arpa.appointment.data-issue.correct','ARPA_APPOINTMENT_DATA_ISSUE',?,?, 'WARNING',?)")
                ->execute([$actorId,$correctionId,$this->json(['issue_row_key'=>$rowKey,'issue_type'=>$issue['issue_type'],'action'=>$action,'resolution_status'=>$resolution,'reason'=>$reason,'appointment_ids'=>$related]),$_SERVER['REMOTE_ADDR']??'CLI']);
            return ['correction_id'=>$correctionId,'resolution_status'=>$resolution,'issue_remaining'=>$remaining];
        });
    }

    public function correctionsForOfficer(string $officerId,?array $ascIds=null):array
    {
        $sql='SELECT c.*,u.username,u.display_name,l.name_en asc_name,a.appointment_type,a.arpa_name_snapshot FROM arpa_appointment_data_correction c JOIN system_user u ON u.id=c.corrected_by JOIN location l ON l.id=c.asc_location_id LEFT JOIN arpa_division_appointment a ON a.id=c.appointment_id WHERE c.officer_id=?';$params=[$officerId];
        if($ascIds!==null){if($ascIds===[])return [];$sql.=' AND c.asc_location_id IN('.implode(',',array_fill(0,count($ascIds),'?')).')';$params=array_merge($params,$ascIds);}
        $sql.=' ORDER BY c.corrected_at DESC,c.id DESC';$s=$this->pdo->prepare($sql);$s->execute($params);return $s->fetchAll();
    }

    public function correctionsForAppointment(string $appointmentId):array
    {
        $s=$this->pdo->prepare('SELECT c.*,COALESCE(NULLIF(u.display_name,\'\'),u.username) corrected_by_name FROM arpa_appointment_data_correction c JOIN system_user u ON u.id=c.corrected_by WHERE c.appointment_id=? OR JSON_CONTAINS(c.related_appointment_ids_json,JSON_QUOTE(?)) ORDER BY c.corrected_at,c.id');
        $s->execute([$appointmentId,$appointmentId]);return $s->fetchAll();
    }

    public function canonicalCorrectionForm(string $appointmentId,string $viewerId):array
    {
        $appointment=$this->appointment($appointmentId);
        if(!$this->isDataIssueCanonical($appointmentId))throw new DomainException('Only an assignment created through historical Data Issue resolution can be corrected here.');
        if(!$this->canCorrect($viewerId,(string)$appointment['asc_location_id']))throw new DomainException('You cannot correct this assignment from your current working context.');
        return ['record'=>$appointment,'end_reasons'=>$this->pdo->query('SELECT id,system_key,name_en FROM arpa_appointment_end_reason WHERE active=1 ORDER BY display_order,name_en')->fetchAll()];
    }

    public function correctCanonicalEndDate(string $appointmentId,array $input,string $actorId):array
    {
        $reason=trim((string)($input['correction_reason']??''));
        if($reason==='')throw new DomainException('Reason for Correction is required.');
        $status=strtoupper(trim((string)($input['appointment_status']??'')));
        if(!in_array($status,['OPEN','CLOSED'],true))throw new DomainException('Select whether the assignment is Open or Closed.');
        return $this->transaction(function()use($appointmentId,$input,$actorId,$reason,$status):array{
            $this->lockAppointments([$appointmentId]);
            $before=$this->appointment($appointmentId);
            if(!$this->isDataIssueCanonical($appointmentId))throw new DomainException('Only an assignment created through historical Data Issue resolution can be corrected here.');
            if(!$this->canCorrect($actorId,(string)$before['asc_location_id']))throw new DomainException('You cannot correct this assignment from your current working context.');
            $effectiveTo=$status==='CLOSED'?$this->date($input['effective_to']??null,'End Date'):null;
            if($effectiveTo!==null&&$effectiveTo<(string)$before['effective_from'])throw new DomainException('The end date cannot be earlier than the start date.');
            $endReason=$status==='CLOSED'&&$this->nullText($input['end_reason_id']??null)!==null?$this->endReason($input['end_reason_id']):null;
            if(($before['effective_to']??null)===$effectiveTo&&($before['end_reason_id']??null)===$endReason)throw new DomainException('No assignment date or end-reason change was provided.');
            $this->assertHistoricalPeriodAvailable((string)$before['arpa_division_location_id'],(string)$before['effective_from'],$effectiveTo,$appointmentId,(string)$before['request_id']);
            $this->assertFollowingContinuity($appointmentId,(string)$before['arpa_division_location_id'],(string)$before['effective_from'],$effectiveTo,(string)$before['request_id']);
            $correctionId=$this->uuid();$action=$effectiveTo===null?'CLEAR_EFFECTIVE_TO':'SET_EFFECTIVE_TO';
            if($effectiveTo===null){
                if($before['closure_id']!==null)$this->pdo->prepare('DELETE FROM arpa_division_appointment_closure WHERE id=?')->execute([$before['closure_id']]);
            }elseif($before['closure_id']!==null){
                $this->pdo->prepare('UPDATE arpa_division_appointment_closure SET effective_to=?,end_reason_id=?,data_correction_id=? WHERE id=?')->execute([$effectiveTo,$endReason,$correctionId,$before['closure_id']]);
            }else{
                $metadata=$this->decode((string)$before['origin_metadata_json']);$metadata['last_data_issue_correction_id']=$correctionId;
                $this->pdo->prepare("INSERT INTO arpa_division_appointment_closure(id,record_origin,appointment_id,request_id,effective_to,end_reason_id,closure_kind,closure_source,data_correction_id,remarks,context_snapshot_json,approved_by,approved_at,approval_timestamp_provenance,origin_metadata_json) VALUES(?,'LEGACY_IMPORT',?,?,?,?,'DIRECT','DATA_ISSUE_CORRECTION',?,?,?,NULL,NULL,'UNAVAILABLE_FROM_LEGACY_SOURCE',?)")
                    ->execute([$this->uuid(),$appointmentId,$before['request_id'],$effectiveTo,$endReason,$correctionId,'Corrected historical assignment end date.',$before['hierarchy_snapshot_json'],$this->json($metadata)]);
            }
            $this->pdo->prepare('UPDATE arpa_division_appointment_request SET requested_effective_to=?,end_reason_id=?,updated_by=?,updated_at=NOW(),version=version+1 WHERE id=?')->execute([$effectiveTo,$endReason,$actorId,$before['request_id']]);
            $after=$this->appointment($appointmentId);$rowKey='CANONICAL_ASSIGNMENT:'.$appointmentId;
            $this->writeCorrection($correctionId,$rowKey,'CANONICAL_END_DATE_CORRECTION',$after,$actorId,$action,$reason,$input,[$before],[$after]);
            return ['correction_id'=>$correctionId,'appointment_id'=>$appointmentId,'appointment_status'=>$effectiveTo===null?'OPEN':'CLOSED'];
        });
    }

    public function correction(string $id,string $viewerId):array
    {
        $s=$this->pdo->prepare('SELECT c.*,u.username,u.display_name,o.dad_number officer_number,o.name_with_initials officer_name,o.nic,l.dad_number asc_number,l.name_en asc_name FROM arpa_appointment_data_correction c JOIN system_user u ON u.id=c.corrected_by JOIN officer o ON o.id=c.officer_id JOIN location l ON l.id=c.asc_location_id WHERE c.id=?');$s->execute([$id]);$row=$s->fetch();if(!$row)throw new DomainException('Correction record was not found.');
        if(!ScopeService::canAccessLocation($viewerId,(string)$row['asc_location_id'],date('Y-m-d')))throw new DomainException('You cannot view corrections outside your assigned location.');
        $row['technical_details_allowed']=$this->canViewTechnicalDetails($viewerId);
        return $row;
    }

    private function resolveHistoricalRequest(string $rowKey,array $issue,array $input,string $actorId,string $reason):array
    {
        if($issue['issue_type']!=='LEGACY_HISTORICAL_EXCEPTION')throw new DomainException('This historical issue cannot be converted from this page.');
        $requestId=trim((string)$issue['related_ids']);
        $request=$this->historicalRequest($requestId,true);
        if(!$request)throw new DomainException('The historical appointment request was not found.');
        if((string)$request['record_origin']!=='LEGACY_IMPORT')throw new DomainException('Only an imported historical request can be resolved through this action.');
        $existing=$this->pdo->prepare('SELECT id FROM arpa_division_appointment WHERE request_id=?');$existing->execute([$requestId]);
        if($existing->fetchColumn())throw new DomainException('This Data Issue has already been resolved.');

        $officerId=trim((string)($input['officer_id']??$request['officer_id']));
        $appointmentType=strtoupper(trim((string)($input['appointment_type']??$request['appointment_type'])));
        $divisionId=trim((string)($input['arpa_division_location_id']??$request['arpa_division_location_id']));
        $effectiveFrom=$this->date($input['effective_from']??$request['requested_effective_from'],'Start Date');
        $status=strtoupper(trim((string)($input['appointment_status']??'OPEN')));
        if(!in_array($status,['OPEN','CLOSED'],true))throw new DomainException('Select whether the assignment is Open or Closed.');
        $effectiveTo=$status==='CLOSED'?$this->date($input['effective_to']??null,'End Date'):null;
        if($effectiveTo!==null&&$effectiveTo<$effectiveFrom)throw new DomainException('The end date cannot be earlier than the start date.');
        if(!in_array($appointmentType,ArpaAppointmentRules::APPOINTMENT_TYPES,true))throw new DomainException('Select a valid appointment type.');
        $this->assertArpaOfficer($officerId);
        $ascId=(string)$request['asc_location_id'];$snapshot=$this->locationSnapshot($ascId,$divisionId,$effectiveFrom);
        $endReason=$status==='CLOSED'&&$this->nullText($input['end_reason_id']??null)!==null?$this->endReason($input['end_reason_id']):null;
        $this->assertHistoricalPeriodAvailable($divisionId,$effectiveFrom,$effectiveTo,null,$requestId);

        $permanency=$this->nullText($input['service_permanency_snapshot']??$request['source_service_permanency_snapshot']??null);
        if($permanency!==null&&!in_array($permanency,['PERMANENT_IN_SERVICE','NOT_PERMANENT_IN_SERVICE'],true))throw new DomainException('Select a valid service permanency value or leave it unknown.');
        $permanencySource=(string)($request['source_service_permanency_source']??'UNRESOLVED');
        if(!in_array($permanencySource,['EXACT_PERMANENTED_DATE','CURRENT_STATE_ONLY','UNRESOLVED'],true))$permanencySource='UNRESOLVED';
        if($permanency===null)$permanencySource='UNRESOLVED';

        $correctionId=$this->uuid();$appointmentId=$this->uuid();$before=['request'=>$request,'canonical_appointment'=>null];
        $metadata=$this->decode((string)$request['origin_metadata_json']);
        $metadata['data_issue_resolution']=['correction_id'=>$correctionId,'issue_row_key'=>$rowKey,'resolved_by'=>$actorId,'resolved_at'=>date(DATE_ATOM),'authority'=>'APPOINTMENT_DATA_ISSUE'];
        $exceptionCodes=$this->decode((string)$request['legacy_exception_codes_json']);
        if(!in_array('DATA_ISSUE_RESOLUTION',$exceptionCodes,true))$exceptionCodes[]='DATA_ISSUE_RESOLUTION';
        $hierarchy=$this->json($snapshot+['original_location_snapshot'=>$this->decode((string)$request['location_snapshot_json'])]);

        $this->pdo->prepare('UPDATE arpa_division_appointment_request SET officer_id=?,appointment_type=?,arpa_division_location_id=?,requested_effective_from=?,requested_effective_to=?,end_reason_id=?,request_remarks=?,location_snapshot_json=?,legacy_history_only=0,legacy_exception=1,legacy_exception_codes_json=?,updated_by=?,updated_at=NOW(),origin_metadata_json=?,version=version+1 WHERE id=?')
            ->execute([$officerId,$appointmentType,$divisionId,$effectiveFrom,$effectiveTo,$endReason,$this->nullText($input['remarks']??null)??$request['request_remarks'],$hierarchy,$this->json($exceptionCodes),$actorId,$this->json($metadata),$requestId]);
        if(!empty($request['business_record_id']))$this->pdo->prepare('UPDATE legacy_arpa_appointment_business_record SET officer_id=? WHERE id=?')->execute([$officerId,$request['business_record_id']]);
        $this->pdo->prepare("INSERT INTO arpa_division_appointment(id,record_origin,request_id,officer_id,appointment_type,service_permanency_snapshot,service_permanency_source,province_location_id_snapshot,district_location_id_snapshot,asc_location_id,arpa_division_location_id,province_dad_snapshot,province_name_snapshot,district_dad_snapshot,district_name_snapshot,asc_dad_snapshot,asc_name_snapshot,arpa_dad_snapshot,arpa_name_snapshot,hierarchy_snapshot_json,effective_from,legacy_history_only,legacy_exception,legacy_exception_codes_json,approved_by,approved_at,approval_timestamp_provenance,origin_metadata_json) VALUES(?,'LEGACY_IMPORT',?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,0,1,?,NULL,NULL,'UNAVAILABLE_FROM_LEGACY_SOURCE',?)")
            ->execute([$appointmentId,$requestId,$officerId,$appointmentType,$permanency,$permanencySource,$snapshot['province']['id'],$snapshot['district']['id'],$ascId,$divisionId,$snapshot['province']['dad_number'],$snapshot['province']['name_en'],$snapshot['district']['dad_number'],$snapshot['district']['name_en'],$snapshot['asc']['dad_number'],$snapshot['asc']['name_en'],$snapshot['arpa']['dad_number'],$snapshot['arpa']['name_en'],$hierarchy,$effectiveFrom,$this->json($exceptionCodes),$this->json($metadata)]);
        if($effectiveTo!==null){
            $this->pdo->prepare("INSERT INTO arpa_division_appointment_closure(id,record_origin,appointment_id,request_id,effective_to,end_reason_id,closure_kind,closure_source,data_correction_id,remarks,context_snapshot_json,approved_by,approved_at,approval_timestamp_provenance,origin_metadata_json) VALUES(?,'LEGACY_IMPORT',?,?,?,?,'DIRECT','DATA_ISSUE_CORRECTION',?,?,?,NULL,NULL,'UNAVAILABLE_FROM_LEGACY_SOURCE',?)")
                ->execute([$this->uuid(),$appointmentId,$requestId,$effectiveTo,$endReason,$correctionId,'Resolved historical Appointment Data Issue.',$hierarchy,$this->json($metadata)]);
        }
        $this->pdo->prepare('UPDATE legacy_arpa_appointment_source_reference SET target_appointment_id=? WHERE target_appointment_request_id=?')->execute([$appointmentId,$requestId]);
        $after=$this->appointment($appointmentId);
        $this->writeCorrection($correctionId,$rowKey,(string)$issue['issue_type'],$after,$actorId,'RESOLVE_CANONICAL_ASSIGNMENT',$reason,$input,$before,[$after]);
        return ['correction_id'=>$correctionId,'appointment_id'=>$appointmentId,'resolution_status'=>'RESOLVED_BY_CORRECTION','issue_remaining'=>false,'appointment_status'=>$effectiveTo===null?'OPEN':'CLOSED'];
    }

    private function keepHistoricalRequest(string $rowKey,array $issue,array $input,string $actorId,string $reason):array
    {
        $request=$this->historicalRequest(trim((string)$issue['related_ids']),true);if(!$request)throw new DomainException('The historical appointment request was not found.');
        $correctionId=$this->uuid();$before=['request'=>$request,'canonical_appointment'=>null];
        $this->pdo->prepare("INSERT INTO arpa_appointment_data_correction(id,issue_row_key,issue_type,officer_id,appointment_id,request_id,related_appointment_ids_json,asc_location_id,corrected_by,correction_action,resolution_status,correction_reason,remarks,evidence_reference,before_json,after_json,record_origin,legacy_source_references_json) VALUES(?,?,?,?,NULL,?,JSON_ARRAY(),?,?,'KEEP_AS_HISTORICAL_EXCEPTION','KEPT_HISTORICAL_EXCEPTION',?,?,?,?,?,'LEGACY_IMPORT',?)")
            ->execute([$correctionId,$rowKey,$issue['issue_type'],$request['officer_id'],$request['id'],$request['asc_location_id'],$actorId,$reason,$this->nullText($input['remarks']??null),$this->nullText($input['evidence_reference']??null),$this->json($before),$this->json($before),$this->json($this->sourceReferencesForRequest((string)$request['id']))]);
        $this->pdo->prepare("INSERT INTO audit_event(actor_user_id,action_key,target_type,target_id,details_json,severity,source_ip) VALUES(?,'arpa.appointment.data-issue.correct','ARPA_APPOINTMENT_DATA_ISSUE',?,?,'WARNING',?)")
            ->execute([$actorId,$correctionId,$this->json(['issue_row_key'=>$rowKey,'issue_type'=>$issue['issue_type'],'action'=>'KEEP_AS_HISTORICAL_EXCEPTION','resolution_status'=>'KEPT_HISTORICAL_EXCEPTION','reason'=>$reason,'request_id'=>$request['id']]),$_SERVER['REMOTE_ADDR']??'CLI']);
        return ['correction_id'=>$correctionId,'resolution_status'=>'KEPT_HISTORICAL_EXCEPTION','issue_remaining'=>false];
    }

    private function writeCorrection(string $id,string $rowKey,string $issueType,array $target,string $actorId,string $action,string $reason,array $input,array $before,array $after):void
    {
        $this->pdo->prepare('INSERT INTO arpa_appointment_data_correction(id,issue_row_key,issue_type,officer_id,appointment_id,request_id,related_appointment_ids_json,asc_location_id,corrected_by,correction_action,resolution_status,correction_reason,remarks,evidence_reference,before_json,after_json,record_origin,legacy_source_references_json) VALUES(?,?,?,?,?,?,?,?,?,?,\'RESOLVED_BY_CORRECTION\',?,?,?,?,?,?,?)')
            ->execute([$id,$rowKey,$issueType,$target['officer_id'],$target['id'],$target['request_id'],$this->json([$target['id']]),$target['asc_location_id'],$actorId,$action,$reason,$this->nullText($input['remarks']??null),$this->nullText($input['evidence_reference']??null),$this->json($before),$this->json($after),'LEGACY_IMPORT',$this->json($this->sourceReferencesForRequest((string)$target['request_id']))]);
        $this->pdo->prepare("INSERT INTO audit_event(actor_user_id,action_key,target_type,target_id,details_json,severity,source_ip) VALUES(?,'arpa.appointment.data-issue.correct','ARPA_APPOINTMENT_DATA_ISSUE',?,?,'WARNING',?)")
            ->execute([$actorId,$id,$this->json(['issue_row_key'=>$rowKey,'issue_type'=>$issueType,'action'=>$action,'resolution_status'=>'RESOLVED_BY_CORRECTION','reason'=>$reason,'appointment_id'=>$target['id'],'before'=>$before,'after'=>$after]),$_SERVER['REMOTE_ADDR']??'CLI']);
    }

    private function apply(string $action,array $target,array $all,array $input,string $correctionId):void
    {
        if($action==='KEEP_AS_HISTORICAL_EXCEPTION'){
            if($target['record_origin']!=='LEGACY_IMPORT'&&(int)$target['legacy_exception']!==1)throw new DomainException('Only an old imported record can be kept as a historical record.');
            return;
        }
        if($action==='MARK_HISTORICAL_ONLY'){
            $this->assertLegacyCorrection($target);$this->pdo->prepare('UPDATE arpa_division_appointment SET legacy_history_only=1 WHERE id=?')->execute([$target['id']]);
            $this->pdo->prepare("UPDATE arpa_division_appointment_request SET legacy_history_only=1,updated_at=NOW(),version=version+1 WHERE id=? AND record_origin='LEGACY_IMPORT'")->execute([$target['request_id']]);return;
        }
        if($action==='SELECT_CURRENT_RECORD'){
            $selected=(string)($input['appointment_id']??'');if($selected==='')throw new DomainException('Select the genuinely current appointment.');
            foreach($all as $row){if($row['id']===$selected)continue;$this->assertLegacyCorrection($row);$this->pdo->prepare('UPDATE arpa_division_appointment SET legacy_history_only=1 WHERE id=?')->execute([$row['id']]);$this->pdo->prepare("UPDATE arpa_division_appointment_request SET legacy_history_only=1,updated_at=NOW(),version=version+1 WHERE id=? AND record_origin='LEGACY_IMPORT'")->execute([$row['request_id']]);}return;
        }
        if($action==='SET_EFFECTIVE_TO'){$this->setEffectiveTo($target,$input,$correctionId);return;}
        if($action==='CORRECT_APPOINTMENT_TYPE'){
            $type=strtoupper(trim((string)($input['appointment_type']??'')));if(!in_array($type,ArpaAppointmentRules::APPOINTMENT_TYPES,true))throw new DomainException('Select a valid corrected appointment type.');if($type===$target['appointment_type'])throw new DomainException('The corrected appointment type must differ from the current value.');
            $this->pdo->prepare('UPDATE arpa_division_appointment SET appointment_type=? WHERE id=?')->execute([$type,$target['id']]);return;
        }
        if($action==='CORRECT_EFFECTIVE_FROM'){
            $date=$this->date($input['effective_from']??null,'Correct Start Date');if($target['effective_to']!==null&&$target['effective_to']<$date)throw new DomainException('The corrected start date cannot be after the end date.');(new ArpaDivisionContinuityService($this->pdo))->assertCanStart((string)$target['arpa_division_location_id'],$date,(string)$target['request_id'],(string)$target['id'],false);$this->pdo->prepare('UPDATE arpa_division_appointment SET effective_from=? WHERE id=?')->execute([$date,$target['id']]);return;
        }
        if($action==='CORRECT_END_REASON'){
            if($target['closure_id']===null)throw new DomainException('An end reason can only be corrected on an ended appointment.');$reason=$this->endReason($input['end_reason_id']??null);$this->pdo->prepare('UPDATE arpa_division_appointment_closure SET end_reason_id=?,data_correction_id=? WHERE id=?')->execute([$reason,$correctionId,$target['closure_id']]);return;
        }
        if($action==='CORRECT_ARPA_DIVISION'){
            if($this->nullText($input['evidence_reference']??null)===null)throw new DomainException('Evidence reference is required to correct an ARPA Division.');
            $arpaId=trim((string)($input['arpa_division_location_id']??''));$snapshot=$this->locationSnapshot((string)$target['asc_location_id'],$arpaId,(string)$target['effective_from']);
            (new ArpaDivisionContinuityService($this->pdo))->assertCanStart($arpaId,(string)$target['effective_from'],(string)$target['request_id'],(string)$target['id'],false);
            $this->pdo->prepare('UPDATE arpa_division_appointment SET province_location_id_snapshot=?,district_location_id_snapshot=?,arpa_division_location_id=?,province_dad_snapshot=?,province_name_snapshot=?,district_dad_snapshot=?,district_name_snapshot=?,arpa_dad_snapshot=?,arpa_name_snapshot=?,hierarchy_snapshot_json=? WHERE id=?')
                ->execute([$snapshot['province']['id'],$snapshot['district']['id'],$snapshot['arpa']['id'],$snapshot['province']['dad_number'],$snapshot['province']['name_en'],$snapshot['district']['dad_number'],$snapshot['district']['name_en'],$snapshot['arpa']['dad_number'],$snapshot['arpa']['name_en'],$this->json($snapshot),$target['id']]);
            if($target['closure_id']!==null)$this->pdo->prepare('UPDATE arpa_division_appointment_closure SET context_snapshot_json=?,data_correction_id=? WHERE id=?')->execute([$this->json($snapshot),$correctionId,$target['closure_id']]);return;
        }
        throw new DomainException('This action is not a permitted data correction.');
    }

    private function setEffectiveTo(array $target,array $input,string $correctionId):void
    {
        $date=$this->date($input['effective_to']??null,'Correct End Date');if($date<$target['effective_from'])throw new DomainException('The corrected end date cannot be earlier than the start date.');
        $reason=$this->nullText($input['end_reason_id']??null);if($reason!==null)$reason=$this->endReason($reason);
        if($target['closure_id']!==null){$sql='UPDATE arpa_division_appointment_closure SET effective_to=?,data_correction_id=?';$params=[$date,$correctionId];if($reason!==null){$sql.=',end_reason_id=?';$params[]=$reason;}$sql.=' WHERE id=?';$params[]=$target['closure_id'];$this->pdo->prepare($sql)->execute($params);return;}
        $legacy=$target['record_origin']==='LEGACY_IMPORT';$metadata=$this->decode((string)$target['origin_metadata_json']);$metadata['data_issue_correction_id']=$correctionId;
        $this->pdo->prepare('INSERT INTO arpa_division_appointment_closure(id,record_origin,appointment_id,request_id,effective_to,end_reason_id,closure_kind,closure_source,data_correction_id,remarks,context_snapshot_json,approved_by,approved_at,approval_timestamp_provenance,origin_metadata_json) VALUES(?,?,?,?,?, ?,\'DIRECT\',\'DATA_ISSUE_CORRECTION\',?,?,?,NULL,NULL,?,?)')
            ->execute([$this->uuid(),$target['record_origin'],$target['id'],$target['request_id'],$date,$reason,$correctionId,'Direct appointment data-issue correction; see append-only correction ledger.',$target['hierarchy_snapshot_json'],$legacy?'UNAVAILABLE_FROM_LEGACY_SOURCE':'DATA_CORRECTION_RECORDED',$this->json($metadata)]);
    }

    private function appointments(array $ids):array
    {
        if($ids===[])return [];$marks=implode(',',array_fill(0,count($ids),'?'));
        $sql="SELECT a.*,r.workflow_status,r.legacy_history_only request_legacy_history_only,r.origin_metadata_json request_origin_metadata_json,c.id closure_id,c.effective_to,c.end_reason_id,c.legacy_reason_text,c.closure_source,er.name_en end_reason_name
              FROM arpa_division_appointment a JOIN arpa_division_appointment_request r ON r.id=a.request_id
              LEFT JOIN arpa_division_appointment_closure c ON c.appointment_id=a.id
              LEFT JOIN arpa_appointment_end_reason er ON er.id=c.end_reason_id WHERE a.id IN({$marks}) ORDER BY a.effective_from,a.id";
        $s=$this->pdo->prepare($sql);$s->execute($ids);return $s->fetchAll();
    }
    private function historicalRequest(string $id,bool $lock=false):?array
    {
        if(preg_match('/^[0-9a-f-]{36}$/i',$id)!==1)return null;
        $sql="SELECT r.*,br.id business_record_id,br.reconciled_business_key,br.reconciliation_class,br.source_snapshot_json,
                     JSON_UNQUOTE(JSON_EXTRACT(br.source_snapshot_json,'$.service_permanency_snapshot')) source_service_permanency_snapshot,
                     JSON_UNQUOTE(JSON_EXTRACT(br.source_snapshot_json,'$.service_permanency_source')) source_service_permanency_source
              FROM arpa_division_appointment_request r
              LEFT JOIN legacy_arpa_appointment_source_reference sr ON sr.id=(SELECT MIN(sr1.id) FROM legacy_arpa_appointment_source_reference sr1 WHERE sr1.target_appointment_request_id=r.id)
              LEFT JOIN legacy_arpa_appointment_business_record br ON br.id=sr.business_record_id
              WHERE r.id=? AND r.record_origin='LEGACY_IMPORT'".($lock?' FOR UPDATE':'');
        $s=$this->pdo->prepare($sql);$s->execute([$id]);$row=$s->fetch();return $row?:null;
    }
    private function arpaOfficers(string $ascId,string $selectedOfficerId):array
    {
        $sql="SELECT DISTINCT o.id,o.dad_number,o.name_with_initials,o.nic
              FROM officer o JOIN designation d ON d.id=o.primary_designation_id AND d.system_key='ARPA_OFFICER'
              LEFT JOIN officer_office_assignment oa ON oa.officer_id=o.id AND oa.active=1 AND oa.approval_status='APPROVED' AND oa.effective_from<=CURRENT_DATE() AND (oa.effective_to IS NULL OR oa.effective_to>=CURRENT_DATE())
              LEFT JOIN office ofc ON ofc.id=oa.office_id AND ofc.linked_location_id=?
              WHERE o.id=? OR ofc.id IS NOT NULL ORDER BY o.name_with_initials,o.dad_number";
        $s=$this->pdo->prepare($sql);$s->execute([$ascId,$selectedOfficerId]);return $s->fetchAll();
    }
    private function assertArpaOfficer(string $officerId):void
    {
        $s=$this->pdo->prepare("SELECT COUNT(*) FROM officer o JOIN designation d ON d.id=o.primary_designation_id AND d.system_key='ARPA_OFFICER' WHERE o.id=?");$s->execute([$officerId]);
        if((int)$s->fetchColumn()!==1)throw new DomainException('Select a valid ARPA Officer.');
    }
    private function isDataIssueCanonical(string $appointmentId):bool
    {
        $s=$this->pdo->prepare("SELECT COUNT(*) FROM arpa_appointment_data_correction WHERE appointment_id=? AND correction_action='RESOLVE_CANONICAL_ASSIGNMENT' AND resolution_status='RESOLVED_BY_CORRECTION'");$s->execute([$appointmentId]);return (int)$s->fetchColumn()>0;
    }
    private function sourceReferencesForRequest(string $requestId):array
    {
        $s=$this->pdo->prepare('SELECT source_system,source_table,legacy_appointment_id FROM legacy_arpa_appointment_source_reference WHERE target_appointment_request_id=? ORDER BY id');$s->execute([$requestId]);return $s->fetchAll();
    }
    private function assertHistoricalPeriodAvailable(string $divisionId,string $from,?string $to,?string $excludeAppointmentId,?string $excludeRequestId):void
    {
        $end=$to??'9999-12-31';
        $s=$this->pdo->prepare("SELECT MIN(a.effective_from) FROM arpa_division_appointment a LEFT JOIN arpa_division_appointment_closure c ON c.appointment_id=a.id WHERE a.arpa_division_location_id=? AND a.id<>COALESCE(?,'') AND (a.legacy_history_only=0 OR c.id IS NOT NULL) AND a.effective_from<=? AND COALESCE(c.effective_to,'9999-12-31')>=?");
        $s->execute([$divisionId,$excludeAppointmentId,$end,$from]);$overlap=$s->fetchColumn();
        if($overlap){if($to===null)throw new DomainException('This assignment cannot be reopened because another assignment already starts on '.$this->displayDate((string)$overlap).'.');throw new DomainException('The corrected appointment period overlaps another canonical assignment for this ARPA Division.');}
        $statuses="'".implode("','",ArpaAppointmentReadService::RESERVING_REQUEST_STATUSES)."'";
        $s=$this->pdo->prepare("SELECT MIN(r.requested_effective_from) FROM arpa_division_appointment_request r WHERE r.arpa_division_location_id=? AND r.id<>COALESCE(?,'') AND r.record_origin='NATIVE' AND r.legacy_history_only=0 AND r.workflow_status IN({$statuses}) AND r.requested_effective_from<=? AND COALESCE(r.requested_effective_to,'9999-12-31')>=?");
        $s->execute([$divisionId,$excludeRequestId,$end,$from]);$reservation=$s->fetchColumn();if($reservation)throw new DomainException('The corrected appointment period overlaps a submitted or scheduled assignment starting on '.$this->displayDate((string)$reservation).'.');
        $s=$this->pdo->prepare("SELECT r.id FROM arpa_division_appointment_request r WHERE r.record_origin='LEGACY_IMPORT' AND r.legacy_exception=1 AND r.arpa_division_location_id=? AND r.id<>COALESCE(?,'') AND NOT EXISTS(SELECT 1 FROM arpa_division_appointment a WHERE a.request_id=r.id) AND r.requested_effective_from<=? AND COALESCE(r.requested_effective_to,'9999-12-31')>=? LIMIT 1");
        $s->execute([$divisionId,$excludeRequestId,$end,$from]);if($s->fetchColumn())throw new DomainException('This ARPA Division has another unresolved historical appointment record for the corrected period. Resolve that Appointment Data Issue first.');
    }
    private function assertFollowingContinuity(string $appointmentId,string $divisionId,string $from,?string $to,string $requestId):void
    {
        $statuses="'".implode("','",ArpaAppointmentReadService::RESERVING_REQUEST_STATUSES)."'";
        $sql="SELECT MIN(next_start) FROM (
                SELECT a.effective_from next_start FROM arpa_division_appointment a WHERE a.arpa_division_location_id=? AND a.id<>? AND a.effective_from>?
                UNION ALL
                SELECT r.requested_effective_from FROM arpa_division_appointment_request r WHERE r.arpa_division_location_id=? AND r.id<>? AND r.record_origin='NATIVE' AND r.legacy_history_only=0 AND r.workflow_status IN({$statuses}) AND r.requested_effective_from>?
              ) n";
        $s=$this->pdo->prepare($sql);$s->execute([$divisionId,$appointmentId,$from,$divisionId,$requestId,$from]);$next=$s->fetchColumn();if(!$next)return;
        if($to===null)throw new DomainException('This assignment cannot be reopened because another assignment already starts on '.$this->displayDate((string)$next).'.');
        $required=(new \DateTimeImmutable($to))->modify('+1 day')->format('Y-m-d');
        if($required<(string)$next)throw new DomainException('The corrected end date would leave an uncovered period before the next assignment on '.$this->displayDate((string)$next).'.');
        if($required>(string)$next)throw new DomainException('The corrected end date overlaps the next assignment starting on '.$this->displayDate((string)$next).'.');
    }
    private function appointment(string $id):array{$rows=$this->appointments([$id]);if(!$rows)throw new DomainException('Appointment was not found.');return $rows[0];}
    private function permanentAppointments(string $officerId):array{$s=$this->pdo->prepare("SELECT a.id,a.record_origin,a.arpa_name_snapshot,a.asc_name_snapshot,a.effective_from,c.effective_to,a.legacy_history_only,a.legacy_exception FROM arpa_division_appointment a LEFT JOIN arpa_division_appointment_closure c ON c.appointment_id=a.id WHERE a.officer_id=? AND a.appointment_type='PERMANENT' ORDER BY a.effective_from");$s->execute([$officerId]);return $s->fetchAll();}
    private function corrections(string $rowKey):array{$s=$this->pdo->prepare('SELECT c.*,u.username,u.display_name FROM arpa_appointment_data_correction c JOIN system_user u ON u.id=c.corrected_by WHERE c.issue_row_key=? ORDER BY c.corrected_at DESC,c.id DESC');$s->execute([$rowKey]);return $s->fetchAll();}
    private function issueFromLedger(string $rowKey):array{$s=$this->pdo->prepare('SELECT c.issue_row_key row_key,c.issue_type,c.resolution_status severity,c.officer_id,o.dad_number officer_number,o.name_with_initials officer_name,o.nic,c.asc_location_id,l.name_en asc_name,COALESCE(a.arpa_name_snapshot,\'Reviewed issue\') arpa_divisions,COALESCE(a.appointment_type,\'\') appointment_types,COALESCE(CONCAT(a.effective_from,\' to Reviewed\'),\'Reviewed\') effective_periods,JSON_UNQUOTE(c.related_appointment_ids_json) related_ids,c.record_origin origin,\'Reviewed appointment data issue\' explanation,c.resolution_status recommended_action FROM arpa_appointment_data_correction c JOIN officer o ON o.id=c.officer_id JOIN location l ON l.id=c.asc_location_id LEFT JOIN arpa_division_appointment a ON a.id=c.appointment_id WHERE c.issue_row_key=? ORDER BY c.corrected_at DESC,c.id DESC LIMIT 1');$s->execute([$rowKey]);$row=$s->fetch();if(!$row)throw new DomainException('Correction record was not found.');$decoded=json_decode((string)$row['related_ids'],true);$row['related_ids']=is_array($decoded)?implode(',',$decoded):(string)$row['related_ids'];return $row;}
    private function arpaDivisions(string $ascId):array{$s=$this->pdo->prepare("SELECT l.id,l.dad_number,l.name_en FROM location_relationship lr JOIN location l ON l.id=lr.child_location_id JOIN location_type lt ON lt.id=l.location_type_id AND lt.system_key='ARPA_DIVISION' WHERE lr.parent_location_id=? AND lr.relationship_type='ASC_ARPA_DIVISION' AND lr.active=1 AND lr.approval_status='APPROVED' ORDER BY l.name_en,l.dad_number");$s->execute([$ascId]);return $s->fetchAll();}
    private function locationSnapshot(string $ascId,string $arpaId,string $date):array
    {
        $validationDate=ArpaAppointmentLocationPolicy::validationDate($date);
        $arpaAsc=ArpaAppointmentLocationPolicy::relationshipAtSql('aa','?');
        $districtAsc=ArpaAppointmentLocationPolicy::relationshipAtSql('da','?');
        $provinceDistrict=ArpaAppointmentLocationPolicy::relationshipAtSql('pd','?');
        $sql="SELECT a.id asc_id,a.dad_number asc_dad,a.name_en asc_name,ar.id arpa_id,ar.dad_number arpa_dad,ar.name_en arpa_name,d.id district_id,d.dad_number district_dad,d.name_en district_name,p.id province_id,p.dad_number province_dad,p.name_en province_name
              FROM location a JOIN location_relationship aa ON aa.parent_location_id=a.id AND aa.child_location_id=? AND aa.relationship_type='ASC_ARPA_DIVISION' AND {$arpaAsc}
              JOIN location ar ON ar.id=aa.child_location_id JOIN location_type art ON art.id=ar.location_type_id AND art.system_key='ARPA_DIVISION'
              JOIN location_relationship da ON da.child_location_id=a.id AND da.relationship_type='DISTRICT_ASC' AND {$districtAsc}
              JOIN location d ON d.id=da.parent_location_id LEFT JOIN location_relationship pd ON pd.child_location_id=d.id AND pd.relationship_type='PROVINCE_DISTRICT' AND {$provinceDistrict} LEFT JOIN location p ON p.id=pd.parent_location_id WHERE a.id=?";
        $s=$this->pdo->prepare($sql);$s->execute([$arpaId,$validationDate,$validationDate,$validationDate,$validationDate,$validationDate,$validationDate,$ascId]);$r=$s->fetch();if(!$r||!$r['province_id'])throw new DomainException('The selected ARPA Division is not listed under this ASC for the location check date.');
        return ['province'=>['id'=>$r['province_id'],'dad_number'=>$r['province_dad'],'name_en'=>$r['province_name']],'district'=>['id'=>$r['district_id'],'dad_number'=>$r['district_dad'],'name_en'=>$r['district_name']],'asc'=>['id'=>$r['asc_id'],'dad_number'=>$r['asc_dad'],'name_en'=>$r['asc_name']],'arpa'=>['id'=>$r['arpa_id'],'dad_number'=>$r['arpa_dad'],'name_en'=>$r['arpa_name']]];
    }
    private function endReason(mixed $id):string{$id=trim((string)$id);$s=$this->pdo->prepare('SELECT id FROM arpa_appointment_end_reason WHERE id=? AND active=1');$s->execute([$id]);$value=$s->fetchColumn();if(!$value)throw new DomainException('Select a valid end reason.');return (string)$value;}
    private function lockAppointments(array $ids):void{sort($ids);$marks=implode(',',array_fill(0,count($ids),'?'));$s=$this->pdo->prepare("SELECT id FROM arpa_division_appointment WHERE id IN({$marks}) ORDER BY id FOR UPDATE");$s->execute($ids);$s->fetchAll();}
    private function relatedIds(string $value):array{return array_values(array_unique(array_filter(array_map('trim',explode(',',$value)),fn($id)=>preg_match('/^[0-9a-f-]{36}$/i',$id)===1)));}
    private function allAppointmentsBelongToAsc(array $rows,string $ascId):bool{return $rows!==[]&&count(array_filter($rows,fn($row)=>(string)$row['asc_location_id']!==$ascId))===0;}
    private function canViewTechnicalDetails(string $userId):bool{$s=$this->pdo->prepare("SELECT COUNT(*) FROM user_account_role uar JOIN application_role r ON r.id=uar.role_id AND r.role_code IN('SYSTEM_ADMIN','SECURITY_ADMIN') AND r.active=1 AND r.approval_status='APPROVED' WHERE uar.user_id=? AND uar.active=1 AND uar.approval_status='APPROVED' AND uar.effective_from<=CURRENT_DATE() AND (uar.effective_to IS NULL OR uar.effective_to>=CURRENT_DATE())");$s->execute([$userId]);return (int)$s->fetchColumn()>0;}
    private function assertLegacyCorrection(array $row):void{if($row['record_origin']!=='LEGACY_IMPORT'&&(int)$row['legacy_exception']!==1)throw new DomainException('Only an old imported record can be marked as history only. Use the normal appointment process for a current appointment.');}
    private function legacyReferences(array $rows):array{$out=[];foreach($rows as $row){if($row['record_origin']!=='LEGACY_IMPORT')continue;$meta=$this->decode((string)$row['origin_metadata_json']);$out[]=['appointment_id'=>$row['id'],'request_id'=>$row['request_id'],'source_references'=>$meta['source_references']??$meta['source_reference']??null,'source_table'=>$meta['source_table']??null,'source_row_id'=>$meta['source_row_id']??null];}return $out;}
    private function date(mixed $value,string $label):string{$date=trim((string)$value);$parsed=\DateTimeImmutable::createFromFormat('!Y-m-d',$date);if(!$parsed||$parsed->format('Y-m-d')!==$date)throw new DomainException("{$label} must be a valid date.");return $date;}
    private function displayDate(string $date):string{$time=strtotime($date);return $time===false?$date:date('d M Y',$time);}
    private function decode(string $json):array{$value=json_decode($json,true);return is_array($value)?$value:[];}
    private function json(mixed $value):string{return json_encode($value,JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);}
    private function nullText(mixed $value):?string{$value=trim((string)$value);return $value===''?null:$value;}
    private function uuid():string{return (string)$this->pdo->query('SELECT UUID()')->fetchColumn();}
    private function transaction(callable $work):mixed{$owned=!$this->pdo->inTransaction();if($owned)$this->pdo->beginTransaction();try{$result=$work();if($owned)$this->pdo->commit();return $result;}catch(Throwable $e){if($owned&&$this->pdo->inTransaction())$this->pdo->rollBack();throw $e;}}
}
