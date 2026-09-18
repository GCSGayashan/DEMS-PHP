<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Auth;
use DomainException;
use PDO;
use Throwable;

/** Safe, repeatable batch coordinator for imported open ARPA appointments. */
final class ArpaAppointmentBulkCanonicalizationService
{
    public const BATCH_SIZE=200;
    private const SUMMARY_KEYS=[
        'ELIGIBLE','SKIPPED_CONFLICTING_CURRENT_APPOINTMENT','SKIPPED_ACTIVE_WORKFLOW_RESERVATION',
        'SKIPPED_GENUINE_HISTORICAL_EXCEPTION','SKIPPED_INVALID_COMBINATION','SKIPPED_OTHER_DATA_ISSUE',
    ];

    public function __construct(private readonly PDO $pdo){}

    public function canAccess():bool{return ArpaAdministrativePolicy::isCanonicalDemsAdmin();}

    /** @return array<string,mixed> */
    public function preview(int $page=1,int $perPage=100):array
    {
        $this->assertAccess();$page=max(1,$page);$perPage=max(25,min(200,$perPage));
        $rows=(new ArpaAppointmentDataIssueCorrectionService($this->pdo))->canonicalPromotionCandidates();
        $summary=array_fill_keys(self::SUMMARY_KEYS,0);
        foreach($rows as $row){$key=(string)$row['classification'];$summary[$key]=($summary[$key]??0)+1;}
        $total=count($rows);$pages=max(1,(int)ceil($total/$perPage));$page=min($page,$pages);
        return [
            'rows'=>array_slice($rows,($page-1)*$perPage,$perPage),
            'summary'=>$summary,'total'=>$total,'page'=>$page,'pages'=>$pages,'per_page'=>$perPage,
            'batch_size'=>self::BATCH_SIZE,
            'active_reservation_diagnostics'=>$this->activeReservationDiagnostics($rows),
            'normalization'=>$this->staleExceptionNormalizationPreview(),
        ];
    }

