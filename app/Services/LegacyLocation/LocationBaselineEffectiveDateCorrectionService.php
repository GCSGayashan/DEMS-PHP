<?php
declare(strict_types=1);

namespace App\Services\LegacyLocation;

use App\Services\{ArpaAppointmentReadService,LocationHierarchyEffectiveDatePolicy};
use Closure;
use DomainException;
use PDO;
use RuntimeException;
use Throwable;

/** Controlled correction of imported Location business-effective dates. */
final class LocationBaselineEffectiveDateCorrectionService
{
    private const EXCLUSIVE_RELATIONSHIPS=[
        'PROVINCE_DISTRICT','DISTRICT_DS_DIVISION','DISTRICT_ASC','ASC_ARPA_DIVISION',
    ];
    private const RELATIONSHIP_TYPES=[
        'PROVINCE_DISTRICT'=>['PROVINCE','DISTRICT','ONE'],
        'DISTRICT_DS_DIVISION'=>['DISTRICT','DS_DIVISION','ONE'],
        'DISTRICT_ASC'=>['DISTRICT','ASC','ONE'],
        'DS_DIVISION_ASC'=>['DS_DIVISION','ASC','OPTIONAL_MANY'],
        'ASC_ARPA_DIVISION'=>['ASC','ARPA_DIVISION','ONE'],
        'DS_DIVISION_GN_DIVISION'=>['DS_DIVISION','GN_DIVISION','OPTIONAL_MANY'],
        'ARPA_GN_DIVISION'=>['ARPA_DIVISION','GN_DIVISION','ONE_OR_MORE'],
    ];

    public function __construct(
        private readonly PDO $pdo,
        private readonly PDO $legacy,
        private readonly ?string $reportDirectory=null,
        private readonly ?Closure $faultInjector=null,
    ){}

    public function dryRun(bool $writeReport=true):array
    {
        $before=$this->integrityState();
        $analysis=$this->analyse();
        $after=$this->integrityState();
        if($before!==$after)throw new RuntimeException('Dry-run safety check detected an unexpected database change.');
        $analysis['integrity']['dry_run_target_and_legacy_unchanged']=true;
        $analysis['report_paths']=$writeReport?$this->writeReports($analysis):[];
        return $analysis;
    }

