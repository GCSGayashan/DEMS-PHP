<?php
declare(strict_types=1);

namespace App\Services\LegacyOfficer;

use DateTimeImmutable;
use PDO;
use RuntimeException;
use Throwable;

final class LegacyOfficerPersonnelBackfillService
{
    private const SOURCE_SYSTEM='AGRARIANADMIN_HR';
    private const SOURCE_TABLE='tbl_officer';
    private const FIELDS=[
        'permanent_address'=>['source'=>'residential_address','kind'=>'text'],
        'date_of_birth'=>['source'=>'birth_day','kind'=>'date'],
        'primary_mobile'=>['source'=>'tp_no','kind'=>'phone'],
        'alternative_mobile'=>['source'=>'whatsapp_no','kind'=>'phone'],
        'personal_email'=>['source'=>'email_address','kind'=>'email'],
        'initial_appointment_date'=>['source'=>'first_appoint_date','kind'=>'date'],
        'arpa_service_permanency'=>['source'=>'permanent_or_not','kind'=>'permanency'],
        'service_permanented_date'=>['source'=>'permanented_date','kind'=>'date'],
        'expected_retirement_date'=>['source'=>'pension_date','kind'=>'date'],
    ];

    public function __construct(private readonly PDO $source,private readonly PDO $target,private readonly int $batchSize=500){}

    public function dryRun():array{return $this->buildReport();}

    public function execute():array
    {
        $report=$this->buildReport();
        if($report['true_blockers']>0)throw new RuntimeException("Personnel backfill refused: {$report['true_blockers']} true blockers remain.");
        $runId=(string)$this->target->query('SELECT UUID()')->fetchColumn();
        $this->target->prepare("INSERT INTO legacy_officer_personnel_backfill_run(id,mode,status,target_officers_examined,mapped_officers,would_update_count,warning_count,blocker_count,summary_json,report_path) VALUES(?,'EXECUTE','RUNNING',?,?,?,?,?,?,?)")
            ->execute([$runId,$report['target_officers_examined'],$report['target_officers_mapped'],$report['would_update'],$report['warnings'],$report['true_blockers'],$this->json($report['summary']),$report['report_path']]);
        $updated=0;
        try{
            foreach(array_chunk($report['proposals'],$this->batchSize) as $batch){
                $this->target->beginTransaction();
                foreach($batch as $proposal){
                    if($proposal['applied_fields']!==[]){
                        $sets=[];$values=[];foreach($proposal['applied_fields'] as $field=>$value){$sets[]="`{$field}`=?";$values[]=$value;}$sets[]='version=version+1';$values[]=$proposal['officer_id'];
                        $this->target->prepare('UPDATE officer SET '.implode(',',$sets).' WHERE id=?')->execute($values);$updated++;
                    }
                    $this->target->prepare("INSERT INTO legacy_officer_personnel_provenance(officer_id,backfill_run_id,legacy_officer_ids_json,source_rows_json,resolved_fields_json,conflicting_fields_json,applied_fields_json) VALUES(?,?,?,?,?,?,?)")
                        ->execute([$proposal['officer_id'],$runId,$this->json($proposal['legacy_officer_ids']),$this->json($proposal['source_rows']),$this->json($proposal['resolved_fields']),$this->json($proposal['conflicting_fields']),$this->json($proposal['applied_fields'])]);
                    foreach($proposal['issues'] as $issue)$this->target->prepare("INSERT INTO legacy_officer_personnel_issue(backfill_run_id,officer_id,legacy_officer_ids_json,field_name,issue_type,severity,message,evidence_json) VALUES(?,?,?,?,?,?,?,?)")
                        ->execute([$runId,$proposal['officer_id'],$this->json($proposal['legacy_officer_ids']),$issue['field'],$issue['type'],$issue['severity'],$issue['message'],$this->json($issue['evidence'])]);
                }
                $this->target->commit();
            }
            $status=$report['warnings']>0?'COMPLETED_WITH_WARNINGS':'COMPLETED';
            $this->target->prepare("UPDATE legacy_officer_personnel_backfill_run SET status=?,updated_count=?,completed_at=NOW() WHERE id=?")->execute([$status,$updated,$runId]);
            return ['run_id'=>$runId,'updated'=>$updated,'report'=>$report];
        }catch(Throwable $e){if($this->target->inTransaction())$this->target->rollBack();$this->target->prepare("UPDATE legacy_officer_personnel_backfill_run SET status='FAILED',error_message=?,completed_at=NOW() WHERE id=?")->execute([$e->getMessage(),$runId]);throw $e;}
    }

