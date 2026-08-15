<?php
declare(strict_types=1);

namespace App\Services\LegacyUser;

use App\Core\NicNormalizer;
use PDO;
use RuntimeException;
use Throwable;

final class LegacyUserMigrationService
{
    public const SOURCE_SYSTEM = 'dems_legacy_hr';
    public const SOURCE_TABLE = 'tbl_user';
    private const OFFICER_SOURCE_SYSTEM = 'AGRARIANADMIN_HR';
    private const WORKFLOW_TABLES = ['tbl_officer_apoint','tbl_officer_apoint_2026'];

    private string $runId;
    private array $users=[];
    private array $plans=[];
    private array $issues=[];
    private array $contexts=[];
    private array $access=[];
    private array $workflow=[];
    private array $workflowIds=[];
    private array $sourceUserIds=[];
    private array $references=[];
    private array $targetUsers=[];
    private array $targetByUsername=[];
    private array $targetByOfficer=[];
    private array $officersByNic=[];
    private array $locationReferences=[];
    private array $duplicateEmails=[];
    private array $duplicateUsernames=[];
    private array $protectedBefore=[];
    private array $stats=[];

    public function __construct(
        private readonly PDO $source,
        private readonly PDO $target,
        private readonly bool $dryRun,
        private readonly int $batchSize=500
    ) {
        if($batchSize<1||$batchSize>10000)throw new RuntimeException('Batch size must be between 1 and 10000.');
        $this->runId=self::uuid();
        $this->stats=[
            'legacy_user_count'=>0,'existing_target_users'=>0,'existing_legacy_references'=>0,
            'already_migrated'=>0,'matched_existing_users'=>0,'new_users_to_create'=>0,
            'would_update'=>0,'created'=>0,'mappings_created'=>0,'officer_linked_users'=>0,
            'users_without_officer_link'=>0,'ambiguous_officer_mappings'=>0,'missing_officer_mappings'=>0,
            'username_collisions'=>0,'missing_usernames'=>0,'duplicate_email_users'=>0,
            'legacy_usernames_used'=>0,'generated_usernames_used'=>0,
            'invalid_emails'=>0,'missing_emails'=>0,'target_email_users'=>0,'null_email_users'=>0,
            'missing_phones'=>0,'missing_required_data'=>0,'duplicate_legacy_ids'=>0,
            'manual_review_users'=>0,'invalid_source_users'=>0,'disabled_users'=>0,
            'workflow_user_ids_referenced'=>0,'workflow_user_ids_found'=>0,
            'workflow_user_ids_resolved'=>0,'workflow_user_ids_unresolved'=>0,
            'orphan_user_mapping_ids'=>0,'stale_auxiliary_mapping_rows'=>0,
            'organization_context_rows'=>0,'mapped_location_context_rows'=>0,'users_without_organization_context'=>0,
            'legacy_access_metadata_rows'=>0,'warnings'=>0,'errors'=>0,'critical_blockers'=>0,
        ];
    }

    public function run(): array
    {
        $this->assertSchemas();
        $this->protectedBefore=$this->protectedState();
        $ownsSource=false;
        try{
            if(!$this->source->inTransaction()){
                $this->source->exec('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ');
                $this->source->exec('SET TRANSACTION READ ONLY');
                $this->source->beginTransaction();$ownsSource=true;
            }
            $this->loadSource();
            $this->loadTarget();
            $this->analyse();
            if(!$this->dryRun){
                if($this->stats['critical_blockers']>0){
                    throw new RuntimeException('Execution refused: reconciliation contains critical identity blockers. Resolve MANUAL_REVIEW and INVALID_SOURCE rows first.');
                }
                $this->createRun();
                $this->executePlans();
                $this->persistIssues();
            }
            $after=$this->protectedState();
            $this->assertProtectedState($after);
            $summary=$this->summary($after);
            $paths=$this->writeReports($summary);
            $summary['report_path']=$paths['csv'];$summary['json_report_path']=$paths['json'];
            if(!$this->dryRun)$this->completeRun($summary);
            if($ownsSource&&$this->source->inTransaction())$this->source->commit();
            return $summary;
        }catch(Throwable $e){
            if($ownsSource&&$this->source->inTransaction())$this->source->rollBack();
            if(!$this->dryRun&&$this->tableExists($this->target,'legacy_user_migration_run'))$this->failRun($e);
            throw $e;
        }
    }

