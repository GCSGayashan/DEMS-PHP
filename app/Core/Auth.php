<?php
declare(strict_types=1);
namespace App\Core;

use PDO;

final class Auth
{
    private static ?string $invalidationReason = null;

    public static function attempt(string $username, string $password): bool
    {
        $stmt = Database::pdo()->prepare('SELECT * FROM `system_user` WHERE username = ? AND enabled = 1 AND account_status = \'ACTIVE\' LIMIT 1');
        $stmt->execute([$username]);
        $user = $stmt->fetch();
        if (!$user || empty($user['password_hash']) || !password_verify($password, $user['password_hash'])) {
            return false;
        }
        session_regenerate_id(true);
        $now = time();
        $_SESSION['user_id'] = $user['id'];
        $_SESSION['authenticated_at'] = $now;
        $_SESSION['last_activity_at'] = $now;
        self::$invalidationReason = null;
        return true;
    }

    public static function user(): ?array
    {
        if (empty($_SESSION['user_id'])) return null;

        $now = time();
        $authenticatedAt = (int)($_SESSION['authenticated_at'] ?? 0);
        $lastActivityAt = (int)($_SESSION['last_activity_at'] ?? 0);
        $idleTimeout = max(1, (int)config('app.session_idle_timeout', 1800));
        $absoluteTimeout = max(1, (int)config('app.session_absolute_timeout', 28800));

        if (($authenticatedAt > 0 && $now - $authenticatedAt > $absoluteTimeout)
            || ($lastActivityAt > 0 && $now - $lastActivityAt > $idleTimeout)) {
            self::invalidate('expired');
            return null;
        }

        $stmt = Database::pdo()->prepare("SELECT * FROM `system_user` WHERE id = ? AND enabled = 1 AND account_status = 'ACTIVE' LIMIT 1");
        $stmt->execute([$_SESSION['user_id']]);
        $user = $stmt->fetch() ?: null;
        if ($user === null) {
            self::invalidate('inactive');
            return null;
        }

        // Initialize existing pre-hardening sessions only after active-account revalidation.
        $_SESSION['authenticated_at'] = $authenticatedAt > 0 ? $authenticatedAt : $now;
        $_SESSION['last_activity_at'] = $now;
        return $user;
    }

    public static function check(): bool { return self::user() !== null; }

    public static function logout(): void
    {
        self::$invalidationReason = null;
        self::destroySession();
    }

    private static function invalidate(string $reason): void
    {
        self::destroySession();
        self::$invalidationReason = $reason;
    }

    private static function destroySession(): void
    {
        $_SESSION = [];
        if (ini_get('session.use_cookies')) {
            $params = session_get_cookie_params();
            setcookie(session_name(), '', [
                'expires' => time() - 42000,
                'path' => $params['path'],
                'domain' => $params['domain'],
                'secure' => $params['secure'],
                'httponly' => $params['httponly'],
                'samesite' => $params['samesite'] ?? 'Lax',
            ]);
        }
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_destroy();
        }
    }

    public static function requireLogin(): void
    {
        if (!self::check()) {
            $reason = self::$invalidationReason;
            self::$invalidationReason = null;
            redirect($reason === null ? '/login' : '/login?session=' . rawurlencode($reason));
        }
    }

    public static function permissions(): array
    {
        $u = self::user();
        if (!$u) return [];
        $sql = "SELECT DISTINCT p.permission_key
                FROM user_account_role uar
                JOIN application_role r ON r.id = uar.role_id
                JOIN application_role_permission rp ON rp.role_id = r.id
                JOIN application_permission p ON p.id = rp.permission_id
                WHERE uar.user_id = ?
                  AND uar.active = 1 AND uar.approval_status = 'APPROVED'
                  AND uar.effective_from <= CURRENT_DATE()
                  AND (uar.effective_to IS NULL OR uar.effective_to >= CURRENT_DATE())
                  AND r.active = 1 AND r.approval_status = 'APPROVED'
                  AND p.active = 1";
        $stmt = Database::pdo()->prepare($sql);
        $stmt->execute([$u['id']]);
        return array_column($stmt->fetchAll(PDO::FETCH_ASSOC), 'permission_key');
    }

    public static function can(string $permission): bool
    {
        return in_array($permission, self::permissions(), true);
    }

    public static function requirePermission(string $permission): void
    {
        self::requireLogin();
        if (!self::can($permission)) {
            http_response_code(403);
            View::render('partials/forbidden', ['permission' => $permission]);
            exit;
        }
    }
}
