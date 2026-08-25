<?php
declare(strict_types=1);

use App\Core\{Auth,DataTableQuery,DataTableRegistry,DataTableRequest,Database,ScopeService};
use App\Services\{OperationalUserActivationService,UserAccessManagementService,UserAccountRequestService,UserContextService};

require dirname(__DIR__).'/bootstrap.php';

final class UserAccessManagementTest
{
    private PDO $pdo;
    private int $assertions=0;
    private int $nicSequence=1;

    public function run():int
    {
        $this->pdo=Database::pdo();$before=$this->state();$this->pdo->beginTransaction();
        try{$this->exercise();}finally{$_SESSION=[];Auth::forgetRequestCache();$this->pdo->rollBack();}
        $this->same($before,$this->state(),'all focused access fixtures roll back');
        echo "UserAccessManagementTest: {$this->assertions} assertions passed.\n";return 0;
    }

    private function exercise():void
    {
        [$districtX,$ascX,$districtY,$ascY]=$this->districtFixtures();
        $arpaX=$this->arpaUnder($ascX);$arpaY=$this->arpaUnder($ascY);
        $system=$this->systemAdmin();$checker=$this->createActor('SYSTEM_ADMIN',null,'system-checker');
        $actors=[
            'ASC_SUBJECT_OFFICER'=>$this->createActor('ASC_SUBJECT_OFFICER',$ascX,'asc-subject-manager'),
            'ASC_ADMIN'=>$this->createActor('ASC_ADMIN',$ascX,'asc-manager'),
            'DISTRICT_SUBJECT_OFFICER'=>$this->createActor('DISTRICT_SUBJECT_OFFICER',$districtX,'district-subject-manager'),
            'DISTRICT_ADMIN'=>$this->createActor('DISTRICT_ADMIN',$districtX,'district-manager'),
            'NATIONAL_SUBJECT_OFFICER'=>$this->createActor('NATIONAL_SUBJECT_OFFICER',null,'national-subject-manager'),
            'NATIONAL_ADMIN'=>$this->createActor('NATIONAL_ADMIN',null,'national-manager'),
        ];
        $policy=new UserAccessManagementService($this->pdo);

        $this->same('SYSTEM',$policy->authority($system)['kind'],'SYSTEM_ADMIN remains unrestricted');
        $this->same('ASC_SUBJECT',$policy->authority($actors['ASC_SUBJECT_OFFICER'])['kind'],'ASC Subject Officer receives ASC management authority');
        $this->same([$ascX],$policy->authority($actors['ASC_SUBJECT_OFFICER'])['asc_ids'],'ASC authority uses only its linked working location');
        $this->same('ASC',$policy->authority($actors['ASC_ADMIN'])['kind'],'ASC Administrator receives ASC management authority');
        $this->same('DISTRICT_SUBJECT',$policy->authority($actors['DISTRICT_SUBJECT_OFFICER'])['kind'],'District Subject Officer receives District authority');
        $this->same('DISTRICT',$policy->authority($actors['DISTRICT_ADMIN'])['kind'],'District Administrator receives District authority');
        $this->same('NATIONAL_SUBJECT',$policy->authority($actors['NATIONAL_SUBJECT_OFFICER'])['kind'],'National Subject Officer receives National authority');
        $this->same('NATIONAL',$policy->authority($actors['NATIONAL_ADMIN'])['kind'],'National Administrator receives National authority');

        $requiredPermissions=['user.view','user.request','user.activate','user.block','user.reset-password','user.assign-role','user.revoke-role','user.assign-scope','user.revoke-scope'];
        $permissionPlaceholders=implode(',',array_fill(0,count($requiredPermissions),'?'));
        $this->same(count($requiredPermissions)*6,$this->count("SELECT COUNT(*) FROM application_role r JOIN application_role_permission rp ON rp.role_id=r.id JOIN application_permission p ON p.id=rp.permission_id WHERE r.role_code IN ('ASC_SUBJECT_OFFICER','ASC_ADMIN','DISTRICT_SUBJECT_OFFICER','DISTRICT_ADMIN','NATIONAL_SUBJECT_OFFICER','NATIONAL_ADMIN') AND p.permission_key IN ({$permissionPlaceholders})",$requiredPermissions),'all operational management roles have only the required User Management permission set');

        $matrix=[
            'ASC_SUBJECT_OFFICER'=>['FARMER','ARPA_OFFICER'],
            'ASC_ADMIN'=>['FARMER','ARPA_OFFICER','ASC_SUBJECT_OFFICER'],
            'DISTRICT_SUBJECT_OFFICER'=>['FARMER','ARPA_OFFICER','ASC_SUBJECT_OFFICER','ASC_ADMIN'],
            'DISTRICT_ADMIN'=>['FARMER','ARPA_OFFICER','ASC_SUBJECT_OFFICER','ASC_ADMIN','DISTRICT_SUBJECT_OFFICER'],
            'NATIONAL_SUBJECT_OFFICER'=>['FARMER','ARPA_OFFICER','ASC_SUBJECT_OFFICER','ASC_ADMIN','DISTRICT_SUBJECT_OFFICER','DISTRICT_ADMIN'],
            'NATIONAL_ADMIN'=>['FARMER','ARPA_OFFICER','ASC_SUBJECT_OFFICER','ASC_ADMIN','DISTRICT_SUBJECT_OFFICER','DISTRICT_ADMIN','NATIONAL_SUBJECT_OFFICER'],
        ];
        $locationFor=function(string $roleCode)use($districtX,$ascX,$arpaX):?string{
            return match($roleCode){
                'FARMER'=>$ascX,
                'ARPA_OFFICER'=>$arpaX,
                'ASC_SUBJECT_OFFICER','ASC_ADMIN'=>$ascX,
                'DISTRICT_SUBJECT_OFFICER','DISTRICT_ADMIN'=>$districtX,
                default=>null,
            };
        };
        foreach($matrix as $actorRole=>$allowed){
            $visible=array_column($policy->manageableRoles($actors[$actorRole]),'role_code');sort($visible);$expected=$allowed;sort($expected);
            $this->same($expected,$visible,"{$actorRole} sees exactly the lower operational roles");
            foreach($allowed as $targetRole){
                $policy->validateAssignment($actors[$actorRole],$this->roleId($targetRole),$locationFor($targetRole),date('Y-m-d'));
                $this->assertions++;
                $policy->validateAccountRequestAssignment($actors[$actorRole],$this->roleId($targetRole),$locationFor($targetRole),date('Y-m-d'));
                $this->assertions++;
            }
            $this->throws(fn()=>$policy->validateAssignment($actors[$actorRole],$this->roleId($actorRole),$locationFor($actorRole),date('Y-m-d')),"{$actorRole} cannot manage its own level");
            $this->throws(fn()=>$policy->validateAccountRequestAssignment($actors[$actorRole],$this->roleId($actorRole),$locationFor($actorRole),date('Y-m-d')),"{$actorRole} cannot create its own level");
            $this->throws(fn()=>$policy->validateAssignment($actors[$actorRole],$this->roleId('SYSTEM_ADMIN'),null,date('Y-m-d')),"{$actorRole} cannot manage SYSTEM_ADMIN");
            $this->throws(fn()=>$policy->validateAccountRequestAssignment($actors[$actorRole],$this->roleId('SYSTEM_ADMIN'),null,date('Y-m-d')),"{$actorRole} cannot create SYSTEM_ADMIN");
        }

        $this->throwsMessage(
            fn()=>$policy->validateAssignment($actors['ASC_SUBJECT_OFFICER'],$this->roleId('ASC_SUBJECT_OFFICER'),$ascX,date('Y-m-d')),
            'You can only manage users below your user level.',
            'same-level denial uses the approved user message'
        );
        $this->throws(fn()=>$policy->validateAssignment($actors['ASC_SUBJECT_OFFICER'],$this->roleId('ASC_ADMIN'),$ascX,date('Y-m-d')),'ASC Subject Officer cannot assign ASC Admin or higher');
        $this->throws(fn()=>$policy->validateAssignment($actors['ASC_ADMIN'],$this->roleId('ASC_ADMIN'),$ascX,date('Y-m-d')),'ASC Admin cannot assign ASC Admin');
        $this->throws(fn()=>$policy->validateAssignment($actors['DISTRICT_SUBJECT_OFFICER'],$this->roleId('DISTRICT_SUBJECT_OFFICER'),$districtX,date('Y-m-d')),'District Subject Officer cannot assign its own level');
        $this->throws(fn()=>$policy->validateAssignment($actors['DISTRICT_ADMIN'],$this->roleId('DISTRICT_ADMIN'),$districtX,date('Y-m-d')),'District Admin cannot assign its own level');
        $this->throws(fn()=>$policy->validateAssignment($actors['NATIONAL_SUBJECT_OFFICER'],$this->roleId('NATIONAL_SUBJECT_OFFICER'),null,date('Y-m-d')),'National Subject Officer cannot assign its own level');
        $this->throws(fn()=>$policy->validateAssignment($actors['NATIONAL_ADMIN'],$this->roleId('NATIONAL_ADMIN'),null,date('Y-m-d')),'National Admin cannot assign its own level');

        $policy->validateAssignment($actors['ASC_SUBJECT_OFFICER'],$this->roleId('ARPA_OFFICER'),$arpaX,date('Y-m-d'));$this->assertions++;
        $this->throws(fn()=>$policy->validateAssignment($actors['ASC_SUBJECT_OFFICER'],$this->roleId('ARPA_OFFICER'),$arpaY,date('Y-m-d')),'forged ARPA location outside active ASC is rejected');
        $policy->validateAssignment($actors['ASC_ADMIN'],$this->roleId('ASC_SUBJECT_OFFICER'),$ascX,date('Y-m-d'));$this->assertions++;
        $this->throws(fn()=>$policy->validateAssignment($actors['ASC_ADMIN'],$this->roleId('ASC_SUBJECT_OFFICER'),$ascY,date('Y-m-d')),'ASC Admin cannot post another ASC');
        $policy->validateAssignment($actors['DISTRICT_SUBJECT_OFFICER'],$this->roleId('ASC_ADMIN'),$ascX,date('Y-m-d'));$this->assertions++;
        $this->throws(fn()=>$policy->validateAssignment($actors['DISTRICT_SUBJECT_OFFICER'],$this->roleId('ASC_ADMIN'),$ascY,date('Y-m-d')),'District Subject Officer cannot post another District ASC');
        $policy->validateAssignment($actors['DISTRICT_ADMIN'],$this->roleId('DISTRICT_SUBJECT_OFFICER'),$districtX,date('Y-m-d'));$this->assertions++;
        $this->throws(fn()=>$policy->validateAssignment($actors['DISTRICT_ADMIN'],$this->roleId('DISTRICT_SUBJECT_OFFICER'),$districtY,date('Y-m-d')),'District Admin cannot post another District');
        $policy->validateAssignment($actors['NATIONAL_SUBJECT_OFFICER'],$this->roleId('DISTRICT_ADMIN'),$districtY,date('Y-m-d'));$this->assertions++;
        $policy->validateAssignment($actors['NATIONAL_ADMIN'],$this->roleId('NATIONAL_SUBJECT_OFFICER'),null,date('Y-m-d'));$this->assertions++;

        $arpaTarget=$this->createUser('arpa.target.x');$arpaAssignment=$this->createAssignment($arpaTarget,'ARPA_OFFICER',$arpaX);
        $this->same(true,$policy->canManageUser($actors['ASC_SUBJECT_OFFICER'],$arpaTarget),'ASC Subject Officer can manage an ARPA user in its ASC');
        $farmerTarget=$this->createActor('FARMER',$ascX,'farmer.target.x');
        $this->same(true,$policy->canManageUser($actors['ASC_SUBJECT_OFFICER'],$farmerTarget),'ASC Subject Officer can manage a Farmer assigned to its ASC');
        $outsideFarmerTarget=$this->createActor('FARMER',$ascY,'farmer.target.y');
        $this->same(false,$policy->canManageUser($actors['ASC_SUBJECT_OFFICER'],$outsideFarmerTarget),'ASC Subject Officer cannot manage a Farmer assigned to another ASC');
        $outsideArpaTarget=$this->createActor('ARPA_OFFICER',$arpaY,'arpa.target.y');
        $this->same(false,$policy->canManageUser($actors['ASC_SUBJECT_OFFICER'],$outsideArpaTarget),'ASC Subject Officer cannot manage another ASC');
        $mixedTarget=$this->createUser('mixed.target');$mixedArpa=$this->createAssignment($mixedTarget,'ARPA_OFFICER',$arpaX);$mixedDistrict=$this->createAssignment($mixedTarget,'DISTRICT_ADMIN',$districtX);
        $this->same(false,$policy->canManageUser($actors['ASC_SUBJECT_OFFICER'],$mixedTarget),'whole-user action is denied when any active target role is outside actor authority');
        $policy->assertCanManageRoleAssignment($actors['ASC_SUBJECT_OFFICER'],$mixedArpa);$this->assertions++;
        $this->throws(fn()=>$policy->assertCanManageRoleAssignment($actors['ASC_SUBJECT_OFFICER'],$mixedDistrict),'role-specific action cannot affect higher target role');
        $this->throws(fn()=>$policy->createSubmittedAssignment($actors['ASC_SUBJECT_OFFICER'],$mixedTarget,$this->roleId('ARPA_OFFICER'),$arpaX,date('Y-m-d'),null,'Attempt through lower role','TEST/FORGED'),'lower actor cannot add a role to a multi-role user with higher authority');
        $policy->assertCanManageRoleAssignment($actors['ASC_SUBJECT_OFFICER'],$arpaAssignment);$this->assertions++;

        $this->throws(fn()=>$policy->searchAssignableLocations($actors['ASC_SUBJECT_OFFICER'],$this->roleId('ASC_SUBJECT_OFFICER'),'',100),'forged role_id is rejected by location lookup');
        $arpaResults=$policy->searchAssignableLocations($actors['ASC_SUBJECT_OFFICER'],$this->roleId('ARPA_OFFICER'),'',100);
        $this->same(true,in_array($arpaX,array_column($arpaResults,'id'),true),'ASC location lookup includes subordinate ARPA Division');
        $this->same(false,in_array($arpaY,array_column($arpaResults,'id'),true),'ASC location lookup excludes another ASC');

        $this->activeUserVisibilityCases($actors,$system,$districtX,$ascX,$arpaX,$districtY,$ascY,$arpaY);
        $this->accountRequestCases($actors,$system,$checker,$districtX,$ascX,$arpaX,$ascY,$arpaY);
        $this->effectiveDateAndInactiveCases($actors,$districtX,$ascX,$arpaX,$districtY,$ascY,$arpaY);

        $expired=$this->createActor('ASC_ADMIN',$ascX,'expired-manager',1,'APPROVED',date('Y-m-d',strtotime('-2 days')));
        $inactive=$this->createActor('ASC_ADMIN',$ascX,'inactive-manager',0,'APPROVED');
        $unapproved=$this->createActor('ASC_ADMIN',$ascX,'unapproved-manager',1,'SUBMITTED');
        $disabled=$this->createActor('ASC_ADMIN',$ascX,'disabled-manager');$this->pdo->prepare("UPDATE system_user SET enabled=0,account_status='DISABLED' WHERE id=?")->execute([$disabled]);
        $this->throws(fn()=>$policy->authority($expired),'expired role grants no authority');
        $this->throws(fn()=>$policy->authority($inactive),'inactive role grants no authority');
        $this->throws(fn()=>$policy->authority($unapproved),'unapproved role grants no authority');
        $this->throws(fn()=>$policy->authority($disabled),'disabled user account grants no management authority');

        $systemRole=$this->roleId('SYSTEM_ADMIN');
        $policy->validateAssignment($system,$systemRole,null,date('Y-m-d'));$this->assertions++;
        $this->same(true,in_array('SYSTEM_ADMIN',array_column($policy->manageableRoles($system),'role_code'),true),'existing SYSTEM_ADMIN behavior remains unchanged');

        $replacementTarget=$this->createUser('replacement.target');
        $oldAssignment=$this->createAssignment($replacementTarget,'ARPA_OFFICER',$arpaX);
        $future=date('Y-m-d',strtotime('+10 days'));
        $replacement=$policy->createSubmittedAssignment($system,$replacementTarget,$this->roleId('ARPA_OFFICER'),$arpaY,$future,null,'Transfer ARPA location','TEST/TRANSFER',$oldAssignment);
        $this->same('SUBMITTED',(string)$this->value('SELECT approval_status FROM user_account_role WHERE id=?',[$replacement]),'replacement is submitted directly');
        $this->throws(fn()=>$policy->approveAssignment($system,$replacement),'maker cannot approve own role change');
        $policy->approveAssignment($checker,$replacement);
        $this->same('APPROVED',(string)$this->value('SELECT approval_status FROM user_account_role WHERE id=?',[$replacement]),'different authorized checker approves role change');
        $this->same(date('Y-m-d',strtotime($future.' -1 day')),(string)$this->value('SELECT effective_to FROM user_account_role WHERE id=?',[$oldAssignment]),'role change preserves closed assignment history');

        $this->activationCases($policy,$actors['DISTRICT_ADMIN'],$actors['NATIONAL_ADMIN'],$districtX,$districtY);

        $migration=(string)file_get_contents(dirname(__DIR__).'/database/migrations/048_asc_user_management_access.sql');
        foreach(['ASC_SUBJECT_OFFICER','ASC_ADMIN','user.view','user.assign-role','user.assign-scope'] as $needle)$this->same(true,str_contains($migration,$needle),"migration grants {$needle}");
        $requestMigration=(string)file_get_contents(dirname(__DIR__).'/database/migrations/049_operational_user_account_requests.sql');
        foreach(['ASC_SUBJECT_OFFICER','ASC_ADMIN','DISTRICT_SUBJECT_OFFICER','DISTRICT_ADMIN','NATIONAL_SUBJECT_OFFICER','NATIONAL_ADMIN','user.request'] as $needle)$this->same(true,str_contains($requestMigration,$needle),"account request migration includes {$needle}");
        $activationView=(string)file_get_contents(dirname(__DIR__).'/app/Views/users/activate_operational.php');
        $this->same(true,str_contains($activationView,'assignment-locations'),'activation location choices use the bounded server-side lookup');
        $this->same(true,str_contains($activationView,"['FARMER','ARPA','ASC','DISTRICT','NATIONAL']"),'activation UI includes every operational hierarchy level');
    }

