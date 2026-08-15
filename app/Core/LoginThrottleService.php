<?php
declare(strict_types=1);
namespace App\Core;

use PDO;
use Throwable;

final class LoginThrottleService
{
    public const SUCCESS = 'SUCCESS';
    public const FAILED = 'FAILED';
    public const THROTTLED = 'THROTTLED';

    public function __construct(private readonly PDO $pdo)
    {
    }

    public function authenticate(string $username, string $password, string $clientIp): string
    {
        $normalizedUsername = self::normalizeUsername($username);
        $validatedIp = self::normalizeClientIp($clientIp);
        $keys = [
            'USERNAME' => self::keyHash('USERNAME', $normalizedUsername),
            'CLIENT_IP' => self::keyHash('CLIENT_IP', $validatedIp),
        ];
        $authenticated = false;

        $this->cleanupStaleRecords();

        try {
            $this->pdo->beginTransaction();
            $rows = $this->lockThrottleRows($keys);

            if ($this->hasActiveBlock($rows)) {
                $this->audit('LOGIN_THROTTLED', null, $keys, $validatedIp, 'WARNING');
                $this->pdo->commit();
                return self::THROTTLED;
            }

            if (Auth::attempt(trim($username), $password)) {
                $authenticated = true;
                $this->clearLockedRows($keys);
                $this->audit('LOGIN_SUCCESS', (string)($_SESSION['user_id'] ?? ''), $keys, $validatedIp);
                $this->pdo->commit();
                return self::SUCCESS;
            }

            $this->recordLockedFailure($rows);
            $this->audit('LOGIN_FAILED', null, $keys, $validatedIp, 'WARNING');
            $this->pdo->commit();
            return self::FAILED;
        } catch (Throwable $exception) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            if ($authenticated) {
                Auth::logout();
            }
            throw $exception;
        }
    }

    public static function normalizeUsername(string $username): string
    {
        $normalized = strtolower(trim($username));
        return $normalized === '' ? '<empty>' : $normalized;
    }

    public static function clientIp(array $server): string
    {
        return self::normalizeClientIp((string)($server['REMOTE_ADDR'] ?? ''));
    }

    public static function keyHash(string $type, string $value): string
    {
        return hash('sha256', strtoupper($type) . "\0" . $value);
    }

    private static function normalizeClientIp(string $clientIp): string
    {
        $clientIp = trim($clientIp);
        if (filter_var($clientIp, FILTER_VALIDATE_IP) === false) {
            return '<unknown-client>';
        }
        $packed = inet_pton($clientIp);
        return $packed === false ? $clientIp : (string)inet_ntop($packed);
    }

    private function lockThrottleRows(array $keys): array
    {
        $insert = $this->pdo->prepare("INSERT IGNORE INTO login_attempt_throttle(throttle_type,throttle_key,failed_attempt_count,window_started_at) VALUES(?,?,0,NOW())");
        foreach ($keys as $type => $key) {
            $insert->execute([$type, $key]);
        }

        $select = $this->pdo->prepare("SELECT *, NOW() AS database_now, (blocked_until IS NOT NULL AND blocked_until > NOW()) AS is_blocked FROM login_attempt_throttle WHERE throttle_type=? AND throttle_key=? FOR UPDATE");
        $rows = [];
        foreach ($keys as $type => $key) {
            $select->execute([$type, $key]);
            $row = $select->fetch();
            if (!$row) {
                throw new \RuntimeException('Unable to initialize login throttle state.');
            }
            $rows[] = $row;
        }
        return $rows;
    }

    private function hasActiveBlock(array $rows): bool
    {
        foreach ($rows as $row) {
            if ((int)$row['is_blocked'] === 1) {
                return true;
            }
        }
        return false;
    }

    private function recordLockedFailure(array $rows): void
    {
        $maximum = max(1, (int)config('app.login_max_attempts', 5));
        $window = max(1, (int)config('app.login_attempt_window_seconds', 900));
        $block = max(1, (int)config('app.login_block_seconds', 900));
        $update = $this->pdo->prepare('UPDATE login_attempt_throttle SET failed_attempt_count=?, window_started_at=?, blocked_until=? WHERE id=?');

        foreach ($rows as $row) {
            $now = new \DateTimeImmutable((string)$row['database_now']);
            $windowStart = new \DateTimeImmutable((string)$row['window_started_at']);
            $windowExpired = $windowStart->getTimestamp() <= $now->getTimestamp() - $window;
            $failureCount = $windowExpired ? 1 : (int)$row['failed_attempt_count'] + 1;
            $newWindowStart = $windowExpired ? $now : $windowStart;
            $blockedUntil = $failureCount >= $maximum
                ? $now->modify('+' . $block . ' seconds')->format('Y-m-d H:i:s')
                : null;
            $update->execute([
                $failureCount,
                $newWindowStart->format('Y-m-d H:i:s'),
                $blockedUntil,
                $row['id'],
            ]);
        }
    }

    private function clearLockedRows(array $keys): void
    {
        $delete = $this->pdo->prepare('DELETE FROM login_attempt_throttle WHERE throttle_type=? AND throttle_key=?');
        foreach ($keys as $type => $key) {
            $delete->execute([$type, $key]);
        }
    }

    private function cleanupStaleRecords(): void
    {
        $this->pdo->exec("DELETE FROM login_attempt_throttle WHERE updated_at < DATE_SUB(NOW(), INTERVAL 1 DAY) AND (blocked_until IS NULL OR blocked_until <= NOW()) LIMIT 100");
    }

    private function audit(string $action, ?string $actorUserId, array $keys, string $clientIp, string $severity = 'INFO'): void
    {
        $details = json_encode([
            'username_key' => $keys['USERNAME'],
            'client_key' => $keys['CLIENT_IP'],
            'result' => $action,
        ], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        $statement = $this->pdo->prepare("INSERT INTO audit_event(actor_user_id,action_key,target_type,target_id,details_json,severity,source_ip,created_at) VALUES(?,?,'AUTHENTICATION',?,?,?,?,NOW())");
        $statement->execute([
            $actorUserId !== '' ? $actorUserId : null,
            $action,
            $keys['USERNAME'],
            $details,
            $severity,
            $clientIp === '<unknown-client>' ? null : $clientIp,
        ]);
    }
}
