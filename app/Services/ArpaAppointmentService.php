<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\NumberService;
use DateTimeImmutable;
use DomainException;
use PDO;
use Throwable;

final class ArpaAppointmentService
{
    public function __construct(private readonly PDO $pdo) {}

    public function setServicePermanency(string $officerId, string $status, string $effectiveFrom, ?string $reason, string $actorId): void
    {
        if (!in_array($status, ArpaAppointmentRules::PERMANENCIES, true)) {
            throw new DomainException('Invalid service permanency.');
        }
        $this->assertDate($effectiveFrom, 'Effective date');
        $this->transaction(function () use ($officerId, $status, $effectiveFrom, $reason, $actorId): void {
            $officer = $this->arpaOfficer($officerId, true);
            $previous = $officer['arpa_service_permanency'] ?: null;
            if ($previous === $status) {
                return;
            }
            if($status==='PERMANENT_IN_SERVICE' && $this->officerHasTypeAt($officerId,'ATTEND_TO_DUTY',$effectiveFrom)){
                throw new DomainException('Close all Attend to the Duty appointments before marking the officer Permanent in Service.');
            }
            if($status==='NOT_PERMANENT_IN_SERVICE' && $this->officerHasTypeAt($officerId,'ACTING',$effectiveFrom)){
                throw new DomainException('Close all Acting appointments before marking the officer Not Permanent in Service.');
            }
            $this->pdo->prepare('UPDATE officer SET arpa_service_permanency=?,updated_by=?,updated_at=NOW(),version=version+1 WHERE id=?')
                ->execute([$status, $actorId, $officerId]);
            $this->pdo->prepare('INSERT INTO arpa_service_permanency_history(id,officer_id,previous_status,new_status,effective_from,reason,changed_by) VALUES(?,?,?,?,?,?,?)')
                ->execute([$this->uuid(), $officerId, $previous, $status, $effectiveFrom, $this->nullText($reason), $actorId]);
            $this->audit($actorId, 'arpa.service-permanency.change', 'OFFICER', $officerId, ['previous' => $previous, 'new' => $status, 'effective_from' => $effectiveFrom]);
        });
    }

    public function createDivisionAppointmentRequest(array $data, string $actorId): string
    {
        $officerId = trim((string)($data['officer_id'] ?? ''));
        $type = strtoupper(trim((string)($data['appointment_type'] ?? '')));
        $ascId = trim((string)($data['asc_location_id'] ?? ''));
        $divisionId = trim((string)($data['arpa_division_location_id'] ?? ''));
        $effectiveFrom = trim((string)($data['effective_from'] ?? ''));
        $this->assertDate($effectiveFrom, 'Effective from');
        return $this->transaction(function() use($officerId,$type,$ascId,$divisionId,$effectiveFrom,$data,$actorId):string {
            $officer = $this->arpaOfficer($officerId);
            $read = new ArpaAppointmentReadService($this->pdo);
            $read->assertEligibleOfficer($officerId,$ascId,$effectiveFrom);
            $read->assertDivisionVacant($ascId,$divisionId,$effectiveFrom,true);
            $snapshot = $this->locationSnapshot($ascId, $divisionId, $effectiveFrom);
            $hasPermanent = $type === 'PERMANENT' || $this->hasPermanentAt($officerId, $effectiveFrom);
            ArpaAppointmentRules::assertAppointmentTypeAllowed((string)$officer['arpa_service_permanency'], $type, $hasPermanent);
            $id = $this->uuid();
            $sql = 'INSERT INTO arpa_division_appointment_request(id,request_type,officer_id,appointment_type,asc_location_id,arpa_division_location_id,requested_effective_from,request_remarks,location_snapshot_json,created_by) VALUES(?,?,?,?,?,?,?,?,?,?)';
            $this->pdo->prepare($sql)->execute([$id, 'APPOINTMENT', $officerId, $type, $ascId, $divisionId, $effectiveFrom, $this->nullText($data['remarks'] ?? null), $this->json($snapshot), $actorId]);
            $this->audit($actorId, 'arpa.appointment-request.create', 'ARPA_APPOINTMENT_REQUEST', $id, ['type' => $type]);
            return $id;
        });
    }

    public function updateDivisionRequest(string $id, array $data, string $actorId): void
    {
        $this->transaction(function() use($id,$data,$actorId):void {
            $stmt=$this->pdo->prepare('SELECT * FROM arpa_division_appointment_request WHERE id=? FOR UPDATE');$stmt->execute([$id]);$request=$stmt->fetch();
            $this->assertEditableRequest($request,$actorId);
            if($request['request_type']==='APPOINTMENT'){
                $officerId=trim((string)($data['officer_id']??''));$type=strtoupper(trim((string)($data['appointment_type']??'')));$ascId=trim((string)($data['asc_location_id']??''));$divisionId=trim((string)($data['arpa_division_location_id']??''));$from=trim((string)($data['effective_from']??''));
                $this->assertDate($from,'Effective from');$officer=$this->arpaOfficer($officerId);$read=new ArpaAppointmentReadService($this->pdo);$read->assertEligibleOfficer($officerId,$ascId,$from);$read->assertDivisionVacant($ascId,$divisionId,$from,true);$snapshot=$this->locationSnapshot($ascId,$divisionId,$from);ArpaAppointmentRules::assertAppointmentTypeAllowed((string)$officer['arpa_service_permanency'],$type,$type==='PERMANENT'||$this->hasPermanentAt($officerId,$from));
                $this->pdo->prepare('UPDATE arpa_division_appointment_request SET officer_id=?,appointment_type=?,asc_location_id=?,arpa_division_location_id=?,requested_effective_from=?,request_remarks=?,location_snapshot_json=?,updated_by=?,updated_at=NOW(),version=version+1 WHERE id=?')->execute([$officerId,$type,$ascId,$divisionId,$from,$this->nullText($data['remarks']??null),$this->json($snapshot),$actorId,$id]);
            }elseif($request['request_type']==='END'){
                $to=trim((string)($data['effective_to']??''));$reason=trim((string)($data['end_reason_id']??''));$this->assertDate($to,'Effective to');$source=$this->appointment((string)$request['source_appointment_id']);if($to<$source['effective_from'])throw new DomainException('Effective to cannot precede the source appointment.');$this->endReason($reason);$impact=$this->dependentAppointments($source,$to);
                $this->pdo->prepare('UPDATE arpa_division_appointment_request SET requested_effective_to=?,end_reason_id=?,request_remarks=?,impact_snapshot_json=?,updated_by=?,updated_at=NOW(),version=version+1 WHERE id=?')->execute([$to,$reason,$this->nullText($data['remarks']??null),$this->json($impact),$actorId,$id]);
            }else{
                $oldTo=trim((string)($data['old_effective_to']??''));$newFrom=trim((string)($data['new_effective_from']??''));$asc=trim((string)($data['asc_location_id']??''));$division=trim((string)($data['arpa_division_location_id']??''));$reason=trim((string)($data['end_reason_id']??''));$this->assertDate($oldTo,'Old effective to');$this->assertDate($newFrom,'New effective from');if($newFrom<=$oldTo)throw new DomainException('The new Permanent appointment must start after the old appointment ends.');$source=$this->appointment((string)$request['source_appointment_id']);if($oldTo<$source['effective_from'])throw new DomainException('Transfer end date cannot precede the current appointment.');$this->endReason($reason);$snapshot=$this->locationSnapshot($asc,$division,$newFrom);$impact=$this->dependentAppointments($source,$oldTo);
                $this->pdo->prepare('UPDATE arpa_division_appointment_request SET asc_location_id=?,arpa_division_location_id=?,requested_effective_from=?,requested_effective_to=?,end_reason_id=?,request_remarks=?,impact_snapshot_json=?,location_snapshot_json=?,updated_by=?,updated_at=NOW(),version=version+1 WHERE id=?')->execute([$asc,$division,$newFrom,$oldTo,$reason,$this->nullText($data['remarks']??null),$this->json($impact),$this->json($snapshot),$actorId,$id]);
            }
            $this->audit($actorId,'arpa.appointment-request.edit','ARPA_APPOINTMENT_REQUEST',$id,['status'=>$request['workflow_status']]);
        });
    }

