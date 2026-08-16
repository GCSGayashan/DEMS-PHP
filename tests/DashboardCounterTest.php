<?php
declare(strict_types=1);

use App\Controllers\DashboardController;
use App\Core\Database;

require dirname(__DIR__).'/bootstrap.php';

final class DashboardCounterTest
{
    private int $assertions=0;

    public function run(): int
    {
        $pdo=Database::pdo();
        $counts=DashboardController::counterValues($pdo);

        $expectedActive=(int)$pdo->query("
            SELECT COUNT(*)
            FROM system_user
            WHERE identity_type='STAFF'
              AND account_status='ACTIVE'
              AND enabled=1
        ")->fetchColumn();

        $expectedHistorical=(int)$pdo->query("
            SELECT COUNT(*)
            FROM system_user
            WHERE identity_type='HISTORICAL'
              AND enabled=0
        ")->fetchColumn();

        $expectedTotal=(int)$pdo->query("
            SELECT COUNT(*)
            FROM system_user
        ")->fetchColumn();

        $this->same($expectedActive,$counts['active_users'],'activated operational STAFF identities count as Active Users');
        $this->same($expectedHistorical,$counts['historical_users'],'remaining HISTORICAL identities count as Historical Users');
        $this->same($expectedTotal,$counts['total_user_identities'],'all system_user rows count as Total User Identities');
        $this->same($expectedActive+$expectedHistorical,$expectedTotal,'database identity population reconciles');
        $this->same($counts['active_users']+$counts['historical_users'],$counts['total_user_identities'],'dashboard identity population reconciles');
        $this->same(6429,$counts['officers'],'verified consolidated Officer count remains unchanged');

        $admin=$pdo->query("SELECT identity_type,account_status,enabled FROM system_user WHERE username='dems.admin'")->fetch();
        $this->same(
            ['identity_type'=>'STAFF','account_status'=>'ACTIVE','enabled'=>1],
            ['identity_type'=>$admin['identity_type'],'account_status'=>$admin['account_status'],'enabled'=>(int)$admin['enabled']],
            'dems.admin remains an enabled active STAFF account'
        );

        echo "DashboardCounterTest: {$this->assertions} assertions passed.\n";
        return 0;
    }

    private function same(mixed $expected,mixed $actual,string $message): void
    {
        $this->assertions++;
        if($expected!==$actual)throw new RuntimeException(
            $message.': expected '.var_export($expected,true).', got '.var_export($actual,true)
        );
    }
}

exit((new DashboardCounterTest())->run());