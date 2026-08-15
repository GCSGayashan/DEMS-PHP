<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auth;
use App\Core\DataTableQuery;
use App\Core\DataTableRegistry;
use App\Core\DataTableRequest;
use App\Core\DataTableResponse;
use App\Core\Database;
use Throwable;

final class DataTableController
{
    public function data(string $key): never
    {
        $request = DataTableRequest::fromGlobals();
        try {
            $config = DataTableRegistry::definition($key, $_GET);
            $this->authorize($config, $request->draw);
            $query = new DataTableQuery(Database::pdo(), $config, $request);
            DataTableResponse::send($query->response());
        } catch (Throwable $e) {
            error_log('DataTable endpoint failed [' . $key . ']: ' . $e->getMessage());
            DataTableResponse::error('Unable to load records. Please try again.', 500, $request->draw);
        }
    }

    public function export(string $key): never
    {
        try {
            $config = DataTableRegistry::definition($key, $_GET);
            $this->authorize($config, 0);
            if (empty($config['export'])) {
                DataTableResponse::error('Export is not available for this table.', 404);
            }
            $query = new DataTableQuery(Database::pdo(), $config, DataTableRequest::fromGlobals());
            $filename = preg_replace('/[^a-z0-9-]+/i', '-', (string)$config['filename']) . '-' . date('Ymd-His') . '.csv';

            header('Content-Type: text/csv; charset=utf-8');
            header('Content-Disposition: attachment; filename="' . $filename . '"');
            header('Cache-Control: no-store, private');
            header('X-Content-Type-Options: nosniff');
            $output = fopen('php://output', 'wb');
            if ($output === false) {
                throw new \RuntimeException('Unable to open CSV output stream.');
            }
            fwrite($output, "\xEF\xBB\xBF");
            fputcsv($output, $query->exportHeaders());
            foreach ($query->exportRows() as $row) {
                fputcsv($output, array_map([$this, 'safeCsvValue'], array_values($row)));
            }
            fclose($output);
            exit;
        } catch (Throwable $e) {
            error_log('DataTable export failed [' . $key . ']: ' . $e->getMessage());
            if (!headers_sent()) {
                DataTableResponse::error('Unable to export records. Please try again.', 500);
            }
            exit;
        }
    }

    private function authorize(array $config, int $draw): void
    {
        if (!Auth::check()) {
            DataTableResponse::error('Authentication required.', 401, $draw);
        }
        if (!Auth::can((string)$config['permission']) || (isset($config['authorize']) && !($config['authorize'])())) {
            DataTableResponse::error('You are not authorized to view these records.', 403, $draw);
        }
    }

    private function safeCsvValue(mixed $value): string
    {
        $value = (string)$value;
        return preg_match('/^[=+\-@]/', $value) === 1 ? "'" . $value : $value;
    }
}