    public function createEndRequest(string $appointmentId, string $effectiveTo, string $reasonId, ?string $remarks, string $actorId): string
    {
        $this->assertDate($effectiveTo, 'Effective to');
        $appointment = $this->appointment($appointmentId);
        if ($effectiveTo < $appointment['effective_from']) {
            throw new DomainException('Effective to cannot be before the appointment effective date.');
        }
        $this->endReason($reasonId);
        $impact = $this->dependentAppointments($appointment, $effectiveTo);
        $id = $this->uuid();
        $sql = 'INSERT INTO arpa_division_appointment_request(id,request_type,officer_id,appointment_type,source_appointment_id,asc_location_id,arpa_division_location_id,requested_effective_to,end_reason_id,request_remarks,impact_snapshot_json,location_snapshot_json,created_by) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?)';
        $this->pdo->prepare($sql)->execute([$id, 'END', $appointment['officer_id'], $appointment['appointment_type'], $appointmentId, $appointment['asc_location_id'], $appointment['arpa_division_location_id'], $effectiveTo, $reasonId, $this->nullText($remarks), $this->json($impact), $appointment['hierarchy_snapshot_json'], $actorId]);
        $this->audit($actorId, 'arpa.appointment-end-request.create', 'ARPA_APPOINTMENT_REQUEST', $id, ['appointment_id' => $appointmentId, 'dependent_count' => count($impact)]);
        return $id;
    }

    public function createTransferRequest(string $appointmentId, string $newAscId, string $newDivisionId, string $oldEffectiveTo, string $newEffectiveFrom, string $reasonId, ?string $remarks, string $actorId): string
    {
        $this->assertDate($oldEffectiveTo, 'Old appointment effective to');
        $this->assertDate($newEffectiveFrom, 'New appointment effective from');
        if ($newEffectiveFrom <= $oldEffectiveTo) {
            throw new DomainException('The new Permanent appointment must start after the old appointment ends.');
        }
        $appointment = $this->appointment($appointmentId);
        if ($appointment['appointment_type'] !== 'PERMANENT') {
            throw new DomainException('Only a Permanent ARPA Division appointment can be transferred.');
        }
        if ($oldEffectiveTo < $appointment['effective_from']) {
            throw new DomainException('Transfer end date cannot precede the current appointment.');
        }
        $this->endReason($reasonId);
        $snapshot = $this->locationSnapshot($newAscId, $newDivisionId, $newEffectiveFrom);
        $impact = $this->dependentAppointments($appointment, $oldEffectiveTo);
        $id = $this->uuid();
        $sql = 'INSERT INTO arpa_division_appointment_request(id,request_type,officer_id,appointment_type,source_appointment_id,asc_location_id,arpa_division_location_id,requested_effective_from,requested_effective_to,end_reason_id,request_remarks,impact_snapshot_json,location_snapshot_json,created_by) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?)';
        $this->pdo->prepare($sql)->execute([$id, 'TRANSFER', $appointment['officer_id'], 'PERMANENT', $appointmentId, $newAscId, $newDivisionId, $newEffectiveFrom, $oldEffectiveTo, $reasonId, $this->nullText($remarks), $this->json($impact), $this->json($snapshot), $actorId]);
        $this->audit($actorId, 'arpa.transfer-request.create', 'ARPA_APPOINTMENT_REQUEST', $id, ['source_appointment_id' => $appointmentId, 'dependent_count' => count($impact)]);
        return $id;
    }

    public function createSubject(string $name, string $kind, string $systemKey, string $actorId,?string $nameSi=null,?string $nameTa=null): string
    {
        $kind = strtoupper(trim($kind));
        $name = trim($name);
        $systemKey=strtoupper(trim($systemKey));
        if ($name === '' || $systemKey==='' || preg_match('/^[A-Z][A-Z0-9_]{1,99}$/',$systemKey)!==1 || !in_array($kind, ArpaAppointmentRules::SUBJECT_KINDS, true)) {
            throw new DomainException('Subject name, stable system key, and a supported subject kind are required.');
        }
        if((in_array($kind,ArpaAppointmentRules::EXCLUSIVE_SUBJECT_KINDS,true)||in_array($systemKey,ArpaAppointmentRules::EXCLUSIVE_SUBJECT_KINDS,true)) && $systemKey!==$kind){
            throw new DomainException('System-recognized Bank, Sales Shop, and Sithamu subjects must use a matching stable system key and kind.');
        }
        $id = $this->uuid();
        return $this->transaction(function()use($id,$systemKey,$name,$kind,$actorId,$nameSi,$nameTa):string{
            $dadNumber=NumberService::nextUsing($this->pdo,'SUBJECT');
            $this->pdo->prepare("INSERT INTO subject_master(id,dad_number,system_key,name_en,name_si,name_ta,subject_kind,active,approval_status,effective_from,created_by) VALUES(?,?,?,?,?,?,?,1,'APPROVED',CURRENT_DATE(),?)")
                ->execute([$id,$dadNumber,$systemKey,$name,$this->nullText($nameSi),$this->nullText($nameTa),$kind,$actorId]);
            $this->audit($actorId,'subject.master.create','SUBJECT_MASTER',$id,['dad_number'=>$dadNumber,'kind'=>$kind]);
            return $id;
        });
    }