    private function accountRequestCases(array $actors,string $system,string $checker,string $districtX,string $ascX,string $arpaX,string $ascY,string $arpaY):void
    {
        $service=new UserAccountRequestService($this->pdo);$password='Manual-User-1!';$today=date('Y-m-d');
        $activeStatus=$this->statusId('ACTIVE');
        $this->useContext($actors['ASC_SUBJECT_OFFICER'],'ASC_SUBJECT_OFFICER');
        $arpaDesignation=$this->designationId('ARPA_OFFICER');
        $arpaData=$this->manualAccountData('manual.arpa.user','Manual ARPA User','ARPA_OFFICER',$arpaX,$today,$password,$activeStatus,$arpaDesignation);
        $manual=$service->request($actors['ASC_SUBJECT_OFFICER'],$arpaData);
        $userId=$manual['user_id'];$officerId=(string)$manual['officer_id'];$assignmentId=$manual['role_assignment_id'];$nic=$arpaData['nic'];

        $user=$this->row('SELECT officer_id,identity_type,display_name,identity_source,account_status,approval_status,enabled FROM system_user WHERE id=?',[$userId]);
        $officer=$this->row('SELECT dad_number,nic,nic_normalized,name_with_initials,primary_designation_id,class_id,arpa_service_permanency,officer_status_id,primary_office_id,effective_from,operational_status,approval_status,title_id,gender,date_of_birth,permanent_address,primary_mobile,alternative_mobile,employee_number FROM officer WHERE id=?',[$officerId]);
        $this->same($officerId,$user['officer_id'],'manual staff account is linked to its newly created Officer');
        $this->same('STAFF',$user['identity_type'],'ARPA account uses the existing STAFF identity model');
        $this->same('Manual ARPA User',$user['display_name'],'manual account uses the canonical Officer name');
        $this->same($nic,$officer['nic'],'NIC is stored on the canonical Officer record');
        $this->same($nic,$officer['nic_normalized'],'Officer NIC normalization is preserved');
        $this->same($arpaDesignation,$officer['primary_designation_id'],'Officer Designation uses the selected canonical designation');
        $this->same($this->roleId('ARPA_OFFICER'),(string)$this->value('SELECT role_id FROM user_account_role WHERE id=?',[$assignmentId]),'Application Role is stored independently from Officer Designation');
        $this->same(0,$this->count("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='system_user' AND COLUMN_NAME='nic'"),'NIC is not duplicated on system_user');
        $this->same(true,preg_match('/^70045-\d{7}$/',(string)$officer['dad_number'])===1,'DAD Officer Number is allocated canonically');
        $this->same(null,$officer['class_id'],'Officer class is not fabricated');
        $this->same(null,$officer['arpa_service_permanency'],'service permanency is not fabricated');
        foreach(['title_id','gender','date_of_birth','permanent_address','primary_mobile','alternative_mobile','employee_number'] as $field){
            $this->same(null,$officer[$field],"minimum Officer does not fabricate {$field}");
        }
        $this->same($activeStatus,$officer['officer_status_id'],'selected Officer Status is preserved');
        $this->same('SUBMITTED',$officer['approval_status'],'new Officer follows the submitted approval workflow');
        $this->same('INACTIVE',$officer['operational_status'],'new Officer is not operational before approval');
        $this->same(null,$officer['primary_office_id'],'submitted Officer does not gain an approved primary Office early');
        $officeAssignment=$this->row("SELECT a.id,a.approval_status,a.effective_from,o.linked_location_id,ot.system_key office_type FROM officer_office_assignment a JOIN office o ON o.id=a.office_id JOIN office_type ot ON ot.id=o.office_type_id WHERE a.id=?",[$manual['office_assignment_id']]);
        $this->same('ASC_OFFICE',$officeAssignment['office_type'],'ARPA Officer is assigned through the canonical ASC Office model');
        $this->same($ascX,$officeAssignment['linked_location_id'],'ARPA Division resolves to its approved parent ASC Office');
        $this->same('SUBMITTED',$officeAssignment['approval_status'],'initial Office assignment is submitted with the account');
        $this->same($today,$officeAssignment['effective_from'],'Officer Office assignment uses the selected Effective From date');
        $this->same(UserAccountRequestService::SOURCE_MANUAL,$user['identity_source'],'manual account source is preserved');
        $this->same('SUBMITTED',$user['approval_status'],'manual account follows the submitted account workflow');
        $this->same(0,(int)$user['enabled'],'submitted account cannot sign in before approval');
        $this->same('SUBMITTED',(string)$this->value('SELECT approval_status FROM user_account_role WHERE id=?',[$assignmentId]),'initial role is submitted with the account');
        $scope=$this->row('SELECT approval_status,location_id,effective_from FROM user_account_scope WHERE role_assignment_id=?',[$assignmentId]);
        $this->same('SUBMITTED',$scope['approval_status'],'initial location is submitted with the role');
        $this->same($arpaX,$scope['location_id'],'initial location remains linked to the exact role assignment');
        $this->same($today,(string)$this->value('SELECT effective_from FROM user_account_role WHERE id=?',[$assignmentId]),'role uses the selected Effective From date');
        $this->same($today,$scope['effective_from'],'scope uses the same Effective From date');
        $audit=(string)$this->value("SELECT GROUP_CONCAT(details_json) FROM audit_event WHERE target_id IN (?,?)",[$userId,$officerId]);
        $this->same(false,str_contains($audit,$password),'temporary password is absent from account and Officer audit details');

        $beforeFailure=['officers'=>$this->count('SELECT COUNT(*) FROM officer'),'users'=>$this->count('SELECT COUNT(*) FROM system_user'),'roles'=>$this->count('SELECT COUNT(*) FROM user_account_role'),'scopes'=>$this->count('SELECT COUNT(*) FROM user_account_scope')];
        $this->throws(fn()=>$service->request($actors['ASC_SUBJECT_OFFICER'],array_merge($arpaData,['nic'=>$this->unusedNic()])),'username conflict is rejected before creating another Officer');
        $this->same($beforeFailure,['officers'=>$this->count('SELECT COUNT(*) FROM officer'),'users'=>$this->count('SELECT COUNT(*) FROM system_user'),'roles'=>$this->count('SELECT COUNT(*) FROM user_account_role'),'scopes'=>$this->count('SELECT COUNT(*) FROM user_account_scope')],'username conflict rolls back the complete combined identity');
        $this->throwsMessage(fn()=>$service->request($actors['ASC_SUBJECT_OFFICER'],array_merge($arpaData,['username'=>'duplicate.nic.user'])),'An Officer record already exists for this NIC. Use Existing Approved Officer.','duplicate Officer NIC directs the user to Existing Approved Officer mode');
        $this->same($beforeFailure,['officers'=>$this->count('SELECT COUNT(*) FROM officer'),'users'=>$this->count('SELECT COUNT(*) FROM system_user'),'roles'=>$this->count('SELECT COUNT(*) FROM user_account_role'),'scopes'=>$this->count('SELECT COUNT(*) FROM user_account_scope')],'failed Officer/user creation leaves no partial identity or access rows');
        $this->throws(fn()=>$service->request($actors['ASC_SUBJECT_OFFICER'],array_merge($arpaData,['username'=>'missing.full.name','full_name'=>'','nic'=>$this->unusedNic()])),'Name with Initials is required in manual staff mode');
        $this->throws(fn()=>$service->request($actors['ASC_SUBJECT_OFFICER'],array_merge($arpaData,['username'=>'missing.designation','primary_designation_id'=>'','nic'=>$this->unusedNic()])),'Designation is required in manual staff mode');
        $this->throws(fn()=>$service->request($actors['ASC_SUBJECT_OFFICER'],array_merge($arpaData,['username'=>'same.level.user','role_id'=>$this->roleId('ASC_SUBJECT_OFFICER'),'location_id'=>$ascX,'nic'=>$this->unusedNic()])),'same-level manual account role is rejected');
        $this->throws(fn()=>$service->request($actors['ASC_SUBJECT_OFFICER'],array_merge($arpaData,['username'=>'outside.location.user','location_id'=>$arpaY,'nic'=>$this->unusedNic()])),'forged out-of-scope ARPA location is rejected');
        $this->throws(fn()=>$service->request($actors['ASC_SUBJECT_OFFICER'],array_merge($arpaData,['username'=>'before.baseline.user','effective_from'=>'2024-12-31','nic'=>$this->unusedNic()])),'manual account date before the baseline is rejected');

        $officerCount=$this->count('SELECT COUNT(*) FROM officer');
        $farmer=$service->request($actors['ASC_SUBJECT_OFFICER'],[
            'account_source'=>UserAccountRequestService::SOURCE_MANUAL,'full_name'=>'Manual Farmer','username'=>'manual.farmer.user',
            'role_id'=>$this->roleId('FARMER'),'location_id'=>$ascX,'effective_from'=>'','temporary_password'=>$password,'mfa_method'=>'AUTHENTICATOR_APP',
        ]);
        $this->same($officerCount,$this->count('SELECT COUNT(*) FROM officer'),'Farmer creation does not create an Officer');
        $this->same(null,$farmer['officer_id'],'Farmer account has no Officer link');
        $this->same('FARMER',(string)$this->value('SELECT identity_type FROM system_user WHERE id=?',[$farmer['user_id']]),'Farmer uses the existing FARMER identity classification');
        $this->same(UserAccessManagementService::OPERATIONAL_ACCESS_BASELINE_DATE,(string)$this->value('SELECT effective_from FROM user_account_role WHERE id=?',[$farmer['role_assignment_id']]),'manual account Effective From defaults to the approved 2025 baseline');
        $this->same(UserAccessManagementService::OPERATIONAL_ACCESS_BASELINE_DATE,(string)$this->value('SELECT effective_from FROM user_account_scope WHERE role_assignment_id=?',[$farmer['role_assignment_id']]),'default baseline is applied consistently to the linked scope');

        $this->useContext($actors['ASC_ADMIN'],'ASC_ADMIN');
        $ascRequest=$service->request($actors['ASC_ADMIN'],$this->manualAccountData('manual.asc.user','Manual ASC User','ASC_SUBJECT_OFFICER',$ascX,$today,$password,$activeStatus));
        $this->assertInitialOffice($ascRequest,'ASC_OFFICE',$ascX,'ASC staff registration uses the selected ASC Office');
        $this->same($this->designationId('AGRARIAN_DEVELOPMENT_OFFICER'),(string)$this->value('SELECT primary_designation_id FROM officer WHERE id=?',[$ascRequest['officer_id']]),'ASC Application Role remains independent from the selected Officer Designation');
        $this->useContext($actors['NATIONAL_SUBJECT_OFFICER'],'NATIONAL_SUBJECT_OFFICER');
        $districtRequest=$service->request($actors['NATIONAL_SUBJECT_OFFICER'],$this->manualAccountData('manual.district.user','Manual District User','DISTRICT_ADMIN',$districtX,$today,$password,$activeStatus));
        $this->assertInitialOffice($districtRequest,'DISTRICT_OFFICE',$districtX,'District staff registration uses the selected District Office');
        $this->useContext($actors['NATIONAL_ADMIN'],'NATIONAL_ADMIN');
        $nationalRequest=$service->request($actors['NATIONAL_ADMIN'],$this->manualAccountData('manual.national.user','Manual National User','NATIONAL_SUBJECT_OFFICER',null,$today,$password,$activeStatus));
        $this->assertInitialOffice($nationalRequest,'HEAD_OFFICE',null,'National staff registration uses Head Office');

        $this->pdo->prepare("UPDATE officer_office_assignment SET approval_status='RETURNED' WHERE id=?")->execute([$ascRequest['office_assignment_id']]);
        $this->useContext($checker,'SYSTEM_ADMIN');
        $this->throws(fn()=>$service->approve($checker,$ascRequest['user_id']),'missing initial Office assignment aborts the combined approval');
        $this->same('SUBMITTED',(string)$this->value('SELECT approval_status FROM system_user WHERE id=?',[$ascRequest['user_id']]),'failed approval restores the submitted user request');
        $this->same('SUBMITTED',(string)$this->value('SELECT approval_status FROM officer WHERE id=?',[$ascRequest['officer_id']]),'failed approval restores the submitted Officer');
        $this->same('SUBMITTED',(string)$this->value('SELECT approval_status FROM user_account_role WHERE id=?',[$ascRequest['role_assignment_id']]),'failed approval restores the submitted role');
        $this->same('SUBMITTED',(string)$this->value('SELECT approval_status FROM user_account_scope WHERE role_assignment_id=?',[$ascRequest['role_assignment_id']]),'failed approval restores the submitted location');

        $this->createAssignment($actors['ASC_SUBJECT_OFFICER'],'NATIONAL_ADMIN',null);
        $this->useContext($actors['ASC_SUBJECT_OFFICER'],'ASC_SUBJECT_OFFICER');
        $this->throws(fn()=>$service->request($actors['ASC_SUBJECT_OFFICER'],$this->manualAccountData('borrowed.context.user','Borrowed Context','DISTRICT_ADMIN',null,$today,$password,$activeStatus)),'active context prevents National privilege borrowing during account creation');

        $outsideActor=$this->createActor('ASC_SUBJECT_OFFICER',$ascY,'account-request-outside-actor');
        $this->useContext($outsideActor,'ASC_SUBJECT_OFFICER');
        $outsideResult=(new DataTableQuery($this->pdo,DataTableRegistry::definition('account-requests'),new DataTableRequest(['search'=>['value'=>$nic]])))->response();
        $this->same(0,$outsideResult['recordsFiltered'],'NIC cannot expose a pending request to another ASC');

        $this->useContext($system,'SYSTEM_ADMIN');
        $existing=$this->row("SELECT o.id,o.name_with_initials FROM officer o LEFT JOIN system_user su ON su.officer_id=o.id WHERE o.approval_status='APPROVED' AND su.id IS NULL ORDER BY o.id LIMIT 1");
        if(!$existing)throw new RuntimeException('An approved Officer without a user account is required.');
        $officerCount=$this->count('SELECT COUNT(*) FROM officer');
        $officerRequest=$service->request($system,[
            'account_source'=>UserAccountRequestService::SOURCE_OFFICER,'officer_id'=>$existing['id'],'username'=>'approved.officer.user',
            'role_id'=>$this->roleId('ARPA_OFFICER'),'location_id'=>$arpaX,'effective_from'=>$today,'temporary_password'=>$password,'mfa_method'=>'AUTHENTICATOR_APP',
        ]);
        $this->same($officerCount,$this->count('SELECT COUNT(*) FROM officer'),'Existing Approved Officer mode does not create another Officer');
        $this->same($existing['id'],(string)$this->value('SELECT officer_id FROM system_user WHERE id=?',[$officerRequest['user_id']]),'existing Approved Officer workflow preserves Officer linkage');
        $this->same(true,$officerRequest['role_assignment_id']!=='','existing Approved Officer workflow creates its selected role and scope');
        $this->same(null,$officerRequest['office_assignment_id'],'existing Approved Officer workflow does not alter Office assignments');
        $this->throws(fn()=>$service->request($system,[
            'account_source'=>UserAccountRequestService::SOURCE_OFFICER,'officer_id'=>$existing['id'],'username'=>'duplicate.officer.user',
            'role_id'=>$this->roleId('ARPA_OFFICER'),'location_id'=>$arpaX,'effective_from'=>$today,'temporary_password'=>$password,'mfa_method'=>'AUTHENTICATOR_APP',
        ]),'an Officer cannot receive a duplicate user identity');

        $this->useContext($checker,'SYSTEM_ADMIN');$service->approve($checker,$userId);
        $this->same('ACTIVE',(string)$this->value('SELECT account_status FROM system_user WHERE id=?',[$userId]),'different checker activates the approved manual account');
        $this->same('APPROVED',(string)$this->value('SELECT approval_status FROM officer WHERE id=?',[$officerId]),'combined approval approves the created Officer');
        $this->same('APPROVED',(string)$this->value('SELECT approval_status FROM officer_office_assignment WHERE id=?',[$manual['office_assignment_id']]),'combined approval approves the initial Office assignment');
        $this->same($this->value('SELECT office_id FROM officer_office_assignment WHERE id=?',[$manual['office_assignment_id']]),$this->value('SELECT primary_office_id FROM officer WHERE id=?',[$officerId]),'approved initial Office becomes the Officer primary Office');
        $this->same('APPROVED',(string)$this->value('SELECT approval_status FROM user_account_role WHERE id=?',[$assignmentId]),'account approval approves the initial role transactionally');
        $this->same('APPROVED',(string)$this->value('SELECT approval_status FROM user_account_scope WHERE role_assignment_id=?',[$assignmentId]),'account approval approves the linked initial scope');

        $this->createAssignment($userId,'ASC_SUBJECT_OFFICER',$ascX);
        $this->useContext($actors['ASC_ADMIN'],'ASC_ADMIN');
        $active=(new DataTableQuery($this->pdo,DataTableRegistry::definition('users'),new DataTableRequest(['search'=>['value'=>$nic]])))->response();
        $this->same(1,$active['recordsFiltered'],'Active Users finds the authorized staff identity by NIC');
        $this->same($nic,strip_tags((string)$active['data'][0]['nic']),'Active Users displays the canonical Officer NIC once for a multi-role user');
        $this->same('Agriculture Research and Production Assistant',strip_tags((string)$active['data'][0]['designation_name']),'Active Users displays the canonical Officer Designation');
        $this->same(true,(new UserAccessManagementService($this->pdo))->canManageUser($actors['ASC_ADMIN'],$userId),'linked Officer account remains manageable through role-specific scope');
        $this->useContext($outsideActor,'ASC_SUBJECT_OFFICER');
        $outsideActive=(new DataTableQuery($this->pdo,DataTableRegistry::definition('users'),new DataTableRequest(['search'=>['value'=>$nic]])))->response();
        $this->same(0,$outsideActive['recordsFiltered'],'NIC search does not expose an active user outside the actor scope');

        $this->useContext($actors['ASC_ADMIN'],'ASC_ADMIN');
        (new OperationalUserActivationService($this->pdo))->deactivate($userId,'Manual account deactivation test',null,$actors['ASC_ADMIN']);
        $activeAfter=(new DataTableQuery($this->pdo,DataTableRegistry::definition('users'),new DataTableRequest(['search'=>['value'=>$nic]])))->response();
        $inactiveAfter=(new DataTableQuery($this->pdo,DataTableRegistry::definition('historical-users'),new DataTableRequest(['search'=>['value'=>$nic]])))->response();
        $this->same(0,$activeAfter['recordsFiltered'],'deactivated linked account leaves Active Users');
        $this->same(1,$inactiveAfter['recordsFiltered'],'Inactive Users finds the authorized linked identity by NIC');
        $this->same($nic,strip_tags((string)$inactiveAfter['data'][0]['nic']),'Inactive Users displays the canonical Officer NIC');
        $this->same('Agriculture Research and Production Assistant',strip_tags((string)$inactiveAfter['data'][0]['designation_name']),'Inactive Users displays the canonical Officer Designation');

        $view=(string)file_get_contents(dirname(__DIR__).'/app/Views/users/accounts.php');
        foreach(['Account Source','Existing Approved Officer','User Not Yet Registered as Officer','Name with Initials','NIC','Designation','Officer Status','Effective From','assignment-locations'] as $needle)$this->same(true,str_contains($view,$needle),"account form includes {$needle}");
        $this->same(true,str_contains($view,"hasRole=role.value!==''"),'manual account UI distinguishes an unselected role from Farmer');
        $this->same(true,str_contains($view,'staff=manual&&!farmer'),'manual account UI shows Officer fields before a role is selected');
        $this->same(false,str_contains($view,"staff=manual&&role.value!==''&&!farmer"),'manual account UI no longer waits for role selection before showing Officer fields');
        $editController=(string)file_get_contents(dirname(__DIR__).'/app/Controllers/OfficerController.php');
        foreach(['full_name_en','date_of_birth','permanent_address','primary_mobile','photograph_path','primary_designation_id'] as $field)$this->same(true,str_contains($editController,$field),"existing Officer Edit workflow can complete {$field}");
    }

