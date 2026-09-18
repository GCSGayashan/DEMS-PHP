<?php
declare(strict_types=1);

use App\Core\{Auth,Database};
use App\Services\{ArpaAppointmentBulkCanonicalizationService,ArpaAppointmentDataIssueCorrectionService,OfficerProfileService,UserContextService};

require dirname(__DIR__).'/bootstrap.php';

final class ArpaAppointmentBulkCanonicalizationTest
{
    private PDO $pdo;private int $assertions=0;private string $admin;private array $places=[];

    public function run():int
    {
        $this->pdo=Database::pdo();$this->pdo->beginTransaction();
        try{$this->authenticateAdmin();$this->fixtures();$this->exercise();}
        finally{$_SESSION=[];Auth::forgetRequestCache();if($this->pdo->inTransaction())$this->pdo->rollBack();}
        echo "ArpaAppointmentBulkCanonicalizationTest: {$this->assertions} assertions passed.\n";return 0;
    }

    private function exercise():void
    {
        $correction=new ArpaAppointmentDataIssueCorrectionService($this->pdo);
        [$acting,$actingOfficer]=$this->dependentCandidate('ACTING',0,1,'PERMANENT_IN_SERVICE');
        [$duty,$dutyOfficer]=$this->dependentCandidate('DUTY_COVERING',2,3,'PERMANENT_IN_SERVICE');
        $permanentOfficer=$this->officer('PERMANENT_IN_SERVICE');$permanent=$this->legacyCandidate($permanentOfficer,4,'PERMANENT','2025-01-01');
        $this->same(true,$correction->canonicalPromotionAssessment($acting)['eligible'],'open unique legacy Acting is eligible');
        $this->same(true,$correction->canonicalPromotionAssessment($permanent)['eligible'],'open unique legacy Permanent is eligible');
        $this->same(true,$correction->canonicalPromotionAssessment($duty)['eligible'],'open unique Duty Covering is eligible where business rules allow');

        $closedOfficer=$this->officer('PERMANENT_IN_SERVICE');$closed=$this->legacyCandidate($closedOfficer,5,'PERMANENT','2025-01-01');$this->close($closed,'2025-12-31');
        $this->same('ENDED_HISTORY',$correction->canonicalPromotionAssessment($closed)['blocker_code'],'closed legacy record is not promoted');
        $future=$this->legacyCandidate($this->officer('PERMANENT_IN_SERVICE'),6,'PERMANENT',date('Y-m-d',strtotime('+1 day')));
        $this->same('FUTURE_APPOINTMENT',$correction->canonicalPromotionAssessment($future)['blocker_code'],'future legacy record is not promoted');

        $conflict=$this->legacyCandidate($this->officer('PERMANENT_IN_SERVICE'),7,'PERMANENT','2025-01-01');$this->canonical($this->officer('PERMANENT_IN_SERVICE'),7,'PERMANENT','2025-01-01');
        $this->same('CANONICAL_TIMELINE_CONFLICT',$correction->canonicalPromotionAssessment($conflict)['blocker_code'],'conflicting authoritative Division appointment is skipped by the canonical timeline validator');
        [$invalid]=$this->dependentCandidate('ACTING',8,9,'NOT_PERMANENT_IN_SERVICE');
        $this->same('INVALID_APPOINTMENT_COMBINATION',$correction->canonicalPromotionAssessment($invalid)['blocker_code'],'incompatible Officer appointment combination is skipped');

        $reservation=$this->legacyCandidate($this->officer('PERMANENT_IN_SERVICE'),10,'PERMANENT','2025-01-01');$this->nativeReservation($this->officer('PERMANENT_IN_SERVICE'),10,'PERMANENT','2025-02-01',false);
        $this->same('ACTIVE_WORKFLOW_RESERVATION',$correction->canonicalPromotionAssessment($reservation)['blocker_code'],'non-deleted competing workflow reservation blocks promotion');

        $exact=$this->nativeReservation($dutyOfficer,3,'DUTY_COVERING','2025-02-01',false);
        $this->same(true,$correction->canonicalPromotionAssessment($duty)['eligible'],'exact duplicate native reservation is safely supersedable');
        $deleted=$this->nativeReservation($permanentOfficer,4,'PERMANENT','2025-01-01',true);
        $this->same(true,$correction->canonicalPromotionAssessment($permanent)['eligible'],'administratively deleted duplicate request does not block promotion');

        $genuine=$this->legacyCandidate($this->officer('PERMANENT_IN_SERVICE'),11,'PERMANENT','2025-01-01');$this->keptCorrection($genuine);
        $this->same('CONFIRMED_HISTORICAL',$correction->canonicalPromotionAssessment($genuine)['blocker_code'],'confirmed genuine historical exception remains historical');

        $timelineOfficer=$this->officer('PERMANENT_IN_SERVICE');$timelineCandidate=$this->legacyCandidate($timelineOfficer,12,'PERMANENT','2025-01-01');
        $laterHistory=$this->legacyCandidate($this->officer('PERMANENT_IN_SERVICE'),12,'ACTING','2025-06-01');$this->close($laterHistory,'2025-12-31');
        $timelineAssessment=$correction->validateCanonicalPromotion($timelineCandidate);
        $this->same(false,$timelineAssessment['eligible'],'legacy open appointment with a later authoritative historical period is not Preview eligible');
        $this->same('SKIPPED_CONFLICTING_CURRENT_APPOINTMENT',$timelineAssessment['classification'],'canonical timeline conflict uses the conflicting-current Preview category');
        $this->same('CANONICAL_TIMELINE_CONFLICT',$timelineAssessment['blocker_code'],'canonical timeline conflict has a stable structured blocker code');
        $expectedReason='This assignment cannot be reopened because another assignment already starts on 01 Jun 2025.';
        $this->same($expectedReason,$timelineAssessment['blocker_reason'],'Preview uses the canonical reopen-conflict reason');
        $this->throwsMessage(fn()=>$correction->promoteCanonicalAppointmentFromBulk($timelineCandidate,$this->admin,'timeline-conflict-test'),$expectedReason,'direct execution rejects the same timeline conflict with the same reason');
        $this->same(false,$correction->validateCanonicalPromotion($timelineCandidate)['eligible'],'execution-rejected deterministic conflict does not return to Eligible after recalculation');

        $dependentOfficer=$this->officer('PERMANENT_IN_SERVICE');$supportingPermanent=$this->legacyCandidate($dependentOfficer,13,'PERMANENT','2025-01-01');$dependentActing=$this->legacyCandidate($dependentOfficer,14,'ACTING','2025-02-01');
        $this->same(false,$correction->validateCanonicalPromotion($dependentActing)['eligible'],'dependent appointment is initially blocked without canonical Permanent support');
        $correction->promoteCanonicalAppointmentFromBulk($supportingPermanent,$this->admin,'dependency-test');
        $this->same(true,$correction->validateCanonicalPromotion($dependentActing)['eligible'],'previously blocked dependent appointment becomes eligible after supporting Permanent is promoted');

        $clearedExceptionCandidate=$this->legacyCandidate($this->officer('PERMANENT_IN_SERVICE'),15,'PERMANENT','2025-01-01');
        $this->pdo->prepare("UPDATE arpa_division_appointment SET legacy_exception=0,legacy_exception_codes_json=JSON_ARRAY() WHERE id=?")->execute([$clearedExceptionCandidate]);
        $this->pdo->prepare("UPDATE arpa_division_appointment_request SET legacy_exception=0,legacy_exception_codes_json=JSON_ARRAY() WHERE id=(SELECT request_id FROM arpa_division_appointment WHERE id=?)")->execute([$clearedExceptionCandidate]);
        $allOpenHistory=$correction->canonicalPromotionCandidates();$clearedRow=$this->findById($allOpenHistory,$clearedExceptionCandidate);
        $this->same(true,$clearedRow!==null,'open imported history-only appointment with legacy_exception=0 appears in Bulk Preview');
        $this->same(true,$clearedRow['eligible']??false,'legacy_exception=0 candidate is classified through the shared canonical validator');

        $counts=$this->counts();$preview=(new ArpaAppointmentBulkCanonicalizationService($this->pdo))->preview(1,25);
        $this->same($counts,$this->counts(),'preview makes no database changes');
        $this->same(true,isset($preview['summary']['ELIGIBLE']),'preview reports grouped eligibility counts');
        $allCandidates=$correction->canonicalPromotionCandidates();$eligibleRows=array_values(array_filter($allCandidates,static fn(array $row):bool=>!empty($row['eligible'])));
        foreach($this->stratifiedSample($eligibleRows,40) as $row)$this->same(true,$correction->validateCanonicalPromotion((string)$row['id'])['eligible'],'sampled Preview Eligible candidate agrees with the shared execution validator');

        $this->normalization($preview);

        $batch='test-batch-'.$this->uuid();
        foreach([$permanent,$acting,$duty] as $id)$correction->promoteCanonicalAppointmentFromBulk($id,$this->admin,$batch);
        foreach([$permanent,$acting,$duty] as $id){
            $this->same(0,(int)$this->value('SELECT legacy_history_only FROM arpa_division_appointment WHERE id=?',[$id]),'eligible appointment is promoted');
            $this->same(0,(int)$this->value('SELECT COUNT(*) FROM arpa_division_appointment_closure WHERE appointment_id=?',[$id]),'promotion fabricates no closure/end date');
            $this->same(1,(int)$this->value("SELECT COUNT(*) FROM arpa_appointment_data_correction WHERE appointment_id=? AND correction_action='RESOLVE_CANONICAL_ASSIGNMENT'",[$id]),'every promotion has one correction audit row');
        }
        $this->same(true,str_contains((string)$this->value('SELECT after_json FROM arpa_appointment_data_correction WHERE appointment_id=?',[$acting]),$batch),'correction audit metadata records the bulk batch ID');
        $this->same(1,(int)$this->value('SELECT COUNT(*) FROM arpa_division_appointment_request WHERE id=? AND deleted_at IS NOT NULL',[$exact]),'exact duplicate workflow request is soft-superseded');
        $this->same(1,(int)$this->value('SELECT COUNT(*) FROM arpa_division_appointment_request WHERE id=? AND deleted_at IS NOT NULL',[$deleted]),'previously deleted request remains deleted');
        $profile=(new OfficerProfileService($this->pdo))->profile($actingOfficer,[],null,true);
        $this->same(true,in_array($acting,array_column($profile['current_appointments'],'id'),true),'resolved appointment becomes Current in Officer Profile');
        $this->same(false,in_array($acting,array_column($profile['previous_appointments'],'id'),true),'promoted appointment disappears from Assignment History');
        $auditBefore=(int)$this->value('SELECT COUNT(*) FROM arpa_appointment_data_correction WHERE appointment_id=?',[$acting]);
        $this->throws(fn()=>$correction->promoteCanonicalAppointmentFromBulk($acting,$this->admin,$batch),'running promotion twice is rejected as already resolved');
        $this->same($auditBefore,(int)$this->value('SELECT COUNT(*) FROM arpa_appointment_data_correction WHERE appointment_id=?',[$acting]),'idempotent rerun creates no second audit row');

        $routes=(string)file_get_contents(BASE_PATH.'/routes/web.php');$view=(string)file_get_contents(BASE_PATH.'/app/Views/arpa_appointments/issues/bulk_current.php');
        $this->same(true,str_contains($routes,'issues/bulk-current')&&str_contains($view,'Execute Eligible Promotions'),'admin bulk preview/execute UI is registered');
        $other=(string)$this->value("SELECT id FROM system_user WHERE username<>'dems.admin' AND enabled=1 AND account_status='ACTIVE' LIMIT 1");$_SESSION=['user_id'=>$other,'authenticated_at'=>time(),'last_activity_at'=>time()];Auth::forgetRequestCache();
        $this->same(false,(new ArpaAppointmentBulkCanonicalizationService($this->pdo))->canAccess(),'non-canonical user cannot access the bulk operation');
    }