    /**
     * Read-only enrichment of the exact final Preview classification. The
     * relationship labels below are diagnostic only and never influence the
     * canonical promotion decision.
     *
     * @param array<int,array<string,mixed>>|null $classifiedCandidates
     * @return array{summary:array<string,int>,rows:array<int,array<string,mixed>>,blocked_appointment_ids:array<int,string>}
     */
    public function activeReservationDiagnostics(?array $classifiedCandidates=null):array
    {
        $this->assertAccess();
        $candidates=$classifiedCandidates??(new ArpaAppointmentDataIssueCorrectionService($this->pdo))->canonicalPromotionCandidates();
        $blocked=[];
        foreach($candidates as $candidate){
            if((string)($candidate['classification']??'')==='SKIPPED_ACTIVE_WORKFLOW_RESERVATION')$blocked[(string)$candidate['id']]=$candidate;
        }
        $summary=[
            'distinct_blocked_appointments'=>count($blocked),'blocking_requests'=>0,'related_requests'=>0,
            'exact_duplicates'=>0,'same_officer_same_division_different_period'=>0,
            'same_division_different_officer'=>0,'same_officer_different_division'=>0,
            'other_reservation_relationship'=>0,'multiple_blockers_per_appointment'=>0,
        ];
        if($blocked===[])return ['summary'=>$summary,'rows'=>[],'blocked_appointment_ids'=>[]];

        $ids=array_keys($blocked);$placeholders=implode(',',array_fill(0,count($ids),'?'));
        $statuses="'".implode("','",ArpaAppointmentReadService::RESERVING_REQUEST_STATUSES)."'";
        $sql="SELECT a.id appointment_id,r.id blocking_request_id,r.request_type,r.appointment_type requested_appointment_type,
                     r.workflow_status,r.requested_effective_from,r.requested_effective_to,r.officer_id request_officer_id,
                     ro.dad_number request_officer_number,ro.name_with_initials request_officer_name,
                     r.asc_location_id request_asc_location_id,
                     r.arpa_division_location_id request_arpa_division_id,rd.name_en request_arpa_division,
                     r.record_origin,r.created_at
              FROM arpa_division_appointment a
              JOIN arpa_division_appointment_request r
                ON (r.officer_id=a.officer_id OR r.arpa_division_location_id=a.arpa_division_location_id)
               AND r.deleted_at IS NULL AND r.record_origin='NATIVE' AND r.legacy_history_only=0
               AND r.workflow_status IN({$statuses}) AND r.requested_effective_from IS NOT NULL
               AND COALESCE(r.requested_effective_to,'9999-12-31')>=a.effective_from
               AND NOT EXISTS(SELECT 1 FROM arpa_division_appointment existing WHERE existing.request_id=r.id)
              JOIN officer ro ON ro.id=r.officer_id
              LEFT JOIN location rd ON rd.id=r.arpa_division_location_id
              WHERE a.id IN({$placeholders})
              ORDER BY a.id,r.created_at,r.id";
        $statement=$this->pdo->prepare($sql);$statement->execute($ids);$requestRows=$statement->fetchAll();
        $rows=[];$blockingRequestIds=[];$relatedRequestIds=[];$blockersByAppointment=[];
        foreach($requestRows as $request){
            $candidate=$blocked[(string)$request['appointment_id']];
            $sameOfficer=(string)$request['request_officer_id']===(string)$candidate['officer_id'];
            $sameDivision=(string)$request['request_arpa_division_id']===(string)$candidate['arpa_division_location_id'];
            $sameAsc=(string)$request['request_asc_location_id']===(string)$candidate['asc_location_id'];
            $sameType=(string)$request['requested_appointment_type']===(string)$candidate['appointment_type'];
            $sameFrom=(string)$request['requested_effective_from']===(string)$candidate['effective_from'];
            $candidateTo=$candidate['effective_to']??null;$requestTo=$request['requested_effective_to']??null;
            $sameTo=($candidateTo===null||$candidateTo==='')?($requestTo===null||$requestTo===''):(string)$candidateTo===(string)$requestTo;
            $exact=$sameOfficer&&$sameDivision&&$sameAsc&&$sameType&&$sameFrom&&$sameTo&&(string)$request['request_type']==='APPOINTMENT';
            if($exact)$relationship='EXACT_DUPLICATE';
            elseif($sameOfficer&&$sameDivision)$relationship='SAME_OFFICER_SAME_DIVISION_DIFFERENT_PERIOD';
            elseif($sameDivision)$relationship='SAME_DIVISION_DIFFERENT_OFFICER';
            elseif($sameOfficer)$relationship='SAME_OFFICER_DIFFERENT_DIVISION';
            else $relationship='OTHER_RESERVATION_RELATIONSHIP';
            $validatorBlocker=$sameDivision&&!$exact;
            $row=array_merge([
                'appointment_id'=>$candidate['id'],'officer_number'=>$candidate['officer_number'],'officer_name'=>$candidate['officer_name'],
                'nic'=>$candidate['nic'],'imported_appointment_type'=>$candidate['appointment_type'],
                'arpa_division'=>$candidate['arpa_division_name'],'asc'=>$candidate['asc_name'],
                'imported_effective_from'=>$candidate['effective_from'],'blocker_code'=>$candidate['blocker_code'],
                'blocker_reason'=>$candidate['blocker_reason'],
            ],$request,['relationship'=>$relationship,'validator_blocker'=>$validatorBlocker]);
            $rows[]=$row;$relatedRequestIds[(string)$request['blocking_request_id']]=true;
            $relationshipSummaryKey=[
                'EXACT_DUPLICATE'=>'exact_duplicates',
                'SAME_OFFICER_SAME_DIVISION_DIFFERENT_PERIOD'=>'same_officer_same_division_different_period',
                'SAME_DIVISION_DIFFERENT_OFFICER'=>'same_division_different_officer',
                'SAME_OFFICER_DIFFERENT_DIVISION'=>'same_officer_different_division',
                'OTHER_RESERVATION_RELATIONSHIP'=>'other_reservation_relationship',
            ][$relationship];
            $summary[$relationshipSummaryKey]++;
            if($validatorBlocker){
                $requestId=(string)$request['blocking_request_id'];$blockingRequestIds[$requestId]=true;
                $blockersByAppointment[(string)$candidate['id']][$requestId]=true;
            }
        }
        $summary['blocking_requests']=count($blockingRequestIds);$summary['related_requests']=count($relatedRequestIds);
        foreach($blockersByAppointment as $requestIds)if(count($requestIds)>1)$summary['multiple_blockers_per_appointment']++;
        return ['summary'=>$summary,'rows'=>$rows,'blocked_appointment_ids'=>$ids];
    }