    private function activeUserVisibilityCases(array $actors,string $system,string $districtX,string $ascX,string $arpaX,string $districtY,string $ascY,string $arpaY):void
    {
        $targets=[
            'FARMER'=>$this->createActor('FARMER',$ascX,'visibility.farmer.x'),
            'ARPA_OFFICER'=>$this->createActor('ARPA_OFFICER',$arpaX,'visibility.arpa.x'),
            'ASC_SUBJECT_OFFICER'=>$this->createActor('ASC_SUBJECT_OFFICER',$ascX,'visibility.asc.subject.x'),
            'ASC_ADMIN'=>$this->createActor('ASC_ADMIN',$ascX,'visibility.asc.admin.x'),
            'DISTRICT_SUBJECT_OFFICER'=>$this->createActor('DISTRICT_SUBJECT_OFFICER',$districtX,'visibility.district.subject.x'),
            'DISTRICT_ADMIN'=>$this->createActor('DISTRICT_ADMIN',$districtX,'visibility.district.admin.x'),
            'NATIONAL_SUBJECT_OFFICER'=>$this->createActor('NATIONAL_SUBJECT_OFFICER',null,'visibility.national.subject'),
            'NATIONAL_ADMIN'=>$this->createActor('NATIONAL_ADMIN',null,'visibility.national.admin'),
        ];
        $outside=[
            'FARMER'=>$this->createActor('FARMER',$ascY,'visibility.farmer.y'),
            'ARPA_OFFICER'=>$this->createActor('ARPA_OFFICER',$arpaY,'visibility.arpa.y'),
            'ASC_ADMIN'=>$this->createActor('ASC_ADMIN',$ascY,'visibility.asc.admin.y'),
            'DISTRICT_SUBJECT_OFFICER'=>$this->createActor('DISTRICT_SUBJECT_OFFICER',$districtY,'visibility.district.subject.y'),
        ];
        $mixed=$this->createUser('visibility.mixed');
        $this->createAssignment($mixed,'ARPA_OFFICER',$arpaX);
        $this->createAssignment($mixed,'DISTRICT_ADMIN',$districtX);
        $multiScope=$this->createUser('visibility.multi.scope');
        $multiScopeRole=$this->createAssignment($multiScope,'ARPA_OFFICER',$arpaX);
        $this->pdo->prepare("INSERT INTO user_account_scope(id,user_id,role_assignment_id,scope_type,scope_mode,location_id,effective_from,approval_status,active,reason) VALUES(UUID(),?,?, 'ARPA_DIVISION','EXACT',?,CURRENT_DATE(),'APPROVED',1,'Visibility boundary test')")
            ->execute([$multiScope,$multiScopeRole,$arpaY]);
        $inactive=$this->createActor('ARPA_OFFICER',$arpaX,'visibility.inactive');
        $this->pdo->prepare("UPDATE system_user SET enabled=0,account_status='DISABLED' WHERE id=?")->execute([$inactive]);
        $unapproved=$this->createActor('ARPA_OFFICER',$arpaX,'visibility.unapproved');
        $this->pdo->prepare("UPDATE system_user SET approval_status='SUBMITTED' WHERE id=?")->execute([$unapproved]);
        $expired=$this->createActor('ARPA_OFFICER',$arpaX,'visibility.expired',1,'APPROVED',date('Y-m-d',strtotime('-1 day')));

        $expected=[
            'ASC_SUBJECT_OFFICER'=>['FARMER','ARPA_OFFICER'],
            'ASC_ADMIN'=>['FARMER','ARPA_OFFICER','ASC_SUBJECT_OFFICER'],
            'DISTRICT_SUBJECT_OFFICER'=>['FARMER','ARPA_OFFICER','ASC_SUBJECT_OFFICER','ASC_ADMIN'],
            'DISTRICT_ADMIN'=>['FARMER','ARPA_OFFICER','ASC_SUBJECT_OFFICER','ASC_ADMIN','DISTRICT_SUBJECT_OFFICER'],
            'NATIONAL_SUBJECT_OFFICER'=>['FARMER','ARPA_OFFICER','ASC_SUBJECT_OFFICER','ASC_ADMIN','DISTRICT_SUBJECT_OFFICER','DISTRICT_ADMIN'],
            'NATIONAL_ADMIN'=>['FARMER','ARPA_OFFICER','ASC_SUBJECT_OFFICER','ASC_ADMIN','DISTRICT_SUBJECT_OFFICER','DISTRICT_ADMIN','NATIONAL_SUBJECT_OFFICER'],
        ];
        foreach($expected as $actorRole=>$visibleRoles){
            $this->useContext($actors[$actorRole],$actorRole);
            $visibleIds=array_column((new UserAccessManagementService($this->pdo))->manageableUsers($actors[$actorRole]),'id');
            foreach($targets as $targetRole=>$targetId){
                $this->same(in_array($targetRole,$visibleRoles,true),in_array($targetId,$visibleIds,true),"{$actorRole} Active Users follows lower-role hierarchy for {$targetRole}");
            }
            $this->same(false,in_array($system,$visibleIds,true),"{$actorRole} cannot see SYSTEM_ADMIN");
            $this->same(false,in_array($targets[$actorRole],$visibleIds,true),"{$actorRole} cannot see a same-level peer");
        }

        $this->useContext($actors['ASC_SUBJECT_OFFICER'],'ASC_SUBJECT_OFFICER');
        $ascIds=array_column((new UserAccessManagementService($this->pdo))->manageableUsers($actors['ASC_SUBJECT_OFFICER']),'id');
        $this->same(false,in_array($outside['FARMER'],$ascIds,true),'ASC Active Users excludes Farmer from another ASC');
        $this->same(false,in_array($outside['ARPA_OFFICER'],$ascIds,true),'ASC Active Users excludes ARPA from another ASC');
        $this->same(false,in_array($mixed,$ascIds,true),'ASC Active Users hides a lower-role target that also owns a higher role');
        $this->same(false,in_array($multiScope,$ascIds,true),'ASC Active Users hides a role assignment with another-ASC scope');
        foreach([$inactive,$unapproved,$expired] as $targetId){
            $this->same(false,in_array($targetId,$ascIds,true),'Active Users excludes inactive, unapproved, or expired identities');
        }

        $this->useContext($actors['DISTRICT_SUBJECT_OFFICER'],'DISTRICT_SUBJECT_OFFICER');
        $districtIds=array_column((new UserAccessManagementService($this->pdo))->manageableUsers($actors['DISTRICT_SUBJECT_OFFICER']),'id');
        $this->same(true,in_array($targets['ASC_ADMIN'],$districtIds,true),'District Subject Officer sees lower ASC Admin in own District');
        $this->same(false,in_array($outside['ASC_ADMIN'],$districtIds,true),'District Subject Officer cannot see another District');
        $this->same(false,in_array($outside['DISTRICT_SUBJECT_OFFICER'],$districtIds,true),'District actor cannot see same-level user in another District');

        for($i=0;$i<12;$i++){
            $this->createActor('FARMER',$ascX,sprintf('visibility.page.%02d',$i));
        }
        $this->createActor('FARMER',$ascY,'visibility.page.outside');
        $this->useContext($actors['ASC_SUBJECT_OFFICER'],'ASC_SUBJECT_OFFICER');
        $definition=DataTableRegistry::definition('users');
        $first=(new DataTableQuery($this->pdo,$definition,new DataTableRequest(['start'=>0,'length'=>10,'search'=>['value'=>'visibility.page.']])))->response();
        $second=(new DataTableQuery($this->pdo,$definition,new DataTableRequest(['start'=>10,'length'=>10,'search'=>['value'=>'visibility.page.']])))->response();
        $this->same(12,$first['recordsFiltered'],'server-side Active Users search excludes unauthorized matches');
        $this->same(10,count($first['data']),'first authorized DataTable page is bounded');
        $this->same(2,count($second['data']),'second authorized DataTable page has only remaining authorized rows');
        $outsideSearch=(new DataTableQuery($this->pdo,$definition,new DataTableRequest(['search'=>['value'=>'visibility.page.outside']])))->response();
        $this->same(0,$outsideSearch['recordsFiltered'],'direct DataTable source cannot reveal an out-of-scope user');
        $export=iterator_to_array((new DataTableQuery($this->pdo,$definition,new DataTableRequest(['search'=>['value'=>'visibility.page.']])))->exportRows(),false);
        $this->same(12,count($export),'CSV export uses the same hierarchy and scope filter');

        $this->createAssignment($actors['ASC_SUBJECT_OFFICER'],'NATIONAL_ADMIN',null);
        $this->useContext($actors['ASC_SUBJECT_OFFICER'],'ASC_SUBJECT_OFFICER');
        $ascDefinition=DataTableRegistry::definition('users');
        $ascResult=(new DataTableQuery($this->pdo,$ascDefinition,new DataTableRequest(['search'=>['value'=>'visibility.district.admin.x']])))->response();
        $this->same(0,$ascResult['recordsFiltered'],'ASC working context cannot borrow the actor National Admin role');
        $this->useContext($actors['ASC_SUBJECT_OFFICER'],'NATIONAL_ADMIN');
        $nationalDefinition=DataTableRegistry::definition('users');
        $nationalResult=(new DataTableQuery($this->pdo,$nationalDefinition,new DataTableRequest(['search'=>['value'=>'visibility.district.admin.x']])))->response();
        $this->same(1,$nationalResult['recordsFiltered'],'National visibility is available only after selecting National Admin context');
        $nationalIds=array_column((new UserAccessManagementService($this->pdo))->manageableUsers($actors['ASC_SUBJECT_OFFICER']),'id');
        $this->same(true,in_array($multiScope,$nationalIds,true),'National actor may see compatible multi-location lower-role user');
    }

