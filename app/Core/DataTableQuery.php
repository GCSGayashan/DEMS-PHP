<?php
declare(strict_types=1);

namespace App\Core;

use PDO;
use RuntimeException;

final class DataTableQuery
{
    public function __construct(
        private readonly PDO $pdo,
        private readonly array $config,
        private readonly DataTableRequest $request
    ) {
        foreach (['from', 'select', 'columns', 'count'] as $required) {
            if (!isset($config[$required])) {
                throw new RuntimeException("DataTable configuration is missing {$required}.");
            }
        }
    }

    public function response(): array
    {
        [$baseWhere, $baseParams] = $this->baseWhere();
        [$filteredWhere, $filteredParams] = $this->filteredWhere();

        $total = $this->count($baseWhere, $baseParams);
        $filtered = $filteredWhere === $baseWhere && $filteredParams === $baseParams
            ? $total
            : $this->count($filteredWhere, $filteredParams);
        $rows = $this->fetchRows($filteredWhere, $filteredParams, true);

        return [
            'draw' => $this->request->draw,
            'recordsTotal' => $total,
            'recordsFiltered' => $filtered,
            'data' => array_map(fn(array $row): array => $this->formatRow($row, false), $rows),
        ];
    }

    public function exportRows(): iterable
    {
        [$where, $params] = $this->filteredWhere();
        $sql = $this->selectSql($where, false);
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            yield $this->formatRow($row, true);
        }
    }

    public function exportHeaders(): array
    {
        $headers = [];
        foreach ($this->config['columns'] as $column) {
            if (($column['export'] ?? true) === false) {
                continue;
            }
            $headers[] = $column['label'];
        }
        return $headers;
    }

    private function count(array $where, array $params): int
    {
        $sql = ($this->config['with'] ?? '') . 'SELECT COUNT(DISTINCT ' . $this->config['count'] . ') FROM ' . $this->config['from'] . $this->whereSql($where);
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        return (int)$stmt->fetchColumn();
    }

    private function fetchRows(array $where, array $params, bool $paginate): array
    {
        $sql = $this->selectSql($where, $paginate);
        $stmt = $this->pdo->prepare($sql);
        foreach ($params as $index => $value) {
            $stmt->bindValue($index + 1, $value);
        }
        if ($paginate) {
            $stmt->bindValue(count($params) + 1, $this->request->length, PDO::PARAM_INT);
            $stmt->bindValue(count($params) + 2, $this->request->start, PDO::PARAM_INT);
        }
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    private function selectSql(array $where, bool $paginate): string
    {
        $sql = ($this->config['with'] ?? '') . 'SELECT ' . implode(', ', $this->config['select']) . ' FROM ' . $this->config['from'] . $this->whereSql($where);
        if (!empty($this->config['groupBy'])) {
            $sql .= ' GROUP BY ' . $this->config['groupBy'];
        }
        $sql .= ' ORDER BY ' . $this->orderSql();
        if ($paginate) {
            $sql .= ' LIMIT ? OFFSET ?';
        }
        return $sql;
    }

    private function baseWhere(): array
    {
        return [array_values($this->config['baseWhere'] ?? []), array_values($this->config['baseParams'] ?? [])];
    }

    private function filteredWhere(): array
    {
        [$where, $params] = $this->baseWhere();

        foreach ($this->config['filters'] ?? [] as $name => $filter) {
            $value = $this->request->filter($name);
            if ($value === null) {
                continue;
            }
            if (isset($filter['allowed']) && !in_array($value, $filter['allowed'], true)) {
                continue;
            }
            if (isset($filter['pattern']) && preg_match($filter['pattern'], $value) !== 1) {
                continue;
            }
            if (isset($filter['build'])) {
                $built = ($filter['build'])($value);
                if (is_array($built) && isset($built[0])) {
                    $where[] = $built[0];
                    array_push($params, ...array_values($built[1] ?? []));
                }
                continue;
            }
            $operator = $filter['operator'] ?? '=';
            if ($operator === 'LIKE') {
                $where[] = $filter['column'] . ' LIKE ?';
                $params[] = '%' . self::escapeLike($value) . '%';
            } elseif (in_array($operator, ['=', '>=', '<='], true)) {
                if (($filter['date'] ?? false) && !self::validDate($value)) {
                    continue;
                }
                $where[] = $filter['column'] . ' ' . $operator . ' ?';
                $params[] = $value;
            }
        }

        if ($this->request->search !== '' && !empty($this->config['searchable'])) {
            $searchClauses = [];
            $term = '%' . self::escapeLike($this->request->search) . '%';
            foreach ($this->config['searchable'] as $column) {
                $searchClauses[] = $column . ' LIKE ?';
                $params[] = $term;
            }
            $where[] = '(' . implode(' OR ', $searchClauses) . ')';
        }
        return [$where, $params];
    }

    private function orderSql(): string
    {
        $default = $this->config['defaultOrder'] ?? [0, 'ASC'];
        $index = $this->request->orderColumn;
        $column = $this->config['columns'][$index] ?? null;
        $validRequestedSort = is_array($column) && !empty($column['sort']);
        if (!$validRequestedSort) {
            $index = (int)$default[0];
            $column = $this->config['columns'][$index] ?? null;
        }
        $sort = is_array($column) && !empty($column['sort']) ? $column['sort'] : $this->config['count'];
        $direction = $validRequestedSort
            ? $this->request->orderDirection
            : (strtoupper((string)($default[1] ?? 'ASC')) === 'DESC' ? 'DESC' : 'ASC');
        return $sort . ' ' . $direction . ', ' . $this->config['count'] . ' ASC';
    }

    private function whereSql(array $where): string
    {
        return $where === [] ? '' : ' WHERE ' . implode(' AND ', $where);
    }

    private function formatRow(array $row, bool $export): array
    {
        $result = [];
        foreach ($this->config['columns'] as $column) {
            if ($export && ($column['export'] ?? true) === false) {
                continue;
            }
            $key = $column['key'];
            if ($export && isset($column['exportFormat'])) {
                $result[$key] = ($column['exportFormat'])($row);
            } elseif (!$export && isset($column['format'])) {
                $result[$key] = ($column['format'])($row);
            } else {
                $result[$key] = $row[$key] ?? '';
            }
        }
        return $result;
    }

    private static function escapeLike(string $value): string
    {
        return str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $value);
    }

    private static function validDate(string $value): bool
    {
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $value);
        return $date !== false && $date->format('Y-m-d') === $value;
    }
}
