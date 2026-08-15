<?php
declare(strict_types=1);
namespace App\Controllers;

use App\Core\Auth;
use App\Core\Controller;
use App\Core\Database;
use PDO;
use App\Services\ScopedDashboardService;

final class DashboardController extends Controller
{
    public function index(): void
    {
        Auth::requireLogin();
        $dashboard=(new ScopedDashboardService(Database::pdo()))->dashboard((string)Auth::user()['id'],Auth::can('arpa.legacy-preview.view'));
        $counts=$dashboard['counts'];
        $this->render('dashboard/index', compact('dashboard','counts'));
    }

    public static function counterValues(PDO $pdo): array
    {
        return (new ScopedDashboardService($pdo))->enterpriseCounts();
    }
}