    private function effectiveDateAndInactiveCases(array $actors,string $districtX,string $ascX,string $arpaX,string $districtY,string $ascY,string $arpaY):void
    {
        $policy=new UserAccessManagementService($this->pdo);
        $target=$this->createUser('effective.date.target');
        $arpaAssignment=$this->createAssignment($target,'ARPA_OFFICER',$arpaX);
        $farmerAssignment=$this->createAssignment($target,'FARMER',$ascX);
        $this->pdo->prepare('UPDATE user_account_role SET effective_from=? WHERE id=?')->execute(['2026-01-01',$arpaAssignment]);
        $this->pdo->prepare('UPDATE user_account_scope SET effective_from=? WHERE role_assignment_id=?')->execute(['2026-01-01',$arpaAssignment]);
        $this->pdo->prepare('UPDATE user_account_role SET effective_from=? WHERE id=?')->execute(['2026-03-15',$farmerAssignment]);
        $this->pdo->prepare('UPDATE user_account_scope SET effective_from=? WHERE role_assignment_id=?')->execute(['2026-03-15',$farmerAssignment]);
        $unrelatedScope=$this->uuid();
        $this->pdo->prepare("INSERT INTO user_account_scope(id,user_id,role_assignment_id,scope_type,scope_mode,location_id,effective_from,approval_status,active,reason) VALUES(?,?,?,'ARPA_DIVISION','EXACT',?,'2026-02-01','APPROVED',1,'Separate scope period')")
            ->execute([$unrelatedScope,$target,$arpaAssignment,$arpaX]);

        $this->useContext($actors['ASC_SUBJECT_OFFICER'],'ASC_SUBJECT_OFFICER');
        $definition=DataTableRegistry::definition('users');
        $before=(new DataTableQuery($this->pdo,$definition,new DataTableRequest(['search'=>['value'=>'effective.date.target']])))->response();
        $this->same(1,$before['recordsFiltered'],'Active Users includes the manageable multi-role target');
        $this->same(true,str_contains((string)$before['data'][0]['effective_roles'],'ARPA'), 'Active Users displays the specific role names');
        $this->same(true,str_contains((string)$before['data'][0]['effective_from_dates'],'01 Jan 2026'),'Active Users displays the ARPA assignment Effective From date');
        $this->same(true,str_contains((string)$before['data'][0]['effective_from_dates'],'15 Mar 2026'),'multiple roles retain their matching Effective From dates');
        $this->same(true,str_contains((string)$before['data'][0]['actions'],$arpaAssignment),'Effective From action targets a specific role assignment');

        $auditBefore=$this->count("SELECT COUNT(*) FROM audit_event WHERE action_key='user.role.effective-from.update' AND target_id=?",[$arpaAssignment]);
        $policy->updateRoleEffectiveFrom($actors['ASC_SUBJECT_OFFICER'],$arpaAssignment,'2025-01-01');
        $this->same('2025-01-01',(string)$this->value('SELECT effective_from FROM user_account_role WHERE id=?',[$arpaAssignment]),'higher-level actor edits a lower role start date');
        $this->same(1,$this->count('SELECT COUNT(*) FROM user_account_scope WHERE role_assignment_id=? AND effective_from=?',[$arpaAssignment,'2025-01-01']),'scope sharing the original start date is synchronized');
        $this->same('2026-02-01',(string)$this->value('SELECT effective_from FROM user_account_scope WHERE id=?',[$unrelatedScope]),'unrelated scope period is not modified');
        $this->same('2026-03-15',(string)$this->value('SELECT effective_from FROM user_account_role WHERE id=?',[$farmerAssignment]),'editing one role does not alter another role');
        $this->same($auditBefore+1,$this->count("SELECT COUNT(*) FROM audit_event WHERE action_key='user.role.effective-from.update' AND target_id=?",[$arpaAssignment]),'Effective From edit appends an audit event');
        $audit=json_decode((string)$this->value("SELECT details_json FROM audit_event WHERE action_key='user.role.effective-from.update' AND target_id=? ORDER BY id DESC LIMIT 1",[$arpaAssignment]),true,512,JSON_THROW_ON_ERROR);
        $this->same('2026-01-01',$audit['old_effective_from'],'audit preserves the old Effective From date');
        $this->same('2025-01-01',$audit['new_effective_from'],'audit records the new Effective From date');

        $this->throws(fn()=>$policy->updateRoleEffectiveFrom($actors['ASC_SUBJECT_OFFICER'],$arpaAssignment,'2024-12-31'),'date before the HR baseline is rejected');
        $endedTarget=$this->createUser('effective.ended.target');$endedAssignment=$this->createAssignment($endedTarget,'ARPA_OFFICER',$arpaX,1,'APPROVED','2026-09-01');
        $this->throws(fn()=>$policy->updateRoleEffectiveFrom($actors['ASC_SUBJECT_OFFICER'],$endedAssignment,'2026-09-02'),'start date after end date is rejected');
        $peer=$this->createActor('ASC_SUBJECT_OFFICER',$ascX,'effective.peer');
        $peerAssignment=(string)$this->value('SELECT id FROM user_account_role WHERE user_id=?',[$peer]);
        $this->throws(fn()=>$policy->updateRoleEffectiveFrom($actors['ASC_SUBJECT_OFFICER'],$peerAssignment,'2025-01-01'),'same-level actor cannot edit Effective From');
        $higher=$this->createActor('ASC_ADMIN',$ascX,'effective.higher');
        $higherAssignment=(string)$this->value('SELECT id FROM user_account_role WHERE user_id=?',[$higher]);
        $this->throws(fn()=>$policy->updateRoleEffectiveFrom($actors['ASC_SUBJECT_OFFICER'],$higherAssignment,'2025-01-01'),'lower actor cannot edit a higher role');
        $outside=$this->createActor('ARPA_OFFICER',$arpaY,'effective.outside');
        $outsideAssignment=(string)$this->value('SELECT id FROM user_account_role WHERE user_id=?',[$outside]);
        $this->throws(fn()=>$policy->updateRoleEffectiveFrom($actors['ASC_SUBJECT_OFFICER'],$outsideAssignment,'2025-01-01'),'forged role assignment outside active ASC is rejected');
        $this->createAssignment($actors['ASC_SUBJECT_OFFICER'],'NATIONAL_ADMIN',null);
        $districtTarget=$this->createActor('DISTRICT_ADMIN',$districtX,'effective.district.target');
        $districtAssignment=(string)$this->value('SELECT id FROM user_account_role WHERE user_id=?',[$districtTarget]);
        $this->useContext($actors['ASC_SUBJECT_OFFICER'],'ASC_SUBJECT_OFFICER');
        $this->throws(fn()=>$policy->updateRoleEffectiveFrom($actors['ASC_SUBJECT_OFFICER'],$districtAssignment,'2025-01-01'),'Active Working Context prevents Effective From privilege borrowing');

        $inactiveOwn=$this->createActor('ARPA_OFFICER',$arpaX,'inactive.visibility.own');
        $inactiveOutside=$this->createActor('ARPA_OFFICER',$arpaY,'inactive.visibility.outside');
        $inactivePeer=$this->createActor('ASC_SUBJECT_OFFICER',$ascX,'inactive.visibility.peer');
        foreach([$inactiveOwn,$inactiveOutside,$inactivePeer] as $inactiveUser)$this->deactivateFixture($inactiveUser);
        $this->useContext($actors['ASC_SUBJECT_OFFICER'],'ASC_SUBJECT_OFFICER');
        $activeDefinition=DataTableRegistry::definition('users');
        $activeResult=(new DataTableQuery($this->pdo,$activeDefinition,new DataTableRequest(['search'=>['value'=>'inactive.visibility.own']])))->response();
        $this->same(0,$activeResult['recordsFiltered'],'deactivated account disappears from Active Users');
        $inactiveDefinition=DataTableRegistry::definition('historical-users');
        $inactiveResult=(new DataTableQuery($this->pdo,$inactiveDefinition,new DataTableRequest(['search'=>['value'=>'inactive.visibility.own']])))->response();
        $this->same(1,$inactiveResult['recordsFiltered'],'deactivated account appears in Inactive Users');
        $this->same(true,str_contains((string)$inactiveResult['data'][0]['last_roles'],'ARPA'),'Inactive Users displays the last role');
        $this->same(true,str_contains((string)$inactiveResult['data'][0]['effective_from_dates'],date('d M Y')),'Inactive Users displays historical role Effective From');
        $outsideResult=(new DataTableQuery($this->pdo,$inactiveDefinition,new DataTableRequest(['search'=>['value'=>'inactive.visibility.outside']])))->response();
        $this->same(0,$outsideResult['recordsFiltered'],'ASC inactive query excludes another ASC');
        $peerResult=(new DataTableQuery($this->pdo,$inactiveDefinition,new DataTableRequest(['search'=>['value'=>'inactive.visibility.peer']])))->response();
        $this->same(0,$peerResult['recordsFiltered'],'same-level inactive identity remains hidden');
        $inactiveExport=iterator_to_array((new DataTableQuery($this->pdo,$inactiveDefinition,new DataTableRequest(['search'=>['value'=>'inactive.visibility.']])))->exportRows(),false);
        $this->same(1,count($inactiveExport),'Inactive Users CSV applies the same ASC hierarchy and scope');
        $this->same(true,$policy->canManageUser($actors['ASC_SUBJECT_OFFICER'],$inactiveOwn),'ended role and scope history still establishes management authority');
        $this->same(false,$policy->canManageUser($actors['ASC_SUBJECT_OFFICER'],$inactiveOutside),'direct inactive-user authorization rejects another ASC');

        $districtOwn=$this->createActor('ASC_ADMIN',$ascX,'inactive.district.own');$this->deactivateFixture($districtOwn);
        $districtOutside=$this->createActor('ASC_ADMIN',$ascY,'inactive.district.outside');$this->deactivateFixture($districtOutside);
        $this->useContext($actors['DISTRICT_SUBJECT_OFFICER'],'DISTRICT_SUBJECT_OFFICER');
        $districtDefinition=DataTableRegistry::definition('historical-users');
        $districtOwnResult=(new DataTableQuery($this->pdo,$districtDefinition,new DataTableRequest(['search'=>['value'=>'inactive.district.own']])))->response();
        $districtOutsideResult=(new DataTableQuery($this->pdo,$districtDefinition,new DataTableRequest(['search'=>['value'=>'inactive.district.outside']])))->response();
        $this->same(1,$districtOwnResult['recordsFiltered'],'District actor sees permitted inactive lower user in own District');
        $this->same(0,$districtOutsideResult['recordsFiltered'],'District actor cannot discover inactive user in another District');
    }

