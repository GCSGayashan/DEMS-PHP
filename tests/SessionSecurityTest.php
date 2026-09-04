<?php
declare(strict_types=1);

use App\Core\{Auth,CredentialService,Csrf,Database,SessionManager};

require dirname(__DIR__) . '/bootstrap.php';

final class SessionSecurityTest
{
    private PDO $pdo;
    private int $assertions = 0;
    private string $userId = '';
    private string $username = '';
    private string $password = 'SessionPassword!123';
    private string $sessionDirectory = '';

    public function run(): int
    {
        $this->sessionDirectory = rtrim(sys_get_temp_dir(), '/\\') . DIRECTORY_SEPARATOR . 'dems-session-test-' . bin2hex(random_bytes(8));
        if (!mkdir($this->sessionDirectory, 0700) && !is_dir($this->sessionDirectory)) {
            throw new RuntimeException('Unable to create the isolated session-test directory.');
        }
        if (ini_set('session.save_path', $this->sessionDirectory) === false) {
            throw new RuntimeException('Unable to configure the isolated session-test directory.');
        }
        $this->pdo = Database::pdo();
        $this->userId = (string)$this->pdo->query('SELECT UUID()')->fetchColumn();
        $this->username = 'sessiontest-' . substr(str_replace('-', '', $this->userId), 0, 10);
        $this->pdo->prepare("INSERT INTO system_user(id, identity_type, username, password_hash, account_status, enabled, password_setup_required, created_at) VALUES(?, 'STAFF', ?, ?, 'ACTIVE', 1, 0, NOW())")
            ->execute([$this->userId, $this->username, CredentialService::hashPassword($this->password)]);

        try {
            $this->testCookiePolicy();
            $this->testLoginAndActiveSession();
            $this->testIdleTimeout();
            $this->testAbsoluteTimeout();
            $this->testDisabledAccountRevalidation();
            $this->testInactiveStatusRevalidation();
            $this->testLogoutAndCsrf();
            $this->testSafeConfigurationFallbacks();
        } finally {
            if (session_status() === PHP_SESSION_ACTIVE) {
                Auth::logout();
            }
            $this->pdo->prepare('DELETE FROM system_user WHERE id = ?')->execute([$this->userId]);
            $this->removeSessionDirectory();
        }

        echo "SessionSecurityTest: {$this->assertions} assertions passed.\n";
        return 0;
    }

    private function testCookiePolicy(): void
    {
        $local = SessionManager::cookieOptionsFor('development', 'http://localhost/DEMS-PHP/public');
        $production = SessionManager::cookieOptionsFor('production', 'https://dems.example.gov.lk');

        $this->same(false, $local['secure'], 'localhost HTTP development permits a non-Secure cookie');
        $this->same(true, $production['secure'], 'production HTTPS requires a Secure cookie');
        $this->same(true, $production['httponly'], 'session cookie is HttpOnly');
        $this->same('Lax', $production['samesite'], 'session cookie uses SameSite Lax');
        $this->same('/', $production['path'], 'session cookie is valid for the application root');

        $this->startFreshSession();
        $params = session_get_cookie_params();
        $this->same('1', (string)ini_get('session.use_strict_mode'), 'strict session mode is enabled');
        $this->same(true, $params['httponly'], 'configured session cookie is HttpOnly');
        $this->same('Lax', $params['samesite'], 'configured session cookie uses SameSite Lax');
    }

    private function testLoginAndActiveSession(): void
    {
        $before = session_id();
        $this->same(true, Auth::attempt($this->username, $this->password), 'valid active account can authenticate');
        $this->same(true, session_id() !== '' && session_id() !== $before, 'successful login regenerates the session ID');
        $this->same(true, isset($_SESSION['authenticated_at']) && is_int($_SESSION['authenticated_at']), 'login initializes authenticated timestamp');
        $this->same(true, isset($_SESSION['last_activity_at']) && is_int($_SESSION['last_activity_at']), 'login initializes activity timestamp');

        $previousActivity = (int)$_SESSION['last_activity_at'];
        $user = Auth::user();
        $this->same($this->userId, (string)($user['id'] ?? ''), 'active authenticated session remains valid');
        $this->same(true, (int)$_SESSION['last_activity_at'] >= $previousActivity, 'valid activity refreshes idle timestamp');
    }