    public function createSubjectAssignmentRequest(array $data, string $actorId): string
    {
        $officerId = trim((string)($data['officer_id'] ?? ''));
        $ascId = trim((string)($data['asc_location_id'] ?? ''));
        $subjectId = trim((string)($data['subject_id'] ?? ''));
        $effectiveFrom = trim((string)($data['effective_from'] ?? ''));
        $this->assertDate($effectiveFrom, 'Effective from');
        $this->arpaOfficer($officerId);
        $subject = $this->subject($subjectId,false,$effectiveFrom);
        $snapshot = $this->ascSnapshot($ascId, $effectiveFrom) + ['subject' => $subject];
        $id = $this->uuid();
        $this->pdo->prepare('INSERT INTO arpa_subject_assignment_request(id,request_type,officer_id,asc_location_id,subject_id,requested_effective_from,request_remarks,location_snapshot_json,created_by) VALUES(?,?,?,?,?,?,?,?,?)')
            ->execute([$id, 'ASSIGN', $officerId, $ascId, $subjectId, $effectiveFrom, $this->nullText($data['remarks'] ?? null), $this->json($snapshot), $actorId]);
        $this->audit($actorId, 'arpa.subject-assignment-request.create', 'ARPA_SUBJECT_REQUEST', $id, []);
        return $id;
    }

    public function updateSubjectRequest(string $id,array $data,string $actorId):void
    {
        $this->transaction(function()use($id,$data,$actorId):void{
            $stmt=$this->pdo->prepare('SELECT * FROM arpa_subject_assignment_request WHERE id=? FOR UPDATE');$stmt->execute([$id]);$request=$stmt->fetch();$this->assertEditableRequest($request,$actorId);
            if($request['request_type']==='ASSIGN'){$officer=trim((string)($data['officer_id']??''));$ascId=trim((string)($data['asc_location_id']??''));$subjectId=trim((string)($data['subject_id']??''));$from=trim((string)($data['effective_from']??''));$this->assertDate($from,'Effective from');$this->arpaOfficer($officer);$subject=$this->subject($subjectId,false,$from);$snapshot=$this->ascSnapshot($ascId,$from)+['subject'=>$subject];$this->pdo->prepare('UPDATE arpa_subject_assignment_request SET officer_id=?,asc_location_id=?,subject_id=?,requested_effective_from=?,request_remarks=?,location_snapshot_json=?,updated_by=?,updated_at=NOW(),version=version+1 WHERE id=?')->execute([$officer,$ascId,$subjectId,$from,$this->nullText($data['remarks']??null),$this->json($snapshot),$actorId,$id]);}
            else{$to=trim((string)($data['effective_to']??''));$reason=trim((string)($data['end_reason_id']??''));$this->assertDate($to,'Effective to');$source=$this->subjectAssignment((string)$request['source_assignment_id']);if($to<$source['effective_from'])throw new DomainException('Effective to cannot precede the source assignment.');$this->endReason($reason);$this->pdo->prepare('UPDATE arpa_subject_assignment_request SET requested_effective_to=?,end_reason_id=?,request_remarks=?,updated_by=?,updated_at=NOW(),version=version+1 WHERE id=?')->execute([$to,$reason,$this->nullText($data['remarks']??null),$actorId,$id]);}
            $this->audit($actorId,'arpa.subject-request.edit','ARPA_SUBJECT_REQUEST',$id,['status'=>$request['workflow_status']]);
        });
    }

    public function createSubjectEndRequest(string $assignmentId, string $effectiveTo, string $reasonId, ?string $remarks, string $actorId): string
    {
        $this->assertDate($effectiveTo, 'Effective to');
        $assignment = $this->subjectAssignment($assignmentId);
        if ($effectiveTo < $assignment['effective_from']) {
            throw new DomainException('Effective to cannot be before the assignment effective date.');
        }
        $this->endReason($reasonId);
        $id = $this->uuid();
        $this->pdo->prepare('INSERT INTO arpa_subject_assignment_request(id,request_type,officer_id,asc_location_id,subject_id,source_assignment_id,requested_effective_to,end_reason_id,request_remarks,location_snapshot_json,created_by) VALUES(?,?,?,?,?,?,?,?,?,?,?)')
            ->execute([$id, 'END', $assignment['officer_id'], $assignment['asc_location_id'], $assignment['subject_id'], $assignmentId, $effectiveTo, $reasonId, $this->nullText($remarks), $assignment['context_snapshot_json'], $actorId]);
        $this->audit($actorId, 'arpa.subject-end-request.create', 'ARPA_SUBJECT_REQUEST', $id, []);
        return $id;
    }