    private function activationCases(UserAccessManagementService $policy,string $districtActor,string $nationalActor,string $districtX,string $districtY):void
    {
        $targetFor=function(string $districtId):string{
            $stmt=$this->pdo->prepare("SELECT su.id FROM system_user su JOIN legacy_user_reference lur ON lur.system_user_id=su.id JOIN legacy_user_organization_context luc ON luc.legacy_user_reference_id=lur.id WHERE su.identity_type='HISTORICAL' AND su.enabled=0 AND LOWER(TRIM(lur.legacy_role_name))='district subject officer' AND luc.location_id=? ORDER BY su.id LIMIT 1");
            $stmt->execute([$districtId]);$id=$stmt->fetchColumn();if(!$id)throw new RuntimeException('A District Subject Officer legacy activation fixture is required.');return (string)$id;
        };
        $targets=[$targetFor($districtX),$targetFor($districtY)];
        $this->same(2,count($targets),'scoped historical activation fixtures are available');$service=new OperationalUserActivationService($this->pdo);
        $base=['email'=>null,'temporary_password'=>'Secure-Manager-1!','effective_from'=>date('Y-m-d'),'reason'=>'User management hierarchy test','official_reference'=>'TEST/UM'];
        $service->activate((string)$targets[0],$base+['username'=>'district.activation.'.substr(str_replace('-','',(string)$targets[0]),0,8),'role_enabled'=>['DISTRICT_SUBJECT_OFFICER'],'roles'=>['DISTRICT_SUBJECT_OFFICER'=>$districtX]],$districtActor);
        $this->same('ACTIVE',(string)$this->value('SELECT account_status FROM system_user WHERE id=?',[(string)$targets[0]]),'District Admin activates permitted user in own District');
        $this->throws(fn()=>$service->activate((string)$targets[1],$base+['username'=>'blocked.activation.'.substr(str_replace('-','',(string)$targets[1]),0,8),'role_enabled'=>['DISTRICT_SUBJECT_OFFICER'],'roles'=>['DISTRICT_SUBJECT_OFFICER'=>$districtY]],$districtActor),'District activation rejects another District POST');
        $service->activate((string)$targets[1],$base+['username'=>'national.activation.'.substr(str_replace('-','',(string)$targets[1]),0,8),'role_enabled'=>['DISTRICT_ADMIN'],'roles'=>['DISTRICT_ADMIN'=>$districtY]],$nationalActor);
        $this->same('ACTIVE',(string)$this->value('SELECT account_status FROM system_user WHERE id=?',[(string)$targets[1]]),'National Admin activates permitted District user');
    }

