<?php
declare(strict_types=1);
namespace App\Services;

use App\Core\Auth;
use DomainException;
use PDO;
use Throwable;

/** Uses the existing request soft-delete fields and audit ledger; never removes source evidence. */
final class ArpaLegacyRequestExclusionService
{
    public const AUDIT_ACTION='arpa.appointment.workflow-request.admin-delete';
    private const OPERATION='EXCLUDE_UNRESOLVED_LEGACY';
    public function __construct(private readonly PDO $pdo){}

    public static function allowed():bool
    {
        $context=Auth::activeContext(false);
        return $context!==null&&$context['role_code']==='SYSTEM_ADMIN'&&$context['role_level']==='SYSTEM'
            &&Auth::can('arpa.appointment.view')&&ArpaAdministrativePolicy::isCanonicalDemsAdmin();
    }
    public static function authorize(string $actorId):void
    {
        if(!Auth::isCurrentUser($actorId)||!self::allowed())throw new DomainException('An active canonical DEMS System Administrator / SYSTEM context is required.',403);
    }

    public function preview(string $id,string $actorId):array
    {
        self::authorize($actorId);
        return $this->assess($this->request($id));
    }

    public function exclude(string $id,array $input,string $actorId):void
    {
        self::authorize($actorId);
        $reason=$this->text($input['delete_reason']??'','Removal reason');
        $evidence=$this->text($input['evidence_reference']??'','Supporting evidence reference');
        if(($input['confirm_delete']??'')!=='1'||!hash_equals($id,trim((string)($input['confirmation_id']??''))))throw new DomainException('Confirm exclusion and enter the complete request ID.',422);
        $expected=(string)($input['preview_hash']??'');
        if(!preg_match('/^[a-f0-9]{64}$/D',$expected))throw new DomainException('Open the dependency preview before confirming exclusion.',409);
        $owns=!$this->pdo->inTransaction();
        if($owns)$this->pdo->beginTransaction();else $this->pdo->exec('SAVEPOINT legacy_request_exclusion');
        try{
            $identity=$this->request($id);
            $s=$this->pdo->prepare('SELECT id FROM location WHERE id=? FOR UPDATE');$s->execute([$identity['arpa_division_location_id']]);
            if(!$s->fetchColumn())throw new DomainException('The ARPA Division no longer exists.',409);
            $request=$this->request($id,true);
            if($request['arpa_division_location_id']!==$identity['arpa_division_location_id'])throw new DomainException('The request changed. Reload the dependency preview.',409);
            self::authorize($actorId);
            $preview=$this->assess($request,true);
            if(!$preview['eligible'])throw new DomainException(implode(' ',$preview['blockers']),409);
            if(!hash_equals($expected,$preview['preview_hash']))throw new DomainException('The request or its dependencies changed. Reload the dependency preview.',409);
            $details=['operation'=>self::OPERATION,'previous_values'=>$request,'source_references'=>$preview['source_references'],
                'location_repair_history'=>$preview['location_repair_history'],'dependency_preview'=>$preview['dependencies'],
                'delete_reason'=>$reason,'evidence_reference'=>$evidence,'preview_hash'=>$expected,
                'active_context'=>ArpaAdministrativePolicy::auditContext(Auth::activeContext(false))];
            $this->pdo->prepare("INSERT INTO audit_event(actor_user_id,action_key,target_type,target_id,details_json,severity,source_ip,created_at) VALUES(?,?,'ARPA_DIVISION_APPOINTMENT_REQUEST',?,?,'WARNING',?,NOW())")
                ->execute([$actorId,self::AUDIT_ACTION,$id,json_encode($details,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES),$_SERVER['REMOTE_ADDR']??'CLI']);
            $s=$this->pdo->prepare('UPDATE arpa_division_appointment_request SET deleted_at=NOW(),deleted_by=?,delete_reason=?,version=version+1 WHERE id=? AND deleted_at IS NULL AND version=?');
            $s->execute([$actorId,$reason,$id,$request['version']]);
            if($s->rowCount()!==1)throw new DomainException('The request was already excluded or changed.',409);
            if($owns)$this->pdo->commit();else $this->pdo->exec('RELEASE SAVEPOINT legacy_request_exclusion');
        }catch(Throwable $e){
            if($this->pdo->inTransaction()){if($owns)$this->pdo->rollBack();else{$this->pdo->exec('ROLLBACK TO SAVEPOINT legacy_request_exclusion');$this->pdo->exec('RELEASE SAVEPOINT legacy_request_exclusion');}}
            throw $e;
        }
    }

