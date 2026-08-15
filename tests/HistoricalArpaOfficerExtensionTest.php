<?php
declare(strict_types=1);

use App\Core\Database;
use App\Core\LegacyDatabase;
use App\Services\LegacyOfficer\HistoricalArpaOfficerExtensionService;

require dirname(__DIR__).'/bootstrap.php';

final class HistoricalArpaOfficerExtensionTest
{
    private int $assertions=0;
    public function run(): int
    {
        $target=Database::pdo();$before=$this->state($target);
        $cfg=config('legacy_database');
        $summary=(new HistoricalArpaOfficerExtensionService(LegacyDatabase::pdo(),$target,true,500,is_string($cfg['officer_effective_from']??null)?$cfg['officer_effective_from']:null))->run();
        $this->same(1145,$summary['historical_extension_population'],'stable historical extension population');
        $this->same(1145,$summary['historical_extension_mapped'],'all extension identities mapped');
        $this->same(0,$summary['historical_extension_unmapped'],'no unmapped extension identities');
        $this->same(0,$summary['would_create'],'idempotent rerun creates no Officer');
        $this->same(0,$summary['would_update'],'idempotent rerun creates no reference');
        $this->same(0,$summary['true_blockers'],'no identity blockers');
        $this->same(850,$summary['class_i'],'Class I source mapping');
        $this->same(125,$summary['class_ii'],'Class II source mapping');
        $this->same(153,$summary['class_iii'],'Class III source mapping');
        $this->same(17,$summary['class_null_select'],'Select grade stays NULL');
        $this->same(1138,$summary['service_permanent'],'service permanent evidence');
        $this->same(6,$summary['service_not_permanent'],'service not-permanent evidence');
        $this->same(1,$summary['service_permanency_unknown'],'unknown service permanency retained');
        $this->same(828,$summary['legacy_active'],'legacy active mapping');
        $this->same(317,$summary['legacy_inactive'],'legacy inactive mapping');
        $this->same($before,$this->state($target),'dry-run performs zero target writes');
        $this->same('OFFICER',$summary['number_category'],'existing enterprise allocator category');
        $this->same('70045',$summary['number_category_code'],'existing enterprise allocator code');
        $this->same(1,(int)$target->query("SELECT COUNT(DISTINCT officer_id) FROM legacy_officer_reference WHERE source_system='AGRARIANADMIN_HR' AND source_table='tbl_officer' AND legacy_officer_id IN ('6290','7154')")->fetchColumn(),'verified source aliases resolve to one Officer');
        $this->same(1,(int)$target->query("SELECT COUNT(*) FROM legacy_officer_identity_consolidation WHERE primary_legacy_officer_id='6290' AND alias_legacy_officer_id='7154'")->fetchColumn(),'identity consolidation is audited');
        $this->same(1,(int)$target->query("SELECT COUNT(*) FROM number_allocation WHERE allocated_number='70045-0006400'")->fetchColumn(),'retired duplicate DAD number remains allocated');
        $this->same('INACTIVE',(string)$target->query("SELECT o.operational_status FROM officer o JOIN legacy_officer_reference r ON r.officer_id=o.id WHERE r.legacy_officer_id='7154' AND r.source_system='AGRARIANADMIN_HR'")->fetchColumn(),'later reliable inactive state retained');
        $this->same('197522100292',(string)$target->query("SELECT o.nic_normalized FROM officer o JOIN legacy_officer_reference r ON r.officer_id=o.id WHERE r.legacy_officer_id='7154' AND r.source_system='AGRARIANADMIN_HR'")->fetchColumn(),'verified NIC retained on consolidated Officer');
        echo "HistoricalArpaOfficerExtensionTest: {$this->assertions} assertions passed.\n";return 0;
    }
    private function state(PDO $pdo): array{$out=[];foreach(['officer','legacy_officer_reference','number_allocation','arpa_division_appointment_request','arpa_division_appointment','arpa_subject_assignment_request','arpa_subject_assignment','arpa_officer_sub_designation_period','system_user','user_account_role','user_account_scope'] as $t)$out[$t]=(int)$pdo->query("SELECT COUNT(*) FROM `{$t}`")->fetchColumn();return $out;}
    private function same(mixed $expected,mixed $actual,string $message): void{$this->assertions++;if($expected!==$actual)throw new RuntimeException("{$message}: expected ".var_export($expected,true).', got '.var_export($actual,true));}
}

exit((new HistoricalArpaOfficerExtensionTest())->run());