    public function execute(string $executorUserId,string $backupFile):array
    {
        $backup=$this->assertBackup($backupFile);$this->assertExecutor($executorUserId);
        $owned=!$this->pdo->inTransaction();if($owned)$this->pdo->beginTransaction();
        try{
            $this->pdo->query('SELECT id FROM location ORDER BY id FOR UPDATE')->fetchAll(PDO::FETCH_COLUMN);
            $this->pdo->query('SELECT id FROM location_relationship ORDER BY id FOR UPDATE')->fetchAll(PDO::FETCH_COLUMN);
            $this->pdo->query('SELECT id FROM office ORDER BY id FOR UPDATE')->fetchAll(PDO::FETCH_COLUMN);
            $analysis=$this->analyse();
            if($analysis['blockers']['total']>0)throw new DomainException('Location baseline correction is blocked by ambiguous or invalid versions. Run --dry-run and review the blockers.');
            if($analysis['location_master']['would_change']===0&&$analysis['location_relationships']['would_change']===0&&$analysis['offices']['would_change']===0){if($owned)$this->pdo->commit();return ['status'=>'ALREADY_CORRECTED','run_id'=>null,'analysis'=>$analysis];}
            $immutableBefore=$this->immutableState();$runId=$this->uuid();$changedLocations=0;$changedRelationships=0;$changedOffices=0;
            $locationUpdate=$this->pdo->prepare('UPDATE location SET effective_from=?,updated_at=updated_at WHERE id=? AND effective_from=?');
            foreach($analysis['_location_proposals'] as $index=>$proposal){$locationUpdate->execute([LocationHierarchyEffectiveDatePolicy::BASELINE_DATE,$proposal['id'],$proposal['effective_from']]);if($locationUpdate->rowCount()!==1)throw new RuntimeException('A Location changed concurrently during baseline correction.');$changedLocations++;$this->inject('location',$index,$proposal);}
            $relationshipUpdate=$this->pdo->prepare('UPDATE location_relationship SET effective_from=? WHERE id=? AND effective_from=?');
            foreach($analysis['_relationship_proposals'] as $index=>$proposal){$relationshipUpdate->execute([LocationHierarchyEffectiveDatePolicy::BASELINE_DATE,$proposal['id'],$proposal['effective_from']]);if($relationshipUpdate->rowCount()!==1)throw new RuntimeException('A Location relationship changed concurrently during baseline correction.');$changedRelationships++;$this->inject('relationship',$index,$proposal);}
            $officeUpdate=$this->pdo->prepare('UPDATE office SET effective_from=?,updated_at=updated_at WHERE id=? AND effective_from=?');
            foreach($analysis['_office_proposals'] as $index=>$proposal){$officeUpdate->execute([LocationHierarchyEffectiveDatePolicy::BASELINE_DATE,$proposal['id'],$proposal['effective_from']]);if($officeUpdate->rowCount()!==1)throw new RuntimeException('An Office changed concurrently during baseline correction.');$changedOffices++;$this->inject('office',$index,$proposal);}
            $post=$this->analyse();
            if($post['location_master']['would_change']!==0||$post['location_relationships']['would_change']!==0||$post['offices']['would_change']!==0||$post['blockers']['total']!==0)throw new RuntimeException('Post-correction idempotency verification failed.');
            if($immutableBefore!==$this->immutableState())throw new RuntimeException('Immutable Location, audit, appointment, access, or legacy data changed unexpectedly.');
            $details=['baseline_date'=>LocationHierarchyEffectiveDatePolicy::BASELINE_DATE,'location_records_corrected'=>$changedLocations,'relationship_records_corrected'=>$changedRelationships,'office_records_corrected'=>$changedOffices,'location_count'=>$post['location_master']['total'],'relationship_count'=>$post['location_relationships']['total'],'office_count'=>$post['offices']['total'],'backup_file'=>basename($backup['path']),'backup_sha256'=>$backup['sha256'],'post_check'=>['would_change_locations'=>0,'would_change_relationships'=>0,'would_change_offices'=>0,'blockers'=>0]];
            $this->pdo->prepare("INSERT INTO audit_event(actor_user_id,action_key,target_type,target_id,details_json,severity,source_ip) VALUES(?,'organization.baseline-effective-date.correct','ORGANIZATION_BASELINE_CORRECTION',?,?,'HIGH','CLI')")->execute([$executorUserId,$runId,$this->json($details)]);
            if($owned)$this->pdo->commit();
            return ['status'=>'COMPLETED','run_id'=>$runId,'locations_corrected'=>$changedLocations,'relationships_corrected'=>$changedRelationships,'offices_corrected'=>$changedOffices,'post_dry_run'=>$post];
        }catch(Throwable $error){if($owned&&$this->pdo->inTransaction())$this->pdo->rollBack();throw $error;}
    }

    private function analyse():array
    {
        $batchDates=$this->importBatchDates();$blockers=[];
        if(count($batchDates)!==1)$blockers[]=['area'=>'IMPORT_EVIDENCE','reason'=>'Expected exactly one legacy Location import batch date.','values'=>$batchDates];
        $importDate=$batchDates[0]??null;
        [$locationSummary,$locationByType,$locationProposals,$legacyLocationIds,$locationTypes]=$this->analyseLocations($importDate,$blockers);
        [$relationshipSummary,$relationshipByType,$relationshipProposals,$projectedRelationships]=$this->analyseRelationships($importDate,$legacyLocationIds,$blockers);
        $hierarchy=$this->validateHierarchy($locationTypes,$projectedRelationships,$blockers);
        $appointmentIssues=$this->appointmentHierarchyProjection($projectedRelationships);
        [$officeSummary,$officeByType,$officeProposals]=$this->analyseOffices($locationTypes,$blockers);
        $integrity=$this->integrityState();
        return [
            'mode'=>'DRY_RUN_ONLY','baseline_date'=>LocationHierarchyEffectiveDatePolicy::BASELINE_DATE,'generated_at'=>date(DATE_ATOM),'legacy_import_batch_dates'=>$batchDates,
            'location_master'=>$locationSummary+['by_type'=>$locationByType],
            'location_relationships'=>$relationshipSummary+['by_type'=>$relationshipByType],
            'hierarchy_validation'=>$hierarchy,'offices'=>$officeSummary+['by_type'=>$officeByType],'appointment_hierarchy_issues'=>$appointmentIssues,
            'safety'=>['appointment_rows_changed'=>0,'officer_rows_changed'=>0,'user_rows_changed'=>0,'role_scope_rows_changed'=>0,'legacy_source_rows_changed'=>0,'allowed_columns'=>['location.effective_from','location_relationship.effective_from','office.effective_from']],
            'blockers'=>['total'=>count($blockers),'records'=>$blockers],
            'integrity'=>['location_count'=>$integrity['target']['location_count'],'relationship_count'=>$integrity['target']['relationship_count'],'location_dad_hash'=>$integrity['target']['location_dad_hash'],'appointment_state_hash'=>$integrity['target']['appointment_state_hash'],'legacy_state'=>$integrity['legacy']],
            '_location_proposals'=>$locationProposals,'_relationship_proposals'=>$relationshipProposals,'_office_proposals'=>$officeProposals,
        ];
    }

