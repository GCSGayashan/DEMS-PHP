<?php
declare(strict_types=1);

namespace App\Services\LegacyAppointment;

use App\Services\ArpaAppointmentLocationPolicy;
use PDO;
use RuntimeException;
use Throwable;

/** Read-only reconciliation for the two legacy ARPA appointment sources. */
final class HistoricalArpaAppointmentDiagnosticService
{
    private const TABLES=['tbl_officer_apoint','tbl_officer_apoint_2026'];
    private const OFFICER_SOURCE='AGRARIANADMIN_HR';
    private const USER_SOURCE='dems_legacy_hr';
    private const ACTORS=['asc_varify_by','asc_approve_by','district_varify_by','district_approve_by','national_varify_by','national_approve_by'];
    private array $rows=[],$officers=[],$arpa=[],$asc=[],$officerRefs=[],$targetOfficers=[],$targetLocations=[],$locationRefs=[],$userRefs=[],$targetUsers=[],$userAsc=[],$reasons=[],$targetReasons=[],$resolutions=[];
    private array $canonical=[],$issues=[],$raw=[],$ruleViolations=[],$missingArpaEvidence=[];
    private array $stats=[],$previewHierarchyCache=[];

    public function __construct(private readonly PDO $source,private readonly PDO $target){}

    public function run(?callable $previewConsumer=null): array
    {
        $this->assertSchema();
        $sourceBefore=$this->sourceState();$targetBefore=$this->targetState();$owns=false;
        try{
            if(!$this->source->inTransaction()){
                $this->source->exec('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ');
                $this->source->exec('SET TRANSACTION READ ONLY');
                $this->source->beginTransaction();$owns=true;
            }
            $this->load();
            $this->analyseRows();
            $dedupe=$this->reconcile();
            $this->buildMissingArpaEvidence();
            $this->rows=[];
            $history=$this->analyseHistory();
            if($previewConsumer!==null)$this->emitPreviewRecords($previewConsumer);
            $summary=$this->buildSummary($dedupe,$history,$sourceBefore,$targetBefore);
            $paths=$this->writeReports($summary);
            $summary['csv_report_path']=$paths['csv'];$summary['json_report_path']=$paths['json'];$summary['special_asc_review_report_path']=$paths['special_asc_review'];$summary['current_conflict_report_path']=$paths['current_conflicts'];$summary['missing_arpa_location_report_path']=$paths['missing_arpa_location'];
            if($owns)$this->source->commit();
            return $summary;
        }catch(Throwable $e){if($owns&&$this->source->inTransaction())$this->source->rollBack();throw $e;}
    }

    public static function appointmentType(mixed $value): ?string
    {
        return match((int)$value){1=>'PERMANENT',2=>'ACTING',3=>'DUTY_COVERING',4=>'ATTEND_TO_DUTY',default=>null};
    }

    public static function workflowState(array $row): string
    {
        $a=(int)$row['asc_approve'];$d=(int)$row['district_approve'];$n=(int)$row['national_approve'];
        if(!in_array($a,[0,1,2],true)||!in_array($d,[0,1,2],true)||!in_array($n,[0,1,2],true))return 'UNKNOWN_LEGACY_STATE';
        if($n===2&&$a===2&&$d===2)return 'NATIONAL_APPROVED';
        if($n===1&&$a===2&&$d===2)return 'NATIONAL_VERIFIED';
        if($n===0&&$a===2&&$d===2)return 'DISTRICT_APPROVED';
        if($n===0&&$a===2&&$d===1)return 'DISTRICT_VERIFIED';
        if($n===0&&$d===0&&$a===2)return 'ASC_APPROVED';
        if($n===0&&$d===0&&$a===1)return 'ASC_VERIFIED';
        if($a===0&&$d===0&&$n===0)return !empty($row['asc_varify_by'])?'REJECTED_OR_RESET':'DRAFT';
        return 'UNKNOWN_LEGACY_STATE';
    }

    private function assertSchema(): void
    {
        $appointment=['auto_id','officer_id','officer_level','duty_type','location','appoint_date','appoint_end_date','appoint_end_reason','asc_approve','district_approve','national_approve','asc_varify_by','asc_approve_by','district_varify_by','district_approve_by','national_varify_by','national_approve_by','status','created_at','updated_at'];
        foreach(self::TABLES as $table)$this->requireColumns($this->source,$table,$appointment);
        $this->requireColumns($this->source,'tbl_officer',['officer_id','sub_designation_id','permanent_or_not','permanented_date','appoint_location_id']);
        $this->requireColumns($this->source,'tbl_arpa',['auto_id','asc_id','arpa_code','arpa_name']);
        $this->requireColumns($this->source,'tbl_asc',['auto_id','dis_id','asc_code','asc_name']);
        $this->requireColumns($this->source,'tbl_district',['auto_id','dis_code','dis_name']);
        $this->requireColumns($this->source,'tbl_reason',['reason_id','reason_name_en']);
        $this->requireColumns($this->source,'tbl_user_has_asc',['user_id','location_id','status']);
        $this->requireColumns($this->target,'legacy_officer_reference',['source_system','source_table','legacy_officer_id','officer_id']);
        $this->requireColumns($this->target,'legacy_location_reference',['source_system','source_table','legacy_id','location_id']);
        $this->requireColumns($this->target,'legacy_user_reference',['source_system','source_table','legacy_user_id','system_user_id']);
        foreach(['arpa_division_appointment_request','arpa_division_appointment','arpa_subject_assignment_request','arpa_subject_assignment','arpa_officer_sub_designation_period','arpa_appointment_workflow_action','arpa_subject_workflow_action'] as $table)if(!$this->tableExists($this->target,$table))throw new RuntimeException("Target table missing: {$table}");
    }

    private function load(): void
    {
        foreach(self::TABLES as $table){
            $rows=$this->source->query("SELECT * FROM `{$table}` ORDER BY auto_id")->fetchAll();
            foreach($rows as &$row)$row['_source_table']=$table;unset($row);
            $this->rows[$table]=$rows;
        }
        foreach($this->source->query('SELECT * FROM tbl_officer')->fetchAll() as $r)$this->officers[(string)$r['officer_id']]=$r;
        $sql='SELECT a.auto_id,a.asc_id,a.arpa_code,a.arpa_name,s.asc_code,s.asc_name,s.dis_id,d.dis_code,d.dis_name FROM tbl_arpa a LEFT JOIN tbl_asc s ON s.auto_id=a.asc_id LEFT JOIN tbl_district d ON d.auto_id=s.dis_id';
        foreach($this->source->query($sql)->fetchAll() as $r)$this->arpa[(string)$r['auto_id']]=$r;
        foreach($this->source->query('SELECT s.auto_id,s.asc_code,s.asc_name,s.dis_id,d.dis_code,d.dis_name FROM tbl_asc s LEFT JOIN tbl_district d ON d.auto_id=s.dis_id')->fetchAll() as $r)$this->asc[(string)$r['auto_id']]=$r;
        foreach($this->source->query('SELECT reason_id,reason_name_en FROM tbl_reason')->fetchAll() as $r)$this->reasons[(string)$r['reason_id']]=trim((string)$r['reason_name_en']);
        foreach($this->source->query('SELECT user_id,location_id,status FROM tbl_user_has_asc WHERE location_id IS NOT NULL')->fetchAll() as $r){
            if((int)$r['status']!==1||!isset($this->asc[(string)$r['location_id']]))continue;
            $this->userAsc[(string)$r['user_id']][(string)$r['location_id']]=true;
        }
        $stmt=$this->target->prepare("SELECT r.legacy_officer_id,r.officer_id,o.dad_number,o.nic,o.name_with_initials,o.full_name_en FROM legacy_officer_reference r JOIN officer o ON o.id=r.officer_id WHERE r.source_system=? AND r.source_table='tbl_officer'");$stmt->execute([self::OFFICER_SOURCE]);
        foreach($stmt->fetchAll() as $r){$this->officerRefs[(string)$r['legacy_officer_id']]=(string)$r['officer_id'];$this->targetOfficers[(string)$r['officer_id']]=$r;}
        $stmt=$this->target->prepare('SELECT source_table,legacy_id,location_id FROM legacy_location_reference WHERE source_system=?');$stmt->execute([self::OFFICER_SOURCE]);
        foreach($stmt->fetchAll() as $r)$this->locationRefs[(string)$r['source_table']][(string)$r['legacy_id']]=(string)$r['location_id'];
        $stmt=$this->target->prepare('SELECT r.legacy_user_id,r.system_user_id,u.username,u.display_name FROM legacy_user_reference r JOIN system_user u ON u.id=r.system_user_id WHERE r.source_system=? AND r.source_table=\'tbl_user\'');$stmt->execute([self::USER_SOURCE]);
        foreach($stmt->fetchAll() as $r){$this->userRefs[(string)$r['legacy_user_id']]=(string)$r['system_user_id'];$this->targetUsers[(string)$r['legacy_user_id']]=['id'=>$r['system_user_id'],'username'=>$r['username'],'display_name'=>$r['display_name']];}
        if($this->tableExists($this->target,'legacy_arpa_appointment_resolution')){
            $sql='SELECT i.item_type,i.reconciled_business_key,r.* FROM legacy_arpa_appointment_resolution r JOIN legacy_arpa_reconciliation_item i ON i.id=r.reconciliation_item_id WHERE i.active=1';
            foreach($this->target->query($sql)->fetchAll() as $r)$this->resolutions[(string)$r['item_type']][(string)$r['reconciled_business_key']]=$r;
        }
        foreach($this->target->query('SELECT system_key,name_en FROM arpa_appointment_end_reason')->fetchAll() as $r)$this->targetReasons[$this->textKey((string)$r['name_en'])]=(string)$r['system_key'];
    }