    private function assertSchemas(): void
    {
        $sourceRequired=[
            'tbl_user'=>['id','username','user_inname','user_nic','email','tp_number','status','user_role','created_location','created_at','updated_at'],
            'tbl_role'=>['auto_id','role_name','user_level'],
            'tbl_user_level'=>['auto_id','user_level_name'],
            'tbl_user_has_asc'=>['id','user_id','location_id','status'],
            'tbl_user_has_district'=>['id','user_id','location_id','status'],
            'tbl_user_has_arpa'=>['id','user_id','location_id','status'],
            'tbl_user_location'=>['id','user_id','user_level_id','location_id','status'],
            'tbl_subject'=>['id','subject_name','variable','subject_status'],
            'tbl_user_has_subject'=>['auto_id','user_id','subject_id','status'],
            'tbl_officer'=>['officer_id','nic'],
        ];
        foreach(self::WORKFLOW_TABLES as $table)$sourceRequired[$table]=['auto_id'];
        foreach($sourceRequired as $table=>$columns){
            if(!$this->tableExists($this->source,$table))throw new RuntimeException("Legacy source table is missing: {$table}");
            foreach($columns as $column)if(!$this->columnExists($this->source,$table,$column))throw new RuntimeException("Legacy source column is missing: {$table}.{$column}");
        }
        foreach(['system_user','officer','legacy_officer_reference','legacy_location_reference','legacy_user_migration_run','legacy_user_reference','legacy_user_organization_context','legacy_user_access_metadata','legacy_user_migration_issue','user_account_role','user_account_scope','application_role','application_permission','subject_master'] as $table){
            if(!$this->tableExists($this->target,$table))throw new RuntimeException("Target table is missing: {$table}. Run php bin/migrate.php first.");
        }
        foreach(['display_name','email','email_normalized','mobile','historical_identity','identity_source'] as $column){
            if(!$this->columnExists($this->target,'system_user',$column))throw new RuntimeException("Target system_user.{$column} is missing. Run php bin/migrate.php first.");
        }
        if(!$this->columnExists($this->target,'legacy_user_reference','legacy_created_by_user_id'))throw new RuntimeException('Target legacy_user_reference.legacy_created_by_user_id is missing. Run php bin/migrate.php first.');
    }

    private function loadSource(): void
    {
        $sql="SELECT u.id,u.username,u.user_inname,u.user_nic,u.email,u.tp_number,u.status,u.user_role,u.created_location,u.created_at,u.updated_at,r.role_name,r.user_level,l.user_level_name
              FROM tbl_user u LEFT JOIN tbl_role r ON r.auto_id=u.user_role LEFT JOIN tbl_user_level l ON l.auto_id=r.user_level ORDER BY u.id";
        $this->users=$this->source->query($sql)->fetchAll();
        $this->stats['legacy_user_count']=count($this->users);
        $this->sourceUserIds=array_fill_keys(array_map(fn($row)=>(string)$row['id'],$this->users),true);

        $this->loadContexts('tbl_user_has_asc','ASC','tbl_asc');
        $this->loadContexts('tbl_user_has_district','DISTRICT','tbl_district');
        $this->loadContexts('tbl_user_has_arpa','ARPA_DIVISION','tbl_arpa');
        $rows=$this->source->query("SELECT m.id,m.user_id,m.user_level_id,m.location_id,m.status,l.user_level_name FROM tbl_user_location m LEFT JOIN tbl_user_level l ON l.auto_id=m.user_level_id ORDER BY m.id")->fetchAll();
        foreach($rows as $row){
            $level=$this->levelKey((string)($row['user_level_name']??''));
            $sourceTable=['DISTRICT'=>'tbl_district','ASC'=>'tbl_asc','ARPA_DIVISION'=>'tbl_arpa','GN_DIVISION'=>'tbl_gnd'][$level]??null;
            $this->contexts[(string)$row['user_id']][]=$row+['source_table'=>'tbl_user_location','level_key'=>$level,'location_source_table'=>$sourceTable];
        }
        $access=$this->source->query("SELECT m.auto_id,m.user_id,m.subject_id,m.status,s.subject_name,s.variable,s.subject_status FROM tbl_user_has_subject m LEFT JOIN tbl_subject s ON s.id=m.subject_id ORDER BY m.auto_id")->fetchAll();
        foreach($access as $row)$this->access[(string)$row['user_id']][]=$row;

        $officerRefs=[];
        $stmt=$this->target->prepare("SELECT legacy_officer_id,officer_id FROM legacy_officer_reference WHERE source_system=? AND source_table='tbl_officer'");
        $stmt->execute([self::OFFICER_SOURCE_SYSTEM]);
        foreach($stmt->fetchAll() as $row)$officerRefs[(string)$row['legacy_officer_id']]=(string)$row['officer_id'];
        foreach($this->source->query('SELECT officer_id,nic FROM tbl_officer')->fetchAll() as $row){
            $key=NicNormalizer::matchKey(NicNormalizer::normalize($row['nic']));if($key===null)continue;
            $legacyId=(string)$row['officer_id'];
            $this->officersByNic[$key][]=['legacy_officer_id'=>$legacyId,'target_officer_id'=>$officerRefs[$legacyId]??null];
        }
        $this->discoverWorkflowReferences();
    }