    private function analyseLocations(?string $importDate,array &$blockers):array
    {
        $rows=$this->pdo->query("SELECT l.id,l.dad_number,l.effective_from,l.effective_to,l.approval_status,l.created_at,l.updated_at,l.version,lt.system_key type_key,lt.name_en type_name,COUNT(r.id) legacy_reference_count FROM location l JOIN location_type lt ON lt.id=l.location_type_id LEFT JOIN legacy_location_reference r ON r.location_id=l.id GROUP BY l.id ORDER BY lt.display_order,l.dad_number,l.id")->fetchAll();
        $summary=['total'=>count($rows),'baseline_records_examined'=>0,'already_2024_01_05'=>0,'would_change'=>0,'multiple_version_records'=>0,'later_revisions_preserved'=>0,'excluded_nonbaseline_records'=>0,'blockers'=>0];$byType=[];$proposals=[];$legacyIds=[];$types=[];$groups=[];
        foreach($rows as $row){$types[$row['id']]=$row['type_key'];$groups[$row['dad_number']][]=$row;if((int)$row['legacy_reference_count']>0)$legacyIds[$row['id']]=true;$byType[$row['type_key']]??=['name'=>$row['type_name'],'total'=>0,'examined'=>0,'already_2024_01_05'=>0,'would_change'=>0,'later_revisions_preserved'=>0,'blockers'=>0];$byType[$row['type_key']]['total']++;}
        foreach($groups as $dad=>$versions){$classification=LocationBaselineVersionPolicy::classify($versions);$first=$classification['first'];$laterVersions=$classification['later'];if(count($versions)>1){$summary['multiple_version_records']+=count($versions);$summary['later_revisions_preserved']+=count($laterVersions);foreach($laterVersions as $later)$byType[$later['type_key']]['later_revisions_preserved']++;if($classification['ambiguous']){$blockers[]=['area'=>'LOCATION','id'=>$first['id'],'dad_number'=>$dad,'reason'=>'Location versions have a tied or overlapping first period.'];$summary['blockers']++;$byType[$first['type_key']]['blockers']++;}}
            $type=$first['type_key'];$imported=(int)$first['legacy_reference_count']>0&&$importDate!==null&&substr((string)$first['created_at'],0,10)===$importDate;
            if($first['approval_status']!=='APPROVED'||!$imported){$summary['excluded_nonbaseline_records']++;continue;}
            $summary['baseline_records_examined']++;$byType[$type]['examined']++;
            if($first['effective_from']<LocationHierarchyEffectiveDatePolicy::BASELINE_DATE||($first['effective_to']!==null&&$first['effective_to']<LocationHierarchyEffectiveDatePolicy::BASELINE_DATE)){$item=['area'=>'LOCATION','id'=>$first['id'],'dad_number'=>$dad,'reason'=>'The first imported Location period cannot safely be extended to the baseline date.'];$blockers[]=$item;$summary['blockers']++;$byType[$type]['blockers']++;continue;}
            if($first['effective_from']===LocationHierarchyEffectiveDatePolicy::BASELINE_DATE){$summary['already_2024_01_05']++;$byType[$type]['already_2024_01_05']++;continue;}
            $summary['would_change']++;$byType[$type]['would_change']++;$proposals[]=['id'=>$first['id'],'dad_number'=>$dad,'type'=>$type,'effective_from'=>$first['effective_from'],'proposed_effective_from'=>LocationHierarchyEffectiveDatePolicy::BASELINE_DATE,'effective_to'=>$first['effective_to'],'created_at'=>$first['created_at'],'updated_at'=>$first['updated_at'],'version'=>$first['version']];
        }
        ksort($byType);return [$summary,$byType,$proposals,$legacyIds,$types];
    }