    public function workflow(string $entity, string $requestId, string $action, string $stage, ?string $comments, string $actorId): string
    {
        $isDivision = $entity === 'division';
        if (!$isDivision && $entity !== 'subject') {
            throw new DomainException('Unsupported workflow entity.');
        }
        $table = $isDivision ? 'arpa_division_appointment_request' : 'arpa_subject_assignment_request';
        $history = $isDivision ? 'arpa_appointment_workflow_action' : 'arpa_subject_workflow_action';
        if(in_array(strtoupper($action),['RETURN_FOR_CORRECTION','REJECT'],true) && $this->nullText($comments)===null){
            throw new DomainException('Comments are required when returning or rejecting a request.');
        }
        return $this->transaction(function () use ($table, $history, $entity, $requestId, $action, $stage, $comments, $actorId): string {
            $stmt = $this->pdo->prepare("SELECT * FROM {$table} WHERE id=? FOR UPDATE");
            $stmt->execute([$requestId]);
            $request = $stmt->fetch();
            if (!$request) {
                throw new DomainException('Workflow request was not found.');
            }
            $transition = ArpaAppointmentRules::transition((string)$request['workflow_status'], $action, $stage);
            if (strtoupper($action) === 'SUBMIT' && !$this->canSubmitRequest($request,$actorId)) {
                throw new DomainException('Only the draft creator or an authorized ASC correction officer may submit this request.');
            }
            if (strtoupper($action) === 'SUBMIT') {
                $createsDuty=($entity==='division' && in_array((string)$request['request_type'],['APPOINTMENT','TRANSFER'],true)) || ($entity==='subject' && (string)$request['request_type']==='ASSIGN');
                if($createsDuty){$date=(string)$request['requested_effective_from'];$officeAssignments=new OfficerOfficeAssignmentService($this->pdo);if(!$officeAssignments->hasCurrentAscOfficeAssignment((string)$request['officer_id'],(string)$request['asc_location_id'],$date))throw new DomainException('This officer is not currently assigned to the selected Agrarian Service Center Office.');}
            }
            if (strtoupper($action) === 'VERIFY' && in_array(strtoupper($stage), ['DISTRICT','NATIONAL'], true)) {
                $review=$this->pdo->prepare('SELECT COUNT(*) FROM arpa_appointment_stage_review WHERE entity_type=? AND request_id=? AND review_stage=?');
                $review->execute([strtoupper($entity),$requestId,strtoupper($stage)]);
                if ((int)$review->fetchColumn()===0) throw new DomainException(ucfirst(strtolower($stage)).' review information must be recorded before verification.');
            }
            if (strtoupper($action) === 'APPROVE') {
                $this->assertMakerChecker($history, $requestId, (string)$request['created_by'], strtoupper($stage), $actorId);
            }
            if ($transition['status'] === 'NATIONAL_APPROVED') {
                if ($entity === 'division') {
                    $this->finalizeDivision($request, $actorId);
                } else {
                    $this->finalizeSubject($request, $actorId);
                }
            }
            $final = $transition['status'] === 'NATIONAL_APPROVED';
            $this->pdo->prepare("UPDATE {$table} SET workflow_status=?,updated_by=?,updated_at=NOW(),finalized_by=" . ($final ? '?' : 'finalized_by') . ',finalized_at=' . ($final ? 'NOW()' : 'finalized_at') . ',version=version+1 WHERE id=?')
                ->execute($final ? [$transition['status'], $actorId, $actorId, $requestId] : [$transition['status'], $actorId, $requestId]);
            $this->pdo->prepare("INSERT INTO {$history}(request_id,action,stage,user_id,comments,previous_status,new_status) VALUES(?,?,?,?,?,?,?)")
                ->execute([$requestId, strtoupper($action), strtoupper($stage), $actorId, $this->nullText($comments), $request['workflow_status'], $transition['status']]);
            $auditDetails=['previous'=>$request['workflow_status'],'new'=>$transition['status'],'stage'=>strtoupper($stage)];
            if(in_array(strtoupper($action),['RETURN_FOR_CORRECTION','REJECT'],true))$auditDetails['reason']=$this->nullText($comments);
            $this->audit($actorId,"arpa.{$entity}-workflow.".strtolower($action),strtoupper("ARPA_{$entity}_REQUEST"),$requestId,$auditDetails);
            return $transition['status'];
        });
    }

    public function saveStageReview(string $entity, string $requestId, string $stage, ?string $information, ?string $remarks, string $actorId): void
    {
        $stage = strtoupper($stage);
        if (!in_array($entity, ['division','subject'], true) || !in_array($stage, ['DISTRICT','NATIONAL'], true)) {
            throw new DomainException('Unsupported ARPA review stage.');
        }
        $table = $entity === 'division' ? 'arpa_division_appointment_request' : 'arpa_subject_assignment_request';
        $requiredStatus = $stage === 'DISTRICT' ? 'ASC_APPROVED' : 'DISTRICT_APPROVED';
        $information = $this->nullText($information);
        $remarks = $this->nullText($remarks);
        if ($information === null && $remarks === null) throw new DomainException('Review information or remarks are required.');

        $this->transaction(function () use ($table,$entity,$requestId,$stage,$requiredStatus,$information,$remarks,$actorId): void {
            $requestStmt=$this->pdo->prepare("SELECT workflow_status FROM {$table} WHERE id=? FOR UPDATE");
            $requestStmt->execute([$requestId]);
            $status=$requestStmt->fetchColumn();
            if ($status === false) throw new DomainException('Workflow request was not found.');
            if ($status !== $requiredStatus) throw new DomainException("{$stage} review information can only be edited while the request is at {$requiredStatus}.");

            $entityType=strtoupper($entity);
            $existingStmt=$this->pdo->prepare('SELECT * FROM arpa_appointment_stage_review WHERE entity_type=? AND request_id=? AND review_stage=? FOR UPDATE');
            $existingStmt->execute([$entityType,$requestId,$stage]);
            $existing=$existingStmt->fetch() ?: null;
            $id=$existing['id'] ?? $this->uuid();
            $previous=$existing ? ['review_information'=>$existing['review_information'],'remarks'=>$existing['remarks'],'updated_by'=>$existing['updated_by'],'version'=>(int)$existing['version']] : null;
            if ($existing) {
                $this->pdo->prepare('UPDATE arpa_appointment_stage_review SET review_information=?,remarks=?,updated_by=?,updated_at=NOW(),version=version+1 WHERE id=?')->execute([$information,$remarks,$actorId,$id]);
            } else {
                $this->pdo->prepare('INSERT INTO arpa_appointment_stage_review(id,entity_type,request_id,review_stage,review_information,remarks,updated_by) VALUES(?,?,?,?,?,?,?)')->execute([$id,$entityType,$requestId,$stage,$information,$remarks,$actorId]);
            }
            $new=['review_information'=>$information,'remarks'=>$remarks,'updated_by'=>$actorId,'version'=>(int)($existing['version'] ?? -1)+1];
            $this->pdo->prepare('INSERT INTO arpa_appointment_stage_review_audit(stage_review_id,entity_type,request_id,review_stage,previous_review_json,new_review_json,changed_by) VALUES(?,?,?,?,?,?,?)')->execute([$id,$entityType,$requestId,$stage,$previous===null?null:$this->json($previous),$this->json($new),$actorId]);
            $this->audit($actorId,'arpa.'.strtolower($stage).'-review.edit',strtoupper("ARPA_{$entity}_REQUEST"),$requestId,['stage'=>$stage,'previous'=>$previous,'new'=>$new]);
        });
    }

