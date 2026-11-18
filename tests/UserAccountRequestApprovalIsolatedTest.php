<?php
declare(strict_types=1);

use App\Core\{Auth,Database};
use App\Services\{OfficerOfficeAssignmentService,UserAccountRequestService,UserContextService};

require dirname(__DIR__).'/bootstrap.php';

/** No working database is selected: all schema, fixtures and writes are disposable. */
final class UserAccountRequestApprovalIsolatedTest
{
    private PDO $pdo;
    private int $assertions=0;
    private int $sequence=0;
    private string $district;
    private string $office;
    private array $actors=[];

    public function run():void
    {
        $host=getenv('DEMS_ISOLATED_DB_HOST')?:'127.0.0.1';
        $port=getenv('DEMS_ISOLATED_DB_PORT')?:'3306';
        if(!in_array($host,['127.0.0.1','localhost'],true)||!ctype_digit($port))throw new RuntimeException('Use a local disposable MySQL server.');
        $name='dems_test_account_approval_'.bin2hex(random_bytes(6));
        $options=[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,PDO::ATTR_EMULATE_PREPARES=>false,PDO::MYSQL_ATTR_MULTI_STATEMENTS=>true];
        $user=getenv('DEMS_ISOLATED_DB_USER')?:'root';$password=getenv('DEMS_ISOLATED_DB_PASSWORD')?:'';
        $server=new PDO("mysql:host=$host;port=$port;charset=utf8mb4",$user,$password,$options);
        $this->same(null,$server->query('SELECT DATABASE()')->fetchColumn(),'administrative connection selects no database');
        // Load .env once, then override all database settings before config() can cache them.
        env('APP_ENV');foreach(['DB_HOST'=>$host,'DB_PORT'=>$port,'DB_DATABASE'=>$name,'DB_USERNAME'=>$user,'DB_PASSWORD'=>$password] as $key=>$value)$_ENV[$key]=$value;
        $property=new ReflectionProperty(Database::class,'pdo');$old=$property->getValue();$session=$_SESSION??[];$created=false;
        try{
            if(!preg_match('/^dems_test_account_approval_[a-f0-9]{12}$/D',$name))throw new RuntimeException('Unsafe fixture database name.');
            $server->exec('CREATE DATABASE `'.$name.'` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');$created=true;
            $this->pdo=new PDO("mysql:host=$host;port=$port;dbname=$name;charset=utf8mb4",$user,$password,$options);
            $this->same($name,$this->pdo->query('SELECT DATABASE()')->fetchColumn(),'exact newly created target verified before fixture writes');$property->setValue(null,$this->pdo);
            $this->schema();$this->seed();$this->success(false);$this->success(true);$this->submittedWithHistory();$this->requestRoundTrips();$this->dateBoundaries();$this->invalidAssignments();$this->security();$this->rollbackFailures();
        }finally{
            if(isset($this->pdo)&&$this->pdo->inTransaction())$this->pdo->rollBack();
            $property->setValue(null,$old);$_SESSION=$session;Auth::forgetRequestCache();
            if($created)$server->exec('DROP DATABASE `'.$name.'`');
        }
        $s=$server->prepare('SELECT COUNT(*) FROM information_schema.schemata WHERE schema_name=?');$s->execute([$name]);
        $this->same(0,(int)$s->fetchColumn(),'disposable database removed');
        echo 'UserAccountRequestApprovalIsolatedTest: '.$this->assertions." assertions passed.\n";
        echo "Cleanup verified; no working database selected or modified.\n";
    }

