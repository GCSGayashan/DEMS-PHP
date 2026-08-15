<?php
declare(strict_types=1);

use App\Core\DataTableQuery;
use App\Core\DataTableRegistry;
use App\Core\DataTableRequest;
use App\Core\Database;
use App\Core\WorkflowService;
use App\Services\ArpaAppointmentReadService;
use App\Services\LocationHierarchyEffectiveDatePolicy;

require dirname(__DIR__) . '/bootstrap.php';

final class SqlInjectionSafetyTest
{
    private PDO $pdo;
    private int $assertions = 0;
    private array $protectedCounts = [];

    public function run(): int
    {
        $this->pdo = Database::pdo();
        $this->protectedCounts = [
            'officer' => (int)$this->pdo->query('SELECT COUNT(*) FROM officer')->fetchColumn(),
            'location' => (int)$this->pdo->query('SELECT COUNT(*) FROM location')->fetchColumn(),
        ];
        $this->createFixture();

        $this->testDataTableRequestNormalization();
        $this->testDataTableValuesRemainBound();
        $this->testDynamicIdentifierBoundaries();
        $this->testNormalSortingAndFiltering();

        echo "SqlInjectionSafetyTest: {$this->assertions} assertions passed.\n";
        return 0;
    }

    private function createFixture(): void
    {
        $this->pdo->exec('DROP TEMPORARY TABLE IF EXISTS sql_injection_safety_rows');
        $this->pdo->exec('CREATE TEMPORARY TABLE sql_injection_safety_rows (id INT PRIMARY KEY, name VARCHAR(100), status VARCHAR(20)) ENGINE=InnoDB');
        $statement = $this->pdo->prepare('INSERT INTO sql_injection_safety_rows(id,name,status) VALUES(?,?,?)');
        $statement->execute([1, "O'Brien", 'ACTIVE']);
        $statement->execute([2, 'Normal record', 'INACTIVE']);
        $statement->execute([3, 'Another record', 'ACTIVE']);
    }

    private function testDataTableRequestNormalization(): void
    {
        $request = new DataTableRequest([
            'start' => '0; DROP TABLE officer',
            'length' => '10 UNION SELECT password_hash FROM system_user',
            'order' => [['column' => '999 UNION SELECT 1', 'dir' => 'DESC; DROP TABLE officer']],
            'columns' => [['data' => 'password_hash FROM system_user']],
        ]);

        $this->same(0, $request->start, 'SQL text cannot become an OFFSET expression');
        $this->same(10, $request->length, 'numeric length parsing cannot append a SQL expression');
        $this->same(999, $request->orderColumn, 'the requested order index is only an integer');
        $this->same('ASC', $request->orderDirection, 'malicious sort direction falls back to ASC');

        $invalidLength = new DataTableRequest(['length' => '1; SELECT 1']);
        $this->same(25, $invalidLength->length, 'unsupported page length uses the bounded default');
        $this->same(0, $invalidLength->start, 'missing offset uses zero');
    }

    private function testDataTableValuesRemainBound(): void
    {
        $config = $this->config();
        $payload = "' OR 1=1; DROP TABLE officer; --";
        $search = $this->query($config, [
            'length' => 10,
            'search' => ['value' => $payload],
            'order' => [['column' => 999, 'dir' => 'DESC; DROP TABLE location']],
        ]);
        $this->same(0, $search['recordsFiltered'], 'search SQL syntax is treated as literal bound text');
        $this->same([], $search['data'], 'search payload cannot alter the query structure');

        $filter = $this->query($config, [
            'length' => 10,
            'filters' => ['status' => $payload, 'ignored_sql_column' => 'id DESC'],
        ]);
        $this->same(0, $filter['recordsFiltered'], 'filter SQL syntax is treated as a bound value');
        $this->same(3, $this->fixtureCount(), 'hostile DataTables values cannot change rows');

        $quote = $this->query($config, ['length' => 10, 'search' => ['value' => "O'Brien"]]);
        $this->same(1, $quote['recordsFiltered'], 'quotes in legitimate search text remain usable');
        $this->same("O'Brien", $quote['data'][0]['name'], 'bound quoted search returns the intended row');
    }

