<?php
declare(strict_types=1);

namespace App\Services;

use DomainException;
use PDO;
use Throwable;

final class ArpaAppointmentCorrectionPermanentDeleteService
{
    public function __construct(private readonly PDO $pdo){}

    /** @return array{eligible:bool,code:string,reason:string} */
    public function eligibility(string $correctionId,string $actorId):array
    {
        ArpaCorrectionPermanentDeletePolicy::assert($actorId);
        if(!$this->auditSchemaAvailable()){
            return $this->blocked('AUDIT_SCHEMA_UNAVAILABLE','Permanent deletion is unavailable until its independent audit migration has been applied.');
        }
        if(!$this->lineageSchemaAvailable()){
            return $this->blocked('LINEAGE_SCHEMA_UNAVAILABLE','Permanent deletion is unavailable because amendment lineage cannot be verified safely.');
        }
        $correction=$this->correction($correctionId,false);
        if($correction===null){
            return $this->blocked(
                $this->deletionAuditExists($correctionId)?'ALREADY_DELETED':'NOT_FOUND',
                $this->deletionAuditExists($correctionId)?'This correction has already been permanently deleted.':'The correction record was not found.'
            );
        }
        return $this->evaluate($correction,false);
    }

    /** @return array{correction_id:string,audit_id:string} */
    public function permanentlyDelete(string $correctionId,string $deletionReason,string $confirmation,string $actorId):array
    {
        ArpaCorrectionPermanentDeletePolicy::assert($actorId);
        $correctionId=trim($correctionId);$deletionReason=trim($deletionReason);$confirmation=trim($confirmation);
        if($deletionReason==='')throw new DomainException('Provide a reason for permanently deleting this correction decision.');
        if(mb_strlen($deletionReason)>500)throw new DomainException('The deletion reason must not exceed 500 characters.');
        if($confirmation===''||!hash_equals($correctionId,$confirmation))throw new DomainException('Enter the complete correction ID to confirm permanent deletion.');
        if(!$this->auditSchemaAvailable())throw new DomainException('Permanent deletion is unavailable until its independent audit migration has been applied.');
        if(!$this->lineageSchemaAvailable())throw new DomainException('Permanent deletion is unavailable because amendment lineage cannot be verified safely.');

        $ownsTransaction=!$this->pdo->inTransaction();$savepoint='arpa_correction_delete';
        if($ownsTransaction)$this->pdo->beginTransaction();else $this->pdo->exec('SAVEPOINT '.$savepoint);
        try{
            $correction=$this->correction($correctionId,true);
            if($correction===null){
                if($this->deletionAuditExists($correctionId))throw new DomainException('This correction has already been permanently deleted.');
                throw new DomainException('The correction record was not found.');
            }
            $eligibility=$this->evaluate($correction,true);
            if(!$eligibility['eligible'])throw new DomainException($eligibility['reason']);

            $snapshot=json_encode($correction,JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);
            $audit=$this->pdo->prepare('INSERT INTO arpa_appointment_data_correction_deletion_audit(
                correction_id,officer_id,appointment_id,request_id,asc_location_id,
                original_correction_action,original_resolution_status,correction_snapshot_json,
                deleted_by,deletion_reason
            ) VALUES(?,?,?,?,?,?,?,?,?,?)');
            $audit->execute([
                $correction['id'],$correction['officer_id'],$correction['appointment_id'],$correction['request_id'],$correction['asc_location_id'],
                $correction['correction_action'],$correction['resolution_status'],$snapshot,$actorId,$deletionReason,
            ]);
            $auditId=(string)$this->pdo->lastInsertId();

            $delete=$this->pdo->prepare('DELETE FROM arpa_appointment_data_correction WHERE id=?');$delete->execute([$correctionId]);
            if($delete->rowCount()!==1)throw new DomainException('The correction changed before it could be deleted. Reload and try again.');

            if($ownsTransaction)$this->pdo->commit();else $this->pdo->exec('RELEASE SAVEPOINT '.$savepoint);
            return ['correction_id'=>$correctionId,'audit_id'=>$auditId];
        }catch(Throwable $e){
            if($ownsTransaction&&$this->pdo->inTransaction())$this->pdo->rollBack();
            elseif(!$ownsTransaction&&$this->pdo->inTransaction()){
                $this->pdo->exec('ROLLBACK TO SAVEPOINT '.$savepoint);$this->pdo->exec('RELEASE SAVEPOINT '.$savepoint);
            }
            throw $e;
        }
    }