    private function schema():void
    {
        // Explicit schema/reference allowlist. No migration runner, source imports,
        // working database copy, Reports, Payments or amendment dependencies.
        foreach(['001_initial_schema','002_seed_reference_data','003_workflow_metadata','004_access_workflow','005_assignment_workflows',
            '006_account_activation_flags','009_legacy_arpa_officer_master','010_legacy_officer_optional_birth_date','011_legacy_officer_record_permissive_fields',
            '017_legacy_user_identity_migration','031_officer_office_assignment','039_system_user_password_changed_at','046_role_scoped_user_access_management',
            '047_subject_officer_user_management_access','048_asc_user_management_access','049_operational_user_account_requests',
            '055_system_notification_center','056_assignment_soft_delete','060_subject_officer_office_assignment_workflow','061_officer_office_assignment_replacement_workflow'] as $migration){
            $this->pdo->exec(file_get_contents(BASE_PATH.'/database/migrations/'.$migration.'.sql'));
        }
    }

    private function seed():void
    {
        $this->district=$this->uuid();$this->office=$this->uuid();
        $this->pdo->prepare("INSERT INTO location(id,dad_number,location_type_id,name_en,effective_from,operational_status,approval_status) SELECT ?,'UAT-DISTRICT',id,'Synthetic District','2020-01-01','ACTIVE','APPROVED' FROM location_type WHERE system_key='DISTRICT'")->execute([$this->district]);
        $this->pdo->prepare("INSERT INTO office(id,dad_number,office_type_id,name_en,linked_location_id,effective_from,operational_status,approval_status) SELECT ?,'UAT-OFFICE',id,'Synthetic District Office',?,'2020-01-01','ACTIVE','APPROVED' FROM office_type WHERE system_key='DISTRICT_OFFICE'")->execute([$this->office,$this->district]);
        foreach(['maker'=>'SYSTEM_ADMIN','checker'=>'SECURITY_ADMIN','viewer'=>'NATIONAL_VIEWER','district'=>'DISTRICT_ADMIN'] as $key=>$code){
            $id=$this->uuid();$role=$this->uuid();$scope=$this->uuid();$this->actors[$key]=$id;
            $this->pdo->prepare("INSERT INTO system_user(id,username,display_name,account_status,approval_status,enabled) VALUES(?,?,?,'ACTIVE','APPROVED',1)")->execute([$id,'synthetic.'.$key,'Synthetic '.$key]);
            $this->pdo->prepare("INSERT INTO user_account_role(id,user_id,role_id,effective_from,approval_status,active) SELECT ?,?,id,'2020-01-01','APPROVED',1 FROM application_role WHERE role_code=?")->execute([$role,$id,$code]);
            $this->pdo->prepare("INSERT INTO user_account_scope(id,user_id,role_assignment_id,scope_type,scope_mode,location_id,effective_from,approval_status,active) VALUES(?,?,?,?,?,?,'2020-01-01','APPROVED',1)")->execute([$scope,$id,$role,$key==='district'?'DISTRICT':'NATIONAL',$key==='district'?'INCLUDE_CHILDREN':'NATIONAL',$key==='district'?$this->district:null]);
        }
    }