    private function testIdleTimeout(): void
    {
        $_SESSION['authenticated_at'] = time();
        $_SESSION['last_activity_at'] = time() - (int)config('app.session_idle_timeout', 1800) - 1;
        $this->same(null, Auth::user(), 'idle timeout invalidates authentication');
        $this->same(false, isset($_SESSION['user_id']), 'idle timeout clears authenticated user state');
    }

    private function testAbsoluteTimeout(): void
    {
        $this->authenticateFresh();
        $_SESSION['authenticated_at'] = time() - (int)config('app.session_absolute_timeout', 28800) - 1;
        $_SESSION['last_activity_at'] = time();
        $this->same(null, Auth::user(), 'absolute timeout invalidates authentication');
        $this->same(false, isset($_SESSION['user_id']), 'absolute timeout clears authenticated user state');
    }

    private function testDisabledAccountRevalidation(): void
    {
        $this->authenticateFresh();
        $this->pdo->prepare('UPDATE system_user SET enabled = 0 WHERE id = ?')->execute([$this->userId]);
        $this->same(null, Auth::user(), 'disabled account cannot continue an existing session');
        $this->same(false, isset($_SESSION['user_id']), 'disabled-account revalidation clears authentication');
        $this->pdo->prepare('UPDATE system_user SET enabled = 1 WHERE id = ?')->execute([$this->userId]);
    }

    private function testInactiveStatusRevalidation(): void
    {
        $this->authenticateFresh();
        $this->pdo->prepare("UPDATE system_user SET account_status = 'SUSPENDED' WHERE id = ?")->execute([$this->userId]);
        $this->same(null, Auth::user(), 'non-ACTIVE account cannot continue an existing session');
        $this->same(false, isset($_SESSION['user_id']), 'inactive-status revalidation clears authentication');
        $this->pdo->prepare("UPDATE system_user SET account_status = 'ACTIVE' WHERE id = ?")->execute([$this->userId]);
    }

    private function testLogoutAndCsrf(): void
    {
        $this->authenticateFresh();
        $token = Csrf::token();
        $_POST['_csrf'] = $token;
        Csrf::validate();
        $this->same($token, (string)($_SESSION['_csrf'] ?? ''), 'valid CSRF behavior remains unchanged');

        Auth::logout();
        $this->same(false, isset($_SESSION['user_id']), 'logout clears authenticated user state');
        $this->same(false, isset($_SESSION['authenticated_at']), 'logout clears authentication timestamp');
        $this->same(false, isset($_SESSION['last_activity_at']), 'logout clears activity timestamp');
    }

    private function testSafeConfigurationFallbacks(): void
    {
        $source = (string)file_get_contents(BASE_PATH . '/config/app.php');
        $this->same(true, str_contains($source, "env('APP_ENV', 'production')"), 'missing APP_ENV falls back to production');
        $this->same(true, str_contains($source, "env('APP_DEBUG', false)"), 'missing APP_DEBUG falls back to false');
        $this->same(true, str_contains((string)file_get_contents(BASE_PATH . '/.env.example'), 'SESSION_IDLE_TIMEOUT=1800'), 'idle timeout is documented');
        $this->same(true, str_contains((string)file_get_contents(BASE_PATH . '/.env.example'), 'SESSION_ABSOLUTE_TIMEOUT=28800'), 'absolute timeout is documented');
    }

    private function authenticateFresh(): void
    {
        $this->startFreshSession();
        $this->same(true, Auth::attempt($this->username, $this->password), 'test account reauthenticates');
    }

    private function startFreshSession(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            Auth::logout();
        }
        session_id('');
        SessionManager::start();
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

exit((new SessionSecurityTest())->run());