    /** @return array<string,mixed> */
    public function execute(string $actorId):array
    {
        $this->assertAccess();
        if((string)(Auth::user()['id']??'')!==$actorId)throw new DomainException('The authenticated administrator does not match the reconciliation actor.');
        $batchId=(string)$this->pdo->query('SELECT UUID()')->fetchColumn();
        $correction=new ArpaAppointmentDataIssueCorrectionService($this->pdo);
        $eligible=array_values(array_filter($correction->canonicalPromotionCandidates(),static fn(array $row):bool=>!empty($row['eligible'])));
        $selected=array_slice($eligible,0,self::BATCH_SIZE);$results=[];$promoted=0;$skipped=0;$failed=0;
        foreach($selected as $row){
            $appointmentId=(string)$row['id'];
            try{
                $fresh=$correction->validateCanonicalPromotion($appointmentId);
                if(empty($fresh['eligible'])){
                    $skipped++;$results[]=['appointment_id'=>$appointmentId,'result'=>'SKIPPED','reason'=>$fresh['blocker_reason']??'Eligibility changed before execution.'];continue;
                }
                $result=$correction->promoteCanonicalAppointmentFromBulk($appointmentId,$actorId,$batchId);
                $promoted++;$results[]=['appointment_id'=>$appointmentId,'result'=>'PROMOTED','reason'=>'Promoted','correction_id'=>$result['correction_id']??null];
            }catch(DomainException $e){
                $skipped++;$results[]=['appointment_id'=>$appointmentId,'result'=>'SKIPPED','reason'=>$e->getMessage()];
            }catch(Throwable $e){
                $failed++;error_log('ARPA bulk canonical reconciliation failed: batch='.$batchId.' appointment='.$appointmentId.' class='.get_class($e).' code='.$e->getCode().' message='.$e->getMessage());
                $results[]=['appointment_id'=>$appointmentId,'result'=>'FAILED','reason'=>'Unexpected processing failure; see the server log.'];
            }
        }
        return [
            'batch_id'=>$batchId,'promoted'=>$promoted,'skipped'=>$skipped,'failed'=>$failed,
            'processed'=>count($selected),'eligible_before_execution'=>count($eligible),
            'remaining_before_recalculation'=>max(0,count($eligible)-count($selected)),'results'=>$results,
        ];
    }

    /** @return array{count:int,rows:array<int,array<string,mixed>>} */
    public function staleExceptionNormalizationPreview():array
    {
        $this->assertAccess();$rows=$this->normalizationRows(null,true);
        foreach($rows as &$row)$row=array_merge($row,$this->staleExceptionNormalizationAssessmentFromRow($row));
        unset($row);return ['count'=>count($rows),'rows'=>$rows];
    }

    /** @return array<string,mixed> */
    public function staleExceptionNormalizationAssessment(string $appointmentId):array
    {
        $this->assertAccess();$rows=$this->normalizationRows($appointmentId,false);
        if($rows===[])return ['eligible'=>false,'classification'=>'NOT_ELIGIBLE','reason'=>'Appointment was not found.'];
        return array_merge($rows[0],$this->staleExceptionNormalizationAssessmentFromRow($rows[0]));
    }