    private function analyseRelationships(?string $importDate,array $legacyLocationIds,array &$blockers):array
    {
        $rows=$this->pdo->query('SELECT id,parent_location_id,child_location_id,relationship_type,effective_from,effective_to,approval_status,active,created_at FROM location_relationship ORDER BY relationship_type,child_location_id,parent_location_id,effective_from,id')->fetchAll();
        $summary=['total'=>count($rows),'baseline_relationships_examined'=>0,'already_2024_01_05'=>0,'would_change'=>0,'multiple_version_records'=>0,'later_revisions_preserved'=>0,'excluded_nonbaseline_records'=>0,'blockers'=>0];$byType=[];$groups=[];$proposals=[];$projected=[];
        foreach($rows as $row){$row['original_effective_from']=$row['effective_from'];$type=$row['relationship_type'];$byType[$type]??=['total'=>0,'examined'=>0,'already_2024_01_05'=>0,'would_change'=>0,'later_revisions_preserved'=>0,'blockers'=>0];$byType[$type]['total']++;$key=in_array($type,self::EXCLUSIVE_RELATIONSHIPS,true)?$type.'|'.$row['child_location_id']:$type.'|'.$row['parent_location_id'].'|'.$row['child_location_id'];$groups[$key][]=$row;}
        foreach($groups as $versions){$classification=LocationBaselineVersionPolicy::classify($versions);$first=$classification['first'];$laterVersions=$classification['later'];$type=$first['relationship_type'];
            if(count($versions)>1){$summary['multiple_version_records']+=count($versions);$summary['later_revisions_preserved']+=count($laterVersions);$byType[$type]['later_revisions_preserved']+=count($laterVersions);if($classification['ambiguous']){$blockers[]=['area'=>'RELATIONSHIP','id'=>$first['id'],'reason'=>'Relationship versions have a tied or overlapping first period.'];$summary['blockers']++;$byType[$type]['blockers']++;}}
            $imported=isset($legacyLocationIds[$first['parent_location_id']],$legacyLocationIds[$first['child_location_id']])&&$importDate!==null&&substr((string)$first['created_at'],0,10)===$importDate;
            if($first['approval_status']!=='APPROVED'||(int)$first['active']!==1||!$imported){$summary['excluded_nonbaseline_records']++;foreach(array_merge([$first],$laterVersions) as $row)$projected[]=$row;continue;}
            $summary['baseline_relationships_examined']++;$byType[$type]['examined']++;
            if($first['effective_from']<LocationHierarchyEffectiveDatePolicy::BASELINE_DATE||($first['effective_to']!==null&&$first['effective_to']<LocationHierarchyEffectiveDatePolicy::BASELINE_DATE)){$blockers[]=['area'=>'RELATIONSHIP','id'=>$first['id'],'reason'=>'The first imported relationship period cannot safely be extended to the baseline date.'];$summary['blockers']++;$byType[$type]['blockers']++;foreach(array_merge([$first],$laterVersions) as $row)$projected[]=$row;continue;}
            if($first['effective_from']===LocationHierarchyEffectiveDatePolicy::BASELINE_DATE){$summary['already_2024_01_05']++;$byType[$type]['already_2024_01_05']++;}
            else{$summary['would_change']++;$byType[$type]['would_change']++;$proposals[]=['id'=>$first['id'],'relationship_type'=>$type,'parent_location_id'=>$first['parent_location_id'],'child_location_id'=>$first['child_location_id'],'effective_from'=>$first['effective_from'],'proposed_effective_from'=>LocationHierarchyEffectiveDatePolicy::BASELINE_DATE,'effective_to'=>$first['effective_to'],'created_at'=>$first['created_at']];$first['effective_from']=LocationHierarchyEffectiveDatePolicy::BASELINE_DATE;}
            $projected[]=$first;foreach($laterVersions as $later)$projected[]=$later;
        }
        ksort($byType);return [$summary,$byType,$proposals,$projected];
    }

