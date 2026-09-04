<?php
declare(strict_types=1);

use App\Core\{Auth,CredentialService,Database,LoginThrottleService,SessionManager};

require dirname(__DIR__) . '/bootstrap.php';

final class LoginThrottleTest
{
    private PDO $pdo;
    private int $assertions = 0;
    private string $userId = '';
    private string $username = '';
    private string $password = 'ThrottlePassword!123';
    private array $createdKeys = [];
    private array $auditTargets = [];
    private string $sessionDirectory = '';

    public function run(): int
    {
        $this->configureSessionDirectory();
        $this->pdo = Database::pdo();
        $this->userId = (string)$this->pdo->query('SELECT UUID()')->fetchColumn();
        $this->username = 'throttletest-' . substr(str_replace('-', '', $this->userId), 0, 10);
        $this->pdo->prepare("INSERT INTO system_user(id,identity_type,username,password_hash,account_status,enabled,password_setup_required,created_at) VALUES(?,'STAFF',?,?,'ACTIVE',1,0,NOW())")
            ->execute([$this->userId, $this->username, CredentialService::hashPassword($this->password)]);

        try {
            $this->startSession();
            $this->testPolicyBoundaryAndExpiry();
            $this->testUnknownUsernameThrottle();
            $this->testUsernameIsolationBehindSharedIp();
            $this->testExistingClientIpRowIsIgnored();
            $this->testDisabledAccountIsGeneric();
            $this->testAuditAndCredentialSafety();
            $this->testCsrfAndConfiguration();
        } finally {
            if (session_status() === PHP_SESSION_ACTIVE) {
                Auth::logout();
            }
            $this->pdo->prepare("UPDATE system_user SET enabled=1,account_status='ACTIVE' WHERE id=?")->execute([$this->userId]);
            foreach (array_unique($this->auditTargets) as $target) {
                $this->pdo->prepare("DELETE FROM audit_event WHERE target_type='AUTHENTICATION' AND target_id=?")->execute([$target]);
            }
            foreach (array_unique($this->createdKeys) as $key) {
                $this->pdo->prepare('DELETE FROM login_attempt_throttle WHERE throttle_key=?')->execute([$key]);
            }
            $this->pdo->prepare('DELETE FROM system_user WHERE id=?')->execute([$this->userId]);
            $this->removeSessionDirectory();
        }

        echo "LoginThrottleTest: {$this->assertions} assertions passed.\n";
        return 0;
    }

    private function testPolicyBoundaryAndExpiry(): void
    {
        $service = new LoginThrottleService($this->pdo);
        $ip = '192.0.2.41';
        $this->track($this->username);

        for ($attempt = 1; $attempt <= 4; $attempt++) {
            $this->same(LoginThrottleService::FAILED, $service->authenticate($this->username, 'WrongPassword!1', $ip), "failure {$attempt} remains a normal failed login");
        }
        $this->same(LoginThrottleService::FAILED, $service->authenticate($this->username, 'WrongPassword!1', $ip), 'fifth failure creates throttle state after returning a normal failure');
        $this->same(5, $this->throttleCount('USERNAME', $this->username), 'username counter reaches five');
        $this->same(0, $this->rowCount('CLIENT_IP', $ip), 'failed login does not create a client IP throttle row');
        $this->same(true, $this->isBlocked('USERNAME', $this->username), 'fifth failure blocks the username key');
        $this->same(LoginThrottleService::THROTTLED, $service->authenticate($this->username, $this->password, $ip), 'sixth attempt during the block is throttled before authentication');

        $this->expire($this->username);
        $this->same(LoginThrottleService::SUCCESS, $service->authenticate($this->username, $this->password, $ip), 'login succeeds after the temporary block and window expire');
        $this->same(0, $this->rowCount('USERNAME', $this->username), 'successful login clears only the username throttle row');
        $this->same($this->userId, (string)($_SESSION['user_id'] ?? ''), 'successful throttled login continues through existing Auth session setup');
        Auth::logout();
        $this->startSession();
    }