    private function buildReport():array
    {
        $targets=$this->target->query("SELECT id,dad_number,permanent_address,date_of_birth,primary_mobile,alternative_mobile,personal_email,initial_appointment_date,arpa_service_permanency,service_permanented_date,expected_retirement_date FROM officer ORDER BY dad_number")->fetchAll();
        $refs=$this->target->query("SELECT legacy_officer_id,officer_id FROM legacy_officer_reference WHERE source_system='AGRARIANADMIN_HR' AND source_table='tbl_officer' ORDER BY legacy_officer_id")->fetchAll();
        $refByLegacy=[];$idsByOfficer=[];foreach($refs as $r){$refByLegacy[(string)$r['legacy_officer_id']]=(string)$r['officer_id'];$idsByOfficer[(string)$r['officer_id']][]=(string)$r['legacy_officer_id'];}
        $rowsByOfficer=[];$sourceRows=$this->source->query("SELECT officer_id,residential_address,birth_day,tp_no,whatsapp_no,email_address,first_appoint_date,permanent_or_not,permanented_date,pension_date FROM tbl_officer ORDER BY officer_id")->fetchAll();
        foreach($sourceRows as $row){$targetId=$refByLegacy[(string)$row['officer_id']]??null;if($targetId!==null)$rowsByOfficer[$targetId][]=$row;}
        $emailOwners=[];foreach($rowsByOfficer as $officerId=>$rows)foreach($rows as $row){$email=$this->clean($row['email_address'],'email');if($email!==null)$emailOwners[$email][$officerId]=true;}
        $stats=['target_officers_examined'=>count($targets),'target_officers_mapped'=>0,'addresses_available'=>0,'dob_available'=>0,'dob_invalid'=>0,'telephone_available'=>0,'telephone_invalid'=>0,'whatsapp_available'=>0,'whatsapp_invalid'=>0,'email_valid'=>0,'email_invalid'=>0,'email_duplicate'=>0,'first_appointment_available'=>0,'first_appointment_invalid'=>0,'permanent_in_service'=>0,'not_permanent_in_service'=>0,'permanency_unknown'=>0,'permanented_date_available'=>0,'permanented_date_invalid'=>0,'pension_date_available'=>0,'pension_date_invalid'=>0,'alias_field_conflicts'=>0,'warnings'=>0,'true_blockers'=>0,'would_update'=>0];
        $proposals=[];
        foreach($targets as $target){$officerId=(string)$target['id'];$rows=$rowsByOfficer[$officerId]??[];if($rows===[])continue;$stats['target_officers_mapped']++;$resolved=[];$conflicts=[];$issues=[];
            foreach(self::FIELDS as $field=>$meta){$values=[];$rawNonBlank=0;$invalid=0;foreach($rows as $row){$raw=$row[$meta['source']]??null;if(trim((string)$raw)!=='')$rawNonBlank++;$clean=$this->clean($raw,$meta['kind']);if($clean!==null)$values[$this->comparisonKey($clean,$meta['kind'])]=$clean;elseif(trim((string)$raw)!=='')$invalid++;}
                if($meta['kind']==='email')foreach(array_keys($values) as $key)if(count($emailOwners[$key]??[])>1){unset($values[$key]);$stats['email_duplicate']++;$issues[]=$this->issue($field,'DUPLICATE_SHARED_EMAIL','Legacy email is shared by multiple target Officer identities and was not backfilled.',array_column($rows,$meta['source']));}
                if(count($values)>1){$conflicts[$field]=array_values($values);$stats['alias_field_conflicts']++;$issues[]=$this->issue($field,'ALIAS_FIELD_CONFLICT','Consolidated legacy Officer records contain conflicting valid values; the target value was preserved and no source value was selected.',array_values($values));$resolved[$field]=null;}
                else $resolved[$field]=$values===[]?null:array_values($values)[0];
                $this->coverage($stats,$field,$resolved[$field],$invalid,$rawNonBlank);
                if($invalid>0)$issues[]=$this->issue($field,'INVALID_LEGACY_FIELD','One or more nonblank legacy values are invalid and were omitted.',array_column($rows,$meta['source']));
            }
            $applied=[];foreach($resolved as $field=>$value)if($value!==null&&trim((string)($target[$field]??''))==='')$applied[$field]=$value;
            if($applied!==[])$stats['would_update']++;
            $stats['warnings']+=count($issues);$proposals[]=['officer_id'=>$officerId,'dad_number'=>$target['dad_number'],'legacy_officer_ids'=>$idsByOfficer[$officerId]??[],'source_rows'=>$rows,'resolved_fields'=>$resolved,'conflicting_fields'=>$conflicts,'applied_fields'=>$applied,'issues'=>$issues];
        }
        $missing=count($targets)-$stats['target_officers_mapped'];if($missing>0){$stats['true_blockers']=$missing;}
        $reportPath=$this->writeReport($stats,$proposals);
        return $stats+['summary'=>$stats,'proposals'=>$proposals,'report_path'=>$reportPath];
    }