    private function finalizeDivision(array $request, string $actorId): void
    {
        $this->arpaOfficer((string)$request['officer_id'], true);
        if ($request['request_type'] === 'APPOINTMENT') {
            $this->insertAppointment($request, $actorId);
            return;
        }
        $source = $this->appointment((string)$request['source_appointment_id'], true);
        if ($request['request_type'] === 'END') {
            $this->closeAppointmentAndDependents($source, $request, $actorId, 'DIRECT');
            return;
        }
        if ($request['request_type'] === 'TRANSFER') {
            $this->closeAppointmentAndDependents($source, $request, $actorId, 'TRANSFER');
            $this->insertAppointment($request, $actorId);
            return;
        }
        throw new DomainException('Unsupported division request type.');
    }

    private function insertAppointment(array $request, string $actorId): void
    {
        $officer = $this->arpaOfficer((string)$request['officer_id'], true);
        $effectiveFrom = (string)$request['requested_effective_from'];
        if(!(new OfficerOfficeAssignmentService($this->pdo))->hasCurrentAscOfficeAssignment((string)$request['officer_id'],(string)$request['asc_location_id'],$effectiveFrom))throw new DomainException('This officer is not currently assigned to the selected Agrarian Service Center Office.');
        $type = (string)$request['appointment_type'];
        $snapshot = $this->locationSnapshot((string)$request['asc_location_id'], (string)$request['arpa_division_location_id'], $effectiveFrom, true);
        $hasPermanent = $type === 'PERMANENT' || $this->hasPermanentAt((string)$request['officer_id'], $effectiveFrom);
        ArpaAppointmentRules::assertAppointmentTypeAllowed((string)$officer['arpa_service_permanency'], $type, $hasPermanent);
        if ($this->hasExclusiveSubjectAt((string)$request['officer_id'], $effectiveFrom)) {
            throw new DomainException('The officer has an exclusive Bank, Sales Shop, or Sithamu assignment. Close it first.');
        }
        if ($this->divisionOccupiedAt((string)$request['arpa_division_location_id'], $effectiveFrom, $request['source_appointment_id'] ?? null)) {
            throw new DomainException('The ARPA Division already has an officer for an overlapping period.');
        }
        if ($type === 'PERMANENT' && $this->officerHasTypeAt((string)$request['officer_id'], 'PERMANENT', $effectiveFrom, $request['source_appointment_id'] ?? null)) {
            throw new DomainException('The officer already has a Permanent ARPA Division for an overlapping period.');
        }
        if ($type === 'ACTING' && $this->officerHasTypeAt((string)$request['officer_id'], 'ACTING', $effectiveFrom)) {
            throw new DomainException('The officer already has an Acting appointment for an overlapping period.');
        }
        if ($type === 'ATTEND_TO_DUTY' && $this->officerHasTypeAt((string)$request['officer_id'], 'ATTEND_TO_DUTY', $effectiveFrom)) {
            throw new DomainException('The officer already has an Attend to the Duty appointment for an overlapping period.');
        }
        $sql = 'INSERT INTO arpa_division_appointment(id,request_id,officer_id,appointment_type,service_permanency_snapshot,province_location_id_snapshot,district_location_id_snapshot,asc_location_id,arpa_division_location_id,province_dad_snapshot,province_name_snapshot,district_dad_snapshot,district_name_snapshot,asc_dad_snapshot,asc_name_snapshot,arpa_dad_snapshot,arpa_name_snapshot,hierarchy_snapshot_json,effective_from,approved_by,approved_at) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,NOW())';
        $this->pdo->prepare($sql)->execute([
            $this->uuid(), $request['id'], $request['officer_id'], $type, $officer['arpa_service_permanency'],
            $snapshot['province']['id'] ?? null, $snapshot['district']['id'] ?? null, $snapshot['asc']['id'], $snapshot['arpa']['id'],
            $snapshot['province']['dad_number'] ?? null, $snapshot['province']['name_en'] ?? null,
            $snapshot['district']['dad_number'] ?? null, $snapshot['district']['name_en'] ?? null,
            $snapshot['asc']['dad_number'], $snapshot['asc']['name_en'], $snapshot['arpa']['dad_number'], $snapshot['arpa']['name_en'],
            $this->json($snapshot), $effectiveFrom, $actorId,
        ]);
    }

    private function closeAppointmentAndDependents(array $source, array $request, string $actorId, string $kind): void
    {
        $end = (string)$request['requested_effective_to'];
        if ($end < $source['effective_from']) {
            throw new DomainException('Effective to cannot precede the source appointment.');
        }
        $this->assertNotClosed('arpa_division_appointment_closure', 'appointment_id', (string)$source['id']);
        $this->insertAppointmentClosure($source, $request, $end, $actorId, $kind);
        if ($source['appointment_type'] !== 'PERMANENT') {
            return;
        }
        foreach ($this->dependentAppointments($source, $end, true) as $dependent) {
            $dependentEnd = max($end, (string)$dependent['effective_from']);
            $this->insertAppointmentClosure($dependent, $request, $dependentEnd, $actorId, 'DEPENDENT');
        }
    }

    private function insertAppointmentClosure(array $appointment, array $request, string $effectiveTo, string $actorId, string $kind): void
    {
        $snapshot = ['appointment_id' => $appointment['id'], 'type' => $appointment['appointment_type'], 'effective_from' => $appointment['effective_from'], 'effective_to' => $effectiveTo];
        $this->pdo->prepare('INSERT INTO arpa_division_appointment_closure(id,appointment_id,request_id,effective_to,end_reason_id,closure_kind,remarks,context_snapshot_json,approved_by,approved_at,letter_date) VALUES(?,?,?,?,?,?,?,?,?,NOW(),?)')
            ->execute([$this->uuid(), $appointment['id'], $request['id'], $effectiveTo, $request['end_reason_id'], $kind, $this->nullText($request['request_remarks'] ?? null), $this->json($snapshot), $actorId, $effectiveTo]);
    }