    private function validateHierarchy(array $locationTypes,array $relationships,array &$blockers):array
    {
        $baseline=LocationHierarchyEffectiveDatePolicy::BASELINE_DATE;$parents=[];$compatibility=[];
        foreach($relationships as $row){if($row['approval_status']!=='APPROVED'||(int)$row['active']!==1||$row['effective_from']>$baseline||($row['effective_to']!==null&&$row['effective_to']<$baseline))continue;$type=$row['relationship_type'];$parents[$type][$row['child_location_id']][]=$row['parent_location_id'];$rule=self::RELATIONSHIP_TYPES[$type]??null;if($rule===null){$compatibility[]=['relationship_id'=>$row['id'],'reason'=>'Unknown relationship type '.$type];continue;}if(($locationTypes[$row['parent_location_id']]??null)!==$rule[0]||($locationTypes[$row['child_location_id']]??null)!==$rule[1])$compatibility[]=['relationship_id'=>$row['id'],'reason'=>'Parent or child Location Type does not match '.$type];}
        $checks=[];foreach(self::RELATIONSHIP_TYPES as $relationshipType=>[$parentType,$childType,$cardinality]){$childIds=array_keys(array_filter($locationTypes,fn($type)=>$type===$childType));$missing=0;$one=0;$multiple=0;foreach($childIds as $child){$count=count(array_unique($parents[$relationshipType][$child]??[]));if($count===0)$missing++;elseif($count===1)$one++;else$multiple++;}$checks[$relationshipType]=['parent_type'=>$parentType,'child_type'=>$childType,'rule'=>$cardinality,'children'=>count($childIds),'missing'=>$missing,'one_parent'=>$one,'multiple_parents'=>$multiple];if($cardinality==='ONE'&&($missing>0||$multiple>0))$blockers[]=['area'=>'HIERARCHY','relationship_type'=>$relationshipType,'reason'=>'Required one-parent hierarchy has missing or multiple parents.','missing'=>$missing,'multiple'=>$multiple];if($cardinality==='ONE_OR_MORE'&&$missing>0)$blockers[]=['area'=>'HIERARCHY','relationship_type'=>$relationshipType,'reason'=>'Required hierarchy coverage is missing.','missing'=>$missing];}
        foreach($compatibility as $problem)$blockers[]=['area'=>'HIERARCHY']+$problem;
        return ['checks'=>$checks,'type_compatibility_errors'=>count($compatibility),'missing_required_parents'=>array_sum(array_map(fn($c)=>$c['rule']==='ONE'?$c['missing']:($c['rule']==='ONE_OR_MORE'?$c['missing']:0),$checks)),'ambiguous_required_parents'=>array_sum(array_map(fn($c)=>$c['rule']==='ONE'?$c['multiple_parents']:0,$checks))];
    }

    private function analyseOffices(array $locationTypes,array &$blockers):array
    {
        $rows=$this->pdo->query('SELECT o.id,o.dad_number,o.effective_from,o.effective_to,o.linked_location_id,o.created_at,o.updated_at,o.version,o.operational_status,o.approval_status,ot.system_key office_type FROM office o JOIN office_type ot ON ot.id=o.office_type_id ORDER BY ot.system_key,o.dad_number,o.effective_from,o.id')->fetchAll();
        $summary=['total'=>count($rows),'baseline_records_examined'=>0,'already_2024_01_05'=>0,'would_change'=>0,'multiple_version_records'=>0,'later_revisions_preserved'=>0,'excluded_nonbaseline_records'=>0,'blockers'=>0,'old_to_new_examples'=>[]];$byType=[];$groups=[];$proposals=[];
        foreach($rows as $row){$type=$row['office_type'];$groups[$row['dad_number']][]=$row;$byType[$type]??=['total'=>0,'examined'=>0,'already_2024_01_05'=>0,'would_change'=>0,'later_revisions_preserved'=>0,'blockers'=>0,'current_dates'=>[]];$byType[$type]['total']++;$byType[$type]['current_dates'][$row['effective_from']]=($byType[$type]['current_dates'][$row['effective_from']]??0)+1;}
        foreach($groups as $dad=>$versions){$classification=LocationBaselineVersionPolicy::classify($versions);$first=$classification['first'];$later=$classification['later'];$type=$first['office_type'];if(count($versions)>1){$summary['multiple_version_records']+=count($versions);$summary['later_revisions_preserved']+=count($later);$byType[$type]['later_revisions_preserved']+=count($later);if($classification['ambiguous']){$blockers[]=['area'=>'OFFICE','id'=>$first['id'],'dad_number'=>$dad,'reason'=>'Office versions have a tied or overlapping first period.'];$summary['blockers']++;$byType[$type]['blockers']++;}}
            if($first['approval_status']!=='APPROVED'||$first['operational_status']!=='ACTIVE'){$summary['excluded_nonbaseline_records']++;continue;}
            $expected=$type==='DISTRICT_OFFICE'?'DISTRICT':($type==='ASC_OFFICE'?'ASC':null);$validContext=($type==='HEAD_OFFICE'&&$first['linked_location_id']===null)||($expected!==null&&($locationTypes[$first['linked_location_id']]??null)===$expected);if(!$validContext){$blockers[]=['area'=>'OFFICE','id'=>$first['id'],'dad_number'=>$dad,'reason'=>'Office Type and linked Location do not match the authoritative baseline structure.'];$summary['blockers']++;$byType[$type]['blockers']++;continue;}
            $summary['baseline_records_examined']++;$byType[$type]['examined']++;if($first['effective_from']<LocationHierarchyEffectiveDatePolicy::BASELINE_DATE||($first['effective_to']!==null&&$first['effective_to']<LocationHierarchyEffectiveDatePolicy::BASELINE_DATE)){$blockers[]=['area'=>'OFFICE','id'=>$first['id'],'dad_number'=>$dad,'reason'=>'The first approved Office period cannot safely be extended to the baseline date.'];$summary['blockers']++;$byType[$type]['blockers']++;continue;}
            if($first['effective_from']===LocationHierarchyEffectiveDatePolicy::BASELINE_DATE){$summary['already_2024_01_05']++;$byType[$type]['already_2024_01_05']++;continue;}
            $summary['would_change']++;$byType[$type]['would_change']++;$summary['old_to_new_examples'][$first['effective_from'].' -> '.LocationHierarchyEffectiveDatePolicy::BASELINE_DATE]=($summary['old_to_new_examples'][$first['effective_from'].' -> '.LocationHierarchyEffectiveDatePolicy::BASELINE_DATE]??0)+1;$proposals[]=['id'=>$first['id'],'dad_number'=>$dad,'type'=>$type,'effective_from'=>$first['effective_from'],'proposed_effective_from'=>LocationHierarchyEffectiveDatePolicy::BASELINE_DATE,'effective_to'=>$first['effective_to'],'created_at'=>$first['created_at'],'updated_at'=>$first['updated_at'],'version'=>$first['version']];
        }
        foreach($byType as &$data)ksort($data['current_dates']);unset($data);ksort($byType);ksort($summary['old_to_new_examples']);return [$summary,$byType,$proposals];
    }