    private function clean(mixed $value,string $kind):mixed
    {
        $value=trim((string)$value);if($value==='')return null;
        return match($kind){
            'text'=>preg_replace('/\s+/u',' ',$value)?:null,
            'date'=>$this->date($value),
            'phone'=>$this->phone($value),
            'email'=>(strlen($value)<=255&&filter_var(strtolower($value),FILTER_VALIDATE_EMAIL)!==false)?strtolower($value):null,
            'permanency'=>match(strtolower($value)){'yes'=>'PERMANENT_IN_SERVICE','no'=>'NOT_PERMANENT_IN_SERVICE',default=>null},
            default=>null,
        };
    }
    private function date(string $value):?string{if(str_starts_with($value,'0000-00-00'))return null;$d=DateTimeImmutable::createFromFormat('!Y-m-d',substr($value,0,10));return $d!==false&&$d->format('Y-m-d')===substr($value,0,10)?$d->format('Y-m-d'):null;}
    private function phone(string $value):?string{$value=preg_replace('/\s+/',' ',trim($value))??'';$digits=preg_replace('/\D/','',$value)??'';return strlen($value)<=20&&strlen($digits)>=7&&strlen($digits)<=15&&preg_match('/^[+0-9() .-]+$/',$value)===1?$value:null;}
    private function comparisonKey(mixed $value,string $kind):string{return match($kind){'text'=>strtolower(preg_replace('/\s+/u',' ',trim((string)$value))??''),'phone'=>preg_replace('/\D/','',(string)$value)??'','email'=>strtolower((string)$value),default=>(string)$value};}
    private function coverage(array &$s,string $field,mixed $value,int $invalid,int $rawNonBlank):void
    {
        $map=['permanent_address'=>['addresses_available',null],'date_of_birth'=>['dob_available','dob_invalid'],'primary_mobile'=>['telephone_available','telephone_invalid'],'alternative_mobile'=>['whatsapp_available','whatsapp_invalid'],'personal_email'=>['email_valid','email_invalid'],'initial_appointment_date'=>['first_appointment_available','first_appointment_invalid'],'service_permanented_date'=>['permanented_date_available','permanented_date_invalid'],'expected_retirement_date'=>['pension_date_available','pension_date_invalid']];
        if($field==='arpa_service_permanency'){if($value==='PERMANENT_IN_SERVICE')$s['permanent_in_service']++;elseif($value==='NOT_PERMANENT_IN_SERVICE')$s['not_permanent_in_service']++;else $s['permanency_unknown']++;return;}
        if(!isset($map[$field]))return;if($value!==null)$s[$map[$field][0]]++;if($invalid>0&&$map[$field][1]!==null)$s[$map[$field][1]]++;
    }
    private function issue(string $field,string $type,string $message,mixed $evidence):array{return ['field'=>$field,'type'=>$type,'severity'=>'WARNING','message'=>$message,'evidence'=>$evidence];}
    private function writeReport(array $stats,array $proposals):string{$dir=BASE_PATH.'/storage/reports';if(!is_dir($dir))mkdir($dir,0770,true);$path=$dir.'/legacy-officer-personnel-backfill-'.date('Ymd-His').'.json';file_put_contents($path,$this->json(['generated_at'=>date(DATE_ATOM),'summary'=>$stats,'officers'=>$proposals]),LOCK_EX);return $path;}
    private function json(mixed $value):string{return json_encode($value,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_INVALID_UTF8_SUBSTITUTE);}
}
