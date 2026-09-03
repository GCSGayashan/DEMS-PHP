<?php
declare(strict_types=1);

use App\Core\{Auth,Database,ScopeService};
use App\Services\{ArpaAppointmentReadService,ArpaAppointmentRules,UserContextService};
require dirname(__DIR__).'/bootstrap.php';

final class ArpaAppointmentHistoricalAuthorizationTest
{
    private PDO $pdo;
    private int $assertions=0;

    public function run():int
    {
        $this->pdo=Database::pdo();
        $this->pdo->beginTransaction();
        try{$this->exercise();}
        finally{$_SESSION=[];Auth::forgetRequestCache();$this->pdo->rollBack();}
        echo "ArpaAppointmentHistoricalAuthorizationTest: {$this->assertions} assertions passed.\n";
        return 0;
    }

    private function exercise():void
    {
        [$districtA,$ascA,$ascB]=$this->locations();
        $ascUser=$this->user('historical.auth.asc');
        [$ascRole,$ascScope]=$this->assignment($ascUser,'ASC_ADMIN','ASC','EXACT',$ascA);
        [$nationalBorrowRole,$nationalBorrowScope]=$this->assignment($ascUser,'NATIONAL_ADMIN','NATIONAL','NATIONAL',null);
        $districtUser=$this->user('historical.auth.district');
        [$districtRole,$districtScope]=$this->assignment($districtUser,'DISTRICT_SUBJECT_OFFICER','DISTRICT','INCLUDE_CHILDREN',$districtA);
        $nationalUser=$this->user('historical.auth.national');
        [$nationalRole,$nationalScope]=$this->assignment($nationalUser,'NATIONAL_SUBJECT_OFFICER','NATIONAL','NATIONAL',null);
        $contexts=new UserContextService($this->pdo);

        $this->authenticate($ascUser);$contexts->select($ascUser,$ascRole,$ascScope);Auth::forgetRequestCache();
        $context=Auth::activeContext();
        $this->same($ascRole,(string)($context['role_assignment_id']??''),'selected ASC role assignment remains authoritative');
        $this->same($ascScope,(string)($context['scope_assignment_id']??''),'selected ASC scope assignment remains authoritative');
        $this->same(true,ScopeService::canAccessCurrentArpaStage($ascUser,'ASC',$ascA),'current ASC context authorizes its own ASC');
        $this->same(true,ScopeService::canAccessArpaStage($ascUser,'ASC',$ascA,'2026-07-14'),'historical appointment date does not re-date current ASC authorization');
        $this->same(true,ScopeService::canAccessArpaStage($ascUser,'ASC',$ascA,'2025-01-01'),'system appointment baseline does not re-date current ASC authorization');
        $this->same(false,ScopeService::canAccessArpaStage($ascUser,'ASC',$ascB,'2026-07-14'),'forged ASC remains outside the selected ASC context');
        $this->same(false,ScopeService::canAccessCurrentArpaStage($ascUser,'NATIONAL',$ascA),'ASC context cannot borrow the user\'s National role');
        $this->same([], (new ArpaAppointmentReadService($this->pdo))->eligibleOfficersForAsc($ascUser,$ascB,'2026-07-14'),'date reload returns no candidates for a forged ASC');

        $this->authenticate($districtUser);$contexts->select($districtUser,$districtRole,$districtScope);Auth::forgetRequestCache();
        $this->same(true,ScopeService::canAccessArpaStage($districtUser,'DISTRICT',$ascA,'2026-07-14'),'current District context reaches its descendant ASC for a historical record');
        $this->same(false,ScopeService::canAccessArpaStage($districtUser,'ASC',$ascA,'2026-07-14'),'District context cannot perform the ASC stage');

        $this->authenticate($nationalUser);$contexts->select($nationalUser,$nationalRole,$nationalScope);Auth::forgetRequestCache();
        $this->same(true,ScopeService::canAccessArpaStage($nationalUser,'NATIONAL',$ascA,'2026-07-14'),'current National context processes historical records without account-date coupling');

        ArpaAppointmentRules::assertNativeEffectiveDate('2025-01-01');$this->assertions++;
        $this->throws(fn()=>ArpaAppointmentRules::assertNativeEffectiveDate('2024-12-31'),'pre-baseline date is rejected by the ARPA business-date rule');

        $controller=(string)file_get_contents(BASE_PATH.'/app/Controllers/ArpaAppointmentController.php');
        $scope=(string)file_get_contents(BASE_PATH.'/app/Core/ScopeService.php');
        $router=(string)file_get_contents(BASE_PATH.'/app/Core/Router.php');
        $form=(string)file_get_contents(BASE_PATH.'/app/Views/arpa_appointments/division_form.php');
        $formJs=(string)file_get_contents(BASE_PATH.'/public/assets/js/arpa-division-form.js');
        $this->same(false,str_contains($controller,'assertArpaStageScope(\'ASC\',$selectedAsc,$effectiveDate)'),'date reload no longer passes business date into authorization');
        $this->same(true,str_contains($scope,'canAccessCurrentArpaStage'),'scope service exposes explicit current-context authorization');
        $this->same(true,str_contains($router,'catch (AuthorizationException $exception)'),'GET authorization failures become controlled 403 responses');
        $this->same(true,str_contains($formJs,"target.searchParams.set('effective_from',date.value)"),'date change continues to reload business-date candidate data');
        $this->same(true,str_contains($formJs,"fetch(target.toString()"),'date change uses an in-place dependent-data refresh');
        $this->same(true,str_contains($form,'assets/js/arpa-division-form.js'),'date-change behavior remains a local application asset');
    }