    private function fixtures():void
    {
        $sql="SELECT rel.parent_location_id asc_id,rel.child_location_id division_id
              FROM location_relationship rel
              WHERE rel.relationship_type='ASC_ARPA_DIVISION' AND rel.active=1 AND rel.approval_status='APPROVED'
                AND NOT EXISTS(SELECT 1 FROM arpa_division_appointment a WHERE a.arpa_division_location_id=rel.child_location_id)
                AND NOT EXISTS(SELECT 1 FROM arpa_division_appointment_request r WHERE r.arpa_division_location_id=rel.child_location_id AND r.deleted_at IS NULL)
              LIMIT 20";
        $this->places=$this->pdo->query($sql)->fetchAll();if(count($this->places)<20)throw new RuntimeException('Twenty unused ARPA Divisions are required.');
    }

    private function authenticateAdmin():void
    {
        $this->admin=(string)$this->value("SELECT id FROM system_user WHERE username='dems.admin' AND enabled=1 AND account_status='ACTIVE' AND approval_status='APPROVED'");
        $_SESSION=['user_id'=>$this->admin,'authenticated_at'=>time(),'last_activity_at'=>time()];Auth::forgetRequestCache();
        $contexts=(new UserContextService($this->pdo))->availableContexts($this->admin);$context=array_values(array_filter($contexts,fn(array $r):bool=>(string)$r['role_code']==='SYSTEM_ADMIN'))[0]??null;
        if(!$context)throw new RuntimeException('Canonical dems.admin SYSTEM_ADMIN context is required.');
        (new UserContextService($this->pdo))->select($this->admin,(string)$context['role_assignment_id'],$context['scope_assignment_id']?:(null));Auth::forgetRequestCache();
    }

