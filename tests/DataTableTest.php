<?php
declare(strict_types=1);

use App\Core\DataTableQuery;
use App\Core\DataTableRegistry;
use App\Core\DataTableRequest;
use App\Core\Database;

require dirname(__DIR__) . '/bootstrap.php';

final class DataTableTest
{
    private PDO $pdo;
    private int $assertions = 0;

    public function run(): int
    {
        $this->pdo = Database::pdo();
        $_SESSION = [];
        $adminId = $this->pdo->query("SELECT su.id FROM `system_user` su JOIN user_account_role uar ON uar.user_id=su.id JOIN application_role r ON r.id=uar.role_id WHERE r.role_code='SYSTEM_ADMIN' LIMIT 1")->fetchColumn();
        if (!$adminId) {
            throw new RuntimeException('DataTable tests require the seeded SYSTEM_ADMIN test account.');
        }
        $_SESSION['user_id'] = $adminId;

        $this->testRequestValidation();
        $this->testQueryProtocol();
        $this->testModuleDefinitions();
        $this->testPermissionAwareActions();
        $this->testResponsiveContract();

        echo "DataTableTest: {$this->assertions} assertions passed.\n";
        return 0;
    }

    private function testRequestValidation(): void
    {
        foreach ([10, 25, 50, 100] as $length) {
            $request = new DataTableRequest(['length' => $length]);
            $this->same($length, $request->length, "page length {$length}");
        }
        $this->same(25, (new DataTableRequest(['length' => -1]))->length, 'All/invalid length rejected');
        $this->same('DESC', (new DataTableRequest(['order' => [['dir' => 'DESC']]]))->orderDirection, 'DESC normalized');
        $this->same('ASC', (new DataTableRequest(['order' => [['dir' => 'DROP TABLE']]]))->orderDirection, 'invalid direction falls back');
    }

    private function testQueryProtocol(): void
    {
        $this->pdo->exec('CREATE TEMPORARY TABLE datatable_test_rows (id INT PRIMARY KEY, name VARCHAR(50), status VARCHAR(20), group_key VARCHAR(10)) ENGINE=InnoDB');
        $insert = $this->pdo->prepare('INSERT INTO datatable_test_rows (id,name,status,group_key) VALUES (?,?,?,?)');
        for ($id = 1; $id <= 150; $id++) {
            $insert->execute([$id, 'Item ' . str_pad((string)$id, 3, '0', STR_PAD_LEFT), $id % 2 === 0 ? 'ACTIVE' : 'INACTIVE', 'G' . ($id % 3)]);
        }
        $config = [
            'from' => 'datatable_test_rows t',
            'select' => ['t.id', 't.name', 't.status', 't.group_key'],
            'count' => 't.id',
            'searchable' => ['t.name', 't.status'],
            'filters' => [
                'status' => ['column' => 't.status', 'allowed' => ['ACTIVE', 'INACTIVE']],
                'group' => ['column' => 't.group_key', 'allowed' => ['G0', 'G1', 'G2']],
            ],
            'columns' => [
                ['label' => 'ID', 'key' => 'id', 'sort' => 't.id'],
                ['label' => 'Name', 'key' => 'name', 'sort' => 't.name'],
                ['label' => 'Status', 'key' => 'status', 'sort' => 't.status'],
                ['label' => 'Group', 'key' => 'group_key', 'sort' => 't.group_key'],
            ],
            'defaultOrder' => [0, 'ASC'],
        ];

        foreach ([10, 25, 50, 100] as $length) {
            $response = $this->query($config, ['draw' => 7, 'start' => 0, 'length' => $length]);
            $this->same($length, count($response['data']), "server page length {$length}");
            $this->same(150, $response['recordsTotal'], 'recordsTotal remains complete');
            $this->same(7, $response['draw'], 'draw echoed');
        }

        $search = $this->query($config, ['length' => 25, 'search' => ['value' => 'Item 149']]);
        $this->same(1, $search['recordsFiltered'], 'global search filtered count');
        $this->same('Item 149', $search['data'][0]['name'], 'global search result');

        $asc = $this->query($config, ['length' => 10, 'order' => [['column' => 0, 'dir' => 'asc']]]);
        $desc = $this->query($config, ['length' => 10, 'order' => [['column' => 0, 'dir' => 'desc']]]);
        $this->same(1, (int)$asc['data'][0]['id'], 'ascending sort');
        $this->same(150, (int)$desc['data'][0]['id'], 'descending sort');

        $invalid = $this->query($config, ['length' => 10, 'order' => [['column' => 999, 'dir' => 'desc']]]);
        $this->same(1, (int)$invalid['data'][0]['id'], 'invalid sort column uses safe default');

        $empty = $this->query($config, ['length' => 25, 'search' => ['value' => 'not-present']]);
        $this->same(0, $empty['recordsFiltered'], 'empty search count');
        $this->same([], $empty['data'], 'empty search data');

        $combined = $this->query($config, ['length' => 25, 'filters' => ['status' => 'ACTIVE', 'group' => 'G1']]);
        $this->same(25, $combined['recordsFiltered'], 'filters combine');
        $this->same(25, count($combined['data']), 'filtered page count');

        $query = new DataTableQuery($this->pdo, $config, new DataTableRequest(['length' => 10, 'filters' => ['status' => 'ACTIVE']]));
        $exported = iterator_to_array($query->exportRows());
        $this->same(75, count($exported), 'CSV export returns complete filtered result');
        $this->same(25, count($this->query($config, ['length' => 25])['data']), 'large table does not load all rows');
    }