    private function analyseRows(): void
    {
        $this->raw=['source_rows'=>[],'type_counts'=>[],'location'=>[],'permanency'=>[],'sub'=>[],'flags'=>[],'officer_sets'=>[],'workflow_ids'=>[],'actor_sets'=>[],'workflow_stages'=>[]];
        foreach(self::ACTORS as $f)$this->raw['workflow_stages'][$f]=['rows_with_user'=>0,'distinct_users'=>0,'resolved_users'=>0,'unresolved_users'=>0,'rows_with_timestamp'=>0,'timestamp_source'=>'UNAVAILABLE'];
        foreach($this->rows as $table=>$rows){$this->raw['source_rows'][$table]=count($rows);foreach($rows as $row){
            $a=$this->analyseRow($row);$type=$a['level']==='ARPA_DIVISION'?($a['appointment_type']??'INVALID_DUTY_TYPE'):$a['level'];
            $this->raw['type_counts'][$type]=($this->raw['type_counts'][$type]??0)+1;
            $this->raw['location'][$a['level']][$a['location_resolution']]=($this->raw['location'][$a['level']][$a['location_resolution']]??0)+1;
            $this->raw['permanency'][$a['service_permanency_confidence']]=($this->raw['permanency'][$a['service_permanency_confidence']]??0)+1;
            if($a['level']==='SITHAMU')$this->raw['sub'][$a['sub_designation_id']??'NULL']=($this->raw['sub'][$a['sub_designation_id']??'NULL']??0)+1;
            $flag=$row['asc_approve'].'/'.$row['district_approve'].'/'.$row['national_approve'].'/'.$row['status'].' => '.$a['workflow_state'];$this->raw['flags'][$flag]=($this->raw['flags'][$flag]??0)+1;
            $id=$a['legacy_officer_id'];$this->raw['officer_sets'][$a['level']][$table][$id]=true;$this->raw['officer_sets'][$a['level']]['union'][$id]=true;
            foreach(self::ACTORS as $f){$uid=$a['workflow'][$f]['legacy_user_id'];if($uid===null)continue;$resolved=$a['workflow'][$f]['target_user_id']!==null;$this->raw['workflow_stages'][$f]['rows_with_user']++;$this->raw['actor_sets'][$f][$uid]=$resolved;$this->raw['workflow_ids'][$uid]=$resolved;}
        }}
        foreach(self::ACTORS as $f){$set=$this->raw['actor_sets'][$f]??[];$resolved=count(array_filter($set));$this->raw['workflow_stages'][$f]['distinct_users']=count($set);$this->raw['workflow_stages'][$f]['resolved_users']=$resolved;$this->raw['workflow_stages'][$f]['unresolved_users']=count($set)-$resolved;}
    }

    private function analyseRow(array $row): array
    {
        $level=$this->level((string)$row['officer_level']);$legacyOfficer=(string)$row['officer_id'];$officer=$this->officers[$legacyOfficer]??null;
        $targetOfficer=$this->officerRefs[$legacyOfficer]??null;$from=$this->date($row['appoint_date']);$to=$this->date($row['appoint_end_date']);
        $locationClass=null;$legacyContext=null;$targetContext=null;$contextEvidence=null;
        if($level==='ARPA_DIVISION'){
            $legacyLocation=$this->positiveId($row['location']);$legacyContext=$legacyLocation!==null?($this->arpa[$legacyLocation]??null):null;
            $targetContext=$legacyLocation!==null?($this->locationRefs['tbl_arpa'][$legacyLocation]??null):null;
            $locationClass=$legacyLocation===null?'MISSING':($legacyContext===null?'INVALID':($targetContext===null?'MISSING_TARGET_MAPPING':'EXACT'));
            $contextEvidence=$locationClass==='EXACT'?'appointment.location -> tbl_arpa.auto_id -> legacy_location_reference':null;
        }else{
            [$locationClass,$legacyContext,$targetContext,$contextEvidence]=$this->resolveSubjectAsc($row,$officer);
        }
        $reasonId=$this->positiveId($row['appoint_end_reason']);$reasonText=$reasonId!==null?($this->reasons[$reasonId]??null):null;
        $reasonKey=$reasonText!==null?($this->targetReasons[$this->textKey($reasonText)]??null):null;
        $workflow=[];foreach(self::ACTORS as $field){$legacy=$this->positiveId($row[$field]);$workflow[$field]=['legacy_user_id'=>$legacy,'target_user_id'=>$legacy!==null?($this->userRefs[$legacy]??null):null,'timestamp'=>null,'timestamp_source'=>'UNAVAILABLE_FROM_LEGACY_SOURCE'];}
        [$service,$serviceConfidence]=$this->historicalPermanency($officer,$from);
        return [
            'row'=>$row,'key'=>$row['_source_table'].':'.$row['auto_id'],'source_table'=>$row['_source_table'],'source_id'=>(string)$row['auto_id'],
            'level'=>$level,'appointment_type'=>self::appointmentType($row['duty_type']),'legacy_officer_id'=>$legacyOfficer,'target_officer_id'=>$targetOfficer,
            'legacy_officer_exists'=>$officer!==null,'effective_from'=>$from,'effective_to'=>$to,'location_resolution'=>$locationClass,
            'legacy_context'=>$legacyContext,'target_context_id'=>$targetContext,'context_evidence'=>$contextEvidence,
            'workflow_state'=>self::workflowState($row),'legacy_operational_approval'=>(int)$row['status']===1&&(int)$row['district_approve']===2,
            'workflow'=>$workflow,'legacy_reason_id'=>$reasonId,'legacy_reason_text'=>$reasonText,'target_reason_key'=>$reasonKey,
            'service_permanency'=>$service,'service_permanency_confidence'=>$serviceConfidence,
            'sub_designation_id'=>$officer!==null?$this->positiveId($officer['sub_designation_id']):null,
        ];
    }

    private function resolveSubjectAsc(array $row,?array $officer): array
    {
        $appointmentLocation=$this->positiveId($row['location']);
        if($appointmentLocation!==null&&isset($this->asc[$appointmentLocation]))return ['EXACT',$this->asc[$appointmentLocation],$this->locationRefs['tbl_asc'][$appointmentLocation]??null,'appointment.location -> tbl_asc.auto_id'];
        $candidate=[];$evidence=[];
        foreach(['asc_varify_by','asc_approve_by'] as $field){$uid=$this->positiveId($row[$field]);if($uid===null)continue;foreach(array_keys($this->userAsc[$uid]??[]) as $ascId){$candidate[$ascId]=true;$evidence[]="{$field}:user={$uid}:tbl_user_has_asc.location_id={$ascId}";}}
        if(count($candidate)===1){$ascId=(string)array_key_first($candidate);return ['STRONG_DERIVED',$this->asc[$ascId],$this->locationRefs['tbl_asc'][$ascId]??null,'unique active ASC mapping of recorded ASC workflow actor; '.implode('; ',$evidence)];}
        $current=$officer!==null?$this->positiveId($officer['appoint_location_id']):null;
        if($current!==null&&isset($this->asc[$current]))return ['CURRENT_STATE_ONLY',$this->asc[$current],$this->locationRefs['tbl_asc'][$current]??null,'tbl_officer.appoint_location_id (current-state evidence only)'];
        return ['UNRESOLVED',null,null,count($candidate)>1?'ASC workflow actors resolve to multiple ASC mappings':'no appointment-specific or workflow ASC evidence'];
    }