    private function fixture(bool $approved=true):array
    {
        $user=$this->uuid();$officer=$this->uuid();$role=$this->uuid();$scope=$this->uuid();$assignment=$this->uuid();$maker=$this->actors['maker'];$this->sequence++;
        $this->pdo->prepare("INSERT INTO officer(id,dad_number,nic,nic_normalized,name_with_initials,officer_status_id,effective_from,approval_status,operational_status,created_by,submitted_by) SELECT ?,?,?,?,'Synthetic Staff',id,'2025-01-01','SUBMITTED','INACTIVE',?,? FROM officer_status WHERE system_key='ACTIVE'")->execute([$officer,'UAT-'.str_pad((string)$this->sequence,7,'0',STR_PAD_LEFT),'UAT-NIC-'.$this->sequence,'UAT-NIC-'.$this->sequence,$maker,$maker]);
        $this->pdo->prepare("INSERT INTO system_user(id,officer_id,identity_type,identity_source,username,account_status,approval_status,enabled,requested_by,submitted_by) VALUES(?,?,'STAFF','MANUAL_NO_OFFICER',?,'REQUESTED','SUBMITTED',0,?,?)")->execute([$user,$officer,'synthetic.request.'.$this->sequence,$maker,$maker]);
        $status=$approved?'APPROVED':'SUBMITTED';
        $this->pdo->prepare("INSERT INTO user_account_role(id,user_id,role_id,effective_from,approval_status,active,reason,created_by,submitted_by,approved_by,approved_at) SELECT ?,?,id,'2025-01-01',?,?,'Initial role for user account request',?,?,?,? FROM application_role WHERE role_code='DISTRICT_ADMIN'")->execute([$role,$user,$status,(int)$approved,$maker,$maker,$approved?$maker:null,$approved?'2025-01-02 10:00:00':null]);
        $this->pdo->prepare("INSERT INTO user_account_scope(id,user_id,role_assignment_id,scope_type,scope_mode,location_id,effective_from,approval_status,active,created_by,submitted_by,approved_by,approved_at) VALUES(?,?,?,'DISTRICT','INCLUDE_CHILDREN',?,'2025-01-01',?,?,?,?,?,?)")->execute([$scope,$user,$role,$this->district,$status,(int)$approved,$maker,$maker,$approved?$maker:null,$approved?'2025-01-02 10:00:00':null]);
        $this->pdo->prepare("INSERT INTO officer_office_assignment(id,officer_id,office_id,effective_from,approval_status,active,is_primary,reason,created_by,submitted_by) VALUES(?,?,?,'2025-01-01','SUBMITTED',0,1,?,?,?)")->execute([$assignment,$officer,$this->office,OfficerOfficeAssignmentService::USER_ACCOUNT_REQUEST_INITIAL_REASON,$maker,$maker]);
        (new App\Services\WorkflowNotificationService($this->pdo))->actionForUser($this->actors['checker'],'ACCESS_MANAGEMENT','Synthetic pending request','Synthetic evidence','SYSTEM_USER',$user,'APPROVAL','/access-management/account-requests',$maker);
        return compact('user','officer','role','scope','assignment');
    }

    private function success(bool $approved):void
    {
        $this->scenario(function()use($approved):void{
            $f=$this->fixture($approved);$before=$this->accessRows($f);$this->context('checker');
            $this->same(false,Auth::can('officer.office-assignment.approve'),'Security Admin has no general HR Office approval permission');
            (new UserAccountRequestService($this->pdo))->approve($this->actors['checker'],$f['user']);
            $u=$this->one('SELECT approval_status,account_status,enabled FROM system_user WHERE id=?',[$f['user']]);
            $this->same(['approval_status'=>'APPROVED','account_status'=>'ACTIVE','enabled'=>1],$u,'combined account approved and activated');
            $this->same('APPROVED',$this->one('SELECT approval_status FROM officer WHERE id=?',[$f['officer']])['approval_status'],'Officer approval preserved');
            $office=$this->one('SELECT approval_status,active,version FROM officer_office_assignment WHERE id=?',[$f['assignment']]);
            $this->same(['approval_status'=>'APPROVED','active'=>1,'version'=>1],$office,'initial Office approval and version preserved');
            $this->same($this->office,$this->one('SELECT primary_office_id FROM officer WHERE id=?',[$f['officer']])['primary_office_id'],'primary Office updated');
            $after=$this->accessRows($f);if($approved)$this->same($before,$after,'already approved role/scope are byte-for-byte unchanged');
            foreach($after as $row){$this->same('APPROVED',$row['approval_status'],'role/scope approved');$this->same(1,(int)$row['active'],'role/scope active');}
            $this->same($approved?0:1,$this->count("SELECT COUNT(*) FROM audit_event WHERE action_key='user.role.approve' AND target_id=?",[$f['role']]),'no second role approval audit/call for reused assignment');
            $audit=json_decode($this->one("SELECT details_json FROM audit_event WHERE action_key='user.approve' AND target_id=?",[$f['user']])['details_json'],true);
            $this->same([$f['role']],$audit['role_assignment_ids'],'account audit retains exact initial role identity');
            $this->same($approved?[$f['role']]:null,$audit['already_approved_role_assignment_ids']??null,'audit explicitly identifies reused approval');
            $this->same(1,$this->count("SELECT COUNT(*) FROM system_notification WHERE entity_id=? AND action_status='COMPLETED' AND notification_type='ACTION_REQUIRED'",[$f['user']]),'pending account notification completed');
            $this->same(1,$this->count("SELECT COUNT(*) FROM system_notification WHERE entity_id=? AND title='User Account Request Approved' AND recipient_user_id=?",[$f['user'],$this->actors['maker']]),'request maker informed');
            $this->reject($f,'duplicate account approval denied');
        });
    }