    private function appointmentHierarchyProjection(array $relationships):array
    {
        $baseline=LocationHierarchyEffectiveDatePolicy::BASELINE_DATE;$index=[];foreach($relationships as $row){if($row['relationship_type']!=='ASC_ARPA_DIVISION'||$row['approval_status']!=='APPROVED'||(int)$row['active']!==1)continue;$index[$row['parent_location_id'].'|'.$row['child_location_id']][]=$row;}
        $strictBefore=0;$projectedAfter=0;$preBaselineBefore=0;$appointments=$this->pdo->query('SELECT asc_location_id,arpa_division_location_id,effective_from FROM arpa_division_appointment')->fetchAll();foreach($appointments as $appointment){$date=max($appointment['effective_from'],$baseline);$rows=$index[$appointment['asc_location_id'].'|'.$appointment['arpa_division_location_id']]??[];$beforeMatch=false;$afterMatch=false;foreach($rows as $row){$original=$row['original_effective_from'];if($original<=$date&&($row['effective_to']===null||$row['effective_to']>=$date))$beforeMatch=true;if($row['effective_from']<=$date&&($row['effective_to']===null||$row['effective_to']>=$date))$afterMatch=true;}if(!$beforeMatch){$strictBefore++;if($appointment['effective_from']<$baseline)$preBaselineBefore++;}if(!$afterMatch)$projectedAfter++;}
        $applicationBefore=(int)$this->pdo->query("SELECT COUNT(*) FROM ".ArpaAppointmentReadService::issueSource()." q WHERE q.issue_type='APPOINTMENT_OUTSIDE_ASC'")->fetchColumn();return ['appointments_examined'=>count($appointments),'strict_stored_date_issues_before'=>$strictBefore,'application_policy_issues_before'=>$applicationBefore,'pre_baseline_appointments_flagged_before'=>$preBaselineBefore,'projected_issues_after'=>$projectedAfter,'genuine_remaining_mismatches'=>$projectedAfter,'appointment_rows_would_change'=>0];
    }

