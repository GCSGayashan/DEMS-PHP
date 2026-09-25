<?php
declare(strict_types=1);

namespace App\Services;

use DomainException;
use PDO;
use Throwable;

/** Safe one-time coordinator for native requests approved before ASC materialization. */
final class ArpaAscApprovedOperationalBackfillService
{
    public const MAX_LIMIT=200;
    private const STATUSES=['ASC_APPROVED','DISTRICT_VERIFIED','DISTRICT_APPROVED','NATIONAL_VERIFIED','NATIONAL_APPROVED'];
    private int $savepointSequence=0;
    private string $runId='';
    private bool $previewOwnsTransaction=false;
    private ?string $previewSavepoint=null;

    public function __construct(private readonly PDO $pdo){}

    /** @return array<string,mixed> */
    public function run(bool $execute=false,int $limit=100):array
    {
        $this->runId=$this->uuid();
        $limit=max(1,min(self::MAX_LIMIT,$limit));
        $initialCandidateCount=$this->missingCandidateCount();
        $summary=[
            'eligible_appointment_requests'=>0,'already_canonical'=>$this->alreadyCanonicalCount(),
            'historical_bounded'=>0,'current_open'=>0,'future'=>0,
            'blocked_by_overlap'=>0,'blocked_by_active_workflow'=>0,'invalid_or_missing_data'=>0,
            'eligible_end_requests'=>0,'end_missing_source_canonical'=>0,'already_closed'=>$this->alreadyClosedCount(),
            'appointments_materialized'=>0,'appointment_closures_created'=>0,'end_closures_created'=>0,
            'skipped'=>0,'failed'=>0,'remaining_eligible'=>0,'remaining_candidate_rows'=>0,
        ];
        $details=[];$remaining=$limit;
        if(!$execute)$this->beginPreviewSandbox();
        try{
            foreach($this->appointmentCandidates($remaining) as $row){
                $remaining--; $this->countPeriod($summary,$row);
                $this->process($row,$execute,$summary,$details);
            }
            if($remaining>0){
                foreach($this->endCandidates($remaining) as $row){
                    if(empty($row['source_exists'])){
                        $summary['end_missing_source_canonical']++;$summary['skipped']++;
                        $details[]=$this->detail($row,'SKIPPED_MISSING_SOURCE_CANONICAL','The END request source canonical appointment is missing.');
                        continue;
                    }
                    $this->process($row,$execute,$summary,$details);
                }
            }
        }finally{
            if(!$execute)$this->rollbackPreviewSandbox();
        }
        $summary['remaining_eligible']=max(0,$initialCandidateCount-count($details));
        $summary['remaining_candidate_rows']=$this->missingCandidateCount();
        return ['run_id'=>$this->runId,'mode'=>$execute?'EXECUTE':'PREVIEW','limit'=>$limit,'summary'=>$summary,'details'=>$details];
    }

    /** @param array<string,mixed> $row @param array<string,int> $summary @param array<int,array<string,mixed>> $details */
    private function process(array $row,bool $execute,array &$summary,array &$details):void
    {
        try{
            $result=$this->boundary(function()use($row,$execute):array{
                $materialized=(new ArpaAppointmentService($this->pdo))->materializeApprovedNativeDivisionRequest((string)$row['id']);
                if($execute)$this->auditBackfill($row,$materialized);
                return $materialized;
            });
            $type=(string)$row['request_type'];
            if($type==='APPOINTMENT')$summary['eligible_appointment_requests']++;else $summary['eligible_end_requests']++;
            if($execute){
                if(!empty($result['appointment_created']))$summary['appointments_materialized']++;
                if($type==='APPOINTMENT')$summary['appointment_closures_created']+=(int)$result['closures_created'];
                else $summary['end_closures_created']+=(int)$result['closures_created'];
            }
            $details[]=$this->detail($row,$execute?'MATERIALIZED':'ELIGIBLE',$execute?'Canonical operational data materialized.':'Validation passed; no writes performed.',$result);
        }catch(DomainException $e){
            $classification=$this->domainClassification($e->getMessage());
            $summary[$classification]++;$summary['skipped']++;
            $details[]=$this->detail($row,strtoupper($classification),$e->getMessage());
        }catch(Throwable $e){
            $summary['failed']++;$details[]=$this->detail($row,'FAILED','Unexpected processing failure; inspect the server log.');
            error_log('ASC-approved ARPA operational backfill failed: request='.(string)$row['id'].' class='.get_class($e).' code='.$e->getCode().' message='.$e->getMessage());
        }
    }