    private function createActor(string $roleCode,?string $locationId,string $name,int $active=1,string $status='APPROVED',?string $effectiveTo=null):string
    {
        $user=$this->createUser($name);$this->createAssignment($user,$roleCode,$locationId,$active,$status,$effectiveTo);return $user;
    }

    private function createAssignment(string $userId,string $roleCode,?string $locationId,int $active=1,string $status='APPROVED',?string $effectiveTo=null):string
    {
        $id=$this->uuid();$roleId=$this->roleId($roleCode);$this->pdo->prepare('INSERT INTO user_account_role(id,user_id,role_id,effective_from,effective_to,approval_status,active,reason) VALUES(?,?,?,CURRENT_DATE(),?,?,?,?)')->execute([$id,$userId,$roleId,$effectiveTo,$status,$active,'User access management test']);
        $level=(string)$this->value('SELECT role_level FROM application_role WHERE id=?',[$roleId]);
        if(in_array($level,['NATIONAL','DISTRICT','ASC','ARPA','FARMER'],true)){$type=['NATIONAL'=>'NATIONAL','DISTRICT'=>'DISTRICT','ASC'=>'ASC','ARPA'=>'ARPA_DIVISION','FARMER'=>'ASC'][$level];$mode=['NATIONAL'=>'NATIONAL','DISTRICT'=>'INCLUDE_CHILDREN','ASC'=>'EXACT','ARPA'=>'EXACT','FARMER'=>'EXACT'][$level];$this->pdo->prepare('INSERT INTO user_account_scope(id,user_id,role_assignment_id,scope_type,scope_mode,location_id,effective_from,effective_to,approval_status,active,reason) VALUES(UUID(),?,?,?,?,?,CURRENT_DATE(),?,?,?,?)')->execute([$userId,$id,$type,$mode,$locationId,$effectiveTo,$status,$active,'User access management test']);}
        return $id;
    }

