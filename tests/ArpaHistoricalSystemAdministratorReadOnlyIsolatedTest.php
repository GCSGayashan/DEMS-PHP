<?php
declare(strict_types=1);

define('ARPA_HISTORICAL_RELEASE_FIXTURE_ONLY',true);
require __DIR__.'/ArpaHistoricalReleaseSecurityIsolatedTest.php';

use App\Core\{Auth,Database};
use App\Services\{ArpaAdministrativePolicy,ArpaHistoricalAppointmentApprovalService,ArpaHistoricalApprovalReadService};

final class ArpaHistoricalSystemAdministratorReadOnlyIsolatedTest
{
    private PDO $pdo;
    private object $fixture;
    private object $ui;
    private int $assertions=0;
    private const ADMIN='00000000-0000-4000-8000-000000000003';

    public function run():void
    {
        $c=config('database');
        if(!in_array($c['host'],['127.0.0.1','localhost'],true)||!str_starts_with($c['database'],'dems_test_'))throw new RuntimeException('Isolated local process configuration required.');
        $db='dems_test_hist_ui_'.bin2hex(random_bytes(5));
        $server=new PDO("mysql:host={$c['host']};port={$c['port']};charset=utf8mb4",$c['username'],$c['password'],[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
        $property=new ReflectionProperty(Database::class,'pdo');$old=$property->getValue();$session=$_SESSION??[];$created=false;
        try{
            $server->exec('CREATE DATABASE `'.$db.'` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');$created=true;
            $this->pdo=new PDO("mysql:host={$c['host']};port={$c['port']};dbname={$db};charset=utf8mb4",$c['username'],$c['password'],[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,PDO::ATTR_EMULATE_PREPARES=>false,PDO::MYSQL_ATTR_MULTI_STATEMENTS=>true,PDO::MYSQL_ATTR_INIT_COMMAND=>'SET SESSION default_storage_engine=InnoDB']);
            if($this->pdo->query('SELECT DATABASE()')->fetchColumn()!==$db)throw new RuntimeException('Wrong disposable database.');
            $property->setValue(null,$this->pdo);$sources=ArpaHistoricalReleaseSecurityIsolatedTest::fixture($this->pdo);
            $this->fixture=new ArpaHistoricalAppointmentApprovalIsolatedTest();(new ReflectionProperty($this->fixture,'pdo'))->setValue($this->fixture,$this->pdo);
            $this->ui=new ArpaHistoricalApprovalUiIsolatedTest();(new ReflectionProperty($this->ui,'db'))->setValue($this->ui,$db);
            $s=$sources[0];$this->pdo->prepare("INSERT INTO arpa_division_appointment_closure(id,record_origin,appointment_id,request_id,effective_to,end_reason_id,closure_source,data_correction_id,context_snapshot_json,approval_timestamp_provenance) VALUES(UUID(),'LEGACY_IMPORT',?,?,'2024-12-31','18000000-0000-4000-8000-000000000001','DATA_ISSUE_CORRECTION',?,'{}','UNAVAILABLE_FROM_LEGACY_SOURCE')")->execute([$s['appointment'],$s['request'],$s['correction']]);
            $service=new ArpaHistoricalAppointmentApprovalService($this->pdo);$read=new ArpaHistoricalApprovalReadService($this->pdo);
            $this->context(1,'ASC_SUBJECT_OFFICER');$case=$service->register($s['correction'],['reason'=>'Synthetic complete-period audit','evidence_reference'=>'Synthetic administrative registration evidence'],'00000000-0000-4000-8000-000000000001')['case'];
            $before=$this->snapshot();$this->context(3,'SYSTEM_ADMIN');
            $this->same(true,ArpaAdministrativePolicy::isCanonicalSystemAdministrator(),'canonical identity and System Working Context required');
            $this->same(true,$read->canRead(self::ADMIN),'canonical System Administrator has explicit read access');
            $this->same(false,$read->canUse(self::ADMIN),'read access does not grant workflow queue access');
            $this->same(0,$read->count(self::ADMIN),'no additional pending workflow population');
            $detail=$read->requestCase($s['appointment'],$s['request'],self::ADMIN);
            $this->same($case['id'],$detail['case']['id'],'exact registered case selected for System Administrator');
            $this->same('DRAFT',$detail['case']['workflow_status'],'registered Draft status is visible');
            $this->same('2020-01-01',$detail['baseline']['appointment']['effective_from'],'existing appointment start is visible');
            $this->same('2024-12-31',$detail['baseline']['closures'][0]['effective_to'],'existing closure is visible in same case');
            $this->same('REGISTER',$detail['events'][0]['action'],'registration audit is visible');
            $this->same(null,$read->requestCase($sources[1]['appointment'],$s['request'],self::ADMIN),'administrator still requires exact appointment/request identity');
            $this->same(false,$read->canRead('00000000-0000-4000-8000-000000000001'),'current administrator cannot forge another actor');
            $this->same(1,(int)$read->profileRows([$s['appointment']],self::ADMIN)[$s['appointment']][0]['can_read_case'],'profile link uses same read-only policy');
            ArpaHistoricalAppointmentApprovalService::assertCliRegistrationOperator(self::ADMIN);
            $this->same($before,$this->snapshot(),'read and CLI operator eligibility checks write no records');
            $this->routes($s,$case);$this->deniedActions($service,$case);
            $this->authorization($read,$service,$case);$this->ambiguity($read,$sources,$case);
            $this->same($before,$this->snapshot(),'all reads and denied actions preserve cases, audit and imported source records');
            echo 'ArpaHistoricalSystemAdministratorReadOnlyIsolatedTest: '.$this->assertions." assertions passed; disposable database removed.\n";
        }finally{$property->setValue(null,$old);$_SESSION=$session;Auth::forgetRequestCache();if($created)$server->exec('DROP DATABASE `'.$db.'`');}
    }
    private function routes(array $source,array $case):void
    {
        $base='/hr/arpa-appointments/historical-approvals/'.$case['id'];
        foreach(['/hr/arpa-appointments/requests/division/'.$source['request'],$base] as $url){
            [$status,$html]=$this->route('GET',$url);$this->same(200,$status,'canonical administrator can open '.$url);
            foreach(['DRAFT','2020-01-01','2024-12-31','REGISTER'] as $text)$this->same(true,str_contains($html,$text),'read-only page displays '.$text);
            if(str_contains($url,'/requests/division/'))foreach(['Next Action','original request workflow','audit only'] as $text)$this->same(true,str_contains($html,$text),'administrator sees read-only original-workflow information: '.$text);
            foreach([$base.'/attest','name="expected_version"','value="VERIFY"','value="APPROVE"','value="REJECT"','value="RETURN_FOR_CORRECTION"',$base.'/save',$base.'/submit'] as $write)$this->same(false,str_contains($html,$write),'no historical write control '.$write);
        }
        foreach(['save'=>['expected_source_hash'=>$case['baseline_hash'],'reason'=>'Attempt','evidence_reference'=>'Attempt'],
            'submit'=>[],
            'review'=>['action'=>'VERIFY','reason'=>'Attempt','evidence_reference'=>'Attempt'],
            'attest'=>['action'=>'SUBMIT','confirm_records'=>'1','baseline_hash'=>$case['baseline_hash']]] as $action=>$post){
            [$status]=$this->route('POST',$base.'/'.$action,$post+['expected_version'=>'0']);$this->same(403,$status,'direct historical '.$action.' remains forbidden');
        }
    }
    private function deniedActions(ArpaHistoricalAppointmentApprovalService $service,array $case):void
    {
        $id=$case['id'];$hash=$case['baseline_hash'];$this->context(3,'SYSTEM_ADMIN');
        $this->denied(fn()=>$service->updateDraft($id,['reason'=>'Attempt','evidence_reference'=>'Attempt'],0,$hash,self::ADMIN),'draft editing');
        $this->denied(fn()=>$service->submit($id,0,self::ADMIN),'submission');
        $this->denied(fn()=>$service->submitExistingRecordAttestation($id,0,self::ADMIN,true,$hash),'attested submission');
        $this->pdo->beginTransaction();
        try{
            // Even accidental workflow-permission mappings must not turn this read path into write authority.
            $this->pdo->exec("INSERT IGNORE INTO application_role_permission SELECT 'release-system-role',id FROM application_permission WHERE permission_key LIKE 'arpa.appointment.%'");Auth::forgetRequestCache();
            foreach(['DRAFT','SUBMITTED','ASC_VERIFIED','ASC_APPROVED','DISTRICT_VERIFIED','DISTRICT_APPROVED','NATIONAL_VERIFIED','NATIONAL_APPROVED','RETURNED','REJECTED'] as $status){
                $this->pdo->prepare('UPDATE arpa_appointment_historical_approval_case SET workflow_status=? WHERE id=?')->execute([$status,$id]);
                $detail=$service->interfaceGet($id,self::ADMIN);
                $this->same(['edit'=>false,'submit'=>false,'primary'=>null,'return'=>false,'reject'=>false,'final'=>false],$detail['actions'],'all controls remain read-only at '.$status);
                $this->same(false,$detail['next_action']['authorized'],'next action never grants administrator write authority at '.$status);
                foreach(['VERIFY','APPROVE','RETURN_FOR_CORRECTION','REJECT'] as $action){
                    $this->denied(fn()=>$service->review($id,$action,0,self::ADMIN,'Attempt','Attempt'),$status.' '.$action);
                    $this->denied(fn()=>$service->reviewExistingRecordAttestation($id,$action,0,self::ADMIN,'Attempt',true,$hash),$status.' attested '.$action);
                }
            }
        }finally{$this->pdo->rollBack();Auth::forgetRequestCache();}
    }
    private function authorization(ArpaHistoricalApprovalReadService $read,ArpaHistoricalAppointmentApprovalService $service,array $case):void
    {
        $this->context(3,'ASC_ADMIN');$this->same(false,ArpaAdministrativePolicy::isCanonicalSystemAdministrator(),'identity alone is not System authorization');
        $this->same(null,$read->requestCase($case['appointment_id'],$case['request_id'],self::ADMIN),'ASC context does not acquire Draft audit visibility');
        $this->denied(fn()=>$service->get($case['id'],self::ADMIN),'wrong active context read');
        $this->pdo->exec("INSERT INTO user_account_role(id,user_id,role_id,active,approval_status,effective_from) VALUES('noncanonical-system-read','00000000-0000-4000-8000-000000000011','release-system-role',1,'APPROVED',CURRENT_DATE())");
        $this->context(11,'SYSTEM_ADMIN');$actor='00000000-0000-4000-8000-000000000011';
        $this->same(false,$read->canRead($actor),'noncanonical System account gets no exception');$this->denied(fn()=>$service->get($case['id'],$actor),'noncanonical System case read');
        $this->context(3,'SYSTEM_ADMIN');$this->pdo->beginTransaction();
        try{$this->pdo->exec("DELETE FROM application_role_permission WHERE role_id='release-system-role'");Auth::forgetRequestCache();
            $this->same(false,$read->canRead(self::ADMIN),'existing appointment-view permission remains required');$this->denied(fn()=>$service->get($case['id'],self::ADMIN),'missing view permission');
        }finally{$this->pdo->rollBack();Auth::forgetRequestCache();}
    }
    private function ambiguity(ArpaHistoricalApprovalReadService $read,array $sources,array $case):void
    {
        $this->context(3,'SYSTEM_ADMIN');$this->pdo->beginTransaction();
        try{
            $this->pdo->prepare("UPDATE arpa_appointment_historical_approval_case SET workflow_status='NATIONAL_APPROVED' WHERE id=?")->execute([$case['id']]);
            $this->pdo->prepare("INSERT INTO arpa_appointment_historical_approval_case(id,request_id,appointment_id,source_correction_id,officer_id,asc_location_id,baseline_json,baseline_hash,reason,evidence_reference,created_by,workflow_status) SELECT UUID(),request_id,appointment_id,?,officer_id,asc_location_id,baseline_json,baseline_hash,reason,evidence_reference,created_by,'REJECTED' FROM arpa_appointment_historical_approval_case WHERE id=?")->execute([$sources[1]['correction'],$case['id']]);
            $this->same(null,$read->requestCase($case['appointment_id'],$case['request_id'],self::ADMIN),'ambiguous matched cases remain hidden even from administrator');
        }finally{$this->pdo->rollBack();}
    }
    private function snapshot():array
    {
        $source=(new ReflectionMethod($this->fixture,'operational'))->invoke($this->fixture);
        foreach(['case','event'] as $table)$source['historical_'.$table]=$this->pdo->query('SELECT * FROM arpa_appointment_historical_approval_'.$table.' ORDER BY id')->fetchAll();return $source;
    }
    private function context(int $actor,string $role):void{(new ReflectionMethod($this->fixture,'context'))->invoke($this->fixture,'00000000-0000-4000-8000-'.str_pad((string)$actor,12,'0',STR_PAD_LEFT),$role);}
    private function route(string $method,string $url,array $post=[]):array{return (new ReflectionMethod($this->ui,'route'))->invoke($this->ui,$method,$url,$post,3,'SYSTEM_ADMIN');}
    private function denied(callable $action,string $label):void{try{$action();throw new RuntimeException('Unauthorized '.$label.' succeeded.');}catch(DomainException $e){$this->same(403,$e->getCode(),$label.' denied');}}
    private function same(mixed $expected,mixed $actual,string $label):void{$this->assertions++;if($expected!==$actual)throw new RuntimeException($label.'; expected '.var_export($expected,true).', got '.var_export($actual,true));}
}
try{(new ArpaHistoricalSystemAdministratorReadOnlyIsolatedTest())->run();}catch(Throwable $e){fwrite(STDERR,$e->getMessage()."\n".$e->getTraceAsString()."\n");exit(1);}