    private function historicalPermanency(?array $officer,?string $appointmentDate): array
    {
        if($officer===null||$appointmentDate===null)return [null,'UNRESOLVED'];
        $permanentDate=$this->date($officer['permanented_date']);
        if($permanentDate!==null)return [$appointmentDate>=$permanentDate?'PERMANENT_IN_SERVICE':'NOT_PERMANENT_IN_SERVICE','EXACT_PERMANENTED_DATE'];
        $current=strtolower(trim((string)$officer['permanent_or_not']));
        if($current==='no')return ['NOT_PERMANENT_IN_SERVICE','CURRENT_STATE_ONLY'];
        return [null,'UNRESOLVED'];
    }

    private function reconcile(): array
    {
        $old=$this->rows['tbl_officer_apoint'];$new=$this->rows['tbl_officer_apoint_2026'];$usedOld=[];$usedNew=[];$counts=['EXACT_DUPLICATE'=>0,'SAME_APPOINTMENT_CONTINUATION'=>0,'OLD_HISTORY_ONLY'=>0,'2026_ONLY'=>0,'CONFLICT'=>0,'AMBIGUOUS'=>0];
        $newExact=$this->groupIndexes($new,fn($r)=>$this->signature($r,true));
        foreach($old as $oi=>$o){$c=array_values(array_filter($newExact[$this->signature($o,true)]??[],fn($ni)=>!isset($usedNew[$ni])));if(count($c)===1){$ni=$c[0];$usedOld[$oi]=$usedNew[$ni]=true;$counts['EXACT_DUPLICATE']++;$this->addCanonical($o,$new[$ni],'EXACT_DUPLICATE');}elseif(count($c)>1){$usedOld[$oi]=true;foreach($c as $ni)$usedNew[$ni]=true;$counts['AMBIGUOUS']++;$this->addAmbiguous($o,array_map(fn($i)=>$new[$i],$c));}}
        $oldRemaining=$this->remaining($old,$usedOld);$newRemaining=$this->remaining($new,$usedNew);$oldCore=$this->groupIndexes($oldRemaining,fn($r)=>$this->signature($r,false));$newCore=$this->groupIndexes($newRemaining,fn($r)=>$this->signature($r,false));
        foreach($oldCore as $sig=>$ois){$nis=$newCore[$sig]??[];if(count($ois)===1&&count($nis)===1){$oi=$ois[0];$ni=$nis[0];$usedOld[$oi]=$usedNew[$ni]=true;$counts['SAME_APPOINTMENT_CONTINUATION']++;$this->addCanonical($old[$oi],$new[$ni],'SAME_APPOINTMENT_CONTINUATION');}elseif($nis!==[]){foreach($ois as $oi)$usedOld[$oi]=true;foreach($nis as $ni)$usedNew[$ni]=true;$counts['AMBIGUOUS']++;$this->addAmbiguousGroup(array_map(fn($i)=>$old[$i],$ois),array_map(fn($i)=>$new[$i],$nis));}}
        $oldConflict=$this->groupIndexes($this->remaining($old,$usedOld),fn($r)=>$this->conflictSignature($r));$newConflict=$this->groupIndexes($this->remaining($new,$usedNew),fn($r)=>$this->conflictSignature($r));
        foreach($oldConflict as $sig=>$ois){$nis=$newConflict[$sig]??[];if($nis===[])continue;if(count($ois)===1&&count($nis)===1){$oi=$ois[0];$ni=$nis[0];$usedOld[$oi]=$usedNew[$ni]=true;$counts['CONFLICT']++;$this->markReconciliationIssue([$old[$oi],$new[$ni]],'CONFLICT','Same officer/type/start date differs in location or other appointment identity fields.');}else{foreach($ois as $oi)$usedOld[$oi]=true;foreach($nis as $ni)$usedNew[$ni]=true;$counts['AMBIGUOUS']++;$this->addAmbiguousGroup(array_map(fn($i)=>$old[$i],$ois),array_map(fn($i)=>$new[$i],$nis));}}
        foreach($old as $i=>$r)if(!isset($usedOld[$i])){$counts['OLD_HISTORY_ONLY']++;$this->addCanonical($r,null,'OLD_HISTORY_ONLY');}
        foreach($new as $i=>$r)if(!isset($usedNew[$i])){$counts['2026_ONLY']++;$this->addCanonical(null,$r,'2026_ONLY');}
        return $counts;
    }

    private function addCanonical(?array $old,?array $new,string $class): void
    {
        $row=$new??$old;
        if($old!==null&&$new!==null){foreach(['appoint_end_date','appoint_end_reason'] as $f)if($this->blank($new[$f])&&!$this->blank($old[$f]))$row[$f]=$old[$f];}
        $a=$this->analyseRow($row);$a['legacy_location_id']=$this->positiveId($row['location']);$a['workflow_actor_ids']=array_map(fn($v)=>$v['legacy_user_id'],$a['workflow']);unset($a['row'],$a['workflow']);$a['reconciliation']=$class;$a['source_references']=array_values(array_filter([$old!==null?'tbl_officer_apoint:'.$old['auto_id']:null,$new!==null?'tbl_officer_apoint_2026:'.$new['auto_id']:null]));$a['has_2026']=$new!==null;$this->canonical[]=$a;
    }

    private function addAmbiguous(array $old,array $new): void{$this->addAmbiguousGroup([$old],$new);}
    private function addAmbiguousGroup(array $old,array $new): void{$this->markReconciliationIssue(array_merge($old,$new),'AMBIGUOUS','Multiple old/2026 rows share the same deterministic appointment identity.');}
    private function markReconciliationIssue(array $rows,string $type,string $message): void{foreach($rows as $r)$this->issues[]=['record'=>$r['_source_table'].':'.$r['auto_id'],'type'=>$type,'severity'=>'BLOCKER','message'=>$message];}

