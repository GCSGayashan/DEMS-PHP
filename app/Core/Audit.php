<?php
declare(strict_types=1);
namespace App\Core;

final class Audit
{
    public static function record(string $action, string $targetType, ?string $targetId = null, array $details = [], string $severity = 'INFO'): void
    {
        $user = Auth::user();
        $stmt = Database::pdo()->prepare('INSERT INTO audit_event (actor_user_id, action_key, target_type, target_id, details_json, severity, source_ip, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, NOW())');
        $stmt->execute([$user['id'] ?? null, $action, $targetType, $targetId, json_encode($details, JSON_UNESCAPED_UNICODE), $severity, $_SERVER['REMOTE_ADDR'] ?? null]);
    }
}
