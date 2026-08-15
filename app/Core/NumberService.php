<?php
declare(strict_types=1);
namespace App\Core;

use PDO;

final class NumberService
{
    public static function next(string $categoryKey): string
    {
        return self::nextUsing(Database::pdo(), $categoryKey);
    }

    /**
     * Allocate through the enterprise number tables, participating in an
     * existing caller transaction when one is active.
     */
    public static function nextUsing(PDO $pdo, string $categoryKey): string
    {
        $ownsTransaction = !$pdo->inTransaction();
        if ($ownsTransaction) {
            $pdo->beginTransaction();
        }
        try {
            $stmt = $pdo->prepare('SELECT id, category_code, next_value FROM number_category WHERE category_key = ? AND active = 1 FOR UPDATE');
            $stmt->execute([$categoryKey]);
            $row = $stmt->fetch();
            if (!$row) throw new \RuntimeException("Number category not configured: {$categoryKey}");
            $number = sprintf('%s-%07d', $row['category_code'], (int)$row['next_value']);
            $pdo->prepare('UPDATE number_category SET next_value = next_value + 1 WHERE id = ?')->execute([$row['id']]);
            $pdo->prepare('INSERT INTO number_allocation (category_id, allocated_number, allocated_at) VALUES (?, ?, NOW())')->execute([$row['id'], $number]);
            if ($ownsTransaction) {
                $pdo->commit();
            }
            return $number;
        } catch (\Throwable $e) {
            if ($ownsTransaction && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }
}