    private function invalidAssignments():void
    {
        // Each mutation has its own synthetic aggregate and enclosing rollback.
        $mutations=[
            'missing scope'=>fn($f)=>$this->pdo->prepare('DELETE FROM user_account_scope WHERE id=?')->execute([$f['scope']]),
            'missing role'=>function($f){$this->pdo->prepare('DELETE FROM user_account_scope WHERE id=?')->execute([$f['scope']]);$this->pdo->prepare('DELETE FROM user_account_role WHERE id=?')->execute([$f['role']]);},
            'duplicate scope'=>fn($f)=>$this->pdo->prepare("INSERT INTO user_account_scope(id,user_id,role_assignment_id,scope_type,scope_mode,location_id,effective_from,approval_status,active) SELECT UUID(),user_id,role_assignment_id,scope_type,scope_mode,location_id,effective_from,approval_status,active FROM user_account_scope WHERE id=?")->execute([$f['scope']]),
            'multiple roles'=>fn($f)=>$this->extraRole($f,'APPROVED'),
            'mixed submitted and approved roles'=>fn($f)=>$this->extraRole($f,'SUBMITTED'),
            'role replaces predecessor'=>fn($f)=>$this->update('user_account_role',$f['role'],'replaces_assignment_id',$this->one('SELECT id FROM user_account_role WHERE user_id=?',[$this->actors['maker']])['id']),
            'role has replacement'=>function($f){$id=$this->extraRole($f,'DRAFT');$this->update('user_account_role',$id,'replaces_assignment_id',$f['role']);},
            'role has deleted replacement'=>function($f){$id=$this->extraRole($f,'DRAFT');$this->update('user_account_role',$id,'replaces_assignment_id',$f['role']);$this->update('user_account_role',$id,'deleted_at',date('Y-m-d H:i:s'));},
            'cross-account scope'=>function($f){$this->pdo->exec('SET FOREIGN_KEY_CHECKS=0');try{$this->update('user_account_scope',$f['scope'],'user_id',$this->actors['maker']);}finally{$this->pdo->exec('SET FOREIGN_KEY_CHECKS=1');}},
            'role unavailable'=>fn($f)=>$this->pdo->exec("UPDATE application_role SET active=0 WHERE role_code='DISTRICT_ADMIN'"),
            'role unassignable'=>fn($f)=>$this->pdo->exec("UPDATE application_role SET assignable=0 WHERE role_code='DISTRICT_ADMIN'"),
            'role unapproved'=>fn($f)=>$this->pdo->exec("UPDATE application_role SET approval_status='SUBMITTED' WHERE role_code='DISTRICT_ADMIN'"),
            'location inactive'=>fn($f)=>$this->update('location',$this->district,'operational_status','INACTIVE'),
            'location unapproved'=>fn($f)=>$this->update('location',$this->district,'approval_status','SUBMITTED'),
            'nonexistent location'=>function($f){$this->pdo->exec('SET FOREIGN_KEY_CHECKS=0');try{$this->update('user_account_scope',$f['scope'],'location_id',$this->uuid());}finally{$this->pdo->exec('SET FOREIGN_KEY_CHECKS=1');}},
            'location future'=>fn($f)=>$this->update('location',$this->district,'effective_from',date('Y-m-d',strtotime('+1 day'))),
            'incompatible scope mode'=>fn($f)=>$this->update('user_account_scope',$f['scope'],'scope_mode','EXACT'),
            'incompatible scope type'=>fn($f)=>$this->update('user_account_scope',$f['scope'],'scope_type','ASC'),
            'incompatible Office scope'=>fn($f)=>$this->update('user_account_scope',$f['scope'],'office_id',$this->office),
            'missing location'=>fn($f)=>$this->update('user_account_scope',$f['scope'],'location_id',null),
            'wrong location type'=>fn($f)=>$this->pdo->prepare("UPDATE location SET location_type_id=(SELECT id FROM location_type WHERE system_key='ASC') WHERE id=?")->execute([$this->district]),
            'scope before role'=>fn($f)=>$this->update('user_account_scope',$f['scope'],'effective_from','2024-12-31'),
            'scope exceeds role end'=>function($f){$this->update('user_account_role',$f['role'],'effective_to',date('Y-m-d',strtotime('+1 year')));},
        ];
        foreach(['user_account_role'=>'role','user_account_scope'=>'scope'] as $table=>$key){
            foreach(['inactive'=>['active',0],'deleted'=>['deleted_at',date('Y-m-d H:i:s')],'unapproved'=>['approval_status','SUBMITTED'],'expired'=>['effective_to',date('Y-m-d',strtotime('-1 day'))],'future'=>['effective_from',date('Y-m-d',strtotime('+1 day'))],'invalid dates'=>['effective_to','2024-12-31']] as $label=>[$column,$value]){
                if($key==='role'&&$label==='unapproved')continue; // Valid SUBMITTED path is tested separately.
                $mutations[$key.' '.$label]=fn($f)=>$this->update($table,$f[$key],$column,$value);
            }
        }
        foreach($mutations as $label=>$mutate)$this->scenario(function()use($mutate,$label):void{$f=$this->fixture();$mutate($f);$this->context('checker');$this->reject($f,$label);});
        foreach(['APPROVED','SUBMITTED'] as $status)$this->scenario(function()use($status):void{
            $f=$this->fixture();$id=$this->extraRole($f,$status);$this->update('user_account_role',$id,'deleted_at',date('Y-m-d H:i:s'));
            $this->context('checker');$this->reject($f,'fallback rejects deleted competing '.$status.' role');
        });
        $this->scenario(function():void{$f=$this->fixture();$this->extraRole($f,'SUBMITTED');$this->extraRole($f,'SUBMITTED');$this->context('checker');$this->reject($f,'multiple submitted roles conflict with approved fallback candidate');});
    }