    private function analyseHistory(): array
    {
        $today=date('Y-m-d');$eligible=[];$projected=[];$currentCandidates=[];$manual=[];$blocker=[];$activationSuppressed=[];
        $history=['ended'=>0,'open'=>0,'end_date_without_reason'=>0,'reason_without_end_date'=>0,'mapped_reasons'=>0,'unmapped_reasons'=>0,'invalid_start_dates'=>0,'canonical_type_counts'=>[],'canonical_location_coverage'=>[],
            'requests_to_create'=>0,'operational_arpa_to_create'=>0,'subject_assignments_to_create'=>0,'sithamu_periods_to_create'=>0,
            'subject_assignments_by_kind'=>array_fill_keys(['AGRARIAN_BANK','SALES_SHOP','SITHAMU'],0),
            'workflow_request_only'=>0,'finally_approved_operational'=>0,'ended_operational'=>0,'rejected_or_incomplete'=>0,
            'legacy_exceptions'=>[],'current_active_conflicts'=>[],'current_active'=>array_fill_keys(['PERMANENT','ACTING','DUTY_COVERING','ATTEND_TO_DUTY','AGRARIAN_BANK','SALES_SHOP','SITHAMU'],0),'records_prevented_from_activation'=>0];
        $blockerReasons=[];$block=function(string $key,string $reason)use(&$blocker,&$blockerReasons):void{$blocker[$key]=true;$blockerReasons[$key][$reason]=true;};
        foreach($this->canonical as $i=>&$a){
            $key='canonical:'.$i;$a['canonical_key']=$key;$businessKey=$this->businessKey($a);$core=true;
            $special=in_array($a['level'],['AGRARIAN_BANK','SALES_SHOP','SITHAMU'],true);
            $specialResolution=$this->resolutions['SPECIAL_ASC'][$businessKey]??null;
            $missingResolution=$this->resolutions['MISSING_ARPA_LOCATION'][$businessKey]??null;
            $specialConfirmed=$specialResolution!==null&&$specialResolution['resolution_status']==='CONFIRMED'&&!empty($specialResolution['selected_target_asc_id']);
            $specialDeterministic=$special&&$a['target_context_id']!==null&&in_array($a['location_resolution'],['EXACT','STRONG_DERIVED','CURRENT_STATE_ONLY'],true);
            $specialPreserved=$specialResolution!==null&&$specialResolution['resolution_status']==='CONFIRMED'&&($specialResolution['activation_decision']??null)==='PRESERVE_HISTORY_ONLY';
            $historicalOnly=$specialPreserved||($special&&!$specialConfirmed&&!$specialDeterministic)||($specialResolution!==null&&$specialResolution['resolution_status']==='UNRESOLVED_HISTORICAL');
            $arpaConfirmed=$missingResolution!==null&&$missingResolution['resolution_status']==='CONFIRMED'&&!empty($missingResolution['selected_target_arpa_id'])&&!empty($missingResolution['selected_target_asc_id']);
            $arpaPreserved=$missingResolution!==null&&$missingResolution['resolution_status']==='CONFIRMED'&&($missingResolution['activation_decision']??null)==='PRESERVE_HISTORY_ONLY';
            if($historicalOnly)$activationSuppressed[$key]=true;
            if($specialConfirmed){$a['resolved_target_context_id']=$specialResolution['selected_target_asc_id'];$a['target_context_id']=$specialResolution['selected_target_asc_id'];}
            if($arpaConfirmed){$a['resolved_target_context_id']=$missingResolution['selected_target_arpa_id'];$a['resolved_target_asc_id']=$missingResolution['selected_target_asc_id'];$a['target_context_id']=$missingResolution['selected_target_arpa_id'];}
            $type=$a['level']==='ARPA_DIVISION'?($a['appointment_type']??'INVALID_DUTY_TYPE'):$a['level'];$history['canonical_type_counts'][$type]=($history['canonical_type_counts'][$type]??0)+1;
            $history['canonical_location_coverage'][$a['level']][$a['location_resolution']]=($history['canonical_location_coverage'][$a['level']][$a['location_resolution']]??0)+1;
            if($a['effective_to']!==null)$history['ended']++;else $history['open']++;
            if($a['effective_from']===null){$history['invalid_start_dates']++;$manual[$key]=true;$block($key,'MISSING_EFFECTIVE_FROM');$core=false;}
            if($a['effective_to']!==null&&$a['legacy_reason_id']===null)$history['end_date_without_reason']++;
            if($a['effective_to']===null&&$a['legacy_reason_id']!==null)$history['reason_without_end_date']++;
            if($a['legacy_reason_id']!==null){if($a['target_reason_key']!==null)$history['mapped_reasons']++;else $history['unmapped_reasons']++;}
            if($a['target_officer_id']===null){$block($key,'MISSING_TARGET_OFFICER');$core=false;}
            if($a['level']==='ARPA_DIVISION'&&$a['location_resolution']!=='EXACT'&&!$arpaConfirmed&&!$arpaPreserved){$block($key,'ARPA_LOCATION_NOT_EXACT');$core=false;}
            if($special&&!$specialConfirmed&&!$specialDeterministic&&!$specialPreserved){$activationSuppressed[$key]=true;if(str_contains(strtolower((string)$a['context_evidence']),'multiple')){$manual[$key]=true;$block($key,'AMBIGUOUS_ASC_IDENTITY');}}
            if($a['target_context_id']===null&&!$historicalOnly){$manual[$key]=true;$core=false;}
            if($a['level']==='ARPA_DIVISION'&&$a['appointment_type']===null){$manual[$key]=true;$core=false;}
            $eligible[$key]=$core;
            if($core)$history['requests_to_create']++;
            $operational=$core&&$a['legacy_operational_approval'];$a['projected_operational']=$operational;
            if($operational){$projected[$key]=true;$history['finally_approved_operational']++;if($a['effective_to']!==null)$history['ended_operational']++;if($a['level']==='ARPA_DIVISION')$history['operational_arpa_to_create']++;else{$history['subject_assignments_to_create']++;$history['subject_assignments_by_kind'][$a['level']]++;if($a['level']==='SITHAMU')$history['sithamu_periods_to_create']++;}}
            elseif($core){$history['workflow_request_only']++;if(in_array($a['workflow_state'],['DRAFT','REJECTED_OR_RESET','UNKNOWN_LEGACY_STATE'],true))$history['rejected_or_incomplete']++;}
            $current=$a['has_2026']&&$a['legacy_operational_approval']&&$a['effective_from']!==null&&$a['effective_from']<=$today&&($a['effective_to']===null||$a['effective_to']>=$today);
            if($current&&$special&&!$specialConfirmed&&!$specialDeterministic&&!$specialPreserved)$activationSuppressed[$key]=true;
            $a['legacy_current_candidate']=$current;if($current)$currentCandidates[$key]=true;
        }unset($a);
        $violations=$this->auditRules($currentCandidates);$this->ruleViolations=$violations;
        $byCanonical=[];foreach($this->canonical as $index=>$row)$byCanonical[$row['canonical_key']]=$index;
        foreach($violations as $v){$bucket=$v['current']?'current_active_conflicts':'legacy_exceptions';$history[$bucket][$v['type']]=($history[$bucket][$v['type']]??0)+1;foreach($v['records'] as $key){if(!$v['current'])continue;$idx=$byCanonical[$key];$businessKey=$this->businessKey($this->canonical[$idx]);$resolution=$this->resolutions['CURRENT_CONFLICT'][$businessKey]??null;$decision=$resolution['activation_decision']??null;if($resolution!==null&&$resolution['resolution_status']==='CONFIRMED'&&$decision==='ACTIVATE_CURRENT')continue;$activationSuppressed[$key]=true;}}
        foreach($this->canonical as &$a){$key=$a['canonical_key'];$a['manual_review']=isset($manual[$key]);$a['true_blocker']=isset($blocker[$key]);if($a['legacy_current_candidate']){if(isset($blocker[$key])||!($eligible[$key]??false)||isset($activationSuppressed[$key]))$history['records_prevented_from_activation']++;else{$type=$a['level']==='ARPA_DIVISION'?$a['appointment_type']:$a['level'];if(isset($history['current_active'][$type]))$history['current_active'][$type]++;}}}unset($a);
        $reasonCounts=[];foreach($blockerReasons as $reasons)foreach(array_keys($reasons) as $reason)$reasonCounts[$reason]=($reasonCounts[$reason]??0)+1;
        $history['records_requiring_manual_review']=count(array_unique(array_merge(array_keys($manual),array_keys($blocker))));$history['true_execution_blocker_records']=count($blocker);$history['true_blocker_reasons']=$reasonCounts;
        return $history;
    }

    private function auditRules(array $currentCandidates): array
    {
        $violations=[];$approved=array_filter($this->canonical,fn($a)=>$a['legacy_operational_approval']&&$a['effective_from']!==null);
        $emit=function(string $type,array $records)use(&$violations,$currentCandidates):void{$keys=array_values(array_unique(array_column($records,'canonical_key')));$currentKeys=array_intersect($keys,array_keys($currentCandidates));$current=count($keys)===1?count($currentKeys)===1:count($currentKeys)===count($keys);$violations[]=['type'=>$type,'records'=>$keys,'current'=>$current];};
        $byArpa=[];$byOfficer=[];foreach($approved as $a){$byOfficer[$a['legacy_officer_id']][]=$a;if($a['level']==='ARPA_DIVISION'&&$a['legacy_location_id']!==null)$byArpa[$a['legacy_location_id']][]=$a;}
        foreach($byArpa as $group)$this->overlapPairs($group,function($a,$b)use($emit){if($a['legacy_officer_id']!==$b['legacy_officer_id'])$emit('ARPA_DIVISION_OVERLAPPING_OFFICERS',[$a,$b]);});
        foreach($byOfficer as $group){
            foreach(['PERMANENT','ACTING','ATTEND_TO_DUTY'] as $type){$typed=array_values(array_filter($group,fn($a)=>$a['level']==='ARPA_DIVISION'&&$a['appointment_type']===$type));$this->overlapPairs($typed,fn($a,$b)=>$emit('OFFICER_MULTIPLE_'.$type,[$a,$b]));}
            $permanent=array_values(array_filter($group,fn($a)=>$a['level']==='ARPA_DIVISION'&&$a['appointment_type']==='PERMANENT'));
            foreach($group as $a)if($a['level']==='ARPA_DIVISION'&&in_array($a['appointment_type'],['ACTING','DUTY_COVERING','ATTEND_TO_DUTY'],true)){
                $has=false;foreach($permanent as $p)if($this->containsDate($p,$a['effective_from'])){$has=true;break;}if(!$has)$emit('DEPENDENT_WITHOUT_PERMANENT',[$a]);
            }
            foreach($group as $a){if($a['service_permanency_confidence']!=='EXACT_PERMANENTED_DATE')continue;if($a['appointment_type']==='ATTEND_TO_DUTY'&&$a['service_permanency']==='PERMANENT_IN_SERVICE')$emit('PERMANENT_SERVICE_WITH_ATTEND_TO_DUTY',[$a]);if($a['appointment_type']==='ACTING'&&$a['service_permanency']==='NOT_PERMANENT_IN_SERVICE')$emit('NOT_PERMANENT_SERVICE_WITH_ACTING',[$a]);}
            $exclusive=array_values(array_filter($group,fn($a)=>in_array($a['level'],['AGRARIAN_BANK','SALES_SHOP','SITHAMU'],true)));
            foreach($exclusive as $e)foreach($group as $other)if($e['canonical_key']!==$other['canonical_key']&&$this->overlap($e,$other))$emit('EXCLUSIVE_SUBJECT_OVERLAP',[$e,$other]);
        }
        return $violations;
    }