    private function importBatchDates():array{return $this->pdo->query('SELECT DISTINCT DATE(created_at) FROM legacy_location_reference ORDER BY DATE(created_at)')->fetchAll(PDO::FETCH_COLUMN);}
    private function assertExecutor(string $userId):void{$s=$this->pdo->prepare("SELECT COUNT(DISTINCT p.permission_key) FROM system_user u JOIN user_account_role uar ON uar.user_id=u.id AND uar.active=1 AND uar.approval_status='APPROVED' AND uar.effective_from<=CURRENT_DATE() AND (uar.effective_to IS NULL OR uar.effective_to>=CURRENT_DATE()) JOIN application_role r ON r.id=uar.role_id AND r.role_code='SYSTEM_ADMIN' AND r.active=1 AND r.approval_status='APPROVED' JOIN application_role_permission rp ON rp.role_id=r.id JOIN application_permission p ON p.id=rp.permission_id AND p.permission_key IN('location.edit','office.edit') AND p.active=1 WHERE u.id=? AND u.enabled=1 AND u.account_status='ACTIVE'");$s->execute([$userId]);if((int)$s->fetchColumn()!==2)throw new DomainException('Execution requires an active SYSTEM_ADMIN with location.edit and office.edit permissions.');}
    private function assertBackup(string $path):array{$real=realpath($path);if($real===false||!is_file($real)||filesize($real)<1024)throw new DomainException('Execution requires a confirmed non-empty dems_php backup file.');$repo=strtolower(str_replace('\\','/',realpath(BASE_PATH)?:BASE_PATH));$normalized=strtolower(str_replace('\\','/',$real));if(str_starts_with($normalized,rtrim($repo,'/').'/'))throw new DomainException('The database backup must be stored outside the Git repository.');if((int)filemtime($real)<time()-86400)throw new DomainException('The database backup must be less than 24 hours old.');return ['path'=>$real,'sha256'=>hash_file('sha256',$real)];}
    private function integrityState():array{$target=$this->immutableState();$target['location_effective_hash']=(string)$this->pdo->query("SELECT COALESCE(BIT_XOR(CRC32(CONCAT_WS('|',id,effective_from,COALESCE(effective_to,''),COALESCE(updated_at,'')))),0) FROM location")->fetchColumn();$target['relationship_effective_hash']=(string)$this->pdo->query("SELECT COALESCE(BIT_XOR(CRC32(CONCAT_WS('|',id,effective_from,COALESCE(effective_to,'')))),0) FROM location_relationship")->fetchColumn();$target['office_effective_hash']=(string)$this->pdo->query("SELECT COALESCE(BIT_XOR(CRC32(CONCAT_WS('|',id,effective_from,COALESCE(effective_to,''),COALESCE(updated_at,'')))),0) FROM office")->fetchColumn();$target['audit_count']=(int)$this->pdo->query('SELECT COUNT(*) FROM audit_event')->fetchColumn();return ['target'=>$target,'legacy'=>['province'=>$this->legacyCount('tbl_province'),'district'=>$this->legacyCount('tbl_district'),'ds'=>$this->legacyCount('tbl_ds'),'asc'=>$this->legacyCount('tbl_asc'),'arpa'=>$this->legacyCount('tbl_arpa'),'gnd'=>$this->legacyCount('tbl_gnd')]];}
    private function immutableState():array{return ['location_count'=>(int)$this->pdo->query('SELECT COUNT(*) FROM location')->fetchColumn(),'location_dad_hash'=>(string)$this->pdo->query("SELECT COALESCE(BIT_XOR(CRC32(CONCAT_WS('|',id,dad_number,COALESCE(official_code,''),name_en,COALESCE(effective_to,''),operational_status,approval_status,COALESCE(created_by,''),created_at,COALESCE(updated_by,''),COALESCE(version,0)))),0) FROM location")->fetchColumn(),'relationship_count'=>(int)$this->pdo->query('SELECT COUNT(*) FROM location_relationship')->fetchColumn(),'relationship_immutable_hash'=>(string)$this->pdo->query("SELECT COALESCE(BIT_XOR(CRC32(CONCAT_WS('|',id,parent_location_id,child_location_id,relationship_type,COALESCE(effective_to,''),approval_status,active,created_at))),0) FROM location_relationship")->fetchColumn(),'office_count'=>(int)$this->pdo->query('SELECT COUNT(*) FROM office')->fetchColumn(),'office_immutable_hash'=>(string)$this->pdo->query("SELECT COALESCE(BIT_XOR(CRC32(CONCAT_WS('|',id,dad_number,office_type_id,COALESCE(linked_location_id,''),name_en,COALESCE(effective_to,''),requested_status,operational_status,approval_status,COALESCE(created_by,''),created_at,COALESCE(updated_by,''),COALESCE(updated_at,''),COALESCE(submitted_by,''),COALESCE(submitted_at,''),COALESCE(approved_by,''),COALESCE(approved_at,''),COALESCE(returned_by,''),COALESCE(returned_at,''),COALESCE(withdrawn_by,''),COALESCE(withdrawn_at,''),version))),0) FROM office")->fetchColumn(),'appointment_state_hash'=>(string)$this->pdo->query("SELECT CONCAT(COUNT(*),':',COALESCE(BIT_XOR(CRC32(CONCAT_WS('|',id,effective_from,COALESCE(origin_metadata_json,''),arpa_division_location_id,asc_location_id))),0)) FROM arpa_division_appointment")->fetchColumn(),'request_state_hash'=>(string)$this->pdo->query("SELECT CONCAT(COUNT(*),':',COALESCE(BIT_XOR(CRC32(CONCAT_WS('|',id,COALESCE(requested_effective_from,''),COALESCE(requested_effective_to,''),COALESCE(origin_metadata_json,'')))),0)) FROM arpa_division_appointment_request")->fetchColumn(),'workflow_state_hash'=>(string)$this->pdo->query("SELECT CONCAT(COUNT(*),':',COALESCE(BIT_XOR(CRC32(CONCAT_WS('|',id,request_id,action,stage,COALESCE(action_at,''),user_id))),0)) FROM arpa_appointment_workflow_action")->fetchColumn(),'officer_state_hash'=>(string)$this->pdo->query("SELECT CONCAT(COUNT(*),':',COALESCE(BIT_XOR(CRC32(CONCAT_WS('|',id,dad_number,COALESCE(updated_at,''),version))),0)) FROM officer")->fetchColumn(),'user_state_hash'=>(string)$this->pdo->query("SELECT CONCAT(COUNT(*),':',COALESCE(BIT_XOR(CRC32(CONCAT_WS('|',id,username,account_status,enabled,COALESCE(updated_at,'')))),0)) FROM system_user")->fetchColumn(),'role_state_hash'=>(string)$this->pdo->query("SELECT CONCAT(COUNT(*),':',COALESCE(BIT_XOR(CRC32(CONCAT_WS('|',id,user_id,role_id,effective_from,COALESCE(effective_to,''),approval_status,active))),0)) FROM user_account_role")->fetchColumn(),'scope_state_hash'=>(string)$this->pdo->query("SELECT CONCAT(COUNT(*),':',COALESCE(BIT_XOR(CRC32(CONCAT_WS('|',id,user_id,scope_type,scope_mode,COALESCE(location_id,''),COALESCE(office_id,''),effective_from,COALESCE(effective_to,''),approval_status,active))),0)) FROM user_account_scope")->fetchColumn(),'reconciliation_decisions'=>(int)$this->pdo->query("SELECT COUNT(*) FROM legacy_arpa_appointment_resolution WHERE resolution_status='CONFIRMED'")->fetchColumn()];}
    private function legacyCount(string $table):int{if(preg_match('/^[a-z0-9_]+$/',$table)!==1)throw new RuntimeException('Invalid legacy table.');return (int)$this->legacy->query("SELECT COUNT(*) FROM `{$table}`")->fetchColumn();}
    private function inject(string $area,int $index,array $proposal):void{if($this->faultInjector!==null)($this->faultInjector)($area,$index+1,$proposal);}
    private function uuid():string{return (string)$this->pdo->query('SELECT UUID()')->fetchColumn();}
    private function json(mixed $value):string{return json_encode($value,JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);}