    private function boundary(callable $callback):mixed
    {
        $owned=!$this->pdo->inTransaction();$savepoint='arpa_asc_backfill_'.(++$this->savepointSequence);
        if($owned)$this->pdo->beginTransaction();else $this->pdo->exec("SAVEPOINT {$savepoint}");
        try{
            $result=$callback();
            if($owned)$this->pdo->commit();else $this->pdo->exec("RELEASE SAVEPOINT {$savepoint}");
            return $result;
        }catch(Throwable $e){
            if($owned&&$this->pdo->inTransaction())$this->pdo->rollBack();elseif(!$owned){$this->pdo->exec("ROLLBACK TO SAVEPOINT {$savepoint}");$this->pdo->exec("RELEASE SAVEPOINT {$savepoint}");}
            throw $e;
        }
    }

    private function beginPreviewSandbox():void
    {
        if(!$this->pdo->inTransaction()){
            $this->pdo->beginTransaction();$this->previewOwnsTransaction=true;return;
        }
        $this->previewSavepoint='arpa_asc_preview_run';$this->pdo->exec('SAVEPOINT '.$this->previewSavepoint);
    }

    private function rollbackPreviewSandbox():void
    {
        if($this->previewOwnsTransaction){
            if($this->pdo->inTransaction())$this->pdo->rollBack();
        }elseif($this->previewSavepoint!==null&&$this->pdo->inTransaction()){
            $this->pdo->exec('ROLLBACK TO SAVEPOINT '.$this->previewSavepoint);
            $this->pdo->exec('RELEASE SAVEPOINT '.$this->previewSavepoint);
        }
        $this->previewOwnsTransaction=false;$this->previewSavepoint=null;
    }

    /** @return array<int,array<string,mixed>> */
    private function appointmentCandidates(int $limit):array
    {
        if($limit<1)return [];$statuses=$this->statusSql();
        $sql="SELECT r.*,o.dad_number officer_number,o.name_with_initials officer_name,arpa.name_en arpa_name,asc_l.name_en asc_name
              FROM arpa_division_appointment_request r
              JOIN officer o ON o.id=r.officer_id
              LEFT JOIN location arpa ON arpa.id=r.arpa_division_location_id
              LEFT JOIN location asc_l ON asc_l.id=r.asc_location_id
              WHERE r.record_origin='NATIVE' AND r.deleted_at IS NULL AND r.request_type='APPOINTMENT'
                AND r.workflow_status IN({$statuses})
                AND NOT EXISTS(SELECT 1 FROM arpa_division_appointment a WHERE a.request_id=r.id)
              ORDER BY r.created_at,r.id LIMIT ".(int)$limit;
        return $this->pdo->query($sql)->fetchAll();
    }

    /** @return array<int,array<string,mixed>> */
    private function endCandidates(int $limit):array
    {
        if($limit<1)return [];$statuses=$this->statusSql();
        $sql="SELECT r.*,o.dad_number officer_number,o.name_with_initials officer_name,arpa.name_en arpa_name,asc_l.name_en asc_name,
                     EXISTS(SELECT 1 FROM arpa_division_appointment a WHERE a.id=r.source_appointment_id) source_exists
              FROM arpa_division_appointment_request r
              JOIN officer o ON o.id=r.officer_id
              LEFT JOIN location arpa ON arpa.id=r.arpa_division_location_id
              LEFT JOIN location asc_l ON asc_l.id=r.asc_location_id
              WHERE r.record_origin='NATIVE' AND r.deleted_at IS NULL AND r.request_type='END'
                AND r.workflow_status IN({$statuses})
                AND NOT EXISTS(SELECT 1 FROM arpa_division_appointment_closure c WHERE c.request_id=r.id)
              ORDER BY r.created_at,r.id LIMIT ".(int)$limit;
        return $this->pdo->query($sql)->fetchAll();
    }

    /** @param array<string,int> $summary @param array<string,mixed> $row */
    private function countPeriod(array &$summary,array $row):void
    {
        $from=(string)($row['requested_effective_from']??'');$to=$row['requested_effective_to']?:null;$today=date('Y-m-d');
        if($to!==null&&$to<$today)$summary['historical_bounded']++;
        elseif($from>$today)$summary['future']++;
        else $summary['current_open']++;
    }