    private function testUnknownUsernameThrottle(): void
    {
        $service = new LoginThrottleService($this->pdo);
        $username = 'unknown-' . substr(str_replace('-', '', $this->userId), 0, 8);
        $ip = '198.51.100.17';
        $this->track($username);
        for ($attempt = 1; $attempt <= 5; $attempt++) {
            $this->same(LoginThrottleService::FAILED, $service->authenticate($username, 'UnknownPassword!1', $ip), "unknown username failure {$attempt} is recorded generically");
        }
        $this->same(LoginThrottleService::THROTTLED, $service->authenticate($username, 'UnknownPassword!1', $ip), 'unknown username is throttled');
        $this->same(1, $this->rowCount('USERNAME', $username), 'unknown username uses one deduplicated throttle row');
    }

    private function testUsernameIsolationBehindSharedIp(): void
    {
        $service = new LoginThrottleService($this->pdo);
        $ip = '203.0.113.54';
        $usernameA = 'shared-a-' . substr($this->userId, 0, 6);
        $usernameB = 'shared-b-' . substr($this->userId, 0, 6);
        $this->track($usernameA);
        $this->track($usernameB);

        for ($attempt = 1; $attempt <= 4; $attempt++) {
            $this->same(LoginThrottleService::FAILED, $service->authenticate($usernameA, 'WrongPassword!1', $ip), "shared-IP user A failure {$attempt} remains independent");
            $this->same(LoginThrottleService::FAILED, $service->authenticate($usernameB, 'WrongPassword!1', $ip), "shared-IP user B failure {$attempt} remains independent");
        }
        $this->same(false, $this->isBlocked('USERNAME', $usernameA), 'user A is not blocked after four failures');
        $this->same(false, $this->isBlocked('USERNAME', $usernameB), 'user B is not blocked after four failures');
        $this->same(0, $this->rowCount('CLIENT_IP', $ip), 'shared office IP has no throttle counter');

        $this->same(LoginThrottleService::FAILED, $service->authenticate($usernameA, 'WrongPassword!1', $ip), 'fifth failure blocks only user A');
        $this->same(LoginThrottleService::THROTTLED, $service->authenticate($usernameA, 'WrongPassword!1', '198.51.100.201'), 'user A remains blocked when changing IP address');
        $this->same(LoginThrottleService::SUCCESS, $service->authenticate($this->username, $this->password, $ip), 'another user can log in from the same IP while user A is blocked');
        $this->same(4, $this->throttleCount('USERNAME', $usernameB), 'user B counter is unchanged by user A lockout');
        Auth::logout();
        $this->startSession();
        $this->same('192.0.2.99', LoginThrottleService::clientIp(['REMOTE_ADDR' => '192.0.2.99', 'HTTP_X_FORWARDED_FOR' => '203.0.113.200']), 'untrusted forwarded address is ignored');
    }

    private function testExistingClientIpRowIsIgnored(): void
    {
        $service = new LoginThrottleService($this->pdo);
        $ip = '198.51.100.77';
        $clientKey = LoginThrottleService::keyHash('CLIENT_IP', $ip);
        $this->createdKeys[] = $clientKey;
        $this->pdo->prepare("INSERT INTO login_attempt_throttle(throttle_type,throttle_key,failed_attempt_count,window_started_at,blocked_until) VALUES('CLIENT_IP',?,5,NOW(),DATE_ADD(NOW(),INTERVAL 15 MINUTE)) ON DUPLICATE KEY UPDATE failed_attempt_count=5,window_started_at=NOW(),blocked_until=DATE_ADD(NOW(),INTERVAL 15 MINUTE)")
            ->execute([$clientKey]);

        $this->same(true, $this->isBlocked('CLIENT_IP', $ip), 'pre-existing client IP throttle fixture is actively blocked');
        $this->same(LoginThrottleService::SUCCESS, $service->authenticate($this->username, $this->password, $ip), 'pre-existing client IP row does not block authentication');
        $this->same(true, $this->isBlocked('CLIENT_IP', $ip), 'successful login does not depend on or clear the client IP row');
        Auth::logout();
        $this->startSession();
    }

