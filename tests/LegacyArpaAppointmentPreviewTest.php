<?php
declare(strict_types=1);

use App\Core\{DataTableQuery,DataTableRegistry,DataTableRequest,Database,LegacyDatabase};
use App\Services\LegacyAppointment\LegacyArpaAppointmentPreviewService;

require dirname(__DIR__).'/bootstrap.php';

final class LegacyArpaAppointmentPreviewTest
{
    private PDO $pdo;private LegacyArpaAppointmentPreviewService $service;private int $assertions=0;
    public function run(): int
    {
        $this->pdo=Database::pdo();$this->service=new LegacyArpaAppointmentPreviewService(LegacyDatabase::pdo(),$this->pdo);$_SESSION=[];$admin=$this->pdo->query("SELECT su.id FROM system_user su JOIN user_account_role ur ON ur.user_id=su.id JOIN application_role r ON r.id=ur.role_id WHERE r.role_code='SYSTEM_ADMIN' LIMIT 1")->fetchColumn();$_SESSION['user_id']=$admin;
        $this->testPopulation();$this->testDataTableAndFilters();$this->testDetailAndHistory();$this->testReadOnlyGuarantee();echo "LegacyArpaAppointmentPreviewTest: {$this->assertions} assertions passed.\n";return 0;
    }
    private function testPopulation():void
    {
        $summary=$this->service->summary();$this->same(14779,$summary['total'],'reconciled business record total');$this->same(14075,$summary['arpa_division'],'ARPA Division records');$this->same(371,$summary['agrarian_bank'],'Bank records');$this->same(45,$summary['sales_shop'],'Shop records');$this->same(288,$summary['sithamu'],'Sithamu records');$this->same(5167,$summary['pre_baseline_carried_forward'],'carried-forward baseline count');$this->same(9608,$summary['legacy_period'],'2025+ legacy count');$this->same(3,$summary['pre_baseline_history'],'pre-baseline history count');$this->same(4,$summary['blockers'],'only genuine manual-review records remain');$this->same(0,$this->scalar("SELECT COUNT(*) FROM legacy_arpa_appointment_preview WHERE assignment_category='ASC_FUNCTION' AND appointment_type IS NOT NULL"),'special functions never masquerade as appointment types');$this->same(1,$this->scalar("SELECT COUNT(*) FROM application_permission WHERE permission_key='arpa.legacy-preview.view'"),'narrow preview permission exists');$this->same(0,$this->scalar("SELECT COUNT(*) FROM user_account_role ur JOIN system_user u ON u.id=ur.user_id JOIN application_role_permission rp ON rp.role_id=ur.role_id JOIN application_permission p ON p.id=rp.permission_id WHERE u.identity_type='HISTORICAL' AND p.permission_key='arpa.legacy-preview.view'"),'historical users receive no preview permission');
    }
    private function testDataTableAndFilters():void
    {
        $config=DataTableRegistry::definition('legacy-arpa-appointment-preview');$this->same(true,$config['export'],'CSV export enabled');$page=$this->query(['draw'=>7,'length'=>10]);$this->same(7,$page['draw'],'draw preserved');$this->same(14779,$page['recordsTotal'],'DataTable total');$this->same(10,count($page['data']),'server pagination');
        $officer=(string)$this->pdo->query('SELECT o.dad_number FROM legacy_arpa_appointment_preview p JOIN officer o ON o.id=p.officer_id LIMIT 1')->fetchColumn();$this->same(true,$this->query(['length'=>10,'search'=>['value'=>$officer]])['recordsFiltered']>0,'Officer search');
        $asc=(string)$this->pdo->query('SELECT asc_location_id FROM legacy_arpa_appointment_preview WHERE asc_location_id IS NOT NULL LIMIT 1')->fetchColumn();$this->same(true,$this->filtered('asc',$asc)>0,'ASC filter');$this->same(7012,$this->filtered('appointment_type','ACTING'),'appointment type filter');$this->same(371,$this->filtered('assignment_category','AGRARIAN_BANK'),'subject function filter');$this->same(4,$this->filtered('blocker','1'),'blocker filter');$this->same(5167,$this->filtered('baseline_classification','PRE_BASELINE_CARRIED_FORWARD'),'baseline filter');$this->same(6258,$this->filtered('historical_exception','1'),'historical exception filter includes invalid legacy ranges');$this->same(20,$this->filtered('current_conflict','1'),'current conflict filter');$this->same(387,$this->filtered('asc_confidence','STRONG_DERIVED'),'ASC confidence filter');$this->same(5124,$this->filtered('source_scope','BOTH'),'old/2026 source filter includes duplicates and continuations');$this->same(true,$this->filtered('effective_from','2025-01-01')>0,'date filter');
        $exports=iterator_to_array((new DataTableQuery($this->pdo,$config,new DataTableRequest(['filters'=>['assignment_category'=>'SITHAMU']])))->exportRows(),false);$this->same(288,count($exports),'CSV export respects filters');$this->same(true,in_array('Source References',(new DataTableQuery($this->pdo,$config,new DataTableRequest([])))->exportHeaders(),true),'CSV exposes source provenance');
    }
    private function testDetailAndHistory():void
    {
        $key=(string)$this->pdo->query("SELECT reconciled_business_key FROM legacy_arpa_appointment_preview WHERE source_scope='BOTH' AND historical_exception=1 LIMIT 1")->fetchColumn();$record=$this->service->record($key);$this->same(2,count($record['source_references_json']),'detail preserves old and 2026 references');$this->same(true,$record['historical_exception_types_json']!==[],'historical exceptions displayed');$this->same('2025-01-01',$record['baseline_date'],'detail displays baseline date');$this->same(true,in_array($record['baseline_classification'],['PRE_BASELINE_CARRIED_FORWARD','LEGACY_PERIOD','PRE_BASELINE_HISTORY'],true),'detail classifies baseline period');$this->same(true,count($record['source_provenance_json']['source_records'])===2,'detail loads original source flags read-only');
        $workflow=(string)$this->pdo->query("SELECT reconciled_business_key FROM legacy_arpa_appointment_preview WHERE JSON_EXTRACT(workflow_json,'$.asc_varify_by.legacy_user_id') IS NOT NULL LIMIT 1")->fetchColumn();$wf=$this->service->record($workflow)['workflow_json']['asc_varify_by'];$this->same(true,!empty($wf['target_user']['id']),'workflow user resolves to historical system_user');$this->same(null,$wf['timestamp'],'missing timestamp remains NULL');$this->same('UNAVAILABLE_FROM_LEGACY_SOURCE',$wf['timestamp_source'],'timestamp provenance is truthful');
        $history=$this->service->officerHistory($record['officer_id']);$this->same(true,count($history)>0,'Officer history view returns chronological records');$this->same(true,array_reduce($history,fn($ok,$r)=>$ok&&$r['reconciled_business_key']!=='',true),'Officer history links to details');
        $blocked=(string)$this->pdo->query("SELECT reconciled_business_key FROM legacy_arpa_appointment_preview WHERE diagnostic_blocker=1 LIMIT 1")->fetchColumn();$this->same(true,!empty($this->service->record($blocked)['review_item_id']),'blocked detail links to reconciliation workbench');
    }
    private function testReadOnlyGuarantee():void
    {
        $before=$this->state();$legacyBefore=$this->legacyState();$resolutionBefore=$this->scalar('SELECT COUNT(*) FROM legacy_arpa_appointment_resolution');$key=(string)$this->pdo->query('SELECT reconciled_business_key FROM legacy_arpa_appointment_preview LIMIT 1')->fetchColumn();$this->service->record($key);$this->service->officerHistory((string)$this->pdo->query('SELECT officer_id FROM legacy_arpa_appointment_preview LIMIT 1')->fetchColumn());$this->service->summary();$this->same($before,$this->state(),'preview reads create no operational records');$this->same($legacyBefore,$this->legacyState(),'preview does not modify legacy source');$this->same($resolutionBefore,$this->scalar('SELECT COUNT(*) FROM legacy_arpa_appointment_resolution'),'preview does not modify reconciliation decisions');
    }
    private function query(array $input):array{return (new DataTableQuery($this->pdo,DataTableRegistry::definition('legacy-arpa-appointment-preview'),new DataTableRequest($input)))->response();}
    private function filtered(string $name,string $value):int{return $this->query(['length'=>10,'filters'=>[$name=>$value]])['recordsFiltered'];}
    private function state():array{$out=[];foreach(['arpa_division_appointment_request','arpa_division_appointment','arpa_subject_assignment_request','arpa_subject_assignment','arpa_officer_sub_designation_period'] as $table)$out[$table]=$this->scalar("SELECT COUNT(*) FROM {$table}");return $out;}
    private function legacyState():array{$pdo=LegacyDatabase::pdo();return [(int)$pdo->query('SELECT COUNT(*) FROM tbl_officer_apoint')->fetchColumn(),(int)$pdo->query('SELECT COUNT(*) FROM tbl_officer_apoint_2026')->fetchColumn()];}
    private function scalar(string $sql):int{return (int)$this->pdo->query($sql)->fetchColumn();}
    private function same(mixed $e,mixed $a,string $m):void{$this->assertions++;if($e!==$a)throw new RuntimeException($m.': expected '.var_export($e,true).', got '.var_export($a,true));}
}
exit((new LegacyArpaAppointmentPreviewTest())->run());
