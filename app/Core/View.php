<?php
declare(strict_types=1);
namespace App\Core;

final class View
{
    public static function render(string $view, array $data = [], string $layout = 'layouts/admin'): void
    {
        extract($data, EXTR_SKIP);
        $viewFile = BASE_PATH . '/app/Views/' . $view . '.php';
        if (!is_file($viewFile)) {
            throw new \RuntimeException("View not found: {$view}");
        }
        ob_start();
        require $viewFile;
        $content = ob_get_clean();
        require BASE_PATH . '/app/Views/' . $layout . '.php';
    }
}
