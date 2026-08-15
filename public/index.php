<?php
declare(strict_types=1);
require dirname(__DIR__) . '/bootstrap.php';

date_default_timezone_set((string)config('app.timezone','Asia/Colombo'));
App\Core\SessionManager::start();
App\Core\SecurityHeaders::apply();

$router = new App\Core\Router();
require BASE_PATH . '/routes/web.php';
$router->dispatch($_SERVER['REQUEST_METHOD'], $_SERVER['REQUEST_URI']);