    private function domainClassification(string $message):string
    {
        $value=strtolower($message);
        if(str_contains($value,'reservation')||str_contains($value,'workflow request'))return 'blocked_by_active_workflow';
        if(str_contains($value,'overlap')||str_contains($value,'vacant')||str_contains($value,'continuity')||str_contains($value,'uncovered')||str_contains($value,'already starts')||str_contains($value,'period'))return 'blocked_by_overlap';
        return 'invalid_or_missing_data';
    }

    /** @param array<string,mixed> $row @param array<string,mixed> $result */
    private function auditBackfill(array $row,array $result):void
    {
        $action=(string)$row['request_type']==='END'?'arpa.appointment-end.asc-approved-backfill':'arpa.appointment.asc-approved-backfill';
        $details=['run_id'=>$this->runId,'executor'=>getenv('USERNAME')?:getenv('USER')?:'PHP_CLI','host'=>gethostname()?:null,'approved_by'=>$result['approved_by'],'approved_at'=>$result['approved_at'],'workflow_status_preserved'=>$row['workflow_status'],'materialization'=>$result];
        $this->pdo->prepare('INSERT INTO audit_event(actor_user_id,action_key,target_type,target_id,details_json,severity,source_ip) VALUES(NULL,?,?,?,?,?,?)')
            ->execute([$action,'ARPA_DIVISION_APPOINTMENT_REQUEST',$row['id'],json_encode($details,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR),'INFO','CLI']);
    }

    /** @param array<string,mixed> $row @param array<string,mixed> $extra @return array<string,mixed> */
    private function detail(array $row,string $result,string $reason,array $extra=[]):array
    {
        return ['request_id'=>$row['id'],'request_type'=>$row['request_type'],'workflow_status'=>$row['workflow_status'],'officer_number'=>$row['officer_number'],'officer_name'=>$row['officer_name'],'asc'=>$row['asc_name'],'arpa_division'=>$row['arpa_name'],'effective_from'=>$row['requested_effective_from'],'effective_to'=>$row['requested_effective_to'],'result'=>$result,'reason'=>$reason]+$extra;
    }

    private function alreadyCanonicalCount():int
    {
        $statuses=$this->statusSql();return (int)$this->pdo->query("SELECT COUNT(*) FROM arpa_division_appointment_request r WHERE r.record_origin='NATIVE' AND r.deleted_at IS NULL AND r.request_type='APPOINTMENT' AND r.workflow_status IN({$statuses}) AND EXISTS(SELECT 1 FROM arpa_division_appointment a WHERE a.request_id=r.id)")->fetchColumn();
    }

    private function alreadyClosedCount():int
    {
        $statuses=$this->statusSql();return (int)$this->pdo->query("SELECT COUNT(*) FROM arpa_division_appointment_request r WHERE r.record_origin='NATIVE' AND r.deleted_at IS NULL AND r.request_type='END' AND r.workflow_status IN({$statuses}) AND EXISTS(SELECT 1 FROM arpa_division_appointment_closure c WHERE c.request_id=r.id)")->fetchColumn();
    }

    private function missingCandidateCount():int
    {
        $statuses=$this->statusSql();$sql="SELECT
            (SELECT COUNT(*) FROM arpa_division_appointment_request r WHERE r.record_origin='NATIVE' AND r.deleted_at IS NULL AND r.request_type='APPOINTMENT' AND r.workflow_status IN({$statuses}) AND NOT EXISTS(SELECT 1 FROM arpa_division_appointment a WHERE a.request_id=r.id))+
            (SELECT COUNT(*) FROM arpa_division_appointment_request r WHERE r.record_origin='NATIVE' AND r.deleted_at IS NULL AND r.request_type='END' AND r.workflow_status IN({$statuses}) AND NOT EXISTS(SELECT 1 FROM arpa_division_appointment_closure c WHERE c.request_id=r.id))";
        return (int)$this->pdo->query($sql)->fetchColumn();
    }

    private function statusSql():string{return "'".implode("','",self::STATUSES)."'";}

    private function uuid():string
    {
        $bytes=random_bytes(16);$bytes[6]=chr((ord($bytes[6])&0x0f)|0x40);$bytes[8]=chr((ord($bytes[8])&0x3f)|0x80);
        return vsprintf('%s%s-%s-%s-%s-%s%s%s',str_split(bin2hex($bytes),4));
    }
}