    private function submittedWithHistory():void
    {
        $this->scenario(function():void{
            $f=$this->fixture(false);$historical=[];
            foreach(['APPROVED','REJECTED'] as $status){
                $id=$this->extraRole($f,$status);
                $this->pdo->prepare("UPDATE user_account_role SET active=0,effective_from='2020-01-01',effective_to='2024-12-31',role_id=(SELECT id FROM application_role WHERE role_code='NATIONAL_VIEWER') WHERE id=?")->execute([$id]);
                $this->pdo->prepare("INSERT INTO user_account_scope(id,user_id,role_assignment_id,scope_type,scope_mode,effective_from,effective_to,approval_status,active) VALUES(UUID(),?,?,'NATIONAL','NATIONAL','2020-01-01','2024-12-31',?,0)")->execute([$f['user'],$id,$status]);
                $historical[$id]=[$this->one('SELECT * FROM user_account_role WHERE id=?',[$id]),$this->one('SELECT * FROM user_account_scope WHERE role_assignment_id=?',[$id])];
            }
            $this->context('checker');(new UserAccountRequestService($this->pdo))->approve($this->actors['checker'],$f['user']);
            $this->same('APPROVED',$this->one('SELECT approval_status FROM system_user WHERE id=?',[$f['user']])['approval_status'],'one submitted initial assignment remains approvable with unrelated historical roles');
            $this->same('APPROVED',$this->one('SELECT approval_status FROM user_account_role WHERE id=?',[$f['role']])['approval_status'],'normal path approves the submitted role');
            $this->same(1,$this->count("SELECT COUNT(*) FROM audit_event WHERE action_key='user.role.approve' AND target_id=?",[$f['role']]),'normal path invokes existing role approval once');
            $audit=json_decode($this->one("SELECT details_json FROM audit_event WHERE action_key='user.approve' AND target_id=?",[$f['user']])['details_json'],true);
            $this->same(['role_assignment_ids'=>[$f['role']]],$audit,'normal path audit does not claim reused historical approvals');
            foreach($historical as $id=>$before)$this->same($before,[$this->one('SELECT * FROM user_account_role WHERE id=?',[$id]),$this->one('SELECT * FROM user_account_scope WHERE role_assignment_id=?',[$id])],'unrelated historical role and scope remain unchanged');
        });
    }