    private function loadContexts(string $table,string $level,string $locationSourceTable): void
    {
        foreach($this->source->query("SELECT id,user_id,location_id,status FROM `{$table}` ORDER BY id")->fetchAll() as $row){
            $this->contexts[(string)$row['user_id']][]=$row+['source_table'=>$table,'level_key'=>$level,'location_source_table'=>$locationSourceTable];
        }
    }

    private function discoverWorkflowReferences(): void
    {
        $pattern='/^(?:user_id|created_by|verified_by|verify_by|varify_by|approved_by|approve_by)$|_(?:created_by|verified_by|verify_by|varify_by|approved_by|approve_by)$/i';
        foreach(self::WORKFLOW_TABLES as $table){
            $columns=$this->columns($this->source,$table);
            foreach($columns as $column){
                if(preg_match($pattern,$column)!==1)continue;
                $quoted=str_replace('`','``',$column);
                $ids=$this->source->query("SELECT DISTINCT `{$quoted}` user_id FROM `{$table}` WHERE `{$quoted}` IS NOT NULL")->fetchAll(PDO::FETCH_COLUMN);
                $found=0;$missing=0;
                foreach($ids as $id){$key=(string)$id;$this->workflowIds[$key]=true;if($this->sourceUserExists($key))$found++;else $missing++;}
                $this->workflow[]=['source_table'=>$table,'field'=>$column,'distinct_non_null'=>count($ids),'found_in_tbl_user'=>$found,'missing_from_tbl_user'=>$missing,'resolved'=>0,'unresolved'=>0];
            }
        }
        $this->stats['workflow_user_ids_referenced']=count($this->workflowIds);
        $found=0;foreach(array_keys($this->workflowIds) as $id)if($this->sourceUserExists((string)$id))$found++;
        $this->stats['workflow_user_ids_found']=$found;
    }

    private function sourceUserExists(string $id): bool
    {
        return isset($this->sourceUserIds[$id]);
    }

    private function loadTarget(): void
    {
        $this->stats['existing_target_users']=(int)$this->target->query('SELECT COUNT(*) FROM system_user')->fetchColumn();
        foreach($this->target->query('SELECT id,officer_id,username,account_status,enabled,historical_identity FROM system_user')->fetchAll() as $row){
            $id=(string)$row['id'];$this->targetUsers[$id]=$row;
            $username=$this->normalizeUsername($row['username']);if($username!=='')$this->targetByUsername[$username][]=$id;
            if(!empty($row['officer_id']))$this->targetByOfficer[(string)$row['officer_id']][]=$id;
        }
        $stmt=$this->target->prepare('SELECT legacy_user_id,system_user_id FROM legacy_user_reference WHERE source_system=? AND source_table=?');
        $stmt->execute([self::SOURCE_SYSTEM,self::SOURCE_TABLE]);
        foreach($stmt->fetchAll() as $row)$this->references[(string)$row['legacy_user_id']]=(string)$row['system_user_id'];
        $this->stats['existing_legacy_references']=count($this->references);
        $stmt=$this->target->prepare('SELECT source_table,legacy_id,location_id FROM legacy_location_reference WHERE source_system=?');
        $stmt->execute([self::OFFICER_SOURCE_SYSTEM]);
        foreach($stmt->fetchAll() as $row)$this->locationReferences[(string)$row['source_table']][(string)$row['legacy_id']]=(string)$row['location_id'];
    }