    private function testDynamicIdentifierBoundaries(): void
    {
        $this->throws(
            fn() => WorkflowService::submit('officer; DROP TABLE officer', 'ignored'),
            InvalidArgumentException::class,
            'workflow entity cannot select an arbitrary table'
        );
        $this->throws(
            fn() => DataTableRegistry::definition('hr-masters', ['master_type' => 'officer; DROP TABLE officer']),
            RuntimeException::class,
            'HR master route type cannot select an arbitrary table'
        );
        $this->throws(
            fn() => DataTableRegistry::definition('unknown-table-name'),
            RuntimeException::class,
            'unknown DataTable registry keys are rejected'
        );
        $this->throws(
            fn() => LocationHierarchyEffectiveDatePolicy::validationDateSql('a.effective_from; DROP TABLE location'),
            DomainException::class,
            'dynamic business-date identifier requires a strict alias.column form'
        );
        $this->throws(
            fn() => ArpaAppointmentReadService::currentActionIssuePredicate('q; DROP TABLE officer'),
            DomainException::class,
            'dynamic diagnostic alias rejects SQL syntax'
        );
        $this->throws(
            fn() => ArpaAppointmentReadService::issueSource('arpa_division_appointment; DROP TABLE officer'),
            DomainException::class,
            'diagnostic source rejects SQL syntax'
        );
        $this->same($this->protectedCounts['officer'], (int)$this->pdo->query('SELECT COUNT(*) FROM officer')->fetchColumn(), 'Officer table remains intact');
        $this->same($this->protectedCounts['location'], (int)$this->pdo->query('SELECT COUNT(*) FROM location')->fetchColumn(), 'Location table remains intact');
    }

    private function testNormalSortingAndFiltering(): void
    {
        $config = $this->config();
        $sorted = $this->query($config, [
            'length' => 10,
            'filters' => ['status' => 'ACTIVE'],
            'order' => [['column' => 1, 'dir' => 'desc']],
        ]);
        $this->same(2, $sorted['recordsFiltered'], 'normal bound filter still works');
        $this->same("O'Brien", $sorted['data'][0]['name'], 'registered descending sort still works');

        $unknownColumn = $this->query($config, [
            'length' => 10,
            'order' => [['column' => 999, 'dir' => 'desc']],
            'columns' => [['data' => 'name DESC; DROP TABLE officer']],
        ]);
        $this->same(1, (int)$unknownColumn['data'][0]['id'], 'unknown sort column uses the registered default');
        $this->same(3, $this->fixtureCount(), 'normal and hostile reads leave fixture rows unchanged');
    }

    private function config(): array
    {
        return [
            'from' => 'sql_injection_safety_rows t',
            'select' => ['t.id', 't.name', 't.status'],
            'count' => 't.id',
            'searchable' => ['t.name', 't.status'],
            'filters' => [
                'status' => ['column' => 't.status', 'operator' => '='],
            ],
            'columns' => [
                ['label' => 'ID', 'key' => 'id', 'sort' => 't.id'],
                ['label' => 'Name', 'key' => 'name', 'sort' => 't.name'],
                ['label' => 'Status', 'key' => 'status', 'sort' => 't.status'],
            ],
            'defaultOrder' => [0, 'ASC'],
        ];
    }

    private function query(array $config, array $input): array
    {
        return (new DataTableQuery($this->pdo, $config, new DataTableRequest($input)))->response();
    }

    private function fixtureCount(): int
    {
        return (int)$this->pdo->query('SELECT COUNT(*) FROM sql_injection_safety_rows')->fetchColumn();
    }

    private function throws(callable $callable, string $exceptionClass, string $message): void
    {
        $this->assertions++;
        try {
            $callable();
        } catch (Throwable $exception) {
            if ($exception instanceof $exceptionClass) {
                return;
            }
            throw new RuntimeException($message . ': expected ' . $exceptionClass . ', got ' . get_class($exception));
        }
        throw new RuntimeException($message . ': expected exception ' . $exceptionClass);
    }

    private function same(mixed $expected, mixed $actual, string $message): void
    {
        $this->assertions++;
        if ($expected !== $actual) {
            throw new RuntimeException($message . ': expected ' . var_export($expected, true) . ', got ' . var_export($actual, true));
        }
    }
}

exit((new SqlInjectionSafetyTest())->run());