    private function finalizeSubject(array $request, string $actorId): void
    {
        $this->arpaOfficer((string)$request['officer_id'], true);
        if ($request['request_type'] === 'END') {
            $assignment = $this->subjectAssignment((string)$request['source_assignment_id'], true);
            $this->assertNotClosed('arpa_subject_assignment_closure', 'assignment_id', (string)$assignment['id']);
            if ((string)$request['requested_effective_to'] < $assignment['effective_from']) {
                throw new DomainException('Effective to cannot precede the source assignment.');
            }
            $closureId=$this->uuid();
            $this->pdo->prepare('INSERT INTO arpa_subject_assignment_closure(id,assignment_id,request_id,effective_to,end_reason_id,remarks,context_snapshot_json,approved_by,approved_at,letter_date) VALUES(?,?,?,?,?,?,?,?,NOW(),?)')
                ->execute([$closureId, $assignment['id'], $request['id'], $request['requested_effective_to'], $request['end_reason_id'], $this->nullText($request['request_remarks'] ?? null), $assignment['context_snapshot_json'], $actorId, $request['requested_effective_to']]);
            if($assignment['subject_kind_snapshot']==='SITHAMU'){
                $period=$this->pdo->prepare('SELECT id FROM arpa_officer_sub_designation_period WHERE source_subject_assignment_id=? FOR UPDATE');$period->execute([$assignment['id']]);$periodId=$period->fetchColumn();if(!$periodId)throw new DomainException('The synchronized Sithamu sub-designation period is missing.');
                $this->pdo->prepare('INSERT INTO arpa_officer_sub_designation_closure(id,sub_designation_period_id,source_subject_assignment_closure_id,effective_to,end_reason_id,approved_by,approved_at) VALUES(?,?,?,?,?,?,NOW())')->execute([$this->uuid(),$periodId,$closureId,$request['requested_effective_to'],$request['end_reason_id'],$actorId]);
            }
            return;
        }
        $subject = $this->subject((string)$request['subject_id'], true,(string)$request['requested_effective_from']);
        $effectiveFrom = (string)$request['requested_effective_from'];
        if(!(new OfficerOfficeAssignmentService($this->pdo))->hasCurrentAscOfficeAssignment((string)$request['officer_id'],(string)$request['asc_location_id'],$effectiveFrom))throw new DomainException('This officer is not currently assigned to the selected Agrarian Service Center Office.');
        $exclusive = ArpaAppointmentRules::subjectIsExclusive((string)$subject['subject_kind']);
        if ($exclusive) {
            if ($this->officerHasAnyDivisionAt((string)$request['officer_id'], $effectiveFrom) || $this->officerHasAnySubjectAt((string)$request['officer_id'], $effectiveFrom)) {
                throw new DomainException('Bank, Sales Shop, and Sithamu require every incompatible assignment to be closed first.');
            }
        } elseif ($this->hasExclusiveSubjectAt((string)$request['officer_id'], $effectiveFrom)) {
            throw new DomainException('The officer already has an exclusive Bank, Sales Shop, or Sithamu assignment.');
        }
        $snapshot = $this->ascSnapshot((string)$request['asc_location_id'], $effectiveFrom, true);
        $context = $snapshot + ['subject' => $subject];
        $assignmentId=$this->uuid();
        $this->pdo->prepare('INSERT INTO arpa_subject_assignment(id,request_id,officer_id,subject_id,subject_kind_snapshot,officer_exclusive_snapshot,province_location_id_snapshot,district_location_id_snapshot,asc_location_id,province_name_snapshot,district_name_snapshot,asc_dad_snapshot,asc_name_snapshot,subject_name_snapshot,context_snapshot_json,effective_from,approved_by,approved_at) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,NOW())')
            ->execute([$assignmentId, $request['id'], $request['officer_id'], $subject['id'], $subject['subject_kind'], $exclusive ? 1 : 0, $snapshot['province']['id'] ?? null, $snapshot['district']['id'] ?? null, $snapshot['asc']['id'], $snapshot['province']['name_en'] ?? null, $snapshot['district']['name_en'] ?? null, $snapshot['asc']['dad_number'], $snapshot['asc']['name_en'], $subject['name_en'], $this->json($context), $effectiveFrom, $actorId]);
        if($subject['subject_kind']==='SITHAMU'){
            $designation=$this->pdo->query("SELECT id,system_key,name_en FROM designation WHERE system_key='SITHAMU' AND designation_level='SUB' AND active=1 AND approval_status='APPROVED' FOR UPDATE")->fetch();if(!$designation)throw new DomainException('The approved Sithamu sub-designation master is missing.');
            $this->pdo->prepare('INSERT INTO arpa_officer_sub_designation_period(id,officer_id,designation_id,source_subject_assignment_id,asc_location_id,designation_key_snapshot,designation_name_snapshot,asc_dad_snapshot,asc_name_snapshot,effective_from,approved_by,approved_at) VALUES(?,?,?,?,?,?,?,?,?,?,?,NOW())')->execute([$this->uuid(),$request['officer_id'],$designation['id'],$assignmentId,$snapshot['asc']['id'],$designation['system_key'],$designation['name_en'],$snapshot['asc']['dad_number'],$snapshot['asc']['name_en'],$effectiveFrom,$actorId]);
        }
    }

    private function assertMakerChecker(string $historyTable, string $requestId, string $creatorId, string $stage, string $actorId): void
    {
        if ($stage === 'ASC' && $creatorId === $actorId) {
            throw new DomainException('Maker-checker policy prevents the creator from approving this request.');
        }
        $stmt = $this->pdo->prepare("SELECT COUNT(*) FROM {$historyTable} WHERE request_id=? AND action='VERIFY' AND stage=? AND user_id=?");
        $stmt->execute([$requestId, $stage, $actorId]);
        if ((int)$stmt->fetchColumn() > 0) {
            throw new DomainException('Maker-checker policy prevents a verifier from approving the same transaction.');
        }
        if (in_array($stage,['DISTRICT','NATIONAL'],true)) {
            $entityType=$historyTable==='arpa_appointment_workflow_action'?'DIVISION':'SUBJECT';
            $review=$this->pdo->prepare('SELECT COUNT(*) FROM arpa_appointment_stage_review_audit WHERE entity_type=? AND request_id=? AND review_stage=? AND changed_by=?');
            $review->execute([$entityType,$requestId,$stage,$actorId]);
            if ((int)$review->fetchColumn()>0) throw new DomainException('Maker-checker policy prevents a stage review maker from approving the same transaction.');
        }
    }

