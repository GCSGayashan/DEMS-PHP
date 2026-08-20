<?php
declare(strict_types=1);
namespace App\Services;

use App\Core\CredentialService;
use DomainException;
use PDO;
use Throwable;

final class OperationalUserActivationService
{
    public const ROLE_CODES=['ASC_SUBJECT_OFFICER','ASC_ADMIN','ASC_VIEWER','DISTRICT_SUBJECT_OFFICER','DISTRICT_ADMIN','DISTRICT_VIEWER','NATIONAL_SUBJECT_OFFICER','NATIONAL_ADMIN','NATIONAL_VIEWER'];

    public function __construct(private readonly PDO $pdo){}

    public function activate(string $userId,array $data,string $actorId):void
    {
        $this->assertActorPermissions($actorId,['user.activate','user.assign-role','user.assign-scope','user.reset-password']);
        $username=CredentialService::validateOperationalUsername((string)($data['username']??''));
        $passwordHash=CredentialService::hashTemporaryPassword((string)($data['temporary_password']??''));
        $email=$this->email($data['email']??null);
        $reason=$this->requiredText($data['reason']??null,'Activation reason');
        $officialReference=$this->text($data['official_reference']??null);
        $effectiveFrom=$this->date((string)($data['effective_from']??date('Y-m-d')));
        $selections=$this->roleSelections($data['roles']??[],$data['role_enabled']??[]);
        $management=new UserAccessManagementService($this->pdo);
        $management->assertCanManageUser($actorId,$userId,$effectiveFrom);
        foreach($selections as $roleCode=>$locationId){
            $management->validateRoleCodeAssignment($actorId,$roleCode,$locationId,$effectiveFrom);
        }

        $this->transaction(function()use($userId,$actorId,$username,$passwordHash,$email,$reason,$officialReference,$effectiveFrom,$selections):void{
            $stmt=$this->pdo->prepare('SELECT * FROM system_user WHERE id=? FOR UPDATE');$stmt->execute([$userId]);$user=$stmt->fetch();
            if(!$user)throw new DomainException('User identity was not found.');
            if((int)$user['enabled']===1||$user['account_status']==='ACTIVE')throw new DomainException('This identity is already operationally active.');
            if((int)$user['historical_identity']!==1)throw new DomainException('Selective historical activation requires an imported legacy identity.');
            $reference=$this->pdo->prepare('SELECT COUNT(*) FROM legacy_user_reference WHERE system_user_id=?');$reference->execute([$userId]);
            if((int)$reference->fetchColumn()===0)throw new DomainException('The legacy identity reference is missing.');
            $collision=$this->pdo->prepare('SELECT COUNT(*) FROM system_user WHERE username=? AND id<>?');$collision->execute([$username,$userId]);
            if((int)$collision->fetchColumn()>0)throw new DomainException('That username is already assigned to another identity.');
            if($email!==null){$duplicate=$this->pdo->prepare('SELECT COUNT(*) FROM system_user WHERE email_normalized=? AND id<>?');$duplicate->execute([$email,$userId]);if((int)$duplicate->fetchColumn()>0)throw new DomainException('That email address is already assigned to another user.');}

            [$roleRows,$scopeRows]=$this->createAccessAssignments($userId,$selections,$effectiveFrom,$reason,$officialReference,$actorId);
            $this->pdo->prepare("UPDATE system_user SET identity_type='STAFF',username=?,email=?,email_normalized=?,password_hash=?,password_setup_required=1,mfa_method='AUTHENTICATOR_APP',mfa_enrolled=0,account_status='ACTIVE',approval_status='APPROVED',enabled=1,action_reason=?,approved_by=?,approved_at=COALESCE(approved_at,NOW()),activated_by=?,activated_at=NOW(),updated_at=NOW() WHERE id=?")
                ->execute([$username,$email,$email,$passwordHash,$reason,$actorId,$actorId,$userId]);
            $event=$user['identity_type']==='HISTORICAL'?'ACTIVATE':'REACTIVATE';
            $this->recordEvent($userId,$event,$user,'STAFF','ACTIVE',$username,$roleRows,$scopeRows,$reason,$officialReference,$actorId);
        });
    }

