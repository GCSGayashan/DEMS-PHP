<?php
declare(strict_types=1);

namespace App\Core;

final class DataTableRequest
{
    private const LENGTHS = [10, 25, 50, 100];

    public readonly int $draw;
    public readonly int $start;
    public readonly int $length;
    public readonly string $search;
    public readonly int $orderColumn;
    public readonly string $orderDirection;

    public function __construct(private readonly array $input)
    {
        $this->draw = max(0, (int)($input['draw'] ?? 0));
        $this->start = max(0, (int)($input['start'] ?? 0));
        $length = (int)($input['length'] ?? 25);
        $this->length = in_array($length, self::LENGTHS, true) ? $length : 25;

        $search = trim((string)($input['search']['value'] ?? ''));
        $this->search = function_exists('mb_substr') ? mb_substr($search, 0, 200) : substr($search, 0, 200);
        $this->orderColumn = max(0, (int)($input['order'][0]['column'] ?? 0));
        $direction = strtolower((string)($input['order'][0]['dir'] ?? 'asc'));
        $this->orderDirection = $direction === 'desc' ? 'DESC' : 'ASC';
    }

    public static function fromGlobals(): self
    {
        return new self($_GET);
    }

    public function filter(string $name): ?string
    {
        $filters = $this->input['filters'] ?? [];
        if (!is_array($filters) || !array_key_exists($name, $filters)) {
            return null;
        }
        $value = trim((string)$filters[$name]);
        return $value === '' ? null : (function_exists('mb_substr') ? mb_substr($value, 0, 200) : substr($value, 0, 200));
    }
}