    private function testModuleDefinitions(): void
    {
        $definitions = [
            ['locations', []], ['locations', ['scope_type' => 'PROVINCE']],
            ['location-types', []], ['location-hierarchy', []], ['offices', []], ['officers', []],
            ['users', []], ['account-requests', []], ['roles', []], ['permissions', []],
            ['role-assignments', []], ['scope-assignments', []], ['provisioning-failures', []], ['security-history', []],
            ['arpa-division-appointments', []], ['arpa-subject-assignments', []], ['arpa-pending-actions', []],
            ['arpa-new-appointments', []], ['arpa-submitted-appointments', []], ['arpa-approval-verification', []],
            ['arpa-open-appointments', []], ['arpa-historical-appointments', []], ['arpa-vacant-divisions', []],
            ['arpa-appointment-issues', []],
            ['subjects', []],
        ];
        foreach (['titles', 'appointment-natures', 'designations', 'classes', 'officer-statuses', 'civil-statuses'] as $type) {
            $definitions[] = ['hr-masters', ['master_type' => $type]];
        }
        foreach ($definitions as [$key, $context]) {
            $config = DataTableRegistry::definition($key, $context);
            $response = (new DataTableQuery($this->pdo, $config, new DataTableRequest(['length' => 10])))->response();
            $this->same(true, count($response['data']) <= 10, $key . ' uses server page limit');
            $this->same(true, $response['recordsTotal'] >= $response['recordsFiltered'], $key . ' count contract');
        }
    }

    private function testPermissionAwareActions(): void
    {
        $config = DataTableRegistry::definition('offices');
        $actions = $config['columns'][array_key_last($config['columns'])]['format'];
        $draft = $actions(['id' => 'row-1', 'approval_status' => 'DRAFT', 'created_by' => 'someone-else']);
        $this->contains('Submit', $draft, 'authorized Submit action rendered');

        $currentUser = (string)$_SESSION['user_id'];
        $makerSubmitted = $actions(['id' => 'row-2', 'approval_status' => 'SUBMITTED', 'created_by' => $currentUser]);
        $this->same(false, str_contains($makerSubmitted,'Approve'), 'maker cannot see own Approve action');
        $checkerSubmitted = $actions(['id' => 'row-3', 'approval_status' => 'SUBMITTED', 'created_by' => 'someone-else']);
        $this->contains('Approve', $checkerSubmitted, 'authorized checker action rendered');
        $this->contains('name="_csrf"', $draft, 'workflow action retains CSRF token');
    }

    private function testResponsiveContract(): void
    {
        $component = file_get_contents(BASE_PATH . '/app/Views/components/datatable.php');
        $css = file_get_contents(BASE_PATH . '/public/assets/css/app.css');
        $javascript = file_get_contents(BASE_PATH . '/public/assets/js/dems-datatable.js');
        $this->contains('table-responsive', $component, 'Bootstrap responsive table wrapper');
        $this->contains('min-width:0', $css, 'table cannot widen content/sidebar shell');
        $this->contains('scrollX: true', $javascript, 'small-screen horizontal table scrolling');
        $this->contains('serverSide: true', $javascript, 'client enables server-side mode');
        $this->contains('lengthMenu: [10, 25, 50, 100]', $javascript, 'standard page lengths configured');
        $this->contains("emptyTable: config.emptyMessage || 'No records found.'", $javascript, 'standard and module-specific empty states configured');
    }

    private function query(array $config, array $input): array
    {
        return (new DataTableQuery($this->pdo, $config, new DataTableRequest($input)))->response();
    }

    private function same(mixed $expected, mixed $actual, string $message): void
    {
        $this->assertions++;
        if ($expected !== $actual) {
            throw new RuntimeException($message . ': expected ' . var_export($expected, true) . ', got ' . var_export($actual, true));
        }
    }

    private function contains(string $needle, string $haystack, string $message): void
    {
        $this->assertions++;
        if (!str_contains($haystack, $needle)) {
            throw new RuntimeException($message . ': missing ' . $needle);
        }
    }
}

exit((new DataTableTest())->run());