    /** @param array<string,mixed> $correction @return array{eligible:bool,code:string,reason:string} */
    private function evaluate(array $correction,bool $lock):array
    {
        if((string)$correction['correction_action']!=='KEEP_AS_HISTORICAL_EXCEPTION'
            ||(string)$correction['resolution_status']!=='KEPT_HISTORICAL_EXCEPTION'){
            return $this->blocked('CANONICAL_EFFECTS_POSSIBLE','This correction action may have changed canonical appointment, request, or closure data and cannot be safely deleted.');
        }
        try{
            if($this->canonicalJson((string)$correction['before_json'])!==$this->canonicalJson((string)$correction['after_json'])){
                return $this->blocked('EVIDENCE_CHANGED','This correction records operational before/after changes and cannot be safely deleted.');
            }
        }catch(Throwable){
            return $this->blocked('EVIDENCE_UNVERIFIABLE','The correction evidence cannot be verified safely.');
        }
        if($correction['supersedes_correction_id']!==null){
            return $this->blocked('SUPERSEDES_PREDECESSOR','This correction supersedes an earlier decision and is part of the effective-decision chain.');
        }
        if($correction['amendment_request_id']!==null){
            return $this->blocked('APPLIED_AMENDMENT','This correction was applied by an approved amendment and cannot be deleted.');
        }

        $suffix=$lock?' FOR UPDATE':'';
        $request=$this->pdo->prepare('SELECT id,workflow_status,applied_correction_id FROM arpa_appointment_correction_amendment_request WHERE source_correction_id=? OR applied_correction_id=?'.$suffix);
        $request->execute([$correction['id'],$correction['id']]);
        if($request->fetch())return $this->blocked('AMENDMENT_REFERENCE','An active or completed correction amendment references this decision.');

        $successor=$this->pdo->prepare('SELECT id FROM arpa_appointment_data_correction WHERE supersedes_correction_id=?'.$suffix);
        $successor->execute([$correction['id']]);
        if($successor->fetchColumn())return $this->blocked('HAS_SUCCESSOR','A replacement decision supersedes this correction, so it is part of the effective-decision chain.');

        $closure=$this->pdo->prepare('SELECT id FROM arpa_division_appointment_closure WHERE data_correction_id=?'.$suffix);
        $closure->execute([$correction['id']]);
        if($closure->fetchColumn())return $this->blocked('CANONICAL_CLOSURE_REFERENCE','A canonical appointment closure depends on this correction decision.');

        return ['eligible'=>true,'code'=>'ELIGIBLE','reason'=>'This is a standalone no-change historical decision with no amendment, lineage, or canonical closure dependency.'];
    }

    /** @return array<string,mixed>|null */
    private function correction(string $id,bool $lock):?array
    {
        $stmt=$this->pdo->prepare('SELECT c.* FROM arpa_appointment_data_correction c WHERE c.id=?'.($lock?' FOR UPDATE':''));
        $stmt->execute([$id]);$row=$stmt->fetch();return $row?:null;
    }

    private function auditSchemaAvailable():bool
    {
        $stmt=$this->pdo->query("SELECT
            (SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name='arpa_appointment_data_correction_deletion_audit') audit_table,
            (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='arpa_appointment_data_correction_deletion_audit' AND column_name IN(
                'correction_id','officer_id','appointment_id','request_id','asc_location_id','original_correction_action',
                'original_resolution_status','correction_snapshot_json','deleted_by','deletion_reason','deleted_at'
            )) audit_columns");
        $row=$stmt->fetch();return $row&&(int)$row['audit_table']===1&&(int)$row['audit_columns']===11;
    }

    private function lineageSchemaAvailable():bool
    {
        $stmt=$this->pdo->query("SELECT
            (SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name='arpa_appointment_correction_amendment_request') amendment_table,
            (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='arpa_appointment_correction_amendment_request' AND column_name IN('source_correction_id','workflow_status','applied_correction_id')) amendment_columns,
            (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='arpa_appointment_data_correction' AND column_name IN('supersedes_correction_id','amendment_request_id')) lineage_columns,
            (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='arpa_division_appointment_closure' AND column_name='data_correction_id') closure_column");
        $row=$stmt->fetch();return $row&&(int)$row['amendment_table']===1&&(int)$row['amendment_columns']===3&&(int)$row['lineage_columns']===2&&(int)$row['closure_column']===1;
    }

    private function deletionAuditExists(string $id):bool
    {
        $stmt=$this->pdo->prepare('SELECT 1 FROM arpa_appointment_data_correction_deletion_audit WHERE correction_id=? LIMIT 1');$stmt->execute([$id]);
        return (bool)$stmt->fetchColumn();
    }

    private function canonicalJson(string $json):string
    {
        $decoded=json_decode($json,true,512,JSON_THROW_ON_ERROR);
        return json_encode($this->canonicalize($decoded),JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);
    }

    private function canonicalize(mixed $value):mixed
    {
        if(!is_array($value))return $value;
        if(array_is_list($value))return array_map(fn(mixed $item):mixed=>$this->canonicalize($item),$value);
        ksort($value,SORT_STRING);foreach($value as &$item)$item=$this->canonicalize($item);unset($item);return $value;
    }

    /** @return array{eligible:bool,code:string,reason:string} */
    private function blocked(string $code,string $reason):array{return ['eligible'=>false,'code'=>$code,'reason'=>$reason];}
}