    public function audit(string $actorId,?string $id=null):array
    {
        self::authorize($actorId);
        $sql="SELECT a.id,a.target_id request_id,a.actor_user_id,a.created_at,a.details_json,u.display_name administrator
              FROM audit_event a JOIN system_user u ON u.id=a.actor_user_id
              WHERE a.action_key=? AND JSON_UNQUOTE(JSON_EXTRACT(a.details_json,'$.operation'))=?";
        $params=[self::AUDIT_ACTION,self::OPERATION];if($id!==null){$sql.=' AND a.target_id=?';$params[]=$id;}
        $sql.=' ORDER BY a.id DESC LIMIT 100';$s=$this->pdo->prepare($sql);$s->execute($params);$rows=$s->fetchAll();
        foreach($rows as &$row)$row['details']=json_decode($row['details_json'],true,512,JSON_THROW_ON_ERROR);unset($row);
        if($id!==null&&$rows===[])throw new DomainException('Legacy exclusion audit was not found.',404);
        return $rows;
    }

    private function request(string $id,bool $lock=false):array
    {
        if(!preg_match('/^[a-f0-9-]{36}$/iD',$id))throw new DomainException('Legacy request was not found.',404);
        $s=$this->pdo->prepare('SELECT * FROM arpa_division_appointment_request WHERE id=?'.($lock?' FOR UPDATE':''));$s->execute([$id]);$r=$s->fetch();
        if(!$r)throw new DomainException('Legacy request was not found.',404);return $r;
    }