    private function dateBoundaries():void
    {
        $this->scenario(function():void{
            $f=$this->fixture();$today=date('Y-m-d');
            foreach(['user_account_role'=>'role','user_account_scope'=>'scope'] as $table=>$key){$this->update($table,$f[$key],'effective_from',$today);$this->update($table,$f[$key],'effective_to',$today);}
            $before=$this->accessRows($f);$this->context('checker');(new UserAccountRequestService($this->pdo))->approve($this->actors['checker'],$f['user']);
            $this->same($before,$this->accessRows($f),'approved start/end today are inclusive and remain unchanged');
        });
        $this->scenario(function():void{
            $f=$this->fixture(false);$future=date('Y-m-d',strtotime('+10 days'));
            foreach(['user_account_role'=>'role','user_account_scope'=>'scope','officer'=>'officer','officer_office_assignment'=>'assignment'] as $table=>$key)$this->update($table,$f[$key],'effective_from',$future);
            $this->context('checker');(new UserAccountRequestService($this->pdo))->approve($this->actors['checker'],$f['user']);
            $this->same('APPROVED',$this->one('SELECT approval_status FROM system_user WHERE id=?',[$f['user']])['approval_status'],'normal submitted future assignment approval remains supported');
            $this->same('INACTIVE',$this->one('SELECT operational_status FROM officer WHERE id=?',[$f['officer']])['operational_status'],'future Officer is not made operational early');
        });
    }

    private function requestRoundTrips():void
    {
        foreach([false,true] as $preapproved)$this->scenario(function()use($preapproved):void{
            $this->context('maker');$service=new UserAccountRequestService($this->pdo);
            $r=$service->request($this->actors['maker'],[
                'account_source'=>UserAccountRequestService::SOURCE_MANUAL,'full_name'=>'Synthetic combined request',
                'nic'=>'199912345678','username'=>'synthetic.roundtrip','temporary_password'=>'Synthetic-Test-Only-42!',
                'primary_designation_id'=>$this->one("SELECT id FROM designation WHERE system_key='DEVELOPMENT_OFFICER'")['id'],
                'officer_status_id'=>$this->one("SELECT id FROM officer_status WHERE system_key='ACTIVE'")['id'],
                'role_id'=>$this->one("SELECT id FROM application_role WHERE role_code='DISTRICT_ADMIN'")['id'],
                'location_id'=>$this->district,'effective_from'=>date('Y-m-d'),
            ]);
            $this->context('checker');
            if($preapproved)(new App\Services\UserAccessManagementService($this->pdo))->approveAssignment($this->actors['checker'],$r['role_assignment_id']);
            $f=['role'=>$r['role_assignment_id'],'scope'=>$this->one('SELECT id FROM user_account_scope WHERE role_assignment_id=?',[$r['role_assignment_id']])['id']];
            $before=$this->accessRows($f);$service->approve($this->actors['checker'],$r['user_id']);
            $this->same('APPROVED',$this->one('SELECT approval_status FROM system_user WHERE id=?',[$r['user_id']])['approval_status'],'real request() round trip approves account');
            $this->same('APPROVED',$this->one('SELECT approval_status FROM officer WHERE id=?',[$r['officer_id']])['approval_status'],'real request() round trip approves Officer');
            $this->same('APPROVED',$this->one('SELECT approval_status FROM officer_office_assignment WHERE id=?',[$r['office_assignment_id']])['approval_status'],'real request() round trip approves initial Office');
            if($preapproved)$this->same($before,$this->accessRows($f),'separately approved initial assignment is reused unchanged');
            $this->same(1,$this->count("SELECT COUNT(*) FROM audit_event WHERE action_key='user.role.approve' AND target_id=?",[$r['role_assignment_id']]),'exactly one role approval across the real combined workflow');
        });
    }

