<?php
declare(strict_types=1);

use App\Core\{Database,ScopeService};
use App\Services\{ArpaAppointmentFormOptionsService,ArpaAppointmentReadService,ArpaAppointmentRules};

require dirname(__DIR__).'/bootstrap.php';

final class ArpaAppointmentDateChangeOptionsTest
{
    private PDO $pdo;
    private int $assertions=0;

    public function run():int
    {
        $this->pdo=Database::pdo();
        $this->pdo->beginTransaction();
        try{$this->exercise();}finally{$this->pdo->rollBack();}
        echo "ArpaAppointmentDateChangeOptionsTest: {$this->assertions} assertions passed.\n";
        return 0;
    }

    private function exercise():void
    {
        $user=(string)$this->value("SELECT id FROM system_user WHERE username='asctest'");
        $asc=(string)$this->value("SELECT id FROM location WHERE dad_number='70004-0000389'");
        if($user===''||$asc==='')throw new RuntimeException('The verified asctest/Kurunegala fixtures are required.');
        $date='2026-03-08';
        $read=new ArpaAppointmentReadService($this->pdo);
        $formOptions=new ArpaAppointmentFormOptionsService($this->pdo);

        $officers=$read->eligibleOfficersForAsc($user,$asc,$date);
        $divisions=$read->vacantDivisionsForAsc($user,$asc,$date);
        $this->same(7,count($officers),'historical date retains the seven current Kurunegala Office-assigned ARPA Officers');
        $this->same(true,count($divisions)>0,'historical date returns date-dependent vacant Divisions');
        $this->same(true,ScopeService::canAccessCurrentArpaStage($user,'ASC',$asc),'current ASC context authorizes historical option loading');

        $officer=(string)$officers[0]['id'];$division=(string)$divisions[0]['id'];
        $allowed=(array)$officers[0]['allowed_appointment_types'];$type=(string)($allowed[0]??'');
        $loaded=$formOptions->load($user,$asc,$date,[
            'officer_id'=>$officer,
            'arpa_division_location_id'=>$division,
            'appointment_type'=>$type,
        ]);
        $this->same($officer,$loaded['selectedOfficer'],'still-eligible Officer remains selected');
        $this->same($division,$loaded['selectedDivision'],'still-vacant Division remains selected');
        if($type!=='')$this->same($type,$loaded['selectedAppointmentType'],'Appointment Type is recomputed and retained when still allowed');

        $invalid=$formOptions->reconcileSelections($officers,$divisions,[
            'officer_id'=>'not-an-officer',
            'arpa_division_location_id'=>'not-a-division',
            'appointment_type'=>'ACTING',
        ],$date);
        $this->same('',$invalid['selectedOfficer'],'invalidated Officer is cleared alone');
        $this->same('',$invalid['selectedDivision'],'invalidated Division is cleared alone');
        $this->same(true,in_array('The previously selected Officer is not eligible on the selected start date.',$invalid['selectionMessages'],true),'invalid Officer receives a controlled message');
        $this->same(true,in_array('The previously selected ARPA Division has no uncovered period available on the selected start date or is outside the selected ASC.',$invalid['selectionMessages'],true),'invalid Division receives a controlled message');

        ArpaAppointmentRules::assertNativeEffectiveDate('2025-01-01');$this->assertions++;
        $this->throws(fn()=>$formOptions->load($user,$asc,'2024-12-31'),'pre-baseline option request is rejected by business-date validation');

        $otherAsc=(string)$this->value("SELECT l.id FROM location l JOIN location_type lt ON lt.id=l.location_type_id AND lt.system_key='ASC' WHERE l.id<>? AND l.operational_status='ACTIVE' AND l.approval_status='APPROVED' LIMIT 1",[$asc]);
        $this->same(false,ScopeService::canAccessCurrentArpaStage($user,'ASC',$otherAsc),'forged ASC remains outside current Active Working Context');

        if(count($divisions)<3)throw new RuntimeException('Three vacant ARPA Division fixtures are required.');
        $ended=$this->appointment($user,$officer,$asc,(string)$divisions[0]['id'],'2025-01-01');
        $this->close($user,$ended,'2026-03-07');
        $afterEnd=array_column($read->vacantDivisionsForAsc($user,$asc,$date),'id');
        $this->same(true,in_array((string)$divisions[0]['id'],$afterEnd,true),'appointment ended before proposed date does not occupy the Division');

        $this->appointment($user,$officer,$asc,(string)$divisions[1]['id'],'2026-03-01');
        $occupied=array_column($read->vacantDivisionsForAsc($user,$asc,$date),'id');
        $this->same(false,in_array((string)$divisions[1]['id'],$occupied,true),'appointment effective on proposed date excludes the occupied Division');

        $this->appointment($user,$officer,$asc,(string)$divisions[2]['id'],'2026-04-01');
        $scheduled=array_column($read->vacantDivisionsForAsc($user,$asc,$date),'id');
        $this->same(false,in_array((string)$divisions[2]['id'],$scheduled,true),'scheduled open appointment continues to reserve the Division');
        $timelineOptions=$formOptions->load($user,$asc,$date,['arpa_division_location_id'=>(string)$divisions[2]['id']]);
        $this->same(true,in_array((string)$divisions[2]['id'],array_column($timelineOptions['arpaDivisions'],'id'),true),'New Assignment keeps a Division with later coverage visible for historical timeline evaluation');

        $view=(string)file_get_contents(BASE_PATH.'/app/Views/arpa_appointments/division_form.php');
        $controller=(string)file_get_contents(BASE_PATH.'/app/Controllers/ArpaAppointmentController.php');
        $this->same(true,str_contains($view,'No eligible ARPA Officers are available for'),'empty Officer state is explicit');
        $this->same(true,str_contains($view,'No ARPA Divisions are available within this Agrarian Service Center'),'empty Division state is explicit');
        $this->same(true,str_contains($controller,"assertArpaStageScope('ASC',\$ascId)"),'dependent endpoint enforces current ASC scope server-side');
        $this->same(false,str_contains($controller,"assertArpaStageScope('ASC',\$ascId,\$businessDate)"),'business date is not passed into actor authorization');
    }