    private function buildSummary(array $dedupe,array $history,array $sourceBefore,array $targetBefore): array
    {
        $typeCounts=$this->raw['type_counts'];$location=$this->raw['location'];$officer=[];$workflow=$this->raw['workflow_stages'];$flags=$this->raw['flags'];$permanency=$this->raw['permanency'];$sub=$this->raw['sub'];$sets=$this->raw['officer_sets'];
        foreach(['ARPA_DIVISION','AGRARIAN_BANK','SALES_SHOP','SITHAMU'] as $kind){foreach(self::TABLES as $table)$sets[$kind][$table]??=[];$sets[$kind]['union']??=[];}
        foreach($sets as $kind=>$by){$union=array_keys($by['union']);$mapped=array_filter($union,fn($id)=>isset($this->officerRefs[(string)$id]));$missing=array_diff($union,$mapped);$officer[$kind]=['old_distinct'=>count($by['tbl_officer_apoint']),'2026_distinct'=>count($by['tbl_officer_apoint_2026']),'union_distinct'=>count($union),'mapped_target'=>count($mapped),'missing_target'=>count($missing),'missing_but_exists_in_tbl_officer'=>count(array_filter($missing,fn($id)=>isset($this->officers[(string)$id]))),'missing_legacy_officer_ids'=>array_values($missing)];}
        $allOld=[];$allNew=[];foreach($sets as $by){foreach(array_keys($by['tbl_officer_apoint']??[]) as $id)$allOld[$id]=true;foreach(array_keys($by['tbl_officer_apoint_2026']??[]) as $id)$allNew[$id]=true;}$allUnion=$allOld+$allNew;
        $allMapped=array_filter(array_keys($allUnion),fn($id)=>isset($this->officerRefs[(string)$id]));$allMissing=array_diff(array_keys($allUnion),$allMapped);$officer['ALL_RELEVANT']=['old_distinct'=>count($allOld),'2026_distinct'=>count($allNew),'union_distinct'=>count($allUnion),'mapped_target'=>count($allMapped),'missing_target'=>count($allMissing),'missing_but_exists_in_tbl_officer'=>count(array_filter($allMissing,fn($id)=>isset($this->officers[(string)$id]))),'missing_legacy_officer_ids'=>array_values($allMissing)];
        $workflowIds=$this->raw['workflow_ids'];$resolved=count(array_filter($workflowIds));
        $sourceAfter=$this->sourceState();$targetAfter=$this->targetState();$schemaIssues=$this->targetSchemaIncompatibilities();
        return [
            'mode'=>'DRY-RUN_DIAGNOSTIC','generated_at'=>date(DATE_ATOM),'source'=>['tbl_officer_apoint_rows'=>$this->raw['source_rows']['tbl_officer_apoint'],'tbl_officer_apoint_2026_rows'=>$this->raw['source_rows']['tbl_officer_apoint_2026'],'old_distinct_officers'=>count($allOld),'2026_distinct_officers'=>count($allNew),'union_distinct_officers'=>count($allUnion)],
            'officer_coverage'=>$officer,'type_counts'=>$typeCounts,'location_coverage'=>$location,'deduplication'=>$dedupe,
            'workflow'=>['distinct_actors'=>count($workflowIds),'resolved_actors'=>$resolved,'unresolved_actors'=>count($workflowIds)-$resolved,'coverage_percent'=>count($workflowIds)?round($resolved/count($workflowIds)*100,2):100,'stages'=>$workflow,'flag_combinations'=>$flags,'timestamp_finding'=>'No stage timestamp columns exist. created_at is row creation only; updated_at is automatic last-row-change and is not attributed to a stage.'],
            'service_permanency_reconstruction'=>$permanency,'sithamu_sub_designation_ids'=>$sub,'sithamu_sub_designation_meaning'=>'No master table or old PHP label mapping was found; IDs are retained as unresolved raw metadata.',
            'end_reasons'=>['source_master'=>$this->reasons,'target_mapping_by_source_id'=>array_map(fn($name)=>$this->targetReasons[$this->textKey($name)]??null,$this->reasons)],
            'history_and_projection'=>$history,'reconciliation_issue_rows'=>count($this->issues),'workbench_items'=>$this->buildWorkbenchItems(),
            'target_schema_incompatibilities'=>$schemaIssues,
            'asc_evidence_search'=>[
                'result'=>'NO_ADDITIONAL_APPOINTMENT_SPECIFIC_ASC_SOURCE_FOUND',
                'sources_checked'=>['legacy appointment create/edit/transfer/list PHP','tables and columns referencing officer_id','tbl_user_has_asc','tbl_officer.appoint_location_id','letter/request-related source references'],
                'strong_derived_definition'=>'Exactly one active tbl_user_has_asc mapping across the recorded ASC verifier/approver actors. The actor ID, mapping row, candidate ASC and derivation method are retained.',
                'strong_derived_risk'=>'tbl_user_has_asc has no effective dates. A mapping unique at backup time can be wrong for an earlier action if the workflow actor moved ASC; it is intentionally labelled STRONG_DERIVED, never EXACT.',
                'current_state_policy'=>'A unique tbl_officer.appoint_location_id candidate remains labelled CURRENT_STATE_ONLY. It is deterministic for migration, but does not by itself prove that current operational activation is valid.',
            ],
            'missing_arpa_location_evidence'=>$this->missingArpaEvidence,
            'semantics'=>['duty_type'=>[1=>'PERMANENT',2=>'ACTING',3=>'DUTY_COVERING',4=>'ATTEND_TO_DUTY'],'workflow_flags'=>'0=pending/reset, 1=verified, 2=approved; reject code resets flags to zero, so some zero states are distinguishable only when an actor was recorded.','legacy_operational_rule'=>'status=1 AND district_approve=2, verified in old operational reports; national flags are preserved separately.','special_location_rule'=>'Legacy create code deliberately wrote NULL for Bank/Sales Shop/Sithamu; these are subjects, not locations.'],
            'true_execution_blockers'=>$history['true_execution_blocker_records']+count($this->issues),'global_schema_blockers'=>count($schemaIssues),'zero_write_verification'=>['source_before'=>$sourceBefore,'source_after'=>$sourceAfter,'source_unchanged'=>$sourceBefore===$sourceAfter,'target_before'=>$targetBefore,'target_after'=>$targetAfter,'target_unchanged'=>$targetBefore===$targetAfter],
        ];
    }

