<?php
declare(strict_types=1);

use App\Core\Database;
use App\Services\{NotificationBackfillService,WorkflowNotificationService};

require dirname(__DIR__).'/bootstrap.php';

final class NotificationBackfillServiceTest
{
    private int $assertions=0;private PDO $pdo;

    public function run():int
    {
        $this->pdo=Database::pdo();$this->pdo->beginTransaction();
        try{$this->exercise();}finally{if($this->pdo->inTransaction())$this->pdo->rollBack();}
        echo "NotificationBackfillServiceTest: {$this->assertions} assertions passed.\n";return 0;
    }

    private function exercise():void
    {
        $special=$this->specialUserRequestOfficeAssignment();
        $this->same('arpa.appointment.asc-verify',NotificationBackfillService::arpaActionFor('division','END','SUBMITTED')[0]??null,'END submitted routes to ASC verifier');
        $this->same('arpa.appointment.asc-approve',NotificationBackfillService::arpaActionFor('division','END','ASC_VERIFIED')[0]??null,'END ASC verified routes to ASC approver');
        $this->same(true,NotificationBackfillService::isArpaCorrection('RETURNED'),'END returned is backfillable as a maker correction action');
        foreach([
            'ASC_APPROVED'=>'arpa.appointment.district-verify',
            'DISTRICT_VERIFIED'=>'arpa.appointment.district-approve',
            'DISTRICT_APPROVED'=>'arpa.appointment.national-verify',
            'NATIONAL_VERIFIED'=>'arpa.appointment.national-approve',
        ] as $status=>$permission){
            $this->same($permission,NotificationBackfillService::arpaActionFor('division','END',$status)[0]??null,"END {$status} routes to the next governance stage");
        }
        $this->same('arpa.appointment.district-verify',NotificationBackfillService::arpaActionFor('division','APPOINTMENT','ASC_APPROVED')[0]??null,'normal appointment ASC approval still routes to District verification');

        $before=(int)$this->pdo->query('SELECT COUNT(*) FROM system_notification')->fetchColumn();$report=(new NotificationBackfillService($this->pdo))->run(false);$after=(int)$this->pdo->query('SELECT COUNT(*) FROM system_notification')->fetchColumn();
        $this->same($before,$after,'dry-run writes no notifications');
        (new NotificationBackfillService($this->pdo))->run(true);
        $this->same(0,$this->notificationCount($special,null),'backfill does not create a standalone notification for a User Account Request initial Office assignment');
        $this->recipientBehavior();
    }

    private function specialUserRequestOfficeAssignment():string
    {
        $id=$this->uuid();$officer=(string)$this->pdo->query('SELECT id FROM officer ORDER BY id LIMIT 1')->fetchColumn();$office=(string)$this->pdo->query("SELECT id FROM office WHERE approval_status='APPROVED' AND operational_status='ACTIVE' ORDER BY id LIMIT 1")->fetchColumn();$actor=(string)$this->pdo->query('SELECT id FROM system_user ORDER BY id LIMIT 1')->fetchColumn();
        $this->pdo->prepare("INSERT INTO officer_office_assignment(id,officer_id,office_id,effective_from,active,reason,approval_status,created_by,submitted_by,submitted_at) VALUES(?,?,?,CURRENT_DATE(),0,'Initial Office for user account request','SUBMITTED',?,?,NOW())")->execute([$id,$officer,$office,$actor,$actor]);return $id;
    }