    private function createUser(string $username):string{$id=$this->uuid();$this->pdo->prepare("INSERT INTO system_user(id,identity_type,username,display_name,account_status,approval_status,enabled) VALUES(?,'STAFF',?,?,'ACTIVE','APPROVED',1)")->execute([$id,$username,$username]);return $id;}
    private function deactivateFixture(string $userId):void{$this->pdo->prepare("UPDATE user_account_scope SET active=0,effective_to=CURRENT_DATE() WHERE user_id=?")->execute([$userId]);$this->pdo->prepare("UPDATE user_account_role SET active=0,effective_to=CURRENT_DATE() WHERE user_id=?")->execute([$userId]);$this->pdo->prepare("UPDATE system_user SET enabled=0,account_status='DISABLED',updated_at=NOW() WHERE id=?")->execute([$userId]);}
    private function manualAccountData(string $username,string $name,string $roleCode,?string $locationId,string $effectiveFrom,string $password,string $officerStatusId,?string $designationId=null):array
    {
        return [
            'account_source'=>UserAccountRequestService::SOURCE_MANUAL,
            'full_name'=>$name,
            'nic'=>$roleCode==='FARMER'?'':$this->unusedNic(),
            'primary_designation_id'=>$roleCode==='FARMER'?'':($designationId??$this->designationId('AGRARIAN_DEVELOPMENT_OFFICER')),
            'officer_status_id'=>$roleCode==='FARMER'?'':$officerStatusId,
            'username'=>$username,
            'role_id'=>$this->roleId($roleCode),
            'location_id'=>$locationId,
            'effective_from'=>$effectiveFrom,
            'temporary_password'=>$password,
            'mfa_method'=>'AUTHENTICATOR_APP',
        ];
    }
    private function unusedNic():string
    {
        do {
            $nic='2999'.str_pad((string)$this->nicSequence++,8,'0',STR_PAD_LEFT);
        } while($this->count('SELECT COUNT(*) FROM officer WHERE nic_normalized=? OR nic_match_key=?',[$nic,$nic])!==0);
        return $nic;
    }
    private function statusId(string $systemKey):string
    {
        $id=$this->value("SELECT id FROM officer_status WHERE system_key=? AND active=1 AND approval_status='APPROVED'",[$systemKey]);
        if(!$id)throw new RuntimeException("Approved Officer Status {$systemKey} is required.");
        return (string)$id;
    }
    private function designationId(string $systemKey):string
    {
        $id=$this->value("SELECT id FROM designation WHERE system_key=? AND active=1 AND approval_status='APPROVED'",[$systemKey]);
        if(!$id)throw new RuntimeException("Approved Designation {$systemKey} is required.");
        return (string)$id;
    }
    private function assertInitialOffice(array $request,string $officeType,?string $linkedLocationId,string $message):void
    {
        $row=$this->row('SELECT ot.system_key office_type,o.linked_location_id,a.effective_from,a.approval_status FROM officer_office_assignment a JOIN office o ON o.id=a.office_id JOIN office_type ot ON ot.id=o.office_type_id WHERE a.id=?',[(string)$request['office_assignment_id']]);
        $this->same($officeType,(string)($row['office_type']??''),$message);
        $this->same($linkedLocationId,$row['linked_location_id']??null,$message.' uses the expected linked location');
        $this->same('SUBMITTED',(string)($row['approval_status']??''),$message.' remains pending until checker approval');
    }
    private function roleId(string $code):string{$id=$this->value('SELECT id FROM application_role WHERE role_code=?',[$code]);if(!$id)throw new RuntimeException("Role {$code} missing");return (string)$id;}
    private function systemAdmin():string{$id=$this->value("SELECT su.id FROM system_user su JOIN user_account_role uar ON uar.user_id=su.id JOIN application_role r ON r.id=uar.role_id WHERE r.role_code='SYSTEM_ADMIN' AND uar.active=1 AND uar.approval_status='APPROVED' LIMIT 1");if(!$id)throw new RuntimeException('SYSTEM_ADMIN fixture missing');return (string)$id;}