    /** @return array<string,mixed> */
    public function executeStaleExceptionNormalization(string $actorId):array
    {
        $this->assertActor($actorId);$batchId=(string)$this->pdo->query('SELECT UUID()')->fetchColumn();
        $candidates=$this->staleExceptionNormalizationPreview()['rows'];$selected=array_slice($candidates,0,self::BATCH_SIZE);
        $results=[];$normalized=0;$skipped=0;$failed=0;
        foreach($selected as $row){
            try{
                $result=$this->normalizeStaleExceptionFlag((string)$row['id'],$actorId,$batchId);
                $normalized++;$results[]=['appointment_id'=>$row['id'],'result'=>'NORMALIZED','audit_id'=>$result['audit_id'],'reason'=>'Normalized'];
            }catch(DomainException $e){$skipped++;$results[]=['appointment_id'=>$row['id'],'result'=>'SKIPPED','reason'=>$e->getMessage()];}
            catch(Throwable $e){
                $failed++;error_log('ARPA stale legacy exception normalization failed: batch='.$batchId.' appointment='.$row['id'].' class='.get_class($e).' code='.$e->getCode().' message='.$e->getMessage());
                $results[]=['appointment_id'=>$row['id'],'result'=>'FAILED','reason'=>'Unexpected processing failure; see the server log.'];
            }
        }
        return ['batch_id'=>$batchId,'normalized'=>$normalized,'skipped'=>$skipped,'failed'=>$failed,'processed'=>count($selected),'results'=>$results];
    }

    /** @return array{audit_id:string,appointment_id:string} */
    public function normalizeStaleExceptionFlag(string $appointmentId,string $actorId,string $batchId):array
    {
        $this->assertActor($actorId);$batchId=trim($batchId);if($batchId==='')throw new DomainException('A normalization batch ID is required.');
        return $this->transaction(function()use($appointmentId,$actorId,$batchId):array{
            $lock=$this->pdo->prepare('SELECT id FROM arpa_division_appointment WHERE id=? FOR UPDATE');$lock->execute([$appointmentId]);
            if(!$lock->fetchColumn())throw new DomainException('Appointment was not found.');
            $assessment=$this->staleExceptionNormalizationAssessment($appointmentId);
            if(empty($assessment['eligible']))throw new DomainException((string)$assessment['reason']);
            $codes=(string)$assessment['legacy_exception_codes_json'];
            $before=['appointment_id'=>$appointmentId,'legacy_exception'=>1,'legacy_exception_codes_json'=>$this->decodeJson($codes),'record_origin'=>$assessment['record_origin'],'legacy_history_only'=>(int)$assessment['legacy_history_only'],'effective_from'=>$assessment['effective_from']];
            $this->pdo->prepare('UPDATE arpa_division_appointment SET legacy_exception=0 WHERE id=? AND legacy_exception=1')->execute([$appointmentId]);
            $after=$before;$after['legacy_exception']=0;
            $details=['appointment_id'=>$appointmentId,'previous_legacy_exception'=>1,'resulting_legacy_exception'=>0,'existing_canonical_correction_id'=>$assessment['canonical_correction_id'],'actor_user_id'=>$actorId,'batch_id'=>$batchId,'reason'=>'Normalized stale legacy exception flag after verified canonical resolution.','before'=>$before,'after'=>$after];
            $this->pdo->prepare("INSERT INTO audit_event(actor_user_id,action_key,target_type,target_id,details_json,severity,source_ip) VALUES(?,'arpa.appointment.legacy-exception.normalize','ARPA_DIVISION_APPOINTMENT',?,?,'INFO',?)")
                ->execute([$actorId,$appointmentId,$this->json($details),$_SERVER['REMOTE_ADDR']??'CLI']);
            $auditId=(string)$this->pdo->lastInsertId();
            return ['audit_id'=>$auditId,'appointment_id'=>$appointmentId];
        });
    }