    private function arpaOfficer(string $id, bool $lock = false): array
    {
        $stmt = $this->pdo->prepare("SELECT o.*,d.system_key AS designation_key FROM officer o JOIN designation d ON d.id=o.primary_designation_id WHERE o.id=? AND d.system_key='ARPA_OFFICER' AND d.active=1 AND d.approval_status='APPROVED'" . ($lock ? ' FOR UPDATE' : ''));
        $stmt->execute([$id]);
        $row = $stmt->fetch();
        if (!$row) {
            throw new DomainException('Only officers with the approved ARPA_OFFICER designation may use this module.');
        }
        return $row;
    }

    private function locationSnapshot(string $ascId, string $arpaId, string $date, bool $lock = false): array
    {
        if ($lock) {
            $lockStmt = $this->pdo->prepare('SELECT id FROM location WHERE id IN (?,?) ORDER BY id FOR UPDATE');
            $lockStmt->execute([$ascId, $arpaId]);
            $lockStmt->fetchAll();
        }
        $asc = $this->locationOfType($ascId, 'ASC');
        $arpa = $this->locationOfType($arpaId, 'ARPA_DIVISION');
        $stmt = $this->pdo->prepare("SELECT COUNT(*) FROM location_relationship WHERE parent_location_id=? AND child_location_id=? AND relationship_type='ASC_ARPA_DIVISION' AND active=1 AND approval_status='APPROVED' AND effective_from<=? AND (effective_to IS NULL OR effective_to>=?)");
        $stmt->execute([$ascId, $arpaId, $date, $date]);
        if ((int)$stmt->fetchColumn() !== 1) {
            throw new DomainException('The selected ARPA Division does not belong to the selected ASC on the effective date.');
        }
        $snapshot=$this->ascSnapshot($ascId, $date);
        if ($snapshot['district']===null || $snapshot['province']===null) {
            throw new DomainException('The ASC must have approved District and Province hierarchy on the effective date.');
        }
        return $snapshot + ['arpa' => $arpa];
    }

    private function ascSnapshot(string $ascId, string $date, bool $lock = false): array
    {
        if ($lock) {
            $stmt = $this->pdo->prepare('SELECT id FROM location WHERE id=? FOR UPDATE');
            $stmt->execute([$ascId]);
            $stmt->fetch();
        }
        $asc = $this->locationOfType($ascId, 'ASC');
        $sql = "SELECT d.id,d.dad_number,d.name_en,p.id AS province_id,p.dad_number AS province_dad,p.name_en AS province_name
                FROM location_relationship da JOIN location d ON d.id=da.parent_location_id
                JOIN location_type dt ON dt.id=d.location_type_id AND dt.system_key='DISTRICT'
                LEFT JOIN location_relationship pd ON pd.child_location_id=d.id AND pd.relationship_type='PROVINCE_DISTRICT' AND pd.active=1 AND pd.approval_status='APPROVED' AND pd.effective_from<=? AND (pd.effective_to IS NULL OR pd.effective_to>=?)
                LEFT JOIN location p ON p.id=pd.parent_location_id
                WHERE da.child_location_id=? AND da.relationship_type='DISTRICT_ASC' AND da.active=1 AND da.approval_status='APPROVED' AND da.effective_from<=? AND (da.effective_to IS NULL OR da.effective_to>=?) LIMIT 1";
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([$date, $date, $ascId, $date, $date]);
        $row = $stmt->fetch() ?: [];
        return [
            'province' => isset($row['province_id']) ? ['id' => $row['province_id'], 'dad_number' => $row['province_dad'], 'name_en' => $row['province_name']] : null,
            'district' => isset($row['id']) ? ['id' => $row['id'], 'dad_number' => $row['dad_number'], 'name_en' => $row['name_en']] : null,
            'asc' => $asc,
        ];
    }

    private function locationOfType(string $id, string $type): array
    {
        $stmt = $this->pdo->prepare("SELECT l.id,l.dad_number,l.name_en,l.official_code FROM location l JOIN location_type t ON t.id=l.location_type_id WHERE l.id=? AND t.system_key=? AND l.approval_status='APPROVED' AND l.operational_status='ACTIVE'");
        $stmt->execute([$id, $type]);
        $row = $stmt->fetch();
        if (!$row) {
            throw new DomainException("An approved active {$type} location is required.");
        }
        return $row;
    }