    private function writeReports(array $summary): array
    {
        $dir=BASE_PATH.'/storage/reports';if(!is_dir($dir)&&!mkdir($dir,0775,true)&&!is_dir($dir))throw new RuntimeException('Cannot create report directory.');$base=$dir.'/historical-arpa-appointment-diagnostic-'.date('Ymd-His');
        $h=fopen($base.'.csv','wb');if($h===false)throw new RuntimeException('Cannot create CSV report.');
        fputcsv($h,['source_references','reconciliation','legacy_officer_id','target_officer_id','legacy_level','appointment_type','effective_from','effective_to','location_resolution','legacy_context_id','target_context_id','workflow_state','legacy_operational_approval','service_permanency','service_confidence','legacy_reason_id','legacy_reason_text','target_reason_key','sub_designation_id','manual_review','true_blocker'],',','"','');
        foreach($this->canonical as $a)fputcsv($h,[implode('|',$a['source_references']),$a['reconciliation'],$a['legacy_officer_id'],$a['target_officer_id'],$a['level'],$a['appointment_type'],$a['effective_from'],$a['effective_to'],$a['location_resolution'],$a['legacy_context']['auto_id']??null,$a['target_context_id'],$a['workflow_state'],$a['legacy_operational_approval']?1:0,$a['service_permanency'],$a['service_permanency_confidence'],$a['legacy_reason_id'],$a['legacy_reason_text'],$a['target_reason_key'],$a['sub_designation_id'],$a['manual_review']?1:0,$a['true_blocker']?1:0],',','"','');fclose($h);
        $review=$base.'-special-asc-review.csv';$h=fopen($review,'wb');$this->csv($h,['source_references','legacy_officer_id','target_officer_id','dad_number','officer_name','type','effective_from','effective_to','workflow_actors','candidate_legacy_asc_id','candidate_asc_code','candidate_asc_name','candidate_target_asc_id','confidence','derivation_evidence','review_reason']);
        foreach($this->canonical as $a){if(!in_array($a['level'],['AGRARIAN_BANK','SALES_SHOP','SITHAMU'],true)||!in_array($a['location_resolution'],['CURRENT_STATE_ONLY','UNRESOLVED'],true))continue;$to=$this->targetOfficers[$a['target_officer_id']]??[];$ctx=$a['legacy_context']??[];$this->csv($h,[implode('|',$a['source_references']),$a['legacy_officer_id'],$a['target_officer_id'],$to['dad_number']??null,$to['name_with_initials']??($to['full_name_en']??null),$a['level'],$a['effective_from'],$a['effective_to'],json_encode($a['workflow_actor_ids'],JSON_UNESCAPED_SLASHES),$ctx['auto_id']??null,$ctx['asc_code']??null,$ctx['asc_name']??null,$a['target_context_id'],$a['location_resolution'],$a['context_evidence'],$a['location_resolution']==='CURRENT_STATE_ONLY'?'Candidate comes only from current tbl_officer.appoint_location_id; historical validity is unproved.':'No deterministic historical ASC evidence found.']);}fclose($h);
        $conflicts=$base.'-current-conflicts.csv';$h=fopen($conflicts,'wb');$this->csv($h,['conflict_type','source_references','legacy_officer_id','target_officer_id','dad_number','officer_name','appointment_or_subject_type','legacy_location_id','location_name','effective_from','effective_to']);$byKey=[];foreach($this->canonical as $a)$byKey[$a['canonical_key']]=$a;foreach($this->ruleViolations as $v){if(!$v['current'])continue;foreach($v['records'] as $key){$a=$byKey[$key]??null;if($a===null)continue;$to=$this->targetOfficers[$a['target_officer_id']]??[];$this->csv($h,[$v['type'],implode('|',$a['source_references']),$a['legacy_officer_id'],$a['target_officer_id'],$to['dad_number']??null,$to['name_with_initials']??($to['full_name_en']??null),$a['level']==='ARPA_DIVISION'?$a['appointment_type']:$a['level'],$a['legacy_location_id'],$a['legacy_context']['arpa_name']??($a['legacy_context']['asc_name']??null),$a['effective_from'],$a['effective_to']]);}}fclose($h);
        $missing=$base.'-missing-arpa-location.csv';$h=fopen($missing,'wb');$this->csv($h,['source_references','legacy_officer_id','target_officer_id','dad_number','officer_name','effective_from','effective_to','workflow_actors','source_payloads','candidate_other_arpa_locations','reason']);foreach($this->canonical as $a){if($a['level']!=='ARPA_DIVISION'||$a['location_resolution']==='EXACT')continue;$to=$this->targetOfficers[$a['target_officer_id']]??[];$e=$this->missingArpaEvidence[implode('|',$a['source_references'])]??[];$this->csv($h,[implode('|',$a['source_references']),$a['legacy_officer_id'],$a['target_officer_id'],$to['dad_number']??null,$to['name_with_initials']??($to['full_name_en']??null),$a['effective_from'],$a['effective_to'],json_encode($a['workflow_actor_ids'],JSON_UNESCAPED_SLASHES),json_encode($e['source_payloads']??[],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES),json_encode($e['other_arpa_candidates']??[],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES),$e['finding']??'No exact appointment.location mapping.']);}fclose($h);
        file_put_contents($base.'.json',json_encode($summary,JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR),LOCK_EX);return ['csv'=>$base.'.csv','json'=>$base.'.json','special_asc_review'=>$review,'current_conflicts'=>$conflicts,'missing_arpa_location'=>$missing];
    }

    private function csv(mixed $stream,array $fields): void{fputcsv($stream,$fields,',','"','');}

    private function buildMissingArpaEvidence(): void
    {
        $candidates=[];
        foreach($this->rows as $rows)foreach($rows as $row){
            if($this->level((string)$row['officer_level'])!=='ARPA_DIVISION')continue;
            $id=$this->positiveId($row['location']);if($id===null||!isset($this->arpa[$id]))continue;
            $candidates[(string)$row['officer_id']][$id]=['legacy_arpa_id'=>$id,'arpa_code'=>$this->arpa[$id]['arpa_code'],'arpa_name'=>$this->arpa[$id]['arpa_name'],'asc_id'=>$this->arpa[$id]['asc_id'],'source_reference'=>$row['_source_table'].':'.$row['auto_id'],'effective_from'=>$this->date($row['appoint_date'])];
        }
        foreach($this->canonical as $a){
            if($a['level']!=='ARPA_DIVISION'||$a['location_resolution']==='EXACT')continue;
            $key=implode('|',$a['source_references']);$other=array_values($candidates[$a['legacy_officer_id']]??[]);
            $payloads=[];foreach($a['source_references'] as $ref){[$table,$id]=explode(':',$ref,2);foreach($this->rows[$table]??[] as $row)if((string)$row['auto_id']===$id){$copy=$row;unset($copy['_source_table'],$copy['nic'],$copy['password']);$payloads[$ref]=$copy;break;}}
            $this->missingArpaEvidence[$key]=['source_references'=>$a['source_references'],'legacy_officer_id'=>$a['legacy_officer_id'],'target_officer_id'=>$a['target_officer_id'],'effective_from'=>$a['effective_from'],'effective_to'=>$a['effective_to'],'workflow_actor_ids'=>$a['workflow_actor_ids'],'source_payloads'=>$payloads,'other_arpa_candidates'=>$other,'finding'=>$other===[]?'No ARPA location exists on any related appointment row for this officer.':'Other appointment rows contain ARPA locations, but none is appointment-specific evidence for this source row; no candidate is selected.'];
        }
    }

    private function buildWorkbenchItems(): array
    {
        $items=[];$conflicts=[];$byOfficer=[];$conflictOfficers=[];
        foreach($this->ruleViolations as $violation)if($violation['current'])foreach($violation['records'] as $key)$conflicts[$key][$violation['type']]=true;
        foreach($this->canonical as $a)if(isset($conflicts[$a['canonical_key']]))$conflictOfficers[$a['legacy_officer_id']]=true;
        foreach($this->canonical as $a)if(isset($conflictOfficers[$a['legacy_officer_id']]))$byOfficer[$a['legacy_officer_id']][]=$a;
        foreach($this->canonical as $a){
            $special=in_array($a['level'],['AGRARIAN_BANK','SALES_SHOP','SITHAMU'],true);
            $missing=$a['level']==='ARPA_DIVISION'&&$a['location_resolution']!=='EXACT';
            $key=$this->businessKey($a);$refs=$a['source_references'];[$sourceTable,$sourceId]=explode(':',$refs[0],2);
            $actors=[];foreach($a['workflow_actor_ids'] as $field=>$legacyUserId)if($legacyUserId!==null)$actors[$field]=['legacy_user_id'=>$legacyUserId,'target_user'=>$this->targetUsers[$legacyUserId]??null];
            $legacyOfficer=$this->officers[$a['legacy_officer_id']]??[];$context=['source_references'=>$refs,'reconciliation'=>$a['reconciliation'],'officer'=>$this->targetOfficers[$a['target_officer_id']]??null,'legacy_officer_evidence'=>['officer_id'=>$a['legacy_officer_id'],'appoint_location_id'=>$legacyOfficer['appoint_location_id']??null,'permanent_or_not'=>$legacyOfficer['permanent_or_not']??null,'permanented_date'=>$legacyOfficer['permanented_date']??null,'sub_designation_id'=>$legacyOfficer['sub_designation_id']??null],'workflow_actors'=>$actors,'workflow_state'=>$a['workflow_state'],'legacy_operational_approval'=>$a['legacy_operational_approval'],'service_permanency'=>$a['service_permanency'],'service_permanency_source'=>$a['service_permanency_confidence'],'candidate_legacy_context'=>$a['legacy_context'],'candidate_target_location'=>$this->targetLocation($a['target_context_id']),'candidate_evidence'=>$a['context_evidence'],'legacy_current_candidate'=>$a['legacy_current_candidate']];
            if($special){$resolution=$this->resolutions['SPECIAL_ASC'][$key]??null;$confirmed=$resolution!==null&&$resolution['resolution_status']==='CONFIRMED'&&!empty($resolution['selected_target_asc_id']);$preserved=$resolution!==null&&$resolution['resolution_status']==='CONFIRMED'&&($resolution['activation_decision']??null)==='PRESERVE_HISTORY_ONLY';$blocker=!$confirmed&&!$preserved&&str_contains(strtolower((string)$a['context_evidence']),'multiple');$items[]=$this->workbenchItem($key,'SPECIAL_ASC',[$a['level']],$sourceTable,$sourceId,$a,$a['level'],$a['location_resolution'],$a['target_context_id'],null,$context,$blocker);}
            if($missing){$resolution=$this->resolutions['MISSING_ARPA_LOCATION'][$key]??null;$resolved=$resolution!==null&&$resolution['resolution_status']==='CONFIRMED'&&(!empty($resolution['selected_target_arpa_id'])||($resolution['activation_decision']??null)==='PRESERVE_HISTORY_ONLY');$blocker=!$resolved;$context['missing_location_evidence']=$this->missingArpaEvidence[implode('|',$refs)]??null;$items[]=$this->workbenchItem($key,'MISSING_ARPA_LOCATION',['ARPA_LOCATION_NOT_EXACT'],$sourceTable,$sourceId,$a,null,$a['location_resolution'],null,null,$context,$blocker);}
            if(isset($conflicts[$a['canonical_key']])){$context['conflict_types']=array_keys($conflicts[$a['canonical_key']]);$context['officer_appointment_context']=array_map(fn($r)=>['business_key'=>$this->businessKey($r),'source_references'=>$r['source_references'],'level'=>$r['level'],'appointment_type'=>$r['appointment_type'],'effective_from'=>$r['effective_from'],'effective_to'=>$r['effective_to'],'legacy_context'=>$r['legacy_context'],'workflow_state'=>$r['workflow_state'],'service_permanency'=>$r['service_permanency'],'service_permanency_source'=>$r['service_permanency_confidence'],'current'=>$r['legacy_current_candidate']],$byOfficer[$a['legacy_officer_id']]);$legacyAsc=$a['legacy_context']['asc_id']??null;$items[]=$this->workbenchItem($key,'CURRENT_CONFLICT',array_keys($conflicts[$a['canonical_key']]),$sourceTable,$sourceId,$a,null,$a['location_resolution'],$a['level']==='ARPA_DIVISION'&&$legacyAsc!==null?($this->locationRefs['tbl_asc'][(string)$legacyAsc]??null):$a['target_context_id'],$a['level']==='ARPA_DIVISION'?$a['target_context_id']:null,$context,false);}
        }
        return $items;
    }

