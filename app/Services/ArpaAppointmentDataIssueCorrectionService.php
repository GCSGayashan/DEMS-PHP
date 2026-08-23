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
    ];
    public const ACTIONS=[
        'MARK_HISTORICAL_ONLY','SET_EFFECTIVE_TO','CORRECT_APPOINTMENT_TYPE',
        'CORRECT_ARPA_DIVISION','CORRECT_EFFECTIVE_FROM','CORRECT_END_REASON',
        'SELECT_CURRENT_RECORD','KEEP_AS_HISTORICAL_EXCEPTION',
    ];

    public function __construct(private readonly PDO $pdo){}

    public function canCorrect(string $userId,string $ascLocationId):bool
    {
        $context=Auth::activeContextForUser($userId);
        if($context!==null){
            return $context['role_code']==='ASC_SUBJECT_OFFICER'
                && Auth::can(self::PERMISSION)
                && ScopeService::canAccessArpaStage($userId,'ASC',$ascLocationId,date('Y-m-d'));
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
        return (int)$s->fetchColumn()>0&&ScopeService::canAccessArpaStage($userId,'ASC',$ascLocationId,date('Y-m-d'));
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
        $ids=$this->relatedIds((string)$issue['related_ids']);$appointments=$this->appointments($ids);$singleAsc=$this->allAppointmentsBelongToAsc($appointments,(string)$issue['asc_location_id']);
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
            'hierarchy_contexts'=>$hierarchyContexts,
            'permanent_appointments'=>$issue['issue_type']==='DEPENDENT_WITHOUT_PERMANENT'?$this->permanentAppointments((string)$issue['officer_id']):[],
            'corrections'=>$this->corrections($rowKey),
            'end_reasons'=>$this->pdo->query('SELECT id,system_key,name_en FROM arpa_appointment_end_reason WHERE active=1 ORDER BY display_order,name_en')->fetchAll(),
            'arpa_divisions'=>$this->arpaDivisions((string)$issue['asc_location_id']),
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
            if(!$issue)throw new DomainException('The issue is no longer active. Refresh the diagnostic before correcting it.');
            if(!in_array($issue['issue_type'],self::CORRECTABLE_ISSUES,true))throw new DomainException('This record cannot be corrected on this page. Use the normal appointment process.');
            if(!$this->canCorrect($actorId,(string)$issue['asc_location_id']))throw new DomainException('Only the assigned ASC Subject Officer can correct this issue.');
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

    public function correction(string $id,string $viewerId):array
    {
        $s=$this->pdo->prepare('SELECT c.*,u.username,u.display_name,o.dad_number officer_number,o.name_with_initials officer_name,o.nic,l.dad_number asc_number,l.name_en asc_name FROM arpa_appointment_data_correction c JOIN system_user u ON u.id=c.corrected_by JOIN officer o ON o.id=c.officer_id JOIN location l ON l.id=c.asc_location_id WHERE c.id=?');$s->execute([$id]);$row=$s->fetch();if(!$row)throw new DomainException('Correction record was not found.');
        if(!ScopeService::canAccessLocation($viewerId,(string)$row['asc_location_id'],date('Y-m-d')))throw new DomainException('You cannot view corrections outside your assigned location.');
        $row['technical_details_allowed']=$this->canViewTechnicalDetails($viewerId);
        return $row;
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
            $date=$this->date($input['effective_from']??null,'Correct Start Date');if($target['effective_to']!==null&&$target['effective_to']<$date)throw new DomainException('The corrected start date cannot be after the end date.');$this->pdo->prepare('UPDATE arpa_division_appointment SET effective_from=? WHERE id=?')->execute([$date,$target['id']]);return;
        }
        if($action==='CORRECT_END_REASON'){
            if($target['closure_id']===null)throw new DomainException('An end reason can only be corrected on an ended appointment.');$reason=$this->endReason($input['end_reason_id']??null);$this->pdo->prepare('UPDATE arpa_division_appointment_closure SET end_reason_id=?,data_correction_id=? WHERE id=?')->execute([$reason,$correctionId,$target['closure_id']]);return;
        }
        if($action==='CORRECT_ARPA_DIVISION'){
            if($this->nullText($input['evidence_reference']??null)===null)throw new DomainException('Evidence reference is required to correct an ARPA Division.');
            $arpaId=trim((string)($input['arpa_division_location_id']??''));$snapshot=$this->locationSnapshot((string)$target['asc_location_id'],$arpaId,(string)$target['effective_from']);
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
    private function decode(string $json):array{$value=json_decode($json,true);return is_array($value)?$value:[];}
    private function json(mixed $value):string{return json_encode($value,JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);}
    private function nullText(mixed $value):?string{$value=trim((string)$value);return $value===''?null:$value;}
    private function uuid():string{return (string)$this->pdo->query('SELECT UUID()')->fetchColumn();}
    private function transaction(callable $work):mixed{$owned=!$this->pdo->inTransaction();if($owned)$this->pdo->beginTransaction();try{$result=$work();if($owned)$this->pdo->commit();return $result;}catch(Throwable $e){if($owned&&$this->pdo->inTransaction())$this->pdo->rollBack();throw $e;}}
}