    private function assess(array $r,bool $lock=false):array
    {
        $id=(string)$r['id'];$blockers=[];$dependencies=[];$suffix=$lock?' FOR UPDATE':'';
        if($r['record_origin']!=='LEGACY_IMPORT'||$r['request_type']!=='APPOINTMENT'||$r['workflow_status']!=='CREATED'
            ||(int)$r['legacy_history_only']!==1||(int)$r['legacy_exception']!==1||$r['source_appointment_id']!==null){
            $blockers[]='Only unresolved, non-operational imported CREATED appointment requests can be excluded.';
        }
        if(($r['deleted_at']??null)!==null)$blockers[]='This request is already administratively excluded.';
        $s=$this->pdo->query("SELECT COUNT(*) FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='arpa_division_appointment_request' AND column_name IN('deleted_at','deleted_by','delete_reason','version')");
        if((int)$s->fetchColumn()!==4)$blockers[]='The existing request soft-delete schema is unavailable.';
        // Both writes must be transactional. Schema errors are never interpreted as no dependencies.
        $s=$this->pdo->query("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name IN('audit_event','arpa_division_appointment_request') AND engine='InnoDB'");
        if((int)$s->fetchColumn()!==2)$blockers[]='Transactional request and audit storage is required.';
        $checks=[
            'canonical_appointments'=>['SELECT id FROM arpa_division_appointment WHERE request_id=?',[$id]],
            'canonical_closures'=>['SELECT id FROM arpa_division_appointment_closure WHERE request_id=?',[$id]],
            'workflow_actions'=>['SELECT id FROM arpa_appointment_workflow_action WHERE request_id=?',[$id]],
            'correction_decisions'=>["SELECT id FROM arpa_appointment_data_correction WHERE request_id=? OR issue_row_key=? OR JSON_SEARCH(before_json,'one',?) IS NOT NULL OR JSON_SEARCH(after_json,'one',?) IS NOT NULL",[$id,'LEGACY_HISTORICAL_EXCEPTION:'.$id,$id,$id]],
        ];
        foreach($checks as $label=>[$sql,$params]){$s=$this->pdo->prepare($sql.$suffix);$s->execute($params);$dependencies[$label]=$s->fetchAll(PDO::FETCH_COLUMN);if($dependencies[$label]!==[])$blockers[]='Protected dependency: '.str_replace('_',' ',$label).'.';}
        $optional=[
            'arpa_appointment_correction_amendment_request'=>["JSON_SEARCH(baseline_json,'one',?) IS NOT NULL OR JSON_SEARCH(proposed_json,'one',?) IS NOT NULL",[$id,$id]],
            'arpa_appointment_correction_amendment_event'=>["JSON_SEARCH(before_proposal_json,'one',?) IS NOT NULL OR JSON_SEARCH(after_proposal_json,'one',?) IS NOT NULL",[$id,$id]],
            'arpa_appointment_stage_review'=>["entity_type='DIVISION' AND request_id=?",[$id]],
            'arpa_appointment_stage_review_audit'=>["entity_type='DIVISION' AND request_id=?",[$id]],
            'system_notification'=>["entity_type='ARPA_DIVISION_REQUEST' AND entity_id=? AND notification_type='ACTION_REQUIRED' AND action_status='PENDING'",[$id]],
        ];
        foreach($optional as $table=>[$where,$params]){
            $s=$this->pdo->prepare('SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name=?');$s->execute([$table]);
            $dependencies[$table]=[];if(!(int)$s->fetchColumn())continue;
            $s=$this->pdo->prepare('SELECT id FROM '.$table.' WHERE '.$where.' ORDER BY id'.$suffix);$s->execute($params);$dependencies[$table]=$s->fetchAll(PDO::FETCH_COLUMN);
            if($dependencies[$table]!==[])$blockers[]='Protected dependency: '.$table.'.';
        }
        $s=$this->pdo->prepare('SELECT * FROM legacy_arpa_appointment_source_reference WHERE target_appointment_request_id=? ORDER BY id'.$suffix);$s->execute([$id]);$sources=$s->fetchAll();
        if($sources===[])$blockers[]='Verified imported source references are required.';
        foreach($sources as $source){
            if($source['target_appointment_id']!==null)$blockers[]='Source evidence is already linked to a canonical appointment.';
            foreach(['target_subject_request_id','target_subject_assignment_id','target_sub_designation_period_id'] as $field)
                if(($source[$field]??null)!==null)$blockers[]='Source evidence is linked to another protected assignment or workflow.';
        }
        $repairs=[];$s=$this->pdo->query("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name='legacy_arpa_location_repair_item'");
        if((int)$s->fetchColumn()){$s=$this->pdo->prepare('SELECT * FROM legacy_arpa_location_repair_item WHERE request_id=? ORDER BY id'.$suffix);$s->execute([$id]);$repairs=$s->fetchAll();foreach($repairs as $repair)if($repair['appointment_id']!==null||$repair['closure_id']!==null)$blockers[]='Location repair evidence has operational dependencies.';}
        // Fail closed for any referencing table added outside the reviewed baseline.
        $known=['arpa_division_appointment','arpa_division_appointment_closure','arpa_appointment_workflow_action','arpa_appointment_data_correction','legacy_arpa_appointment_source_reference','legacy_arpa_location_repair_item'];
        $s=$this->pdo->query("SELECT TABLE_NAME,COLUMN_NAME FROM information_schema.key_column_usage WHERE referenced_table_schema=DATABASE() AND referenced_table_name='arpa_division_appointment_request' ORDER BY TABLE_NAME,COLUMN_NAME");
        foreach($s->fetchAll() as $fk){$table=$fk['TABLE_NAME'];$column=$fk['COLUMN_NAME'];if(in_array($table,$known,true))continue;if(!preg_match('/^\w+$/D',$table)||!preg_match('/^\w+$/D',$column))throw new DomainException('Unrecognized dependency schema.',503);$q=$this->pdo->prepare("SELECT COUNT(*) FROM `{$table}` WHERE `{$column}`=?");$q->execute([$id]);$dependencies[$table.'.'.$column]=(int)$q->fetchColumn();if($dependencies[$table.'.'.$column]>0)$blockers[]='Unreviewed dependency: '.$table.'.'.$column.'.';}
        $s=$this->pdo->prepare('SELECT dad_number,name_with_initials FROM officer WHERE id=?');$s->execute([$r['officer_id']]);$officer=$s->fetch();
        if(!$officer)$blockers[]='The Officer identity cannot be verified.';
        $s=$this->pdo->prepare('SELECT asc_l.dad_number asc_number,asc_l.name_en asc_name,arpa.dad_number division_number,arpa.name_en division_name FROM location asc_l JOIN location arpa ON arpa.id=? WHERE asc_l.id=?');$s->execute([$r['arpa_division_location_id'],$r['asc_location_id']]);$locations=$s->fetch();
        if(!$locations)$blockers[]='The ASC and ARPA Division identities cannot be verified.';
        $hash=hash('sha256',json_encode([$r,$sources,$repairs,$dependencies],JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE));
        return ['request'=>$r,'officer'=>$officer,'locations'=>$locations,'source_references'=>$sources,'location_repair_history'=>$repairs,'dependencies'=>$dependencies,
            'eligible'=>$blockers===[],'blockers'=>array_values(array_unique($blockers)),'preview_hash'=>$hash];
    }

    private function text(mixed $value,string $label):string
    {
        $value=trim((string)$value);if($value===''||mb_strlen($value)>500||!mb_check_encoding($value,'UTF-8')||preg_match('/[\x00-\x1F\x7F]/u',$value))throw new DomainException($label.' must contain 1 to 500 characters.',422);return $value;
    }
}