    public function deactivate(string $userId,string $reason,?string $officialReference,string $actorId):void
    {
        $this->assertActorPermissions($actorId,['user.block']);
        (new UserAccessManagementService($this->pdo))->assertCanManageUser($actorId,$userId);
        $reason=$this->requiredText($reason,'Deactivation reason');$officialReference=$this->text($officialReference);
        if($userId===$actorId)throw new DomainException('Administrators cannot deactivate their own account.');
        $this->transaction(function()use($userId,$reason,$officialReference,$actorId):void{
            $stmt=$this->pdo->prepare('SELECT * FROM system_user WHERE id=? FOR UPDATE');$stmt->execute([$userId]);$user=$stmt->fetch();
            if(!$user||$user['identity_type']!=='STAFF'||(int)$user['enabled']!==1||$user['account_status']!=='ACTIVE')throw new DomainException('Only an active operational staff identity can be deactivated.');
            $protected=$this->pdo->prepare("SELECT COUNT(*) FROM user_account_role uar JOIN application_role r ON r.id=uar.role_id WHERE uar.user_id=? AND uar.active=1 AND uar.approval_status='APPROVED' AND r.role_code IN('SYSTEM_ADMIN','SECURITY_ADMIN','USER_ADMIN')");$protected->execute([$userId]);
            if((int)$protected->fetchColumn()>0)throw new DomainException('Protected administration accounts must be handled through the dedicated security process.');
            $roles=$this->activeAssignments('user_account_role',$userId);$scopes=$this->activeAssignments('user_account_scope',$userId);
            $this->pdo->prepare("UPDATE user_account_scope SET active=0,effective_to=CASE WHEN effective_to IS NULL OR effective_to>CURRENT_DATE() THEN CURRENT_DATE() ELSE effective_to END,action_reason=? WHERE user_id=? AND active=1")->execute([$reason,$userId]);
            $this->pdo->prepare("UPDATE user_account_role SET active=0,effective_to=CASE WHEN effective_to IS NULL OR effective_to>CURRENT_DATE() THEN CURRENT_DATE() ELSE effective_to END,reason=CONCAT_WS(' | ',NULLIF(reason,''),?) WHERE user_id=? AND active=1")->execute([$reason,$userId]);
            $this->pdo->prepare("UPDATE system_user SET account_status='DISABLED',enabled=0,action_reason=?,updated_at=NOW() WHERE id=?")->execute([$reason,$userId]);
            $this->recordEvent($userId,'DEACTIVATE',$user,'STAFF','DISABLED',(string)$user['username'],$roles,$scopes,$reason,$officialReference,$actorId);
        });
    }

    private function createAccessAssignments(string $userId,array $selections,string $from,string $reason,?string $reference,string $actorId):array
    {
        $roleRows=[];$scopeRows=[];
        foreach($selections as $roleCode=>$locationId){
            $roleStmt=$this->pdo->prepare("SELECT id,role_code,role_level FROM application_role WHERE role_code=? AND active=1 AND assignable=1 AND approval_status='APPROVED' FOR UPDATE");$roleStmt->execute([$roleCode]);$role=$roleStmt->fetch();
            if(!$role||!in_array($role['role_code'],self::ROLE_CODES,true))throw new DomainException('An unsupported operational role was selected.');
            $active=$this->pdo->prepare("SELECT COUNT(*) FROM user_account_role WHERE user_id=? AND role_id=? AND active=1 AND approval_status='APPROVED' AND effective_from<=? AND (effective_to IS NULL OR effective_to>=?)");$active->execute([$userId,$role['id'],$from,$from]);
            if((int)$active->fetchColumn()>0)throw new DomainException("The user already has active role {$roleCode}.");
            [$scopeType,$scopeMode,$validatedLocation]=$this->validateScope((string)$role['role_level'],$locationId);
            $roleAssignmentId=$this->uuid();$scopeId=$this->uuid();
            $this->pdo->prepare("INSERT INTO user_account_role(id,user_id,role_id,effective_from,approval_status,active,reason,official_reference,created_by,created_at,approved_by,approved_at) VALUES(?,?,?,?,'APPROVED',1,?,?,?,NOW(),?,NOW())")->execute([$roleAssignmentId,$userId,$role['id'],$from,$reason,$reference,$actorId,$actorId]);
            $this->pdo->prepare("INSERT INTO user_account_scope(id,user_id,role_assignment_id,scope_type,scope_mode,location_id,effective_from,approval_status,active,reason,official_reference,created_by,created_at,approved_by,approved_at) VALUES(?,?,?,?,?,?,?,'APPROVED',1,?,?,?,NOW(),?,NOW())")->execute([$scopeId,$userId,$roleAssignmentId,$scopeType,$scopeMode,$validatedLocation,$from,$reason,$reference,$actorId,$actorId]);
            $roleRows[]=['id'=>$roleAssignmentId,'role_code'=>$roleCode,'effective_from'=>$from];$scopeRows[]=['id'=>$scopeId,'role_code'=>$roleCode,'scope_type'=>$scopeType,'scope_mode'=>$scopeMode,'location_id'=>$validatedLocation,'effective_from'=>$from];
        }
        return [$roleRows,$scopeRows];
    }