    private function analyse(): void
    {
        $emailGroups=[];$usernameGroups=[];$legacyIdGroups=[];
        foreach($this->users as $row){
            $legacyId=trim((string)$row['id']);$legacyIdGroups[$legacyId][]=$legacyId;
            $email=trim((string)$row['email']);if($email!=='')$emailGroups[strtolower($email)][]=(string)$row['id'];
            $username=$this->normalizeUsername($row['username']);
            if($username==='')$username=$this->normalizeUsername('legacy.hr.'.$legacyId);
            $usernameGroups[$username][]=(string)$row['id'];
        }
        foreach($emailGroups as $key=>$ids)if(count($ids)>1)$this->duplicateEmails[$key]=$ids;
        foreach($usernameGroups as $key=>$ids)if(count($ids)>1)$this->duplicateUsernames[$key]=$ids;
        $this->stats['duplicate_email_users']=array_sum(array_map('count',$this->duplicateEmails));
        foreach($legacyIdGroups as $id=>$ids)if($id===''||count($ids)>1)$this->stats['duplicate_legacy_ids']+=count($ids);

        $candidateTargetUsers=[];
        foreach($this->users as $row){
            $legacyId=(string)$row['id'];$nicKey=NicNormalizer::matchKey(NicNormalizer::normalize($row['user_nic']));
            $candidates=$nicKey!==null?($this->officersByNic[$nicKey]??[]):[];
            $sourceIds=array_values(array_unique(array_column($candidates,'legacy_officer_id')));
            $targets=array_values(array_unique(array_filter(array_column($candidates,'target_officer_id'))));
            if(count($sourceIds)===1&&count($targets)===1)$candidateTargetUsers[$targets[0]][]=$legacyId;
        }

        foreach($this->users as $row)$this->plans[]=$this->planUser($row,$candidateTargetUsers);
        $resolved=[];
        foreach($this->plans as $plan){
            $id=$plan['legacy_id'];$classification=$plan['classification'];
            if(in_array($classification,['MATCHED_EXISTING_TARGET_USER','NEW_LEGACY_USER'],true))$resolved[$id]=true;
            foreach($plan['issues'] as $issue)$this->issues[]=$issue;
        }
        $this->stats['workflow_user_ids_resolved']=count(array_intersect_key($this->workflowIds,$resolved));
        $this->stats['workflow_user_ids_unresolved']=$this->stats['workflow_user_ids_referenced']-$this->stats['workflow_user_ids_resolved'];
        foreach($this->workflow as &$field){
            $ids=$this->workflowFieldIds($field['source_table'],$field['field']);$field['resolved']=count(array_intersect_key($ids,$resolved));$field['unresolved']=count($ids)-$field['resolved'];
        }unset($field);
        $known=array_fill_keys(array_map(fn($r)=>(string)$r['id'],$this->users),true);$orphans=[];
        foreach($this->contexts as $id=>$rows)if(!isset($known[$id])){$orphans[$id]=true;$this->stats['stale_auxiliary_mapping_rows']+=count($rows);}
        foreach($this->access as $id=>$rows)if(!isset($known[$id])){$orphans[$id]=true;$this->stats['stale_auxiliary_mapping_rows']+=count($rows);}
        $this->stats['orphan_user_mapping_ids']=count($orphans);
        foreach(array_keys($orphans) as $id)$this->issues[]=$this->issue((string)$id,'STALE_AUXILIARY_MAPPING','WARNING','Auxiliary legacy mapping refers to an ID absent from tbl_user; no identity or authorization record will be created.',['legacy_user_id'=>(string)$id],'STALE_AUXILIARY_MAPPING');
        $this->stats['warnings']=count(array_filter($this->issues,fn($i)=>$i['severity']==='WARNING'));
        $this->stats['errors']=count(array_filter($this->issues,fn($i)=>$i['severity']==='ERROR'));
        $this->stats['critical_blockers']=$this->stats['errors'];
    }