    /** @return array<int,array<string,mixed>> */
    private function normalizationRows(?string $appointmentId,bool $onlyEligible):array
    {
        $sql="SELECT a.id,a.record_origin,a.request_id,a.officer_id,a.appointment_type,a.asc_location_id,a.arpa_division_location_id,
                     a.asc_name_snapshot asc_name,a.arpa_name_snapshot arpa_division_name,a.effective_from,a.legacy_history_only,
                     a.legacy_exception,a.legacy_exception_codes_json,c.id closure_id,o.dad_number officer_number,o.name_with_initials officer_name,o.nic,
                     (SELECT dc.id FROM arpa_appointment_data_correction dc WHERE dc.appointment_id=a.id
                        AND dc.correction_action='RESOLVE_CANONICAL_ASSIGNMENT' AND dc.resolution_status='RESOLVED_BY_CORRECTION'
                        ORDER BY dc.corrected_at DESC,dc.id DESC LIMIT 1) canonical_correction_id,
                     (JSON_SEARCH(COALESCE(a.legacy_exception_codes_json,JSON_ARRAY()),'one','DATA_ISSUE_RESOLUTION') IS NOT NULL) has_resolution_provenance
              FROM arpa_division_appointment a
              JOIN officer o ON o.id=a.officer_id
              LEFT JOIN arpa_division_appointment_closure c ON c.appointment_id=a.id
              WHERE 1=1";$params=[];
        if($appointmentId!==null){$sql.=' AND a.id=?';$params[]=$appointmentId;}
        if($onlyEligible)$sql.=" AND a.record_origin='LEGACY_IMPORT' AND a.legacy_history_only=0 AND a.legacy_exception=1 AND c.id IS NULL
            AND JSON_SEARCH(COALESCE(a.legacy_exception_codes_json,JSON_ARRAY()),'one','DATA_ISSUE_RESOLUTION') IS NOT NULL
            AND EXISTS(SELECT 1 FROM arpa_appointment_data_correction dc WHERE dc.appointment_id=a.id
                AND dc.correction_action='RESOLVE_CANONICAL_ASSIGNMENT' AND dc.resolution_status='RESOLVED_BY_CORRECTION')";
        $sql.=' ORDER BY a.effective_from,a.id';$s=$this->pdo->prepare($sql);$s->execute($params);return $s->fetchAll();
    }

    /** @param array<string,mixed> $row @return array{eligible:bool,classification:string,reason:?string} */
    private function staleExceptionNormalizationAssessmentFromRow(array $row):array
    {
        $blocked=static fn(string $reason):array=>['eligible'=>false,'classification'=>'NOT_ELIGIBLE','reason'=>$reason];
        if((string)$row['record_origin']!=='LEGACY_IMPORT')return $blocked('Only imported appointments can have a stale legacy exception flag normalized.');
        if((int)$row['legacy_history_only']!==0)return $blocked('The appointment is still history-only and has not been canonically resolved.');
        if((int)$row['legacy_exception']!==1)return $blocked('The legacy exception flag is already clear.');
        if($row['closure_id']!==null)return $blocked('Closed appointments are not eligible for open-current flag normalization.');
        if(empty($row['canonical_correction_id']))return $blocked('A resolved canonical-assignment correction is required.');
        if(empty($row['has_resolution_provenance']))return $blocked('DATA_ISSUE_RESOLUTION provenance is required.');
        return ['eligible'=>true,'classification'=>'ELIGIBLE','reason'=>null];
    }

    private function assertActor(string $actorId):void
    {
        $this->assertAccess();if((string)(Auth::user()['id']??'')!==$actorId)throw new DomainException('The authenticated administrator does not match the reconciliation actor.');
    }

    private function transaction(callable $work):mixed
    {
        $owned=!$this->pdo->inTransaction();if($owned)$this->pdo->beginTransaction();
        try{$result=$work();if($owned)$this->pdo->commit();return $result;}
        catch(Throwable $e){if($owned&&$this->pdo->inTransaction())$this->pdo->rollBack();throw $e;}
    }

    private function decodeJson(?string $json):array{$value=json_decode((string)$json,true);return is_array($value)?$value:[];}
    private function json(mixed $value):string{return json_encode($value,JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);}

    private function assertAccess():void
    {
        if(!$this->canAccess())throw new DomainException('Only the canonical dems.admin account may access bulk legacy current reconciliation.');
    }
}
