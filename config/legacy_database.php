<?php
return [
    'host' => env('LEGACY_DB_HOST', '127.0.0.1'),
    'port' => env('LEGACY_DB_PORT', '3306'),
    'database' => env('LEGACY_DB_NAME', 'dems_legacy_hr'),
    'username' => env('LEGACY_DB_USERNAME', 'root'),
    'password' => env('LEGACY_DB_PASSWORD', ''),
    'charset' => 'utf8mb4',
    'effective_from' => env('LEGACY_LOCATION_EFFECTIVE_FROM', null),
    'officer_effective_from' => env('LEGACY_OFFICER_EFFECTIVE_FROM', null),
];