    private function dependentCandidate(string $type,int $permanentPlace,int $candidatePlace,string $permanency):array
    {
        $officer=$this->officer($permanency);$this->canonical($officer,$permanentPlace,'PERMANENT','2025-01-01');
        return [$this->legacyCandidate($officer,$candidatePlace,$type,'2025-02-01'),$officer];
    }
    private function officer(string $permanency):string
    {
        $id=$this->uuid();$status=(string)$this->value('SELECT id FROM officer_status WHERE active=1 ORDER BY display_order LIMIT 1');
        $this->pdo->prepare("INSERT INTO officer(id,dad_number,name_with_initials,arpa_service_permanency,officer_status_id,effective_from,operational_status,approval_status,created_by,approved_by,approved_at) VALUES(?,?,?, ?,?,CURRENT_DATE(),'ACTIVE','APPROVED',?,?,NOW())")->execute([$id,'BULK-'.substr($id,0,8),'Bulk Canonical Test Officer',$permanency,$status,$this->admin,$this->admin]);return $id;
    }
    private function legacyCandidate(string $officer,int $place,string $type,string $from):string{return $this->appointment($officer,$place,$type,$from,true);}
    private function canonical(string $officer,int $place,string $type,string $from):string{return $this->appointment($officer,$place,$type,$from,false);}
    private function appointment(string $officer,int $place,string $type,string $from,bool $legacy):string
    {
        $asc=(string)$this->places[$place]['asc_id'];$division=(string)$this->places[$place]['division_id'];$request=$this->uuid();$id=$this->uuid();$origin=$legacy?'LEGACY_IMPORT':'NATIVE';$history=$legacy?1:0;$exception=$legacy?1:0;
        $this->pdo->prepare('INSERT INTO arpa_division_appointment_request(id,record_origin,request_type,officer_id,appointment_type,asc_location_id,arpa_division_location_id,requested_effective_from,workflow_status,legacy_history_only,legacy_exception,legacy_exception_codes_json,origin_metadata_json,created_by) VALUES(?,?,?,?,?,?,?, ?,\'NATIONAL_APPROVED\',?,?,?,\'{}\',?)')->execute([$request,$origin,'APPOINTMENT',$officer,$type,$asc,$division,$from,$history,$exception,$legacy?'["BULK_TEST"]':null,$legacy?null:$this->admin]);
        $source=$legacy?'CURRENT_STATE_ONLY':'NATIVE_CURRENT_STATUS';
        $this->pdo->prepare("INSERT INTO arpa_division_appointment(id,record_origin,request_id,officer_id,appointment_type,service_permanency_snapshot,service_permanency_source,asc_location_id,arpa_division_location_id,asc_dad_snapshot,asc_name_snapshot,arpa_dad_snapshot,arpa_name_snapshot,hierarchy_snapshot_json,effective_from,legacy_history_only,legacy_exception,legacy_exception_codes_json,approval_timestamp_provenance,origin_metadata_json,approved_by,approved_at) SELECT ?,?,?,?,?,o.arpa_service_permanency,?,a.id,d.id,a.dad_number,a.name_en,d.dad_number,d.name_en,'{}',?,?,?,?,?, '{}',?,IF(?='NATIVE',NOW(),NULL) FROM officer o JOIN location a ON a.id=? JOIN location d ON d.id=? WHERE o.id=?")->execute([$id,$origin,$request,$officer,$type,$source,$from,$history,$exception,$legacy?'["BULK_TEST"]':null,$legacy?'UNAVAILABLE_FROM_LEGACY_SOURCE':'NATIVE_RECORDED',$legacy?null:$this->admin,$origin,$asc,$division,$officer]);return $id;
    }
    private function nativeReservation(string $officer,int $place,string $type,string $from,bool $deleted):string
    {
        $id=$this->uuid();$this->pdo->prepare("INSERT INTO arpa_division_appointment_request(id,record_origin,request_type,officer_id,appointment_type,asc_location_id,arpa_division_location_id,requested_effective_from,workflow_status,created_by,deleted_at,deleted_by,delete_reason) VALUES(?,'NATIVE','APPOINTMENT',?,?,?,?,?,'ASC_APPROVED',?,IF(?,NOW(),NULL),IF(?, ?,NULL),IF(?,'Test deleted duplicate',NULL))")->execute([$id,$officer,$type,$this->places[$place]['asc_id'],$this->places[$place]['division_id'],$from,$this->admin,$deleted?1:0,$deleted?1:0,$this->admin,$deleted?1:0]);return $id;
    }
    private function close(string $appointment,string $to):void
    {
        $request=(string)$this->value('SELECT request_id FROM arpa_division_appointment WHERE id=?',[$appointment]);$this->pdo->prepare("INSERT INTO arpa_division_appointment_closure(id,record_origin,appointment_id,request_id,effective_to,closure_kind,context_snapshot_json,approval_timestamp_provenance) VALUES(UUID(),'LEGACY_IMPORT',?,?,?,'DIRECT','{}','UNAVAILABLE_FROM_LEGACY_SOURCE')")->execute([$appointment,$request,$to]);
    }
    private function keptCorrection(string $appointment):void
    {
        $row=$this->row('SELECT * FROM arpa_division_appointment WHERE id=?',[$appointment]);$this->pdo->prepare("INSERT INTO arpa_appointment_data_correction(id,issue_row_key,issue_type,officer_id,appointment_id,request_id,related_appointment_ids_json,asc_location_id,corrected_by,correction_action,resolution_status,correction_reason,before_json,after_json,record_origin) VALUES(UUID(),?,'LEGACY_HISTORICAL_EXCEPTION',?,?,?,JSON_ARRAY(?),?,?,'KEEP_AS_HISTORICAL_EXCEPTION','KEPT_HISTORICAL_EXCEPTION','Test confirmed historical','{}','{}','LEGACY_IMPORT')")->execute(['LEGACY_HISTORICAL_EXCEPTION:'.$appointment,$row['officer_id'],$appointment,$row['request_id'],$appointment,$row['asc_location_id'],$this->admin]);
    }