    private function security():void
    {
        foreach(['maker','viewer','district'] as $actor)$this->scenario(function()use($actor):void{$f=$this->fixture();$this->context($actor);$this->reject($f,$actor.' cannot approve request');});
        foreach(['role','scope'] as $key)foreach(['created_by','submitted_by'] as $field)$this->scenario(function()use($key,$field):void{$f=$this->fixture();$this->update($key==='role'?'user_account_role':'user_account_scope',$f[$key],$field,$this->actors['checker']);$this->context('checker');$this->reject($f,$key.' maker-checker '.$field);});
        $this->scenario(function():void{
            $f=$this->fixture();$other=$this->uuid();
            $this->pdo->prepare("INSERT INTO location(id,dad_number,location_type_id,name_en,effective_from,operational_status,approval_status) SELECT ?,'UAT-OTHER',id,'Unrelated synthetic District','2020-01-01','ACTIVE','APPROVED' FROM location_type WHERE system_key='DISTRICT'")->execute([$other]);
            $this->update('user_account_role',$f['role'],'role_id',$this->one("SELECT id FROM application_role WHERE role_code='DISTRICT_SUBJECT_OFFICER'")['id']);
            // Test the existing geographic policy with a permission-bearing scoped
            // reviewer. This grant exists only inside this rolled-back fixture.
            $this->pdo->exec("INSERT IGNORE INTO application_role_permission(role_id,permission_id) SELECT r.id,p.id FROM application_role r JOIN application_permission p ON p.permission_key='user.approve' WHERE r.role_code='DISTRICT_ADMIN'");
            $this->pdo->prepare('UPDATE user_account_scope SET location_id=? WHERE user_id=?')->execute([$other,$this->actors['district']]);
            $this->context('district');$this->reject($f,'out-of-scope permission-bearing reviewer','You can only select locations within your District.');
        });
        $this->scenario(function():void{$f=$this->fixture();$this->context('checker');$this->pdo->exec("UPDATE application_role_permission rp JOIN application_role r ON r.id=rp.role_id JOIN application_permission p ON p.id=rp.permission_id SET p.active=0 WHERE r.role_code='SECURITY_ADMIN' AND p.permission_key='user.approve'");Auth::forgetRequestCache();$this->reject($f,'user.approve remains mandatory');});
    }