    private function planUser(array $row,array $candidateTargetUsers): array
    {
        $id=trim((string)$row['id']);$issues=[];$classification='NEW_LEGACY_USER';$matchMethod='NEW';$targetUserId=null;$targetOfficerId=null;
        $legacyUsername=trim((string)$row['username']);$username=$legacyUsername;$usernameKey=$this->normalizeUsername($username);
        if($usernameKey===''){
            $this->stats['missing_usernames']++;$this->stats['generated_usernames_used']++;
            $username='legacy.hr.'.$id;$usernameKey=$this->normalizeUsername($username);
            $issues[]=$this->issue($id,'GENERATED_HISTORICAL_USERNAME','WARNING','Legacy username is missing; a deterministic disabled historical username will be used.',['target_username'=>$username]);
        }else{$this->stats['legacy_usernames_used']++;}
        $name=trim((string)$row['user_inname']);$rawEmail=trim((string)$row['email']);$email=null;
        $phone=$this->normalizePhone($row['tp_number']);
        $this->stats['null_email_users']++;
        if($rawEmail===''){$this->stats['missing_emails']++;$issues[]=$this->issue($id,'MISSING_EMAIL','WARNING','Legacy email is missing; target email remains NULL.');}
        else{$this->stats['invalid_emails']++;$issues[]=$this->issue($id,preg_match('/^[a-f0-9]{64}$/i',$rawEmail)===1?'LEGACY_EMAIL_DIGEST':'INVALID_EMAIL','WARNING','Legacy email value is not a recoverable address and is retained only as legacy metadata; target email remains NULL.');}
        if($phone===null){$this->stats['missing_phones']++;$issues[]=$this->issue($id,'MISSING_OR_INVALID_PHONE','WARNING','Legacy phone is absent or invalid; target mobile remains NULL.');}

        if(isset($this->references[$id])){
            $targetUserId=$this->references[$id];
            if(!isset($this->targetUsers[$targetUserId])){$classification='INVALID_SOURCE';$issues[]=$this->issue($id,'BROKEN_LEGACY_REFERENCE','ERROR','Existing legacy user reference points to a missing target user.');}
            else{$classification='MATCHED_EXISTING_TARGET_USER';$matchMethod='LEGACY_REFERENCE';$this->stats['already_migrated']++;}
        }else{
            $nicKey=NicNormalizer::matchKey(NicNormalizer::normalize($row['user_nic']));$officerCandidates=$nicKey!==null?($this->officersByNic[$nicKey]??[]):[];
            $sourceOfficerIds=array_values(array_unique(array_column($officerCandidates,'legacy_officer_id')));
            $targetOfficerIds=array_values(array_unique(array_filter(array_column($officerCandidates,'target_officer_id'))));
            if(count($sourceOfficerIds)>1){$this->stats['ambiguous_officer_mappings']++;$this->stats['manual_review_users']++;$issues[]=$this->issue($id,'AMBIGUOUS_OFFICER_MAPPING','WARNING','Legacy NIC resolves to multiple officer candidates; historical identity will be created without an officer link.',[],'OFFICER_LINK_REVIEW');}
            elseif(count($sourceOfficerIds)===1&&count($targetOfficerIds)===0){$this->stats['missing_officer_mappings']++;$this->stats['manual_review_users']++;$issues[]=$this->issue($id,'MISSING_TARGET_OFFICER_MAPPING','WARNING','Officer candidate has no deterministic target mapping; historical identity will be created without an officer link.',[],'OFFICER_LINK_REVIEW');}
            elseif(count($targetOfficerIds)===1){
                $targetOfficerId=$targetOfficerIds[0];
                if(count($candidateTargetUsers[$targetOfficerId]??[])>1){$targetOfficerId=null;$this->stats['ambiguous_officer_mappings']++;$this->stats['manual_review_users']++;$issues[]=$this->issue($id,'MULTIPLE_USERS_FOR_OFFICER','WARNING','Multiple legacy accounts share the officer candidate; historical identity will be created without an officer link.',[],'OFFICER_LINK_REVIEW');}
                elseif(count($this->targetByOfficer[$targetOfficerId]??[])===1){$targetUserId=$this->targetByOfficer[$targetOfficerId][0];$classification='MATCHED_EXISTING_TARGET_USER';$matchMethod='OFFICER_REFERENCE';}
            }
            if($classification==='NEW_LEGACY_USER'&&$targetUserId===null&&$usernameKey!==''){
                $matches=$this->targetByUsername[$usernameKey]??[];
                if(count($matches)===1){
                    $candidate=$this->targetUsers[$matches[0]];
                    if($targetOfficerId!==null&&!empty($candidate['officer_id'])&&(string)$candidate['officer_id']===(string)$targetOfficerId){$targetUserId=(string)$candidate['id'];$classification='MATCHED_EXISTING_TARGET_USER';$matchMethod='OFFICER_AND_USERNAME';}
                    else{$classification='MANUAL_REVIEW';$this->stats['username_collisions']++;$issues[]=$this->issue($id,'USERNAME_COLLISION_DIFFERENT_PERSON','ERROR','Target username already belongs to a different or unverified identity.');}
                }elseif(count($matches)>1){$classification='MANUAL_REVIEW';$this->stats['username_collisions']++;$issues[]=$this->issue($id,'AMBIGUOUS_USERNAME','ERROR','Normalized username matches multiple target users.');}
            }
        }
        if($id===''||isset($this->duplicateUsernames[$usernameKey])){
            if(isset($this->duplicateUsernames[$usernameKey])){$classification='MANUAL_REVIEW';$this->stats['username_collisions']++;$issues[]=$this->issue($id,'DUPLICATE_SOURCE_USERNAME','ERROR','Normalized username is duplicated in the legacy source.');}
            if($id===''){$classification='INVALID_SOURCE';$this->stats['missing_required_data']++;$issues[]=$this->issue(null,'MISSING_LEGACY_ID','ERROR','Stable legacy primary identity is missing.');}
        }
        if($name===''){$classification='INVALID_SOURCE';$this->stats['missing_required_data']++;$issues[]=$this->issue($id,'MISSING_DISPLAY_NAME','ERROR','Legacy display name is missing.');}
        if($classification==='MATCHED_EXISTING_TARGET_USER'){
            $this->stats['matched_existing_users']++;
            if($targetUserId!==null&&($this->targetUsers[$targetUserId]['account_status']??null)==='DISABLED'&&(int)($this->targetUsers[$targetUserId]['enabled']??1)===0)$this->stats['disabled_users']++;
        }
        elseif($classification==='NEW_LEGACY_USER'){$this->stats['new_users_to_create']++;$this->stats['disabled_users']++;}
        elseif($classification==='INVALID_SOURCE')$this->stats['invalid_source_users']++;
        $willBeOfficerLinked=$classification==='NEW_LEGACY_USER'&&$targetOfficerId!==null;
        if($classification==='MATCHED_EXISTING_TARGET_USER'&&$targetUserId!==null)$willBeOfficerLinked=!empty($this->targetUsers[$targetUserId]['officer_id']);
        if($willBeOfficerLinked)$this->stats['officer_linked_users']++;else $this->stats['users_without_officer_link']++;

        $contextRows=$this->contexts[$id]??[];$accessRows=$this->access[$id]??[];
        if($contextRows===[]){$this->stats['users_without_organization_context']++;$issues[]=$this->issue($id,'MISSING_ORGANIZATION_CONTEXT','WARNING','No deterministic legacy organizational mapping exists; no current scope will be created.');}
        $this->stats['organization_context_rows']+=count($contextRows);$this->stats['legacy_access_metadata_rows']+=count($accessRows);
        foreach($contextRows as $context)if($this->mappedLocation($context)!==null)$this->stats['mapped_location_context_rows']++;
        return ['legacy_id'=>$id,'legacy_username'=>$legacyUsername,'classification'=>$classification,'match_method'=>$matchMethod,'target_user_id'=>$targetUserId,'target_officer_id'=>$targetOfficerId,'username'=>$username,'display_name'=>$name,'email'=>$email,'phone'=>$phone,'row'=>$row,'contexts'=>$contextRows,'access'=>$accessRows,'issues'=>$issues];
    }

