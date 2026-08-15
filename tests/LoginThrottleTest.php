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

    public function run(): int
    {
        $this->pdo = Database::pdo();
        $this->userId = (string)$this->pdo->query('SELECT UUID()')->fetchColumn();
        $this->username = 'throttletest-' . substr(str_replace('-', '', $this->userId), 0, 10);
        $this->pdo->prepare("INSERT INTO system_user(id,identity_type,username,password_hash,account_status,enabled,password_setup_required,created_at) VALUES(?,'STAFF',?,?,'ACTIVE',1,0,NOW())")
            ->execute([$this->userId, $this->username, CredentialService::hashPassword($this->password)]);

        try {
            $this->startSession();
            $this->testPolicyBoundaryAndExpiry();
            $this->testUnknownUsernameThrottle();
            $this->testClientIpThrottle();
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
        }

        echo "LoginThrottleTest: {$this->assertions} assertions passed.\n";
        return 0;
    }

    private function testPolicyBoundaryAndExpiry(): void
    {
        $service = new LoginThrottleService($this->pdo);
        $ip = '192.0.2.41';
        $this->track($this->username, $ip);

        for ($attempt = 1; $attempt <= 4; $attempt++) {
            $this->same(LoginThrottleService::FAILED, $service->authenticate($this->username, 'WrongPassword!1', $ip), "failure {$attempt} remains a normal failed login");
        }
        $this->same(LoginThrottleService::FAILED, $service->authenticate($this->username, 'WrongPassword!1', $ip), 'fifth failure creates throttle state after returning a normal failure');
        $this->same(5, $this->throttleCount('USERNAME', $this->username), 'username counter reaches five');
        $this->same(5, $this->throttleCount('CLIENT_IP', $ip), 'client IP counter reaches five');
        $this->same(true, $this->isBlocked('USERNAME', $this->username), 'fifth failure blocks the username key');
        $this->same(true, $this->isBlocked('CLIENT_IP', $ip), 'fifth failure blocks the client key');
        $this->same(LoginThrottleService::THROTTLED, $service->authenticate($this->username, $this->password, $ip), 'sixth attempt during the block is throttled before authentication');

        $this->expire($this->username, $ip);
        $this->same(LoginThrottleService::SUCCESS, $service->authenticate($this->username, $this->password, $ip), 'login succeeds after the temporary block and window expire');
        $this->same(0, $this->matchingRows($this->username, $ip), 'successful login clears relevant username and client throttle rows');
        $this->same($this->userId, (string)($_SESSION['user_id'] ?? ''), 'successful throttled login continues through existing Auth session setup');
        Auth::logout();
        $this->startSession();
    }

    private function testUnknownUsernameThrottle(): void
    {
        $service = new LoginThrottleService($this->pdo);
        $username = 'unknown-' . substr(str_replace('-', '', $this->userId), 0, 8);
        $ip = '198.51.100.17';
        $this->track($username, $ip);
        for ($attempt = 1; $attempt <= 5; $attempt++) {
            $this->same(LoginThrottleService::FAILED, $service->authenticate($username, 'UnknownPassword!1', $ip), "unknown username failure {$attempt} is recorded generically");
        }
        $this->same(LoginThrottleService::THROTTLED, $service->authenticate($username, 'UnknownPassword!1', $ip), 'unknown username is throttled');
        $this->same(1, $this->rowCount('USERNAME', $username), 'unknown username uses one deduplicated throttle row');
    }

    private function testClientIpThrottle(): void
    {
        $service = new LoginThrottleService($this->pdo);
        $ip = '203.0.113.54';
        for ($attempt = 1; $attempt <= 5; $attempt++) {
            $username = 'rotating-' . $attempt . '-' . substr($this->userId, 0, 6);
            $this->track($username, $ip);
            $this->same(LoginThrottleService::FAILED, $service->authenticate($username, 'WrongPassword!1', $ip), "client failure {$attempt} is recorded across rotating usernames");
        }
        $sixthUsername = 'rotating-6-' . substr($this->userId, 0, 6);
        $this->track($sixthUsername, $ip);
        $this->same(LoginThrottleService::THROTTLED, $service->authenticate($sixthUsername, 'WrongPassword!1', $ip), 'client IP is throttled despite username rotation');
        $this->same(5, $this->throttleCount('CLIENT_IP', $ip), 'shared client counter is not bypassed by username rotation');
        $this->same('192.0.2.99', LoginThrottleService::clientIp(['REMOTE_ADDR' => '192.0.2.99', 'HTTP_X_FORWARDED_FOR' => '203.0.113.200']), 'untrusted forwarded address is ignored');
    }

    private function testDisabledAccountIsGeneric(): void
    {
        $service = new LoginThrottleService($this->pdo);
        $ip = '192.0.2.88';
        $this->track($this->username, $ip);
        $this->pdo->prepare('UPDATE system_user SET enabled=0 WHERE id=?')->execute([$this->userId]);
        $this->same(LoginThrottleService::FAILED, $service->authenticate($this->username, $this->password, $ip), 'disabled account receives the same failed result as invalid credentials');
        $this->same(false, isset($_SESSION['user_id']), 'disabled account does not establish authentication');
        $this->pdo->prepare('UPDATE system_user SET enabled=1 WHERE id=?')->execute([$this->userId]);
    }

    private function testAuditAndCredentialSafety(): void
    {
        $actions = $this->pdo->prepare("SELECT action_key,actor_user_id,details_json FROM audit_event WHERE target_type='AUTHENTICATION' AND target_id=? ORDER BY id");
        $target = LoginThrottleService::keyHash('USERNAME', LoginThrottleService::normalizeUsername($this->username));
        $actions->execute([$target]);
        $events = $actions->fetchAll();
        $actionKeys = array_column($events, 'action_key');
        $this->same(true, in_array('LOGIN_FAILED', $actionKeys, true), 'failed login is audited');
        $this->same(true, in_array('LOGIN_THROTTLED', $actionKeys, true), 'throttled login is audited');
        $this->same(true, in_array('LOGIN_SUCCESS', $actionKeys, true), 'successful login is audited');
        $success = array_values(array_filter($events, static fn(array $event): bool => $event['action_key'] === 'LOGIN_SUCCESS'));
        $this->same($this->userId, (string)($success[0]['actor_user_id'] ?? ''), 'successful audit identifies the authenticated user');

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
    }

    private function track(string $username, string $ip): void
    {
        $usernameKey = LoginThrottleService::keyHash('USERNAME', LoginThrottleService::normalizeUsername($username));
        $clientKey = LoginThrottleService::keyHash('CLIENT_IP', $ip);
        $this->createdKeys[] = $usernameKey;
        $this->createdKeys[] = $clientKey;
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

    private function expire(string $username, string $ip): void
    {
        $keys = [
            LoginThrottleService::keyHash('USERNAME', LoginThrottleService::normalizeUsername($username)),
            LoginThrottleService::keyHash('CLIENT_IP', $ip),
        ];
        $statement = $this->pdo->prepare('UPDATE login_attempt_throttle SET blocked_until=DATE_SUB(NOW(),INTERVAL 1 SECOND),window_started_at=DATE_SUB(NOW(),INTERVAL 901 SECOND) WHERE throttle_key IN(?,?)');
        $statement->execute($keys);
    }

    private function matchingRows(string $username, string $ip): int
    {
        $keys = [
            LoginThrottleService::keyHash('USERNAME', LoginThrottleService::normalizeUsername($username)),
            LoginThrottleService::keyHash('CLIENT_IP', $ip),
        ];
        $statement = $this->pdo->prepare('SELECT COUNT(*) FROM login_attempt_throttle WHERE throttle_key IN(?,?)');
        $statement->execute($keys);
        return (int)$statement->fetchColumn();
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

    private function same(mixed $expected, mixed $actual, string $message): void
    {
        $this->assertions++;
        if ($expected !== $actual) {
            throw new RuntimeException("{$message}: expected " . var_export($expected, true) . ', got ' . var_export($actual, true));
        }
    }
}

exit((new LoginThrottleTest())->run());