    private function testDisabledAccountIsGeneric(): void
    {
        $service = new LoginThrottleService($this->pdo);
        $ip = '192.0.2.88';
        $this->track($this->username);
        $this->pdo->prepare('UPDATE system_user SET enabled=0 WHERE id=?')->execute([$this->userId]);
        $this->same(LoginThrottleService::FAILED, $service->authenticate($this->username, $this->password, $ip), 'disabled account receives the same failed result as invalid credentials');
        $this->same(false, isset($_SESSION['user_id']), 'disabled account does not establish authentication');
        $this->pdo->prepare('UPDATE system_user SET enabled=1 WHERE id=?')->execute([$this->userId]);
    }

    private function testAuditAndCredentialSafety(): void
    {
        $actions = $this->pdo->prepare("SELECT action_key,actor_user_id,details_json,source_ip FROM audit_event WHERE target_type='AUTHENTICATION' AND target_id=? ORDER BY id");
        $target = LoginThrottleService::keyHash('USERNAME', LoginThrottleService::normalizeUsername($this->username));
        $actions->execute([$target]);
        $events = $actions->fetchAll();
        $actionKeys = array_column($events, 'action_key');
        $this->same(true, in_array('LOGIN_FAILED', $actionKeys, true), 'failed login is audited');
        $this->same(true, in_array('LOGIN_THROTTLED', $actionKeys, true), 'throttled login is audited');
        $this->same(true, in_array('LOGIN_SUCCESS', $actionKeys, true), 'successful login is audited');
        $success = array_values(array_filter($events, static fn(array $event): bool => $event['action_key'] === 'LOGIN_SUCCESS'));
        $this->same($this->userId, (string)($success[0]['actor_user_id'] ?? ''), 'successful audit identifies the authenticated user');
        $this->same(true, in_array('192.0.2.41', array_column($events, 'source_ip'), true), 'validated client IP remains in authentication audit events');
        $this->same(false, str_contains((string)json_encode($events, JSON_UNESCAPED_SLASHES), 'client_key'), 'audit details no longer treat client IP as a throttle key');

        $columns = (int)$this->pdo->query("SELECT COUNT(*) FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='login_attempt_throttle' AND column_name LIKE '%password%'")->fetchColumn();
        $this->same(0, $columns, 'throttle table has no password column');
        $serialized = json_encode($events, JSON_UNESCAPED_SLASHES);
        $this->same(false, str_contains((string)$serialized, $this->password), 'password is absent from authentication audit values');
        $tableValues = (string)$this->pdo->query("SELECT COALESCE(GROUP_CONCAT(CONCAT_WS('|',throttle_type,throttle_key,failed_attempt_count,window_started_at,COALESCE(blocked_until,'')) SEPARATOR '||'),'') FROM login_attempt_throttle")->fetchColumn();
        $this->same(false, str_contains($tableValues, $this->password), 'password is absent from throttle storage');
    }

    private function testCsrfAndConfiguration(): void
    {
        $controller = (string)file_get_contents(BASE_PATH . '/app/Controllers/AuthController.php');
        $csrfPosition = strpos($controller, 'Csrf::validate();');
        $throttlePosition = strpos($controller, '$result = (new LoginThrottleService');
        $this->same(true, $csrfPosition !== false && $throttlePosition !== false && $csrfPosition < $throttlePosition, 'login CSRF validation runs before throttling and password verification');
        $this->same(true, str_contains((string)file_get_contents(BASE_PATH . '/app/Views/auth/login.php'), 'Csrf::field()'), 'login form retains its CSRF token');
        $this->same(5, (int)config('app.login_max_attempts'), 'maximum attempt fallback is five');
        $this->same(900, (int)config('app.login_attempt_window_seconds'), 'attempt window fallback is fifteen minutes');
        $this->same(900, (int)config('app.login_block_seconds'), 'temporary block fallback is fifteen minutes');
        $this->same(true, str_contains($controller, 'Invalid username or password.'), 'invalid credential message remains generic');
        $this->same(true, str_contains($controller, 'Too many login attempts. Please try again later.'), 'temporary throttle message remains generic');

        $migration = (string)file_get_contents(BASE_PATH . '/database/migrations/044_login_attempt_throttle.sql');
        $this->same(true, str_contains($migration, 'UNIQUE KEY uq_login_throttle_type_key'), 'migration enforces duplicate-row safety');
        $service = (string)file_get_contents(BASE_PATH . '/app/Core/LoginThrottleService.php');
        $this->same(true, str_contains($service, 'FOR UPDATE'), 'counter rows are locked during authentication');
        $this->same(false, str_contains($service, 'HTTP_X_FORWARDED_FOR'), 'throttle service does not trust X-Forwarded-For');
        $this->same(false, str_contains($service, "'CLIENT_IP' =>"), 'client IP is absent from the throttle key collection');
    }