    private function executePlans(): void
    {
        foreach(array_chunk($this->plans,$this->batchSize) as $batch){
            foreach($batch as $plan){
                if(!in_array($plan['classification'],['NEW_LEGACY_USER','MATCHED_EXISTING_TARGET_USER'],true)||isset($this->references[$plan['legacy_id']]))continue;
                $this->target->beginTransaction();
                try{
                    $userId=$plan['target_user_id'];
                    if($plan['classification']==='NEW_LEGACY_USER'){
                        $userId=self::uuid();
                        $sql="INSERT INTO system_user(id,officer_id,identity_type,username,display_name,email,email_normalized,mobile,historical_identity,identity_source,password_hash,account_status,enabled,approval_status,password_setup_required,mfa_enrolled,created_at,updated_at,action_reason)
                              VALUES(?,?,'HISTORICAL',?,?,?,?,?,1,?,NULL,'DISABLED',0,'APPROVED',1,0,?,?,?)";
                        $row=$plan['row'];
                        $this->target->prepare($sql)->execute([$userId,$plan['target_officer_id'],$plan['username'],$plan['display_name'],$plan['email'],$plan['email'],$plan['phone'],self::SOURCE_SYSTEM,$this->validDateTime($row['created_at']),$this->validDateTime($row['updated_at']),'Imported historical identity; login and authorization disabled.']);
                        $this->stats['created']++;
                    }
                    $referenceId=$this->insertReference($plan,(string)$userId);
                    $this->insertHistoricalMetadata($referenceId,$plan);
                    $this->stats['mappings_created']++;
                    $this->target->commit();
                }catch(Throwable $e){if($this->target->inTransaction())$this->target->rollBack();throw new RuntimeException("Legacy user {$plan['legacy_id']} failed: {$e->getMessage()}",0,$e);}
            }
        }
    }

    private function insertReference(array $plan,string $userId): int
    {
        $r=$plan['row'];$payload=$r;unset($payload['password']);
        $sql='INSERT INTO legacy_user_reference(source_system,source_table,legacy_user_id,system_user_id,match_method,legacy_username,legacy_display_name,legacy_nic,legacy_email,legacy_phone,legacy_status,legacy_role_id,legacy_role_name,legacy_user_level_id,legacy_user_level_name,legacy_created_by_user_id,legacy_created_at,legacy_updated_at,legacy_payload_json,migration_run_id) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)';
        $this->target->prepare($sql)->execute([self::SOURCE_SYSTEM,self::SOURCE_TABLE,$plan['legacy_id'],$userId,$plan['match_method'],$this->nullText($r['username']),$plan['display_name'],$this->nullText($r['user_nic']),$this->nullText($r['email']),$this->nullText($r['tp_number']),(string)$r['status'],$r['user_role']!==null?(string)$r['user_role']:null,$this->nullText($r['role_name']),$r['user_level']!==null?(string)$r['user_level']:null,$this->nullText($r['user_level_name']),$r['created_location']!==null?(string)$r['created_location']:null,$this->validDateTime($r['created_at']),$this->validDateTime($r['updated_at']),$this->json($payload),$this->runId]);
        return (int)$this->target->lastInsertId();
    }

