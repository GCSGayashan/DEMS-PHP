<?php
declare(strict_types=1);

require dirname(__DIR__) . '/bootstrap.php';

$dbCfg = config('database');
$appCfg = config('app');

$appEnv = strtolower(trim((string)($appCfg['env'] ?? 'production')));
$dbNameRaw = trim((string)($dbCfg['database'] ?? ''));

if ($dbNameRaw === '') {
    fwrite(STDERR, "FAILED: DB_DATABASE is not configured.\n");
    exit(1);
}

$serverDsn = sprintf(
    'mysql:host=%s;port=%s;charset=%s',
    $dbCfg['host'],
    $dbCfg['port'],
    $dbCfg['charset']
);

try {
    $pdo = new PDO(
        $serverDsn,
        $dbCfg['username'],
        $dbCfg['password'],
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::MYSQL_ATTR_MULTI_STATEMENTS => true,
        ]
    );

    if ($appEnv === 'production') {
        $check = $pdo->prepare(
            'SELECT SCHEMA_NAME
             FROM INFORMATION_SCHEMA.SCHEMATA
             WHERE SCHEMA_NAME = ?
             LIMIT 1'
        );
        $check->execute([$dbNameRaw]);

        if (!$check->fetchColumn()) {
            throw new RuntimeException(
                "Production database '{$dbNameRaw}' does not exist. " .
                "Create it using the hosting/database administrator before running migrations."
            );
        }
    } else {
        $dbName = str_replace('`', '``', $dbNameRaw);

        $pdo->exec(
            "CREATE DATABASE IF NOT EXISTS `{$dbName}` " .
            "CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci"
        );
    }

    $dbName = str_replace('`', '``', $dbNameRaw);
    $pdo->exec("USE `{$dbName}`");

    $files = glob(BASE_PATH . '/database/migrations/*.sql') ?: [];
    sort($files);

    foreach ($files as $file) {
        $version = basename($file, '.sql');

        try {
            $exists = $pdo
                ->query("SHOW TABLES LIKE 'schema_migration'")
                ->fetchColumn();

            if ($exists) {
                $stmt = $pdo->prepare(
                    'SELECT COUNT(*)
                     FROM schema_migration
                     WHERE version = ?
                        OR (
                            CHAR_LENGTH(version) = 50
                            AND version = LEFT(?, 50)
                        )'
                );
                $stmt->execute([$version, $version]);

                if ((int)$stmt->fetchColumn() > 0) {
                    echo "SKIP {$version}\n";
                    continue;
                }
            }

            echo "APPLY {$version}\n";

            $sql = file_get_contents($file);
            if ($sql === false) {
                throw new RuntimeException(
                    "Unable to read migration file: {$file}"
                );
            }

            $pdo->exec($sql);

            $stmt = $pdo->prepare(
                'INSERT IGNORE INTO schema_migration(version) VALUES(?)'
            );
            $stmt->execute([$version]);

        } catch (Throwable $e) {
            fwrite(
                STDERR,
                "FAILED {$version}: {$e->getMessage()}\n"
            );
            exit(1);
        }
    }

    echo "Migrations complete.\n";

} catch (Throwable $e) {
    fwrite(STDERR, "Migration startup failed: {$e->getMessage()}\n");
    exit(1);
}