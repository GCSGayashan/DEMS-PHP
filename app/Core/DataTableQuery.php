<?php
declare(strict_types=1);

namespace App\Core;

use PDO;
use RuntimeException;

final class DataTableQuery
{
    public const MAX_QUALIFIED_EXPORT_BYTES=134217728;
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
        if(isset($this->config['readAdapter']))return ($this->config['readAdapter'])($this->pdo,$this->config,fn(array $config):array=>(new self($this->pdo,$config,$this->request))->response());
        if(isset($this->config['qualificationReader']))return $this->qualifiedResponse();
        if(!empty($this->config['requiresApprovalQualification']))throw new RuntimeException('Approval qualification adapter is required.');
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
        if(isset($this->config['readAdapter'])){
            // Snapshot callbacks must finish before returning. Spool only this adapted
            // export to a private auto-deleted file, not an unbounded PHP rows array.
            $stream=@tmpfile();if($stream===false)throw new RuntimeException('Unable to prepare the qualified export.');
            $maximum=max(1,min(self::MAX_QUALIFIED_EXPORT_BYTES,(int)($this->config['exportSpoolMaxBytes']??self::MAX_QUALIFIED_EXPORT_BYTES)));
            $expectedRows=0;
            try{
                ($this->config['readAdapter'])($this->pdo,$this->config,function(array $config)use($stream,$maximum,&$expectedRows):void{
                    $bytes=0;
                    foreach((new self($this->pdo,$config,$this->request))->exportRows() as $row){
                        $line=json_encode($row,JSON_THROW_ON_ERROR)."\n";
                        $bytes+=strlen($line);if($bytes>$maximum)throw new RuntimeException('Qualified export exceeds the temporary storage budget. Narrow the filters.');
                        if(@fwrite($stream,$line)!==strlen($line))throw new RuntimeException('Unable to prepare the qualified export.');
                        $expectedRows++;
                    }
                });
                if(!@rewind($stream))throw new RuntimeException('Unable to prepare the qualified export.');
                $readRows=0;
                while(($line=@fgets($stream))!==false){$readRows++;yield json_decode($line,true,512,JSON_THROW_ON_ERROR);}
                if(!feof($stream)||$readRows!==$expectedRows)throw new RuntimeException('Unable to read the complete qualified export.');
            }finally{fclose($stream);}
            return;
        }
        if(isset($this->config['qualificationReader'])){
            foreach($this->qualifiedRows(true) as $row)yield $this->formatRow($row,true);
            return;
        }
        if(!empty($this->config['requiresApprovalQualification']))throw new RuntimeException('Approval qualification adapter is required.');
        [$where, $params] = $this->filteredWhere();
        $sql = $this->selectSql($where, false);
        $stmt = $this->pdo->prepare($this->reportingSql($sql));
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

    private function qualifiedResponse():array
    {
        [$base,$bp]=$this->baseWhere();[$filtered,$fp]=$this->filteredWhere();
        $total=0;
        if($base!==$filtered||$bp!==$fp)foreach($this->qualifiedRows(false,true) as $_)$total++;
        $matched=0;$page=[];
        foreach($this->qualifiedRows(true) as $row){
            if($matched>=$this->request->start&&count($page)<$this->request->length)$page[]=$this->formatRow($row,false);
            $matched++;
        }
        return ['draw'=>$this->request->draw,'recordsTotal'=>$base===$filtered&&$bp===$fp?$matched:$total,'recordsFiltered'=>$matched,'data'=>$page];
    }

    private function qualifiedRows(bool $filtered,bool $countOnly=false):iterable
    {
        foreach($this->candidateBatches($filtered,$countOnly) as $rows){
            $qualified=($this->config['qualificationReader'])(array_values(array_unique(array_column($rows,'_qualification_id'))));
            foreach($rows as $row)if(($qualified[$row['_qualification_id']]['classification']??null)==='APPROVED')yield $row;
        }
    }

    /** Internal keyset chunks are NOT user pages. Entire source is consumed before
     * totals are final; user OFFSET/take applies only after qualification above.
     * Comparisons/sorting stay in MySQL, including NULLs and locale collations.
     */
    public function candidateBatches(bool $filtered=true,bool $countOnly=false):iterable
    {
        [$base,$params]=$filtered?$this->filteredWhere():$this->baseWhere();
        [$sort,$direction]=$countOnly?[$this->config['count'],'ASC']:$this->orderParts();
        $tie=$this->config['scanTie']??$this->config['count'];$last=null;
        while(true){
            $where=$base;$args=$params;
            if($last!==null){
                if($last['_scan_sort']===null){
                    $where[]=$direction==='ASC'?"({$sort} IS NOT NULL OR ({$sort} IS NULL AND {$tie}>?))":"({$sort} IS NULL AND {$tie}>?)";$args[]=$last['_scan_tie'];
                }else{
                    $where[]='('.$sort.($direction==='ASC'?'>?':'<?').' OR '.($direction==='DESC'?$sort.' IS NULL OR ':'')."({$sort}=? AND {$tie}>?))";
                    array_push($args,$last['_scan_sort'],$last['_scan_sort'],$last['_scan_tie']);
                }
            }
            $select=$countOnly?[]:$this->config['select'];
            $select[]=$this->config['count'].' _qualification_id';$select[]=$sort.' _scan_sort';$select[]=$tie.' _scan_tie';
            $sql=($this->config['with']??'').'SELECT DISTINCT '.implode(',',$select).' FROM '.$this->config['from'].$this->whereSql($where).' ORDER BY '.$sort.' '.$direction.','.$tie.' ASC LIMIT 200';
            $stmt=$this->pdo->prepare($this->reportingSql($sql));$stmt->execute($args);$rows=$stmt->fetchAll(PDO::FETCH_ASSOC);$stmt->closeCursor();
            if($rows===[])return;
            yield $rows;$last=$rows[array_key_last($rows)];
        }
    }

    private function count(array $where, array $params): int
    {
        $from=$this->config['countFrom']??$this->config['from'];
        $sql = ($this->config['with'] ?? '') . 'SELECT COUNT(DISTINCT ' . $this->config['count'] . ') FROM ' . $from . $this->whereSql($where);
        $stmt = $this->pdo->prepare($this->reportingSql($sql));
        $stmt->execute($params);
        return (int)$stmt->fetchColumn();
    }

    private function fetchRows(array $where, array $params, bool $paginate): array
    {
        $sql = $this->selectSql($where, $paginate);
        $stmt = $this->pdo->prepare($this->reportingSql($sql));
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

    /** Only the approved-assignment adapter supplies this server-derived date.
     * Freeze the complete SQL, including filter closures/CTEs and keyset comparisons.
     * A strictly validated DATE literal avoids inserting positional placeholders into
     * reused SQL expressions (which would reorder/duplicate existing parameters).
     * No client value or database-session/timezone change is used.
     */
    private function reportingSql(string $sql):string
    {
        if(!array_key_exists('approvalReportingDate',$this->config))return $sql;
        $date=$this->config['approvalReportingDate'];
        if(!is_string($date)||preg_match('/^[0-9]{4}-[0-9]{2}-[0-9]{2}$/D',$date)!==1||!self::validDate($date))throw new RuntimeException('Invalid approved-assignment reporting date.');
        return str_replace('CURRENT_DATE()',"DATE '{$date}'",$sql);
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
        [$sort,$direction]=$this->orderParts();
        return $sort.' '.$direction.', '.$this->config['count'].' ASC';
    }

    private function orderParts():array
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
        return [$sort,$direction];
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