    private function track(string $username): void
    {
        $usernameKey = LoginThrottleService::keyHash('USERNAME', LoginThrottleService::normalizeUsername($username));
        $this->createdKeys[] = $usernameKey;
        $this->auditTargets[] = $usernameKey;
    }

    private function throttleCount(string $type, string $value): int
    {
        $key = $type === 'USERNAME'
            ? LoginThrottleService::keyHash($type, LoginThrottleService::normalizeUsername($value))
            : LoginThrottleService::keyHash($type, $value);
        $statement = $this->pdo->prepare('SELECT failed_attempt_count FROM login_attempt_throttle WHERE throttle_type=? AND throttle_key=?');
        $statement->execute([$type, $key]);
        return (int)$statement->fetchColumn();
    }

    private function isBlocked(string $type, string $value): bool
    {
        $key = $type === 'USERNAME'
            ? LoginThrottleService::keyHash($type, LoginThrottleService::normalizeUsername($value))
            : LoginThrottleService::keyHash($type, $value);
        $statement = $this->pdo->prepare('SELECT blocked_until > NOW() FROM login_attempt_throttle WHERE throttle_type=? AND throttle_key=?');
        $statement->execute([$type, $key]);
        return (int)$statement->fetchColumn() === 1;
    }

    private function expire(string $username): void
    {
        $key = LoginThrottleService::keyHash('USERNAME', LoginThrottleService::normalizeUsername($username));
        $statement = $this->pdo->prepare("UPDATE login_attempt_throttle SET blocked_until=DATE_SUB(NOW(),INTERVAL 1 SECOND),window_started_at=DATE_SUB(NOW(),INTERVAL 901 SECOND) WHERE throttle_type='USERNAME' AND throttle_key=?");
        $statement->execute([$key]);
    }

    private function rowCount(string $type, string $value): int
    {
        $key = $type === 'USERNAME'
            ? LoginThrottleService::keyHash($type, LoginThrottleService::normalizeUsername($value))
            : LoginThrottleService::keyHash($type, $value);
        $statement = $this->pdo->prepare('SELECT COUNT(*) FROM login_attempt_throttle WHERE throttle_type=? AND throttle_key=?');
        $statement->execute([$type, $key]);
        return (int)$statement->fetchColumn();
    }

    private function startSession(): void
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            session_id('');
            SessionManager::start();
        }
    }

    private function configureSessionDirectory(): void
    {
        $this->sessionDirectory = rtrim(sys_get_temp_dir(), '/\\') . DIRECTORY_SEPARATOR . 'dems-login-throttle-test-' . bin2hex(random_bytes(8));
        if (!mkdir($this->sessionDirectory, 0700) && !is_dir($this->sessionDirectory)) {
            throw new RuntimeException('Unable to create the isolated login-throttle session directory.');
        }
        if (ini_set('session.save_path', $this->sessionDirectory) === false) {
            throw new RuntimeException('Unable to configure the isolated login-throttle session directory.');
        }
    }

    private function removeSessionDirectory(): void
    {
        if ($this->sessionDirectory === '' || !is_dir($this->sessionDirectory)) {
            return;
        }
        foreach (glob($this->sessionDirectory . DIRECTORY_SEPARATOR . 'sess_*') ?: [] as $file) {
            if (is_file($file)) {
                unlink($file);
            }
        }
        rmdir($this->sessionDirectory);
    }

    private function same(mixed $expected, mixed $actual, string $message): void
    {
        $this->assertions++;
        if ($expected !== $actual) {
            throw new RuntimeException("{$message}: expected " . var_export($expected, true) . ', got ' . var_export($actual, true));
        }
    }
}

exit((new LoginThrottleTest())->run());
