<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Audit;
use DomainException;
use PDO;
use Throwable;

final class ArpaAppointmentAdministrationService
{
    public function __construct(private readonly PDO $pdo){}

    /** @return array<string,mixed> */
    public function request(string $requestId):array
    {
        $s=$this->pdo->prepare("SELECT r.*,o.dad_number officer_number,o.name_with_initials officer_name,o.nic,
                   asc_l.dad_number asc_number,asc_l.name_en asc_name,arpa.dad_number arpa_number,arpa.name_en arpa_name,
                   COALESCE(JSON_UNQUOTE(JSON_EXTRACT(r.location_snapshot_json,'$.district.name_en')),
                     (SELECT d.name_en FROM location_relationship da JOIN location d ON d.id=da.parent_location_id
                       WHERE da.child_location_id=r.asc_location_id AND da.relationship_type='DISTRICT_ASC'
                         AND da.active=1 AND da.approval_status='APPROVED' AND da.effective_from<=CURRENT_DATE()
                         AND (da.effective_to IS NULL OR da.effective_to>=CURRENT_DATE()) ORDER BY da.effective_from DESC,da.id LIMIT 1)) district_name,
                   source_a.arpa_name_snapshot source_appointment_name,
                   EXISTS(SELECT 1 FROM arpa_division_appointment a WHERE a.request_id=r.id) has_authoritative_appointment,
                   EXISTS(SELECT 1 FROM arpa_division_appointment_closure c WHERE c.request_id=r.id) has_authoritative_closure
              FROM arpa_division_appointment_request r
              JOIN officer o ON o.id=r.officer_id
              LEFT JOIN location asc_l ON asc_l.id=r.asc_location_id
              LEFT JOIN location arpa ON arpa.id=r.arpa_division_location_id
              LEFT JOIN arpa_division_appointment source_a ON source_a.id=r.source_appointment_id
             WHERE r.id=?");
        $s->execute([$requestId]);$row=$s->fetch();
        if(!$row)throw new DomainException('ARPA workflow request was not found.');
        return $row;
    }

    /** @return array<string,mixed> */
    public function appointmentForCorrection(string $appointmentId):array
    {
        $s=$this->pdo->prepare("SELECT a.*,o.dad_number officer_number,o.name_with_initials officer_name,o.nic,
                   r.workflow_status,r.request_type,r.requested_effective_from,r.requested_effective_to,
                   c.id closure_id,c.request_id closure_request_id,c.effective_to,c.end_reason_id,
                   end_r.requested_effective_to end_request_effective_to
              FROM arpa_division_appointment a
              JOIN officer o ON o.id=a.officer_id
              JOIN arpa_division_appointment_request r ON r.id=a.request_id
              LEFT JOIN arpa_division_appointment_closure c ON c.appointment_id=a.id
              LEFT JOIN arpa_division_appointment_request end_r ON end_r.id=c.request_id
             WHERE a.id=?");
        $s->execute([$appointmentId]);$row=$s->fetch();
        if(!$row)throw new DomainException('ARPA Division appointment was not found.');
        return $row;
    }

    /** @return array{correction_id:string,appointment_id:string} */
    public function correctDates(string $appointmentId,array $input,string $actorId):array
    {
        $context=ArpaAdministrativePolicy::assertDateCorrection();
        $reason=trim((string)($input['correction_reason']??''));
        if($reason==='')throw new DomainException('Correction Reason is required.');
        return $this->transaction(function()use($appointmentId,$input,$actorId,$context,$reason):array{
            $lock=$this->pdo->prepare('SELECT id FROM arpa_division_appointment WHERE id=? FOR UPDATE');$lock->execute([$appointmentId]);
            if(!$lock->fetchColumn())throw new DomainException('ARPA Division appointment was not found.');
            $before=$this->appointmentForCorrection($appointmentId);
            $from=$this->date($input['effective_from']??null,'Appointment Effective From');
            ArpaAppointmentRules::assertNativeEffectiveDate($from);
            $to=null;
            if($before['closure_id']!==null){
                $to=$this->date($input['effective_to']??null,'Appointment Effective To');
                if($to<$from)throw new DomainException('Appointment Effective To cannot be earlier than Appointment Effective From.');
            }
            $hierarchy=(new ArpaAppointmentLocationPolicy())->hierarchyContext($this->pdo,(string)$before['arpa_division_location_id'],(string)$before['asc_location_id'],$from);
            if(!$hierarchy['matches'])throw new DomainException('The ARPA Division does not belong to the recorded Agrarian Service Center on the corrected date.');
            $read=new ArpaAppointmentReadService($this->pdo);
            $read->assertDivisionPeriodAvailable((string)$before['asc_location_id'],(string)$before['arpa_division_location_id'],$from,$to,true,(string)$before['request_id'],$appointmentId);
            (new ArpaDivisionContinuityService($this->pdo))->assertCanStart((string)$before['arpa_division_location_id'],$from,(string)$before['request_id'],$appointmentId,false,false);
            if((string)$before['effective_from']===$from&&($before['closure_id']===null||((string)$before['effective_to']===$to)))throw new DomainException('No appointment date change was provided.');

            $this->pdo->prepare('UPDATE arpa_division_appointment SET effective_from=? WHERE id=?')->execute([$from,$appointmentId]);
            $this->pdo->prepare('UPDATE arpa_division_appointment_request SET requested_effective_from=?,version=version+1,updated_at=updated_at WHERE id=?')->execute([$from,$before['request_id']]);
            if($before['closure_id']!==null){
                $this->pdo->prepare('UPDATE arpa_division_appointment_closure SET effective_to=? WHERE id=?')->execute([$to,$before['closure_id']]);
                $this->pdo->prepare('UPDATE arpa_division_appointment_request SET requested_effective_to=?,version=version+1,updated_at=updated_at WHERE id=?')->execute([$to,$before['closure_request_id']]);
            }

            $after=$this->appointmentForCorrection($appointmentId);$correctionId=$this->uuid();
            $changed=[];foreach(['effective_from','effective_to','requested_effective_from','requested_effective_to','end_request_effective_to'] as $field){if(($before[$field]??null)!==($after[$field]??null))$changed[]=$field;}
            $source=$this->sourceReferences((string)$before['request_id']);
            $auditContext=ArpaAdministrativePolicy::auditContext($context);
            $beforePayload=['appointment'=>$this->datePayload($before),'active_context'=>$auditContext];
            $afterPayload=['appointment'=>$this->datePayload($after),'active_context'=>$auditContext,'changed_fields'=>$changed];
            $this->pdo->prepare("INSERT INTO arpa_appointment_data_correction(id,issue_row_key,issue_type,officer_id,appointment_id,request_id,related_appointment_ids_json,asc_location_id,corrected_by,correction_action,resolution_status,correction_reason,remarks,evidence_reference,before_json,after_json,record_origin,legacy_source_references_json) VALUES(?,?,'ADMIN_DATE_CORRECTION',?,?,?,?,?,?, 'ADMIN_DATE_CORRECTION','RESOLVED_BY_CORRECTION',?,NULL,NULL,?,?,?,?)")
                ->execute([$correctionId,'ADMIN_DATE_CORRECTION:'.$appointmentId.':'.$correctionId,$before['officer_id'],$appointmentId,$before['request_id'],$this->json([$appointmentId]),$before['asc_location_id'],$actorId,$reason,$this->json($beforePayload),$this->json($afterPayload),$before['record_origin'],$this->json($source)]);
            Audit::record('arpa.appointment.admin-date-correction','ARPA_DIVISION_APPOINTMENT',$appointmentId,['request_id'=>$before['request_id'],'closure_id'=>$before['closure_id'],'officer_id'=>$before['officer_id'],'changed_fields'=>$changed,'reason'=>$reason,'active_context'=>$auditContext,'correction_id'=>$correctionId],'WARNING');
            return ['correction_id'=>$correctionId,'appointment_id'=>$appointmentId];
        });
    }

    public function deleteRequest(string $requestId,string $reason,string $actorId):void
    {
        $context=ArpaAdministrativePolicy::assertDelete();$reason=trim($reason);
        if($reason==='')throw new DomainException('Delete Reason is required.');
        $this->transaction(function()use($requestId,$reason,$actorId,$context):void{
            $s=$this->pdo->prepare('SELECT * FROM arpa_division_appointment_request WHERE id=? FOR UPDATE');$s->execute([$requestId]);$request=$s->fetch();
            if(!$request)throw new DomainException('ARPA workflow request was not found.');
            if($request['deleted_at']!==null)throw new DomainException('This ARPA workflow request has already been deleted.');
            $s=$this->pdo->prepare('SELECT id FROM arpa_division_appointment WHERE request_id=? LIMIT 1');$s->execute([$requestId]);
            if($s->fetchColumn())throw new DomainException('This request produced an authoritative appointment and cannot be deleted. Use the appointment correction facility.');
            $s=$this->pdo->prepare('SELECT id FROM arpa_division_appointment_closure WHERE request_id=? LIMIT 1');$s->execute([$requestId]);
            if($s->fetchColumn())throw new DomainException('This request produced an authoritative appointment closure and cannot be deleted. Use the appointment correction facility.');
            $this->pdo->prepare('UPDATE arpa_division_appointment_request SET deleted_at=NOW(),deleted_by=?,delete_reason=?,version=version+1 WHERE id=? AND deleted_at IS NULL')->execute([$actorId,$reason,$requestId]);
            (new WorkflowNotificationService($this->pdo))->resolveAll('ARPA_DIVISION_REQUEST',$requestId,$actorId,'Workflow request administratively deleted','CANCELLED');
            Audit::record('arpa.appointment.workflow-request.admin-delete','ARPA_DIVISION_APPOINTMENT_REQUEST',$requestId,['officer_id'=>$request['officer_id'],'request_type'=>$request['request_type'],'workflow_status'=>$request['workflow_status'],'source_appointment_id'=>$request['source_appointment_id'],'previous_values'=>$request,'delete_reason'=>$reason,'active_context'=>ArpaAdministrativePolicy::auditContext($context)],'WARNING');
        });
    }

    private function datePayload(array $row):array{return array_intersect_key($row,array_flip(['id','request_id','closure_id','closure_request_id','officer_id','effective_from','effective_to','requested_effective_from','requested_effective_to','end_request_effective_to','workflow_status','record_origin']));}
    private function sourceReferences(string $requestId):array{$s=$this->pdo->prepare('SELECT source_system,source_table,legacy_appointment_id FROM legacy_arpa_appointment_source_reference WHERE target_appointment_request_id=? ORDER BY id');$s->execute([$requestId]);return $s->fetchAll();}
    private function date(mixed $value,string $label):string{$value=trim((string)$value);$date=\DateTimeImmutable::createFromFormat('!Y-m-d',$value);if(!$date||$date->format('Y-m-d')!==$value)throw new DomainException("{$label} must be a valid date.");return $value;}
    private function json(mixed $value):string{return json_encode($value,JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);}
    private function uuid():string{return (string)$this->pdo->query('SELECT UUID()')->fetchColumn();}
    private function transaction(callable $work):mixed{$owned=!$this->pdo->inTransaction();if($owned)$this->pdo->beginTransaction();try{$result=$work();if($owned)$this->pdo->commit();return $result;}catch(Throwable $e){if($owned&&$this->pdo->inTransaction())$this->pdo->rollBack();throw $e;}}
}