    private function useContext(string $userId,string $roleCode):void
    {
        $_SESSION=['user_id'=>$userId,'authenticated_at'=>time(),'last_activity_at'=>time()];
        Auth::forgetRequestCache();
        $service=new UserContextService($this->pdo);
        $context=array_values(array_filter($service->availableContexts($userId),static fn(array $row):bool=>(string)$row['role_code']===$roleCode))[0]??null;
        if($context===null)throw new RuntimeException("Working context {$roleCode} is required for {$userId}.");
        $service->select($userId,(string)$context['role_assignment_id'],$context['scope_assignment_id']===null?null:(string)$context['scope_assignment_id']);
        Auth::forgetRequestCache();
    }

    /** @return array{0:string,1:string,2:string,3:string} */
    private function districtFixtures():array
    {
        $sql="WITH RECURSIVE tree(root_id,id) AS (SELECT l.id,l.id FROM location l JOIN location_type lt ON lt.id=l.location_type_id AND lt.system_key='DISTRICT' WHERE l.approval_status='APPROVED' UNION DISTINCT SELECT t.root_id,lr.child_location_id FROM tree t JOIN location_relationship lr ON lr.parent_location_id=t.id WHERE lr.active=1 AND lr.approval_status='APPROVED' AND lr.effective_from<=CURRENT_DATE() AND (lr.effective_to IS NULL OR lr.effective_to>=CURRENT_DATE())) SELECT t.root_id,MIN(l.id) asc_id FROM tree t JOIN location l ON l.id=t.id JOIN location_type lt ON lt.id=l.location_type_id AND lt.system_key='ASC' GROUP BY t.root_id ORDER BY t.root_id LIMIT 2";
        $rows=$this->pdo->query($sql)->fetchAll();if(count($rows)<2)throw new RuntimeException('Two District/ASC fixtures are required.');return [(string)$rows[0]['root_id'],(string)$rows[0]['asc_id'],(string)$rows[1]['root_id'],(string)$rows[1]['asc_id']];
    }

    private function arpaUnder(string $ascId):string
    {
        $sql="WITH RECURSIVE descendants(id) AS (SELECT ? UNION DISTINCT SELECT lr.child_location_id FROM location_relationship lr JOIN descendants d ON d.id=lr.parent_location_id WHERE lr.active=1 AND lr.approval_status='APPROVED' AND lr.effective_from<=CURRENT_DATE() AND (lr.effective_to IS NULL OR lr.effective_to>=CURRENT_DATE())) SELECT l.id FROM descendants d JOIN location l ON l.id=d.id JOIN location_type lt ON lt.id=l.location_type_id WHERE lt.system_key='ARPA_DIVISION' AND l.operational_status='ACTIVE' AND l.approval_status='APPROVED' LIMIT 1";
        $id=$this->value($sql,[$ascId]);if(!$id)throw new RuntimeException('An ARPA Division fixture is required below each ASC.');return (string)$id;
    }

    private function state():array{return ['officers'=>$this->count('SELECT COUNT(*) FROM officer'),'office_assignments'=>$this->count('SELECT COUNT(*) FROM officer_office_assignment'),'users'=>$this->count('SELECT COUNT(*) FROM system_user'),'roles'=>$this->count('SELECT COUNT(*) FROM user_account_role'),'scopes'=>$this->count('SELECT COUNT(*) FROM user_account_scope'),'events'=>$this->count('SELECT COUNT(*) FROM audit_event')];}
    private function row(string $sql,array $params=[]):array{$stmt=$this->pdo->prepare($sql);$stmt->execute($params);return $stmt->fetch()?:[];}
    private function value(string $sql,array $params=[]):mixed{$stmt=$this->pdo->prepare($sql);$stmt->execute($params);return $stmt->fetchColumn();}
    private function count(string $sql,array $params=[]):int{return (int)$this->value($sql,$params);}
    private function questions():int{$row=$this->pdo->query("SHOW SESSION STATUS LIKE 'Questions'")->fetch();return (int)($row['Value']??$row[1]??0);}
    private function same(mixed $expected,mixed $actual,string $message):void{$this->assertions++;if($expected!==$actual)throw new RuntimeException($message.': expected '.var_export($expected,true).', got '.var_export($actual,true));}
    private function throws(callable $callback,string $message):void{$this->assertions++;try{$callback();}catch(DomainException){return;}throw new RuntimeException($message.': expected DomainException');}
    private function throwsMessage(callable $callback,string $expected,string $message):void{$this->assertions++;try{$callback();}catch(DomainException $e){if($e->getMessage()===$expected)return;throw new RuntimeException($message.': expected '.var_export($expected,true).', got '.var_export($e->getMessage(),true));}throw new RuntimeException($message.': expected DomainException');}
    private function pdoThrows(callable $callback,string $message):void{$this->assertions++;try{$callback();}catch(PDOException){return;}throw new RuntimeException($message.': expected PDOException');}
    private function uuid():string{$hex=bin2hex(random_bytes(16));return substr($hex,0,8).'-'.substr($hex,8,4).'-4'.substr($hex,13,3).'-'.dechex((hexdec($hex[16])&3)|8).substr($hex,17,3).'-'.substr($hex,20);}
}

exit((new UserAccessManagementTest())->run());