    private function rollbackFailures():void
    {
        foreach([false,true] as $approved)$this->scenario(function()use($approved):void{$f=$this->fixture($approved);$this->update('officer_office_assignment',$f['assignment'],'approval_status','RETURNED');$this->context('checker');$this->reject($f,'invalid initial Office rolls back entire aggregate');});
        $this->pdo->exec("CREATE TRIGGER test_account_audit_failure BEFORE INSERT ON audit_event FOR EACH ROW BEGIN IF NEW.action_key='user.approve' THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Synthetic audit failure'; END IF; END");
        try{$this->scenario(function():void{$f=$this->fixture();$this->context('checker');$before=$this->state();try{(new UserAccountRequestService($this->pdo))->approve($this->actors['checker'],$f['user']);throw new RuntimeException('Audit failure not propagated');}catch(PDOException){$this->same($before,$this->state(),'audit failure rolls back Officer, Office, account and audit writes');}});}finally{$this->pdo->exec('DROP TRIGGER test_account_audit_failure');}
        $this->pdo->exec("CREATE TRIGGER test_account_notice_failure BEFORE INSERT ON system_notification FOR EACH ROW BEGIN IF NEW.title='User Account Request Approved' THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Synthetic notification failure'; END IF; END");
        try{$this->scenario(function():void{$f=$this->fixture();$this->context('checker');$before=$this->state();try{(new UserAccountRequestService($this->pdo))->approve($this->actors['checker'],$f['user']);throw new RuntimeException('Notification failure not propagated');}catch(PDOException){$this->same($before,$this->state(),'notification failure rolls back activation, approval audit and pending-stage completion');}});}finally{$this->pdo->exec('DROP TRIGGER test_account_notice_failure');}
    }

    private function reject(array $f,string $label,?string $expectedMessage=null):void
    {
        $before=$this->state();try{(new UserAccountRequestService($this->pdo))->approve((string)$_SESSION['user_id'],$f['user']);throw new RuntimeException($label.' was accepted');}catch(DomainException $e){if($expectedMessage!==null)$this->same($expectedMessage,$e->getMessage(),$label.' fails the intended guard');$this->same($before,$this->state(),$label.' rejected without partial writes');}
    }
    private function context(string $key):void
    {
        $id=$this->actors[$key];$_SESSION=['user_id'=>$id,'authenticated_at'=>time(),'last_activity_at'=>time()];Auth::forgetRequestCache();$s=new UserContextService($this->pdo);$c=$s->availableContexts($id)[0]??throw new RuntimeException('Synthetic context missing');$s->select($id,$c['role_assignment_id'],$c['scope_assignment_id']);Auth::forgetRequestCache();
    }
    private function extraRole(array $f,string $status):string{$id=$this->uuid();$this->pdo->prepare('INSERT INTO user_account_role(id,user_id,role_id,effective_from,approval_status,active,created_by) SELECT ?,user_id,role_id,effective_from,?,1,created_by FROM user_account_role WHERE id=?')->execute([$id,$status,$f['role']]);return $id;}
    private function accessRows(array $f):array{return [$this->one('SELECT * FROM user_account_role WHERE id=?',[$f['role']]),$this->one('SELECT * FROM user_account_scope WHERE id=?',[$f['scope']])];}
    private function scenario(callable $test):void{$this->pdo->beginTransaction();try{$test();}finally{if($this->pdo->inTransaction())$this->pdo->rollBack();Auth::forgetRequestCache();}}
    private function state():array{$r=[];foreach(['system_user','user_account_role','user_account_scope','officer','officer_office_assignment','officer_office_assignment_audit','audit_event','system_notification'] as $t)$r[$t]=hash('sha256',json_encode($this->pdo->query('SELECT * FROM '.$t.' ORDER BY id')->fetchAll()));return $r;}
    private function update(string $table,string $id,string $column,mixed $value):void{$this->pdo->prepare("UPDATE $table SET $column=? WHERE id=?")->execute([$value,$id]);}
    private function one(string $sql,array $params=[]):array{$s=$this->pdo->prepare($sql);$s->execute($params);return $s->fetch()?:[];}
    private function count(string $sql,array $params=[]):int{$s=$this->pdo->prepare($sql);$s->execute($params);return (int)$s->fetchColumn();}
    private function uuid():string{return (string)$this->pdo->query('SELECT UUID()')->fetchColumn();}
    private function same(mixed $expected,mixed $actual,string $label):void{$this->assertions++;if($expected!==$actual)throw new RuntimeException($label.': '.var_export($actual,true));}
}

try{(new UserAccountRequestApprovalIsolatedTest())->run();}catch(Throwable $e){fwrite(STDERR,get_class($e).': '.$e->getMessage()."\n".$e->getTraceAsString()."\n");exit(1);}