    private function writeReports(array $analysis):array
    {
        $directory=$this->reportDirectory??BASE_PATH.'/storage/reports';if(!is_dir($directory)&&!mkdir($directory,0770,true)&&!is_dir($directory))throw new RuntimeException('Cannot create Location baseline report directory.');$stamp=date('Ymd-His');$base=rtrim($directory,'/\\').'/organization-baseline-effective-date-correction-dry-run-'.$stamp;$summary=$analysis;unset($summary['_location_proposals'],$summary['_relationship_proposals'],$summary['_office_proposals']);if(file_put_contents($base.'.json',json_encode($summary,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR))===false)throw new RuntimeException('Cannot write organization baseline JSON report.');$handle=fopen($base.'.csv','wb');if(!$handle)throw new RuntimeException('Cannot write organization baseline CSV report.');fputcsv($handle,['record_kind','id','business_key','type','current_effective_from','proposed_effective_from','effective_to','created_at'],',','"','');foreach($analysis['_location_proposals'] as $row)fputcsv($handle,['LOCATION',$row['id'],$row['dad_number'],$row['type'],$row['effective_from'],LocationHierarchyEffectiveDatePolicy::BASELINE_DATE,$row['effective_to'],$row['created_at']],',','"','');foreach($analysis['_relationship_proposals'] as $row)fputcsv($handle,['RELATIONSHIP',$row['id'],$row['parent_location_id'].'>'.$row['child_location_id'],$row['relationship_type'],$row['effective_from'],LocationHierarchyEffectiveDatePolicy::BASELINE_DATE,$row['effective_to'],$row['created_at']],',','"','');foreach($analysis['_office_proposals'] as $row)fputcsv($handle,['OFFICE',$row['id'],$row['dad_number'],$row['type'],$row['effective_from'],LocationHierarchyEffectiveDatePolicy::BASELINE_DATE,$row['effective_to'],$row['created_at']],',','"','');fclose($handle);return ['json'=>$base.'.json','csv'=>$base.'.csv'];
    }
}