    private function normalization(array $existingPreview):void
    {
        $service=new ArpaAppointmentBulkCanonicalizationService($this->pdo);
        $eligible=$this->normalizationFixture(16,'RESOLVED_BY_CORRECTION',false,true);
        $withoutCorrection=$this->normalizationFixture(17,null,false,true);
        $unresolvedCorrection=$this->normalizationFixture(18,'REVIEWED_UNRESOLVED',false,true);
        $closed=$this->normalizationFixture(19,'RESOLVED_BY_CORRECTION',true,true);
        $assessment=$service->staleExceptionNormalizationAssessment($eligible);
        $this->same(true,$assessment['eligible'],'canonically resolved open import with stale legacy_exception=1 is normalization eligible');
        $this->same(false,$service->staleExceptionNormalizationAssessment($withoutCorrection)['eligible'],'appointment without resolved canonical correction is not normalized');
        $this->same(false,$service->staleExceptionNormalizationAssessment($unresolvedCorrection)['eligible'],'unresolved correction status does not qualify for normalization');
        $this->same(false,$service->staleExceptionNormalizationAssessment($closed)['eligible'],'closed appointment does not qualify for open-current normalization');
        $counts=$this->counts();$normalizationPreview=$service->staleExceptionNormalizationPreview();
        $this->same($counts,$this->counts(),'stale-flag Preview is read-only');
        $this->same(true,$this->findById($normalizationPreview['rows'],$eligible)!==null,'representative production stale-flag pattern is detected in Preview');
        $before=$this->row('SELECT record_origin,legacy_history_only,legacy_exception,legacy_exception_codes_json,effective_from FROM arpa_division_appointment WHERE id=?',[$eligible]);
        $batch='normalization-'.$this->uuid();$result=$service->normalizeStaleExceptionFlag($eligible,$this->admin,$batch);
        $after=$this->row('SELECT record_origin,legacy_history_only,legacy_exception,legacy_exception_codes_json,effective_from FROM arpa_division_appointment WHERE id=?',[$eligible]);
        $this->same(0,(int)$after['legacy_exception'],'normalization clears only the stale current exception flag');
        $this->same($before['legacy_exception_codes_json'],$after['legacy_exception_codes_json'],'historical exception codes remain byte-for-byte unchanged');
        $this->same('LEGACY_IMPORT',$after['record_origin'],'record origin remains LEGACY_IMPORT');
        $this->same(0,(int)$after['legacy_history_only'],'canonical history-only state remains unchanged');
        $this->same($before['effective_from'],$after['effective_from'],'effective_from remains unchanged');
        $this->same(0,(int)$this->value('SELECT COUNT(*) FROM arpa_division_appointment_closure WHERE appointment_id=?',[$eligible]),'normalization creates no closure');
        $this->same(1,(int)$this->value("SELECT COUNT(*) FROM audit_event WHERE id=? AND action_key='arpa.appointment.legacy-exception.normalize'",[$result['audit_id']]),'normalization creates a generic audit event');
        $audit=(string)$this->value('SELECT details_json FROM audit_event WHERE id=?',[$result['audit_id']]);$this->same(true,str_contains($audit,$batch)&&str_contains($audit,'Normalized stale legacy exception flag after verified canonical resolution.'),'normalization audit records batch and reason');
        $auditCount=(int)$this->value("SELECT COUNT(*) FROM audit_event WHERE target_id=? AND action_key='arpa.appointment.legacy-exception.normalize'",[$eligible]);
        $this->throws(fn()=>$service->normalizeStaleExceptionFlag($eligible,$this->admin,$batch),'normalization execution is idempotent');
        $this->same($auditCount,(int)$this->value("SELECT COUNT(*) FROM audit_event WHERE target_id=? AND action_key='arpa.appointment.legacy-exception.normalize'",[$eligible]),'idempotent rerun creates no second audit event');
        $this->same(true,isset($existingPreview['normalization']),'main bulk Preview exposes stale-flag normalization preview');
    }

