<?php
declare(strict_types=1);

use App\Core\DataTableQuery;
use App\Core\DataTableFormat;
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
        $this->testFriendlyPermanencyLabels();
        $this->testQueryProtocol();
        $this->testModuleDefinitions();
        $this->testLocationHierarchyColumns();
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

    private function testFriendlyPermanencyLabels(): void
    {
        $labels = [
            'PERMANENT_IN_SERVICE' => 'Permanent In Service',
            'NOT_PERMANENT_IN_SERVICE' => 'Not Permanent In Service',
            'TEMPORARY' => 'Temporary',
            'CONTRACT' => 'Contract',
            'CASUAL' => 'Casual',
            'RETIRED' => 'Retired',
            'OTHER_SERVICE_VALUE' => 'Other Service Value',
        ];
        foreach ($labels as $raw => $friendly) {
            $this->same($friendly, DataTableFormat::enumLabel($raw), "{$raw} has a friendly label");
        }

        $officers = DataTableRegistry::definition('officers');
        $filter = $officers['filters']['service_permanency'];
        $this->same(
            ['PERMANENT_IN_SERVICE', 'NOT_PERMANENT_IN_SERVICE'],
            array_keys($filter['ui']['options']),
            'Service Permanency filter continues to submit raw values'
        );
        $this->same('Permanent In Service', $filter['ui']['options']['PERMANENT_IN_SERVICE'], 'filter displays friendly Permanent label');

        $column = $officers['columns'][array_search('arpa_service_permanency', array_column($officers['columns'], 'key'), true)];
        $row = ['arpa_service_permanency' => 'PERMANENT_IN_SERVICE'];
        $this->same('Permanent In Service', strip_tags($column['format']($row)), 'Officer DataTable displays friendly permanency');
        $this->same('Permanent In Service', $column['exportFormat']($row), 'Officer CSV exports friendly permanency');

        $appointments = DataTableRegistry::definition('arpa-division-appointments');
        $appointmentColumn = $appointments['columns'][array_search('arpa_service_permanency', array_column($appointments['columns'], 'key'), true)];
        $this->same('Permanent In Service', strip_tags($appointmentColumn['format']($row)), 'ARPA assignment table displays friendly permanency');
        $this->same('Permanent In Service', $appointmentColumn['exportFormat']($row), 'ARPA assignment CSV exports friendly permanency');
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

    private function testLocationHierarchyColumns(): void
    {
        $expected = [
            'PROVINCE' => ['DAD Number','Official Code','Province','Start Date','Status','Approval','Actions'],
            'DISTRICT' => ['DAD Number','Official Code','Province','District','Start Date','Status','Approval','Actions'],
            'DS_DIVISION' => ['DAD Number','Official Code','Province','District','DS Division','Start Date','Status','Approval','Actions'],
            'ASC' => ['DAD Number','Official Code','Province','District','Agrarian Service Center','Start Date','Status','Approval','Actions'],
            'AI_RANGE' => ['DAD Number','Official Code','Province','District','AI Range','Start Date','Status','Approval','Actions'],
            'MAHAWELI_DIVISION' => ['DAD Number','Official Code','Province','District','Mahaweli Division','Start Date','Status','Approval','Actions'],
            'ARPA_DIVISION' => ['DAD Number','Official Code','Province','District','Agrarian Service Center','ARPA Division','Start Date','Status','Approval','Actions'],
            'GN_DIVISION' => ['DAD Number','Official Code','Province','District','GN Division','Start Date','Status','Approval','Actions'],
        ];

        $general = DataTableRegistry::definition('locations');
        $this->same(true, in_array('Type', array_column($general['columns'], 'label'), true), 'mixed Location list retains Type');
        $this->same(true, in_array('Name', array_column($general['columns'], 'label'), true), 'mixed Location list uses generic Name label');

        foreach ($expected as $type => $labels) {
            $config = DataTableRegistry::definition('locations', ['scope_type' => $type]);
            $this->same($labels, array_column($config['columns'], 'label'), "{$type} hierarchy columns");
            $this->same(false, in_array('Type', $labels, true), "{$type} removes redundant Type column");
            $this->same([0, 'ASC'], $config['defaultOrder'], "{$type} defaults to ascending DAD Number order");
            foreach ($config['columns'] as $column) {
                if (in_array($column['label'], ['Province','District','Agrarian Service Center','ARPA Division','GN Division','DS Division','AI Range','Mahaweli Division'], true)) {
                    $this->same(true, !empty($column['sort']), "{$type} {$column['label']} is sortable");
                }
            }
        }

        foreach (['PROVINCE','DISTRICT','ASC','ARPA_DIVISION','GN_DIVISION'] as $type) {
            $config = DataTableRegistry::definition('locations', ['scope_type' => $type]);
            $rows = iterator_to_array((new DataTableQuery($this->pdo, $config, new DataTableRequest([])))->exportRows());
            $stmt = $this->pdo->prepare('SELECT COUNT(*) FROM location l JOIN location_type lt ON lt.id=l.location_type_id WHERE lt.system_key=?');
            $stmt->execute([$type]);
            $rawCount = (int)$stmt->fetchColumn();
            $numbers = array_column($rows, 'dad_number');
            $this->same($rawCount, count($rows), "{$type} export has one row per Location");
            $this->same($rawCount, count(array_unique($numbers)), "{$type} hierarchy joins create no duplicates");
        }

        foreach (['DISTRICT','ASC','ARPA_DIVISION','GN_DIVISION'] as $type) {
            $config = DataTableRegistry::definition('locations', ['scope_type' => $type]);
            $first = (new DataTableQuery($this->pdo, $config, new DataTableRequest(['length' => 10])))->response()['data'][0] ?? null;
            $this->same(true, is_array($first) && trim(strip_tags((string)($first['province_name'] ?? ''))) !== '', "{$type} displays Province from hierarchy");
            $districtKey = $type === 'DISTRICT' ? 'name_en' : 'district_name';
            $this->same(true, is_array($first) && trim(strip_tags((string)($first[$districtKey] ?? ''))) !== '', "{$type} displays District from hierarchy");
            $province = trim(strip_tags((string)$first['province_name']));
            $searched = (new DataTableQuery($this->pdo, $config, new DataTableRequest(['length' => 10, 'search' => ['value' => $province]])))->response();
            $this->same(true, $searched['recordsFiltered'] > 0, "{$type} search matches Province hierarchy name");
            $districtIndex = array_search($districtKey, array_column($config['columns'], 'key'), true);
            $sorted = (new DataTableQuery($this->pdo, $config, new DataTableRequest(['length' => 10, 'order' => [['column' => $districtIndex, 'dir' => 'asc']]])))->response();
            $this->same(true, count($sorted['data']) > 0, "{$type} District hierarchy sorting executes");
        }

        $arpa = DataTableRegistry::definition('locations', ['scope_type' => 'ARPA_DIVISION']);
        $arpaRow = (new DataTableQuery($this->pdo, $arpa, new DataTableRequest(['length' => 10])))->response()['data'][0] ?? [];
        $this->same(true, trim(strip_tags((string)($arpaRow['asc_name'] ?? ''))) !== '', 'ARPA Division displays its Agrarian Service Center');
        $asc = trim(strip_tags((string)$arpaRow['asc_name']));
        $ascSearch = (new DataTableQuery($this->pdo, $arpa, new DataTableRequest(['length' => 10, 'search' => ['value' => $asc]])))->response();
        $this->same(true, $ascSearch['recordsFiltered'] > 0, 'ARPA Division search matches Agrarian Service Center');
        $current = " AND lr.active=1 AND lr.approval_status='APPROVED' AND lr.effective_from<=CURRENT_DATE() AND (lr.effective_to IS NULL OR lr.effective_to>=CURRENT_DATE())";
        $expectedArpa = $this->pdo->prepare("SELECT province.name_en province_name,district.name_en district_name,asc_location.name_en asc_name FROM location leaf JOIN location_relationship lr ON lr.child_location_id=leaf.id AND lr.relationship_type='ASC_ARPA_DIVISION'{$current} JOIN location asc_location ON asc_location.id=lr.parent_location_id JOIN location_relationship district_rel ON district_rel.child_location_id=asc_location.id AND district_rel.relationship_type='DISTRICT_ASC' AND district_rel.active=1 AND district_rel.approval_status='APPROVED' AND district_rel.effective_from<=CURRENT_DATE() AND (district_rel.effective_to IS NULL OR district_rel.effective_to>=CURRENT_DATE()) JOIN location district ON district.id=district_rel.parent_location_id JOIN location_relationship province_rel ON province_rel.child_location_id=district.id AND province_rel.relationship_type='PROVINCE_DISTRICT' AND province_rel.active=1 AND province_rel.approval_status='APPROVED' AND province_rel.effective_from<=CURRENT_DATE() AND (province_rel.effective_to IS NULL OR province_rel.effective_to>=CURRENT_DATE()) JOIN location province ON province.id=province_rel.parent_location_id WHERE leaf.dad_number=? LIMIT 1");
        $expectedArpa->execute([strip_tags((string)$arpaRow['dad_number'])]);
        $expected = $expectedArpa->fetch();
        $this->same((string)$expected['province_name'], trim(strip_tags((string)$arpaRow['province_name'])), 'ARPA Province comes from approved hierarchy');
        $this->same((string)$expected['district_name'], trim(strip_tags((string)$arpaRow['district_name'])), 'ARPA District comes from approved hierarchy');
        $this->same((string)$expected['asc_name'], $asc, 'ARPA Agrarian Service Center comes from approved hierarchy');

        $gn = DataTableRegistry::definition('locations', ['scope_type' => 'GN_DIVISION']);
        $gnRow = (new DataTableQuery($this->pdo, $gn, new DataTableRequest(['length' => 10])))->response()['data'][0] ?? [];
        $expectedGn = $this->pdo->prepare("SELECT province.name_en province_name,district.name_en district_name FROM location leaf JOIN location_relationship arpa_rel ON arpa_rel.child_location_id=leaf.id AND arpa_rel.relationship_type='ARPA_GN_DIVISION' AND arpa_rel.active=1 AND arpa_rel.approval_status='APPROVED' AND arpa_rel.effective_from<=CURRENT_DATE() AND (arpa_rel.effective_to IS NULL OR arpa_rel.effective_to>=CURRENT_DATE()) JOIN location arpa ON arpa.id=arpa_rel.parent_location_id JOIN location_relationship asc_rel ON asc_rel.child_location_id=arpa.id AND asc_rel.relationship_type='ASC_ARPA_DIVISION' AND asc_rel.active=1 AND asc_rel.approval_status='APPROVED' AND asc_rel.effective_from<=CURRENT_DATE() AND (asc_rel.effective_to IS NULL OR asc_rel.effective_to>=CURRENT_DATE()) JOIN location asc_location ON asc_location.id=asc_rel.parent_location_id JOIN location_relationship district_rel ON district_rel.child_location_id=asc_location.id AND district_rel.relationship_type='DISTRICT_ASC' AND district_rel.active=1 AND district_rel.approval_status='APPROVED' AND district_rel.effective_from<=CURRENT_DATE() AND (district_rel.effective_to IS NULL OR district_rel.effective_to>=CURRENT_DATE()) JOIN location district ON district.id=district_rel.parent_location_id JOIN location_relationship province_rel ON province_rel.child_location_id=district.id AND province_rel.relationship_type='PROVINCE_DISTRICT' AND province_rel.active=1 AND province_rel.approval_status='APPROVED' AND province_rel.effective_from<=CURRENT_DATE() AND (province_rel.effective_to IS NULL OR province_rel.effective_to>=CURRENT_DATE()) JOIN location province ON province.id=province_rel.parent_location_id WHERE leaf.dad_number=? LIMIT 1");
        $expectedGn->execute([strip_tags((string)$gnRow['dad_number'])]);
        $expected = $expectedGn->fetch();
        $this->same((string)$expected['province_name'], trim(strip_tags((string)$gnRow['province_name'])), 'GN Province comes from approved ARPA hierarchy');
        $this->same((string)$expected['district_name'], trim(strip_tags((string)$gnRow['district_name'])), 'GN District comes from approved ARPA hierarchy');
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