    private function workbenchItem(string $key,string $itemType,array $issues,string $sourceTable,string $sourceId,array $a,?string $subjectKind,string $confidence,?string $candidateAsc,?string $candidateArpa,array $context,bool $blocker): array
    {
        return ['source_system'=>'dems_legacy_hr','reconciled_business_key'=>$key,'item_type'=>$itemType,'issue_types'=>$issues,'source_references'=>$a['source_references'],'primary_source_table'=>$sourceTable,'primary_source_record_id'=>$sourceId,'legacy_officer_id'=>$a['legacy_officer_id'],'officer_id'=>$a['target_officer_id'],'subject_kind'=>$subjectKind,'appointment_type'=>$a['appointment_type'],'effective_from'=>$a['effective_from'],'effective_to'=>$a['effective_to'],'current_classification'=>$a['legacy_current_candidate']?'CURRENT':'HISTORICAL','source_confidence'=>$confidence,'candidate_asc_id'=>$candidateAsc,'candidate_arpa_id'=>$candidateArpa,'candidate_evidence'=>['method'=>$a['context_evidence'],'legacy_context'=>$a['legacy_context'],'target_context'=>$this->targetLocation($a['target_context_id'])],'context_snapshot'=>$context,'diagnostic_blocker'=>$blocker];
    }

    private function businessKey(array $a): string{return hash('sha256',implode('|',$a['source_references']));}
    private function targetLocation(?string $id): ?array{if($id===null||$id==='')return null;if(array_key_exists($id,$this->targetLocations))return $this->targetLocations[$id];$s=$this->target->prepare('SELECT id,dad_number,name_en,official_code FROM location WHERE id=?');$s->execute([$id]);$this->targetLocations[$id]=$s->fetch()?:null;return $this->targetLocations[$id];}

    private function emitPreviewRecords(callable $consumer): void
    {
        $historical=[];$current=[];
        foreach($this->ruleViolations as $violation)foreach($violation['records'] as $canonicalKey){
            if($violation['current'])$current[$canonicalKey][$violation['type']]=true;
            else $historical[$canonicalKey][$violation['type']]=true;
        }
        foreach($this->canonical as $a){
            $businessKey=$this->businessKey($a);$special=in_array($a['level'],['AGRARIAN_BANK','SALES_SHOP','SITHAMU'],true);
            $specialResolution=$this->resolutions['SPECIAL_ASC'][$businessKey]??null;$humanAsc=$specialResolution!==null&&$specialResolution['resolution_status']==='CONFIRMED'&&!empty($specialResolution['selected_target_asc_id'])?(string)$specialResolution['selected_target_asc_id']:null;$deterministicAsc=$a['target_context_id']!==null&&in_array($a['location_resolution'],['EXACT','STRONG_DERIVED','CURRENT_STATE_ONLY'],true)?(string)$a['target_context_id']:null;$locationResolution=$humanAsc??$deterministicAsc;$specialPreserved=$specialResolution!==null&&$specialResolution['resolution_status']==='CONFIRMED'&&($specialResolution['activation_decision']??null)==='PRESERVE_HISTORY_ONLY';
            $missingResolution=$this->resolutions['MISSING_ARPA_LOCATION'][$businessKey]??null;$arpaResolution=$missingResolution!==null&&$missingResolution['resolution_status']==='CONFIRMED'&&!empty($missingResolution['selected_target_arpa_id'])?(string)$missingResolution['selected_target_arpa_id']:null;
            $ascId=$special?$locationResolution:($this->locationRefs['tbl_asc'][(string)($a['legacy_context']['asc_id']??'')]??null);$arpaId=$a['level']==='ARPA_DIVISION'?($arpaResolution??$a['target_context_id']):null;
            $hierarchy=$this->previewHierarchy($ascId,$arpaId,$a['effective_from']);
            $workflow=[];foreach($a['workflow_actor_ids'] as $field=>$legacyId)$workflow[$field]=['legacy_user_id'=>$legacyId,'target_user'=>$legacyId!==null?($this->targetUsers[(string)$legacyId]??null):null,'timestamp'=>null,'timestamp_source'=>'UNAVAILABLE_FROM_LEGACY_SOURCE'];
            $historicalTypes=array_keys($historical[$a['canonical_key']]??[]);if($a['effective_from']!==null&&$a['effective_to']!==null&&$a['effective_to']<$a['effective_from'])$historicalTypes[]='INVALID_DATE_RANGE';$historicalTypes=array_values(array_unique($historicalTypes));$currentTypes=array_keys($current[$a['canonical_key']]??[]);$blockers=[];
            if($a['effective_from']===null)$blockers[]='MISSING_EFFECTIVE_FROM';
            if($a['level']==='ARPA_DIVISION'&&$a['location_resolution']!=='EXACT'&&$arpaResolution===null)$blockers[]='MISSING_ARPA_LOCATION';
            $sourceScope=count($a['source_references'])===2?'BOTH':(str_starts_with($a['source_references'][0],'tbl_officer_apoint_2026:')?'2026_ONLY':'OLD_ONLY');
            $consumer([
                'reconciled_business_key'=>$businessKey,'source_system'=>'dems_legacy_hr','officer_id'=>$a['target_officer_id'],'legacy_officer_id'=>$a['legacy_officer_id'],'assignment_category'=>$a['level']==='ARPA_DIVISION'?'ARPA_DIVISION':'ASC_FUNCTION','appointment_type'=>$special?null:$a['appointment_type'],'subject_kind'=>$special?$a['level']:null,'service_permanency_snapshot'=>$a['service_permanency'],'service_permanency_source'=>$a['service_permanency_confidence'],'effective_from'=>$a['effective_from'],'effective_to'=>$a['effective_to'],'legacy_reason_id'=>$a['legacy_reason_id'],'legacy_reason_text'=>$a['legacy_reason_text'],'workflow_state'=>$a['workflow_state'],'legacy_operational_approval'=>$a['legacy_operational_approval'],'current_classification'=>$a['legacy_current_candidate']?'CURRENT':'HISTORICAL','reconciliation_class'=>$a['reconciliation'],'source_scope'=>$sourceScope,'location_confidence'=>$a['location_resolution'],'asc_location_id'=>$hierarchy['asc_id'],'arpa_location_id'=>$hierarchy['arpa_id'],'district_location_id'=>$hierarchy['district_id'],'province_location_id'=>$hierarchy['province_id'],'historical_exception'=>$historicalTypes!==[],'historical_exception_types'=>$historicalTypes,'current_conflict'=>$currentTypes!==[],'current_conflict_types'=>$currentTypes,'diagnostic_blocker'=>$blockers!==[],'blocker_types'=>$blockers,'source_references'=>$a['source_references'],'workflow'=>$workflow,'location_provenance'=>['legacy_context'=>$a['legacy_context'],'legacy_location_id'=>$a['legacy_location_id'],'target_context_id'=>$a['target_context_id'],'resolved_target_asc_id'=>$locationResolution,'derivation_method'=>$a['context_evidence'],'source_confidence'=>$a['location_resolution'],'human_resolution'=>$specialResolution??$missingResolution],'source_provenance'=>['source_database'=>'dems_legacy_hr','reconciliation_class'=>$a['reconciliation'],'baseline_date'=>LegacyArpaMigrationPolicy::BASELINE_DATE,'baseline_classification'=>LegacyArpaMigrationPolicy::periodClassification($a['effective_from'],$a['effective_to']),'carried_into_baseline'=>LegacyArpaMigrationPolicy::carriedIntoBaseline($a['effective_from'],$a['effective_to'])],
            ]);
        }
    }