    private function normalizationFixture(int $place,?string $resolutionStatus,bool $closed,bool $withProvenance):string
    {
        $appointment=$this->legacyCandidate($this->officer('PERMANENT_IN_SERVICE'),$place,'PERMANENT','2025-01-01');$request=(string)$this->value('SELECT request_id FROM arpa_division_appointment WHERE id=?',[$appointment]);
        $codes=$withProvenance?'["BUSINESS_RULE","DATA_ISSUE_RESOLUTION"]':'["BUSINESS_RULE"]';
        $this->pdo->prepare('UPDATE arpa_division_appointment SET legacy_history_only=0,legacy_exception=1,legacy_exception_codes_json=? WHERE id=?')->execute([$codes,$appointment]);
        $this->pdo->prepare('UPDATE arpa_division_appointment_request SET legacy_history_only=0,legacy_exception=1,legacy_exception_codes_json=? WHERE id=?')->execute([$codes,$request]);
        if($resolutionStatus!==null){
            $row=$this->row('SELECT * FROM arpa_division_appointment WHERE id=?',[$appointment]);$this->pdo->prepare("INSERT INTO arpa_appointment_data_correction(id,issue_row_key,issue_type,officer_id,appointment_id,request_id,related_appointment_ids_json,asc_location_id,corrected_by,correction_action,resolution_status,correction_reason,before_json,after_json,record_origin) VALUES(UUID(),?,'LEGACY_HISTORICAL_EXCEPTION',?,?,?,JSON_ARRAY(?),?,?,'RESOLVE_CANONICAL_ASSIGNMENT',?,'Normalization fixture','{}','{}','LEGACY_IMPORT')")->execute(['LEGACY_HISTORICAL_EXCEPTION:'.$appointment,$row['officer_id'],$appointment,$request,$appointment,$row['asc_location_id'],$this->admin,$resolutionStatus]);
        }
        if($closed)$this->close($appointment,'2025-12-31');return $appointment;
    }
    private function counts():array{return ['appointment'=>(int)$this->value('SELECT COUNT(*) FROM arpa_division_appointment'),'request'=>(int)$this->value('SELECT COUNT(*) FROM arpa_division_appointment_request'),'correction'=>(int)$this->value('SELECT COUNT(*) FROM arpa_appointment_data_correction'),'audit'=>(int)$this->value('SELECT COUNT(*) FROM audit_event')];}
    /** @param array<int,array<string,mixed>> $rows @return array<int,array<string,mixed>> */
    private function stratifiedSample(array $rows,int $maximum):array
    {
        $count=count($rows);if($count<=$maximum)return $rows;$sample=[];
        for($i=0;$i<$maximum;$i++)$sample[]=$rows[(int)floor($i*($count-1)/max(1,$maximum-1))];
        return $sample;
    }
    /** @param array<int,array<string,mixed>> $rows */
    private function findById(array $rows,string $id):?array{foreach($rows as $row)if((string)($row['id']??'')===$id)return $row;return null;}
    private function value(string $sql,array $params=[]):mixed{$s=$this->pdo->prepare($sql);$s->execute($params);return $s->fetchColumn();}
    private function row(string $sql,array $params=[]):array{$s=$this->pdo->prepare($sql);$s->execute($params);return $s->fetch()?:[];}
    private function uuid():string{return (string)$this->pdo->query('SELECT UUID()')->fetchColumn();}
    private function same(mixed $expected,mixed $actual,string $message):void{$this->assertions++;if($expected!==$actual)throw new RuntimeException($message.': expected '.var_export($expected,true).', got '.var_export($actual,true));}
    private function throws(callable $fn,string $message):void{$this->assertions++;try{$fn();}catch(DomainException){return;}throw new RuntimeException($message.': expected DomainException');}
    private function throwsMessage(callable $fn,string $expected,string $message):void{$this->assertions++;try{$fn();}catch(DomainException $e){if($e->getMessage()===$expected)return;throw new RuntimeException($message.': expected '.var_export($expected,true).', got '.var_export($e->getMessage(),true));}throw new RuntimeException($message.': expected DomainException');}
}

exit((new ArpaAppointmentBulkCanonicalizationTest())->run());