    private function insertHistoricalMetadata(int $referenceId,array $plan): void
    {
        $contextSql='INSERT INTO legacy_user_organization_context(legacy_user_reference_id,source_table,legacy_mapping_id,legacy_level_key,legacy_location_id,location_id,legacy_status,legacy_payload_json) VALUES(?,?,?,?,?,?,?,?)';
        $stmt=$this->target->prepare($contextSql);
        foreach($plan['contexts'] as $row)$stmt->execute([$referenceId,$row['source_table'],(string)$row['id'],$row['level_key'],$row['location_id']!==null?(string)$row['location_id']:null,$this->mappedLocation($row),(string)$row['status'],$this->json($row)]);
        $accessSql='INSERT INTO legacy_user_access_metadata(legacy_user_reference_id,source_table,legacy_mapping_id,legacy_subject_id,legacy_subject_name,legacy_subject_variable,legacy_status,legacy_payload_json) VALUES(?,?,?,?,?,?,?,?)';
        $stmt=$this->target->prepare($accessSql);
        foreach($plan['access'] as $row)$stmt->execute([$referenceId,'tbl_user_has_subject',(string)$row['auto_id'],(string)$row['subject_id'],$this->nullText($row['subject_name']),$this->nullText($row['variable']),(string)$row['status'],$this->json($row)]);
    }

    private function mappedLocation(array $context): ?string
    {
        $table=$context['location_source_table']??null;$legacy=$context['location_id']??null;
        return $table!==null&&$legacy!==null?($this->locationReferences[$table][(string)$legacy]??null):null;
    }

    private function createRun(): void
    {
        $sql='INSERT INTO legacy_user_migration_run(id,source_system,source_table,status,dry_run,batch_size,source_user_count,existing_target_user_count,existing_reference_count,matched_existing_count,would_create_count,manual_review_count,invalid_source_count,warning_count,error_count) VALUES(?,?,?,\'RUNNING\',0,?,?,?,?,?,?,?,?,?,?)';
        $this->target->prepare($sql)->execute([$this->runId,self::SOURCE_SYSTEM,self::SOURCE_TABLE,$this->batchSize,$this->stats['legacy_user_count'],$this->stats['existing_target_users'],$this->stats['existing_legacy_references'],$this->stats['matched_existing_users'],$this->stats['new_users_to_create'],$this->stats['manual_review_users'],$this->stats['invalid_source_users'],$this->stats['warnings'],$this->stats['errors']]);
    }

    private function persistIssues(): void
    {
        $stmt=$this->target->prepare('INSERT INTO legacy_user_migration_issue(migration_run_id,legacy_user_id,classification,issue_type,severity,message,source_payload_json) VALUES(?,?,?,?,?,?,?)');
        foreach($this->issues as $issue)$stmt->execute([$this->runId,$issue['legacy_user_id'],$issue['classification'],$issue['issue_type'],$issue['severity'],$issue['message'],$this->json($issue['payload'])]);
    }

    private function completeRun(array $summary): void
    {
        $status=$summary['warnings']>0?'COMPLETED_WITH_WARNINGS':'COMPLETED';
        $sql='UPDATE legacy_user_migration_run SET completed_at=NOW(),status=?,created_count=?,mapping_created_count=?,warning_count=?,error_count=?,report_path=?,summary_json=?,zero_write_verification_json=? WHERE id=?';
        $this->target->prepare($sql)->execute([$status,$summary['created'],$summary['mappings_created'],$summary['warnings'],$summary['errors'],$summary['report_path'],$this->json($summary),$this->json($summary['protected_state']),$this->runId]);
    }

    private function failRun(Throwable $e): void
    {
        try{$stmt=$this->target->prepare("UPDATE legacy_user_migration_run SET completed_at=NOW(),status='FAILED',error_count=error_count+1,summary_json=? WHERE id=?");$stmt->execute([$this->json(['error'=>$e->getMessage()]),$this->runId]);}catch(Throwable){}
    }

    private function summary(array $after): array
    {
        $coverage=$this->stats['workflow_user_ids_referenced']===0?100.0:round(($this->stats['workflow_user_ids_resolved']/$this->stats['workflow_user_ids_referenced'])*100,2);
        return $this->stats+[
            'run_id'=>$this->runId,'mode'=>$this->dryRun?'DRY-RUN':'EXECUTE',
            'status'=>$this->stats['critical_blockers']>0?'BLOCKED_BY_RECONCILIATION':($this->stats['warnings']>0?'READY_WITH_WARNINGS':'READY'),
            'workflow_fields'=>$this->workflow,'protected_state'=>['before'=>$this->protectedBefore,'after'=>$after,'unchanged'=>$this->protectedBefore===$after],
            'workflow_coverage_percent'=>$coverage,
            'classifications'=>array_count_values(array_column($this->plans,'classification')),
        ];
    }