    private function appointment(string $id, bool $lock = false): array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM arpa_division_appointment WHERE id=?' . ($lock ? ' FOR UPDATE' : ''));
        $stmt->execute([$id]);
        $row = $stmt->fetch();
        if (!$row) throw new DomainException('ARPA Division appointment was not found.');
        return $row;
    }

    private function subject(string $id, bool $lock = false,?string $effectiveDate=null): array
    {
        $effectiveDate??=date('Y-m-d');
        $stmt = $this->pdo->prepare("SELECT * FROM subject_master WHERE id=? AND active=1 AND approval_status='APPROVED' AND effective_from<=? AND (effective_to IS NULL OR effective_to>=?)" . ($lock ? ' FOR UPDATE' : ''));
        $stmt->execute([$id,$effectiveDate,$effectiveDate]);
        $row = $stmt->fetch();
        if (!$row) throw new DomainException('Active ASC subject was not found.');
        return $row;
    }

    private function subjectAssignment(string $id, bool $lock = false): array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM arpa_subject_assignment WHERE id=?' . ($lock ? ' FOR UPDATE' : ''));
        $stmt->execute([$id]);
        $row = $stmt->fetch();
        if (!$row) throw new DomainException('ARPA subject assignment was not found.');
        return $row;
    }

    private function endReason(string $id): array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM arpa_appointment_end_reason WHERE id=? AND active=1');
        $stmt->execute([$id]);
        $row = $stmt->fetch();
        if (!$row) throw new DomainException('Active end reason was not found.');
        return $row;
    }

    private function hasPermanentAt(string $officerId, string $date): bool
    {
        $stmt=$this->pdo->prepare("SELECT COUNT(*) FROM arpa_division_appointment a LEFT JOIN arpa_division_appointment_closure c ON c.appointment_id=a.id WHERE a.officer_id=? AND a.appointment_type='PERMANENT' AND a.effective_from<=? AND (c.effective_to IS NULL OR c.effective_to>=?)");
        $stmt->execute([$officerId,$date,$date]);return (int)$stmt->fetchColumn()>0;
    }

    private function officerHasTypeAt(string $officerId, string $type, string $date, ?string $exclude = null): bool
    {
        $sql = 'SELECT COUNT(*) FROM arpa_division_appointment a LEFT JOIN arpa_division_appointment_closure c ON c.appointment_id=a.id WHERE a.officer_id=? AND a.appointment_type=? AND (c.effective_to IS NULL OR c.effective_to>=?)';
        $params = [$officerId, $type, $date];
        if ($exclude) { $sql .= ' AND a.id<>?'; $params[] = $exclude; }
        $stmt = $this->pdo->prepare($sql); $stmt->execute($params); return (int)$stmt->fetchColumn() > 0;
    }

    private function divisionOccupiedAt(string $divisionId, string $date, ?string $exclude = null): bool
    {
        $sql = 'SELECT COUNT(*) FROM arpa_division_appointment a LEFT JOIN arpa_division_appointment_closure c ON c.appointment_id=a.id WHERE a.arpa_division_location_id=? AND (c.effective_to IS NULL OR c.effective_to>=?)';
        $params = [$divisionId, $date];
        if ($exclude) { $sql .= ' AND a.id<>?'; $params[] = $exclude; }
        $stmt = $this->pdo->prepare($sql); $stmt->execute($params); return (int)$stmt->fetchColumn() > 0;
    }

    private function officerHasAnyDivisionAt(string $officerId, string $date): bool
    {
        $stmt = $this->pdo->prepare('SELECT COUNT(*) FROM arpa_division_appointment a LEFT JOIN arpa_division_appointment_closure c ON c.appointment_id=a.id WHERE a.officer_id=? AND (c.effective_to IS NULL OR c.effective_to>=?)');
        $stmt->execute([$officerId, $date]); return (int)$stmt->fetchColumn() > 0;
    }

    private function officerHasAnySubjectAt(string $officerId, string $date): bool
    {
        $stmt = $this->pdo->prepare('SELECT COUNT(*) FROM arpa_subject_assignment a LEFT JOIN arpa_subject_assignment_closure c ON c.assignment_id=a.id WHERE a.officer_id=? AND (c.effective_to IS NULL OR c.effective_to>=?)');
        $stmt->execute([$officerId, $date]); return (int)$stmt->fetchColumn() > 0;
    }

    private function hasExclusiveSubjectAt(string $officerId, string $date): bool
    {
        $stmt = $this->pdo->prepare('SELECT COUNT(*) FROM arpa_subject_assignment a LEFT JOIN arpa_subject_assignment_closure c ON c.assignment_id=a.id WHERE a.officer_id=? AND a.officer_exclusive_snapshot=1 AND (c.effective_to IS NULL OR c.effective_to>=?)');
        $stmt->execute([$officerId, $date]); return (int)$stmt->fetchColumn() > 0;
    }

    private function dependentAppointments(array $permanent, string $endDate, bool $lock = false): array
    {
        if ($permanent['appointment_type'] !== 'PERMANENT') return [];
        $sql = "SELECT a.* FROM arpa_division_appointment a LEFT JOIN arpa_division_appointment_closure c ON c.appointment_id=a.id WHERE a.officer_id=? AND a.appointment_type<>'PERMANENT' AND c.id IS NULL" . ($lock ? ' FOR UPDATE' : '');
        $stmt = $this->pdo->prepare($sql); $stmt->execute([$permanent['officer_id']]); return $stmt->fetchAll();
    }

    private function assertNotClosed(string $table, string $column, string $id): void
    {
        $stmt = $this->pdo->prepare("SELECT id FROM {$table} WHERE {$column}=? FOR UPDATE");
        $stmt->execute([$id]);
        if ($stmt->fetchColumn()) throw new DomainException('This record has already been ended.');
    }

    private function assertEditableRequest(mixed $request,string $actorId):void
    {
        if(!is_array($request))throw new DomainException('Workflow request was not found.');
        if(!in_array($request['workflow_status'],['CREATED','RETURNED'],true))throw new DomainException('Submitted requests cannot be edited until returned for correction.');
        if(!$this->canSubmitRequest($request,$actorId))throw new DomainException('Only the draft creator or an authorized ASC correction officer may edit this request.');
    }

    private function canSubmitRequest(array $request,string $actorId):bool
    {
        if($request['workflow_status']==='CREATED')return (string)$request['created_by']===$actorId;
        if($request['workflow_status']!=='RETURNED')return false;
        return (new ArpaWorkflowQueuePolicy($this->pdo))->canCorrectReturnedRequest(
            $actorId,
            (string)$request['asc_location_id'],
            (string)($request['requested_effective_from']?:date('Y-m-d'))
        );
    }

    private function assertDate(string $date, string $label): void
    {
        $parsed = DateTimeImmutable::createFromFormat('!Y-m-d', $date);
        if (!$parsed || $parsed->format('Y-m-d') !== $date) throw new DomainException("{$label} must be a valid date.");
    }

    private function audit(string $actorId, string $action, string $type, ?string $targetId, array $details): void
    {
        $this->pdo->prepare('INSERT INTO audit_event(actor_user_id,action_key,target_type,target_id,details_json,source_ip) VALUES(?,?,?,?,?,?)')
            ->execute([$actorId, $action, $type, $targetId, $this->json($details), $_SERVER['REMOTE_ADDR'] ?? 'CLI']);
    }

    private function transaction(callable $callback): mixed
    {
        $owned = !$this->pdo->inTransaction();
        if ($owned) $this->pdo->beginTransaction();
        try {
            $result = $callback();
            if ($owned) $this->pdo->commit();
            return $result;
        } catch (Throwable $e) {
            if ($owned && $this->pdo->inTransaction()) $this->pdo->rollBack();
            throw $e;
        }
    }

    private function uuid(): string
    {
        $b = random_bytes(16); $b[6] = chr((ord($b[6]) & 0x0f) | 0x40); $b[8] = chr((ord($b[8]) & 0x3f) | 0x80);
        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($b), 4));
    }

    private function json(mixed $value): string { return json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR); }
    private function nullText(mixed $value): ?string { $value = trim((string)$value); return $value === '' ? null : $value; }
}
