<?php
declare(strict_types=1);

use App\Core\Database;
use App\Services\NotificationBackfillService;

require dirname(__DIR__).'/bootstrap.php';

final class NotificationBackfillServiceTest
{
    private int $assertions=0;

    public function run():int
    {
        $this->same('arpa.appointment.asc-verify',NotificationBackfillService::arpaActionFor('division','END','SUBMITTED')[0]??null,'END submitted routes to ASC verifier');
        $this->same('arpa.appointment.asc-approve',NotificationBackfillService::arpaActionFor('division','END','ASC_VERIFIED')[0]??null,'END ASC verified routes to ASC approver');
        $this->same(true,NotificationBackfillService::isArpaCorrection('RETURNED'),'END returned is backfillable as a maker correction action');
        foreach(['ASC_APPROVED','DISTRICT_VERIFIED','DISTRICT_APPROVED','NATIONAL_VERIFIED'] as $status){
            $this->same(true,NotificationBackfillService::isDivisionEndAfterTerminalAscApproval('division','END',$status),"END {$status} is skipped after terminal ASC approval");
            $this->same(null,NotificationBackfillService::arpaActionFor('division','END',$status),"END {$status} has no approver route");
        }
        $this->same('arpa.appointment.district-verify',NotificationBackfillService::arpaActionFor('division','APPOINTMENT','ASC_APPROVED')[0]??null,'normal appointment ASC approval still routes to District verification');

        $pdo=Database::pdo();$before=(int)$pdo->query('SELECT COUNT(*) FROM system_notification')->fetchColumn();$report=(new NotificationBackfillService($pdo))->run(false);$after=(int)$pdo->query('SELECT COUNT(*) FROM system_notification')->fetchColumn();
        $this->same($before,$after,'dry-run writes no notifications');
        $this->same(true,array_key_exists('arpa_end_terminal_skipped',$report),'dry-run reports terminal END rows skipped');

        echo "NotificationBackfillServiceTest: {$this->assertions} assertions passed.\n";return 0;
    }

    private function same(mixed $expected,mixed $actual,string $message):void{$this->assertions++;if($expected!==$actual)throw new RuntimeException($message.': expected '.var_export($expected,true).', got '.var_export($actual,true));}
}

exit((new NotificationBackfillServiceTest())->run());