    private function writeReports(array $summary): array
    {
        $dir=BASE_PATH.'/storage/reports';if(!is_dir($dir)&&!mkdir($dir,0775,true)&&!is_dir($dir))throw new RuntimeException('Unable to create migration report directory.');
        $stamp=date('Ymd-His');$base=$dir.'/legacy-user-migration-'.$stamp;
        $handle=fopen($base.'.csv','wb');if($handle===false)throw new RuntimeException('Unable to create migration CSV report.');
        fputcsv($handle,['legacy_user_id','legacy_username','target_username','display_name','classification','match_method','target_user_id','target_officer_id','target_email','target_mobile','legacy_status','legacy_role','legacy_level','context_count','access_metadata_count','issue_types','issue_messages']);
        foreach($this->plans as $p){$r=$p['row'];fputcsv($handle,[$p['legacy_id'],$p['legacy_username'],$p['username'],$p['display_name'],$p['classification'],$p['match_method'],$p['target_user_id'],$p['target_officer_id'],$p['email'],$p['phone'],$r['status'],$r['role_name'],$r['user_level_name'],count($p['contexts']),count($p['access']),implode('|',array_column($p['issues'],'issue_type')),implode('|',array_column($p['issues'],'message'))]);}
        fclose($handle);
        file_put_contents($base.'.json',$this->json($summary),LOCK_EX);
        return ['csv'=>$base.'.csv','json'=>$base.'.json'];
    }

    private function protectedState(): array
    {
        $existing=$this->target->query("SELECT id,username,COALESCE(password_hash,''),account_status,enabled,approval_status,COALESCE(officer_id,''),COALESCE(keycloak_subject_id,'') FROM system_user WHERE historical_identity=0 ORDER BY id")->fetchAll(PDO::FETCH_NUM);
        $counts=[];foreach(['application_role','application_permission','application_role_permission','user_account_role','user_account_scope','subject_master','arpa_division_appointment','arpa_subject_assignment'] as $table)$counts[$table]=(int)$this->target->query("SELECT COUNT(*) FROM `{$table}`")->fetchColumn();
        return ['current_users_hash'=>hash('sha256',$this->json($existing)),'current_user_count'=>count($existing),'authorization_and_operational_counts'=>$counts];
    }

    private function assertProtectedState(array $after): void
    {
        if($after!==$this->protectedBefore)throw new RuntimeException('Migration changed an existing current user, authorization table, Subject Master, or ARPA appointment table.');
    }

    private function workflowFieldIds(string $table,string $field): array
    {
        $q=str_replace('`','``',$field);$ids=$this->source->query("SELECT DISTINCT `{$q}` FROM `{$table}` WHERE `{$q}` IS NOT NULL")->fetchAll(PDO::FETCH_COLUMN);return array_fill_keys(array_map('strval',$ids),true);
    }

    private function issue(?string $legacyId,string $type,string $severity,string $message,array $payload=[],?string $classification=null): array
    {
        $classification??=$severity==='ERROR'?'MANUAL_REVIEW':'NEW_LEGACY_USER';
        return ['legacy_user_id'=>$legacyId,'classification'=>$classification,'issue_type'=>$type,'severity'=>$severity,'message'=>$message,'payload'=>$payload];
    }

    private function normalizeUsername(mixed $value): string{return strtolower(trim((string)$value));}
    private function normalizePhone(mixed $value): ?string{$v=trim((string)$value);return $v!==''&&preg_match('/^\+?[0-9]{7,15}$/',$v)===1?$v:null;}
    private function nullText(mixed $value): ?string{$v=trim((string)$value);return $v===''?null:$v;}
    private function validDateTime(mixed $value): ?string{$v=trim((string)$value);if($v===''||str_starts_with($v,'0000-'))return null;$t=strtotime($v);return $t===false?null:date('Y-m-d H:i:s',$t);}
    private function levelKey(string $value): string{return match(strtolower(trim($value))){'district'=>'DISTRICT','agrarian service center'=>'ASC','arpa'=>'ARPA_DIVISION','gn'=>'GN_DIVISION','head office','administrator','admin'=>'NATIONAL',default=>'OTHER'};}
    private function json(mixed $value): string{return json_encode($value,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR);}

    private function tableExists(PDO $pdo,string $table): bool{$s=$pdo->prepare('SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name=?');$s->execute([$table]);return (int)$s->fetchColumn()===1;}
    private function columnExists(PDO $pdo,string $table,string $column): bool{$s=$pdo->prepare('SELECT COUNT(*) FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name=? AND column_name=?');$s->execute([$table,$column]);return (int)$s->fetchColumn()===1;}
    private function columns(PDO $pdo,string $table): array{$s=$pdo->prepare('SELECT column_name FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name=? ORDER BY ordinal_position');$s->execute([$table]);return array_map('strval',$s->fetchAll(PDO::FETCH_COLUMN));}
    private static function uuid(): string{$b=random_bytes(16);$b[6]=chr((ord($b[6])&0x0f)|0x40);$b[8]=chr((ord($b[8])&0x3f)|0x80);return vsprintf('%s%s-%s-%s-%s-%s%s%s',str_split(bin2hex($b),4));}
}