    /** @return array{0:string,1:string,2:string} */
    private function locations():array
    {
        $sql="WITH RECURSIVE district_tree(district_id,id) AS (
                SELECT l.id,l.id FROM location l JOIN location_type lt ON lt.id=l.location_type_id AND lt.system_key='DISTRICT'
                WHERE l.operational_status='ACTIVE' AND l.approval_status='APPROVED'
                UNION DISTINCT
                SELECT dt.district_id,lr.child_location_id FROM district_tree dt JOIN location_relationship lr ON lr.parent_location_id=dt.id
                WHERE lr.active=1 AND lr.approval_status='APPROVED' AND lr.effective_from<=CURRENT_DATE() AND (lr.effective_to IS NULL OR lr.effective_to>=CURRENT_DATE())
              ) SELECT dt.district_id,l.id asc_id FROM district_tree dt JOIN location l ON l.id=dt.id JOIN location_type lt ON lt.id=l.location_type_id AND lt.system_key='ASC' ORDER BY dt.district_id,l.id";
        $rows=$this->pdo->query($sql)->fetchAll();if(count($rows)<2)throw new RuntimeException('Two active ASC fixtures are required.');
        $first=$rows[0];$other=null;foreach($rows as $row)if((string)$row['asc_id']!==(string)$first['asc_id']){$other=$row;break;}
        if($other===null)throw new RuntimeException('An unrelated ASC fixture is required.');
        return [(string)$first['district_id'],(string)$first['asc_id'],(string)$other['asc_id']];
    }

    /** @return array{0:string,1:string} */
    private function assignment(string $userId,string $roleCode,string $scopeType,string $scopeMode,?string $locationId):array
    {
        $roleAssignment=$this->uuid();$scopeAssignment=$this->uuid();
        $role=(string)$this->value('SELECT id FROM application_role WHERE role_code=?',[$roleCode]);if($role==='')throw new RuntimeException("Role {$roleCode} is required.");
        $this->pdo->prepare("INSERT INTO user_account_role(id,user_id,role_id,effective_from,approval_status,active,reason) VALUES(?,?,?,'2026-08-01','APPROVED',1,'Historical authorization test')")->execute([$roleAssignment,$userId,$role]);
        $this->pdo->prepare("INSERT INTO user_account_scope(id,user_id,role_assignment_id,scope_type,scope_mode,location_id,effective_from,approval_status,active,reason) VALUES(?,?,?,?,?,?,'2026-08-01','APPROVED',1,'Historical authorization test')")->execute([$scopeAssignment,$userId,$roleAssignment,$scopeType,$scopeMode,$locationId]);
        return [$roleAssignment,$scopeAssignment];
    }

    private function user(string $username):string{$id=$this->uuid();$this->pdo->prepare("INSERT INTO system_user(id,identity_type,username,display_name,account_status,approval_status,enabled) VALUES(?,'STAFF',?,?,'ACTIVE','APPROVED',1)")->execute([$id,$username,$username]);return $id;}
    private function authenticate(string $userId):void{$_SESSION=['user_id'=>$userId,'authenticated_at'=>time(),'last_activity_at'=>time()];Auth::forgetRequestCache();}
    private function value(string $sql,array $params=[]):mixed{$s=$this->pdo->prepare($sql);$s->execute($params);return $s->fetchColumn();}
    private function same(mixed $expected,mixed $actual,string $message):void{$this->assertions++;if($expected!==$actual)throw new RuntimeException($message.': expected '.var_export($expected,true).', got '.var_export($actual,true));}
    private function throws(callable $callback,string $message):void{$this->assertions++;try{$callback();}catch(DomainException){return;}throw new RuntimeException($message.': expected DomainException');}
    private function uuid():string{$hex=bin2hex(random_bytes(16));return substr($hex,0,8).'-'.substr($hex,8,4).'-4'.substr($hex,13,3).'-'.dechex((hexdec($hex[16])&3)|8).substr($hex,17,3).'-'.substr($hex,20);}
}

exit((new ArpaAppointmentHistoricalAuthorizationTest())->run());