    private function recipientBehavior():void
    {
        $asc=(string)$this->pdo->query("SELECT l.id FROM location l JOIN location_type lt ON lt.id=l.location_type_id AND lt.system_key='ASC' WHERE l.approval_status='APPROVED' AND l.operational_status='ACTIVE' ORDER BY l.id LIMIT 1")->fetchColumn();
        $creator=$this->actor('ASC_SUBJECT_OFFICER',$asc);$peer=$this->actor('ASC_SUBJECT_OFFICER',$asc);$adminActor=$this->actor('ASC_ADMIN',$asc);$adminPeer=$this->actor('ASC_ADMIN',$asc);$service=new WorkflowNotificationService($this->pdo);

        $defaultEntity=$this->uuid();$service->actionForPermission('arpa.appointment.asc-verify',$asc,'TEST','Verify','Verify request.','TEST_ENTITY',$defaultEntity,'SUBMITTED','/notifications',$creator,['ASC_SUBJECT_OFFICER']);
        $this->same(0,$this->notificationCount($defaultEntity,$creator),'generic actionForPermission excludes actor by default');
        $this->same(1,$this->notificationCount($defaultEntity,$peer),'generic default still notifies another eligible recipient');

        $submittedEntity=$this->uuid();$service->actionForPermission('arpa.appointment.asc-verify',$asc,'TEST','Verify','Verify request.','TEST_ENTITY',$submittedEntity,'SUBMITTED','/notifications',$creator,['ASC_SUBJECT_OFFICER'],false);
        $this->same(1,$this->notificationCount($submittedEntity,$creator),'eligible ARPA creator receives submitted ASC verification notification');
        $this->same(1,$this->notificationCount($submittedEntity,$peer),'another eligible ASC Subject Officer also receives submitted verification notification');

        $approvalEntity=$this->uuid();$service->actionForPermission('arpa.appointment.asc-approve',$asc,'TEST','Approve','Approve request.','TEST_ENTITY',$approvalEntity,'ASC_VERIFIED','/notifications',$adminActor,['ASC_ADMIN']);
        $this->same(0,$this->notificationCount($approvalEntity,$adminActor),'ASC approval notification still excludes acting verifier/actor');
        $this->same(1,$this->notificationCount($approvalEntity,$adminPeer),'ASC approval notification reaches another eligible administrator');

        $officer=(string)$this->pdo->query('SELECT id FROM officer ORDER BY id LIMIT 1')->fetchColumn();$request=$this->uuid();
        $this->pdo->prepare("INSERT INTO arpa_division_appointment_request(id,record_origin,request_type,officer_id,asc_location_id,workflow_status,created_by,updated_by) VALUES(?,'NATIVE','APPOINTMENT',?,?,'SUBMITTED',?,?)")->execute([$request,$officer,$asc,$creator,$creator]);
        $backfill=new NotificationBackfillService($this->pdo);$backfill->run(true);
        $this->same(1,$this->notificationCount($request,$creator),'submitted ARPA backfill includes eligible creator');
        $this->same(1,$this->notificationCount($request,$peer),'submitted ARPA backfill includes other eligible verifier');
        $beforeRerun=$this->notificationCount($request,null);$backfill->run(true);$this->same($beforeRerun,$this->notificationCount($request,null),'rerunning backfill is idempotent through pending-notification dedupe');
    }

    private function actor(string $roleCode,string $asc):string
    {
        $user=$this->uuid();$roleAssignment=$this->uuid();$username='notice-'.strtolower($roleCode).'-'.substr(str_replace('-','',$user),0,8);$role=(string)$this->value('SELECT id FROM application_role WHERE role_code=?',[$roleCode]);
        $this->pdo->prepare("INSERT INTO system_user(id,identity_type,username,display_name,account_status,approval_status,enabled) VALUES(?,'STAFF',?,?,'ACTIVE','APPROVED',1)")->execute([$user,$username,$username]);
        $this->pdo->prepare("INSERT INTO user_account_role(id,user_id,role_id,effective_from,approval_status,active,reason,created_by,approved_by,approved_at) VALUES(?,?,?,CURRENT_DATE(),'APPROVED',1,'Notification test',?,?,NOW())")->execute([$roleAssignment,$user,$role,$user,$user]);
        $this->pdo->prepare("INSERT INTO user_account_scope(id,user_id,role_assignment_id,scope_type,scope_mode,location_id,effective_from,approval_status,active,reason,created_by,approved_by,approved_at) VALUES(UUID(),?,?,'ASC','EXACT',?,CURRENT_DATE(),'APPROVED',1,'Notification test',?,?,NOW())")->execute([$user,$roleAssignment,$asc,$user,$user]);return $user;
    }

    private function notificationCount(string $entityId,?string $recipient):int{$sql='SELECT COUNT(*) FROM system_notification WHERE entity_id=?'.($recipient===null?'':' AND recipient_user_id=?');$s=$this->pdo->prepare($sql);$s->execute($recipient===null?[$entityId]:[$entityId,$recipient]);return (int)$s->fetchColumn();}
    private function value(string $sql,array $params=[]):mixed{$s=$this->pdo->prepare($sql);$s->execute($params);return $s->fetchColumn();}
    private function uuid():string{return (string)$this->pdo->query('SELECT UUID()')->fetchColumn();}

    private function same(mixed $expected,mixed $actual,string $message):void{$this->assertions++;if($expected!==$actual)throw new RuntimeException($message.': expected '.var_export($expected,true).', got '.var_export($actual,true));}
}

exit((new NotificationBackfillServiceTest())->run());