    private function validateScope(string $roleLevel,?string $locationId):array
    {
        if($roleLevel==='NATIONAL'){if($locationId!==null&&trim($locationId)!=='')throw new DomainException('National roles require NATIONAL scope without a location.');return ['NATIONAL','NATIONAL',null];}
        $expected=$roleLevel==='ASC'?'ASC':($roleLevel==='DISTRICT'?'DISTRICT':null);if($expected===null)throw new DomainException('Only ASC, District, and National operational roles are supported.');
        $locationId=trim((string)$locationId);if($locationId==='')throw new DomainException("{$expected} roles require an explicit {$expected} location.");
        $stmt=$this->pdo->prepare("SELECT COUNT(*) FROM location l JOIN location_type t ON t.id=l.location_type_id WHERE l.id=? AND t.system_key=? AND l.operational_status='ACTIVE' AND l.approval_status='APPROVED'");$stmt->execute([$locationId,$expected]);if((int)$stmt->fetchColumn()!==1)throw new DomainException("The selected location is not an approved active {$expected}.");
        return [$expected,$expected==='ASC'?'EXACT':'INCLUDE_CHILDREN',$locationId];
    }

    private function roleSelections(mixed $input,mixed $enabled):array
    {
        if(!is_array($input)||!is_array($enabled))throw new DomainException('Select at least one operational role and scope.');$out=[];
        foreach($enabled as $rawCode){$code=strtoupper(trim((string)$rawCode));if(!in_array($code,self::ROLE_CODES,true))throw new DomainException('An unsupported operational role was selected.');$location=$input[$code]??null;$out[$code]=is_string($location)?trim($location):null;}
        if($out===[])throw new DomainException('Select at least one operational role and scope.');return $out;
    }

    private function recordEvent(string $userId,string $type,array $previous,string $newIdentity,string $newStatus,string $username,array $roles,array $scopes,string $reason,?string $reference,string $actor):void
    {
        $id=$this->uuid();$rolesJson=json_encode($roles,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);$scopesJson=json_encode($scopes,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);
        $this->pdo->prepare('INSERT INTO user_operational_access_event(id,user_id,event_type,previous_identity_type,new_identity_type,previous_account_status,new_account_status,previous_username,new_username,role_assignments_json,scope_assignments_json,reason,official_reference,acted_by) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?)')->execute([$id,$userId,$type,$previous['identity_type'],$newIdentity,$previous['account_status'],$newStatus,$previous['username'],$username,$rolesJson,$scopesJson,$reason,$reference,$actor]);
        $this->pdo->prepare("INSERT INTO audit_event(actor_user_id,action_key,target_type,target_id,details_json,severity,created_at) VALUES(?,?, 'SYSTEM_USER',?,?, 'INFO',NOW())")->execute([$actor,'user.operational.'.strtolower($type),$userId,json_encode(['event_id'=>$id,'previous_identity_type'=>$previous['identity_type'],'new_identity_type'=>$newIdentity,'previous_status'=>$previous['account_status'],'new_status'=>$newStatus,'previous_username'=>$previous['username'],'new_username'=>$username,'roles'=>$roles,'scopes'=>$scopes,'reason'=>$reason,'official_reference'=>$reference],JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR)]);
    }
    private function activeAssignments(string $table,string $userId):array{$s=$this->pdo->prepare("SELECT * FROM {$table} WHERE user_id=? AND active=1 FOR UPDATE");$s->execute([$userId]);return $s->fetchAll();}
    private function assertActorPermissions(string $actorId,array $required):void
    {
        (new UserAccessManagementService($this->pdo))->assertActorPermissions($actorId,$required);
    }
    private function email(mixed $value):?string{$email=strtolower(trim((string)$value));if($email==='')return null;if(filter_var($email,FILTER_VALIDATE_EMAIL)===false)throw new DomainException('Enter a valid current email address.');return $email;}
    private function date(string $value):string{$d=\DateTimeImmutable::createFromFormat('!Y-m-d',$value);if(!$d||$d->format('Y-m-d')!==$value)throw new DomainException('Enter a valid effective date.');return $value;}
    private function requiredText(mixed $v,string $label):string{$v=trim((string)$v);if($v==='')throw new DomainException("{$label} is required.");return $v;}
    private function text(mixed $v):?string{$v=trim((string)$v);return $v===''?null:$v;}
    private function uuid():string{$hex=bin2hex(random_bytes(16));return substr($hex,0,8).'-'.substr($hex,8,4).'-4'.substr($hex,13,3).'-'.dechex((hexdec($hex[16])&3)|8).substr($hex,17,3).'-'.substr($hex,20);}
    private function transaction(callable $fn):mixed{$owned=!$this->pdo->inTransaction();if($owned)$this->pdo->beginTransaction();try{$out=$fn();if($owned)$this->pdo->commit();return $out;}catch(Throwable $e){if($owned&&$this->pdo->inTransaction())$this->pdo->rollBack();throw $e;}}
}