    private function previewHierarchy(?string $ascId,?string $arpaId,?string $effectiveFrom): array
    {
        $date=$effectiveFrom??ArpaAppointmentLocationPolicy::LOCATION_HIERARCHY_BASELINE_DATE;$cacheKey=($ascId??('arpa:'.($arpaId??''))).':'.$date;if(isset($this->previewHierarchyCache[$cacheKey]))return $this->previewHierarchyCache[$cacheKey];
        $policy=new ArpaAppointmentLocationPolicy();
        if($ascId===null&&$arpaId!==null){$parents=$policy->parents($this->target,$arpaId,'ASC_ARPA_DIVISION',$date);$ascId=$parents[0]['id']??null;}
        $districtId=null;$provinceId=null;
        if($ascId!==null){$parents=$policy->parents($this->target,$ascId,'DISTRICT_ASC',$date);$districtId=$parents[0]['id']??null;}
        if($districtId!==null){$parents=$policy->parents($this->target,$districtId,'PROVINCE_DISTRICT',$date);$provinceId=$parents[0]['id']??null;}
        return $this->previewHierarchyCache[$cacheKey]=['asc_id'=>$ascId,'arpa_id'=>$arpaId,'district_id'=>$districtId,'province_id'=>$provinceId];
    }

    private function targetSchemaIncompatibilities(): array
    {
        $issues=[];
        foreach(['arpa_division_appointment_request','arpa_subject_assignment_request'] as $table){
            if(!$this->columnExists($table,'record_origin')||!$this->columnNullable($table,'created_by'))$issues['REQUEST_CREATOR_REQUIRED']="{$table} cannot truthfully retain a LEGACY_IMPORT request with unknown creator.";
        }
        foreach(['arpa_appointment_workflow_action','arpa_subject_workflow_action'] as $table){
            if(!$this->columnExists($table,'record_origin')||!$this->columnExists($table,'timestamp_provenance')||!$this->columnNullable($table,'action_at'))$issues['WORKFLOW_ACTION_TIMESTAMP_REQUIRED']="{$table} cannot preserve an actor with an unavailable legacy action timestamp.";
        }
        foreach(['arpa_division_appointment','arpa_subject_assignment','arpa_division_appointment_closure','arpa_subject_assignment_closure','arpa_officer_sub_designation_period'] as $table){
            if(!$this->columnExists($table,'record_origin')||!$this->columnExists($table,'approval_timestamp_provenance')||!$this->columnNullable($table,'approved_at'))$issues['OPERATIONAL_APPROVAL_TIMESTAMP_REQUIRED']="{$table} cannot represent source-proven approval without inventing approved_at.";
        }
        if(!$this->columnNullable('arpa_division_appointment','service_permanency_snapshot')||!$this->columnExists('arpa_division_appointment','service_permanency_source'))$issues['SERVICE_PERMANENCY_SNAPSHOT_REQUIRED']='Operational ARPA appointments cannot preserve unresolved historical service permanency with provenance.';
        foreach(['legacy_arpa_appointment_business_record','legacy_arpa_appointment_source_reference'] as $table)if(!$this->tableExists($this->target,$table))$issues['SOURCE_PROVENANCE_REQUIRED']="Missing target provenance table {$table}.";
        return $issues;
    }

    private function signature(array $r,bool $exact): string
    {
        $fields=$exact?['officer_id','officer_level','duty_type','location','appoint_date','appoint_end_date','appoint_end_reason','asc_approve','district_approve','national_approve','asc_varify_by','asc_approve_by','district_varify_by','district_approve_by','national_varify_by','national_approve_by','status']:['officer_id','officer_level','duty_type','location','appoint_date'];$v=[];foreach($fields as $f)$v[$f]=$f==='officer_level'?strtolower(trim((string)$r[$f])):$this->scalar($r[$f]);return json_encode($v,JSON_THROW_ON_ERROR);
    }
    private function conflictSignature(array $r): string{return implode('|',[(string)$r['officer_id'],strtolower(trim((string)$r['officer_level'])),(string)$r['duty_type'],(string)$r['appoint_date']]);}
    private function groupIndexes(array $rows,callable $key): array{$out=[];foreach($rows as $i=>$r)$out[$key($r)][]=$i;return $out;}
    private function remaining(array $rows,array $used): array{$out=[];foreach($rows as $i=>$r)if(!isset($used[$i]))$out[$i]=$r;return $out;}
    private function overlapPairs(array $rows,callable $onOverlap): void{$n=count($rows);for($i=0;$i<$n;$i++)for($j=$i+1;$j<$n;$j++)if($this->overlap($rows[$i],$rows[$j]))$onOverlap($rows[$i],$rows[$j]);}
    private function overlap(array $a,array $b): bool{$ae=$a['effective_to']??'9999-12-31';$be=$b['effective_to']??'9999-12-31';return $a['effective_from']<=$be&&$b['effective_from']<=$ae;}
    private function containsDate(array $a,string $date): bool{return $a['effective_from']<=$date&&(($a['effective_to']??'9999-12-31')>=$date);}
    private function level(string $v): string{return match(strtolower(trim($v))){'arpa division'=>'ARPA_DIVISION','agrarian bank'=>'AGRARIAN_BANK','sales shop'=>'SALES_SHOP','sithamu'=>'SITHAMU',default=>'UNKNOWN'};}
    private function date(mixed $v): ?string{$v=trim((string)$v);if($v===''||str_starts_with($v,'0000-'))return null;$d=\DateTimeImmutable::createFromFormat('!Y-m-d',$v);return $d&&$d->format('Y-m-d')===$v?$v:null;}
    private function positiveId(mixed $v): ?string{$v=trim((string)$v);return ctype_digit($v)&&(int)$v>0?(string)(int)$v:null;}
    private function blank(mixed $v): bool{$v=trim((string)$v);return $v===''||$v==='0'||str_starts_with($v,'0000-');}
    private function scalar(mixed $v): mixed{return $v===null?null:(is_string($v)?trim($v):$v);}
    private function textKey(string $v): string{$v=strtolower(trim(str_replace(["\xc2\xa0","ÿ"],' ',$v)));return preg_replace('/[^a-z0-9]+/','',$v)??'';}
    private function sourceState(): array{$out=[];foreach(self::TABLES as $t)$out[$t]=(int)$this->source->query("SELECT COUNT(*) FROM `{$t}`")->fetchColumn();return $out;}
    private function targetState(): array{$out=[];foreach(['officer','system_user','location','arpa_division_appointment_request','arpa_division_appointment','arpa_subject_assignment_request','arpa_subject_assignment','arpa_officer_sub_designation_period','arpa_appointment_workflow_action','arpa_subject_workflow_action'] as $t)$out[$t]=(int)$this->target->query("SELECT COUNT(*) FROM `{$t}`")->fetchColumn();return $out;}
    private function requireColumns(PDO $pdo,string $table,array $columns): void{if(!$this->tableExists($pdo,$table))throw new RuntimeException("Table missing: {$table}");$s=$pdo->prepare('SELECT column_name FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name=?');$s->execute([$table]);$found=array_fill_keys($s->fetchAll(PDO::FETCH_COLUMN),true);foreach($columns as $c)if(!isset($found[$c]))throw new RuntimeException("Column missing: {$table}.{$c}");}
    private function columnExists(string $table,string $column): bool{$s=$this->target->prepare('SELECT COUNT(*) FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name=? AND column_name=?');$s->execute([$table,$column]);return (int)$s->fetchColumn()===1;}
    private function columnNullable(string $table,string $column): bool{$s=$this->target->prepare('SELECT is_nullable FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name=? AND column_name=?');$s->execute([$table,$column]);return strtoupper((string)$s->fetchColumn())==='YES';}
    private function tableExists(PDO $pdo,string $table): bool{$s=$pdo->prepare('SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name=?');$s->execute([$table]);return (int)$s->fetchColumn()===1;}
}