    private function appointment(string $actor,string $officer,string $asc,string $division,string $from):string
    {
        $location=$this->pdo->prepare('SELECT a.dad_number asc_dad,a.name_en asc_name,d.dad_number arpa_dad,d.name_en arpa_name FROM location a JOIN location d ON d.id=? WHERE a.id=?');
        $location->execute([$division,$asc]);$row=$location->fetch();
        $request=$this->uuid();$appointment=$this->uuid();
        $this->pdo->prepare("INSERT INTO arpa_division_appointment_request(id,request_type,officer_id,appointment_type,asc_location_id,arpa_division_location_id,requested_effective_from,workflow_status,created_by,finalized_by,finalized_at) VALUES(?,'APPOINTMENT',?,'PERMANENT',?,?,?,'NATIONAL_APPROVED',?,?,NOW())")
            ->execute([$request,$officer,$asc,$division,$from,$actor,$actor]);
        $this->pdo->prepare("INSERT INTO arpa_division_appointment(id,request_id,officer_id,appointment_type,service_permanency_snapshot,asc_location_id,arpa_division_location_id,asc_dad_snapshot,asc_name_snapshot,arpa_dad_snapshot,arpa_name_snapshot,hierarchy_snapshot_json,effective_from,approved_by,approved_at) VALUES(?,?,?,'PERMANENT','PERMANENT_IN_SERVICE',?,?,?,?,?,?,'{}',?,?,NOW())")
            ->execute([$appointment,$request,$officer,$asc,$division,$row['asc_dad'],$row['asc_name'],$row['arpa_dad'],$row['arpa_name'],$from,$actor]);
        return $appointment;
    }

    private function close(string $actor,string $appointment,string $to):void
    {
        $row=$this->row('SELECT * FROM arpa_division_appointment WHERE id=?',[$appointment]);
        $request=$this->uuid();$reason=(string)$this->value('SELECT id FROM arpa_appointment_end_reason ORDER BY display_order LIMIT 1');
        $this->pdo->prepare("INSERT INTO arpa_division_appointment_request(id,request_type,officer_id,appointment_type,source_appointment_id,asc_location_id,arpa_division_location_id,requested_effective_to,workflow_status,created_by,finalized_by,finalized_at) VALUES(?,'END',?,?,?,?,?,?,'NATIONAL_APPROVED',?,?,NOW())")
            ->execute([$request,$row['officer_id'],$row['appointment_type'],$appointment,$row['asc_location_id'],$row['arpa_division_location_id'],$to,$actor,$actor]);
        $this->pdo->prepare("INSERT INTO arpa_division_appointment_closure(id,appointment_id,request_id,effective_to,end_reason_id,closure_kind,context_snapshot_json,approved_by,approved_at) VALUES(UUID(),?,?,?,?,'DIRECT','{}',?,NOW())")
            ->execute([$appointment,$request,$to,$reason,$actor]);
    }

    private function row(string $sql,array $params=[]):array{$s=$this->pdo->prepare($sql);$s->execute($params);return $s->fetch()?:[];}
    private function value(string $sql,array $params=[]):mixed{$s=$this->pdo->prepare($sql);$s->execute($params);return $s->fetchColumn();}
    private function same(mixed $expected,mixed $actual,string $message):void{$this->assertions++;if($expected!==$actual)throw new RuntimeException($message.': expected '.var_export($expected,true).', got '.var_export($actual,true));}
    private function throws(callable $callback,string $message):void{$this->assertions++;try{$callback();}catch(DomainException){return;}throw new RuntimeException($message.': expected DomainException');}
    private function uuid():string{$hex=bin2hex(random_bytes(16));return substr($hex,0,8).'-'.substr($hex,8,4).'-4'.substr($hex,13,3).'-'.dechex((hexdec($hex[16])&3)|8).substr($hex,17,3).'-'.substr($hex,20);}
}

exit((new ArpaAppointmentDateChangeOptionsTest())->run());
