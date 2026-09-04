<?php
declare(strict_types=1);

use App\Core\{Auth,Database,SecurityHeaders,SessionManager,View};

require dirname(__DIR__) . '/bootstrap.php';

final class SecurityHeadersTest
{
    private int $assertions = 0;
    private string $sessionDirectory = '';

    public function run(): int
    {
        $this->configureSessionDirectory();
        $nonce = SecurityHeaders::generateNonce();
        $development = SecurityHeaders::headersFor('development', 'http://localhost/DEMS-PHP/public', $nonce);
        $production = SecurityHeaders::headersFor('production', 'https://dems.example.gov.lk', $nonce);
        $csp = $development['Content-Security-Policy'] ?? '';

        try {
            $this->testRequiredHeaders($development, $production);
            $this->testPolicy($csp, $nonce);
            $this->testNonceBehavior();
            $this->testInlineScripts();
            $this->testRenderedLayouts();
            $this->testResponseCompatibility();
        } finally {
            if (session_status() === PHP_SESSION_ACTIVE) {
                Auth::logout();
            }
            $this->removeSessionDirectory();
        }

        echo "SecurityHeadersTest: {$this->assertions} assertions passed.\n";
        return 0;
    }

    private function testRequiredHeaders(array $development, array $production): void
    {
        $this->same(true, isset($development['Content-Security-Policy']), 'CSP is configured');
        $this->same('SAMEORIGIN', $development['X-Frame-Options'] ?? null, 'X-Frame-Options is SAMEORIGIN');
        $this->same('nosniff', $development['X-Content-Type-Options'] ?? null, 'X-Content-Type-Options is nosniff');
        $this->same('strict-origin-when-cross-origin', $development['Referrer-Policy'] ?? null, 'strict referrer policy is configured');
        $this->same('camera=(), microphone=(), geolocation=()', $development['Permissions-Policy'] ?? null, 'sensitive browser capabilities are disabled');
        $this->same(false, isset($development['Strict-Transport-Security']), 'localhost development does not receive HSTS');
        $this->same('max-age=31536000; includeSubDomains', $production['Strict-Transport-Security'] ?? null, 'production HTTPS receives HSTS');
        $this->same(false, isset(SecurityHeaders::headersFor('production', 'http://dems.example.gov.lk', SecurityHeaders::generateNonce())['Strict-Transport-Security']), 'production HTTP configuration does not receive HSTS');
        $this->same('no-store, no-cache, must-revalidate, private', $development['Cache-Control'] ?? null, 'dynamic responses use centralized no-store caching');
        $this->same('no-cache', $development['Pragma'] ?? null, 'legacy cache prevention remains available');
    }

    private function testPolicy(string $csp, string $nonce): void
    {
        foreach ([
            "default-src 'self'",
            "object-src 'none'",
            "base-uri 'self'",
            "frame-ancestors 'self'",
            "form-action 'self'",
            "script-src-attr 'none'",
            "connect-src 'self'",
        ] as $directive) {
            $this->same(true, str_contains($csp, $directive), "CSP contains {$directive}");
        }

        preg_match('/(?:^|; )script-src ([^;]+)/', $csp, $scriptDirective);
        $scriptSources = $scriptDirective[1] ?? '';
        $this->same(true, str_contains($scriptSources, "'self'"), 'scripts allow the application origin');
        $this->same(true, str_contains($scriptSources, "'nonce-{$nonce}'"), 'scripts allow the response nonce');
        $this->same(true, str_contains($scriptSources, 'https://cdn.jsdelivr.net'), 'scripts allow the required Bootstrap CDN');
        $this->same(false, str_contains($scriptSources, "'unsafe-inline'"), 'script-src does not allow unsafe-inline');
        $this->same(false, preg_match('/(?:^|\s)\*(?:\s|$)/', $scriptSources) === 1, 'script-src has no wildcard');
        $this->same(false, preg_match('/default-src\s+\*/', $csp) === 1, 'default-src has no wildcard');
        $this->same(true, str_contains($csp, "style-src 'self' 'unsafe-inline' https://cdn.jsdelivr.net"), 'inline CSS and the required CDN remain compatible');
        $this->same(true, str_contains($csp, "font-src 'self' https://cdn.jsdelivr.net data:"), 'Bootstrap Icon fonts remain compatible');
        preg_match_all('~https://[^\s;]+~', $csp, $origins);
        $this->same(['https://cdn.jsdelivr.net'], array_values(array_unique($origins[0] ?? [])), 'jsDelivr is the only remote CSP origin');
    }

    private function testNonceBehavior(): void
    {
        $first = SecurityHeaders::generateNonce();
        $second = SecurityHeaders::generateNonce();
        $decoded = base64_decode($first, true);
        $this->same(true, $first !== $second, 'different responses can receive different nonces');
        $this->same(true, $decoded !== false && strlen($decoded) === 24, 'nonce contains 192 bits from random_bytes');
        $this->same(true, preg_match('/^[A-Za-z0-9+\/=]+$/', $first) === 1, 'nonce is safe for CSP and escaped HTML attributes');
        $this->same(SecurityHeaders::nonce(), SecurityHeaders::nonce(), 'nonce accessor is stable within one response');
    }

    private function testInlineScripts(): void
    {
        $scripts = 0;
        $rocketLoaderExcluded = 0;
        $external = 0;
        $externalOptOutBeforeSource = 0;
        $inline = 0;
        $protected = 0;
        $inlineOptOutBeforeNonce = 0;
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(BASE_PATH . '/app/Views'));
        foreach ($iterator as $file) {
            if (!$file->isFile() || strtolower($file->getExtension()) !== 'php') {
                continue;
            }
            $source = (string)file_get_contents($file->getPathname());
            preg_match_all('/<script\b([^>]*)>/i', $source, $tags, PREG_SET_ORDER);
            foreach ($tags as $tag) {
                $scripts++;
                $optOutPosition = stripos($tag[1], 'data-cfasync="false"');
                if ($optOutPosition !== false) {
                    $rocketLoaderExcluded++;
                }
                if (preg_match('/\bsrc\s*=/i', $tag[1]) === 1) {
                    $external++;
                    $sourcePosition = stripos($tag[1], 'src=');
                    if ($optOutPosition !== false && $sourcePosition !== false && $optOutPosition < $sourcePosition) {
                        $externalOptOutBeforeSource++;
                    }
                    continue;
                }
                $inline++;
                $noncePosition = strpos($tag[1], 'SecurityHeaders::nonce()');
                if ($noncePosition !== false) {
                    $protected++;
                }
                if ($optOutPosition !== false && $noncePosition !== false && $optOutPosition < $noncePosition) {
                    $inlineOptOutBeforeNonce++;
                }
            }
        }
        $this->same(18, $scripts, 'all application script tags are inventoried');
        $this->same($scripts, $rocketLoaderExcluded, 'every application script opts out of Cloudflare Rocket Loader');
        $this->same(9, $external, 'all nine application-loaded external scripts are inventoried');
        $this->same($external, $externalOptOutBeforeSource, 'external Rocket Loader opt-outs appear before src');
        $this->same(9, $inline, 'all nine application inline script blocks are inventoried');
        $this->same($inline, $protected, 'every inline script block uses the nonce helper');
        $this->same($inline, $inlineOptOutBeforeNonce, 'inline Rocket Loader opt-outs preserve nonce placement');
        $this->same(0, $inline - $protected, 'no unprotected inline script remains');
    }

    private function testRenderedLayouts(): void
    {
        $this->startSession();
        ob_start();
        View::render('auth/login', ['sessionNotice' => null], 'layouts/auth');
        $login = (string)ob_get_clean();
        $this->same(true, str_contains($login, '<form method="post"'), 'login page still renders');
        $this->same(true, str_contains($login, 'name="_csrf"'), 'rendered login retains CSRF protection');
        $this->same(true, str_contains($login, '<script data-cfasync="false" src='), 'authentication layout scripts opt out of Rocket Loader');

        $userId = (string)Database::pdo()->query("SELECT id FROM system_user WHERE enabled=1 AND account_status='ACTIVE' ORDER BY created_at LIMIT 1")->fetchColumn();
        $this->same(true, $userId !== '', 'an active account is available for read-only layout rendering');
        $_SESSION['user_id'] = $userId;
        $_SESSION['authenticated_at'] = time();
        $_SESSION['last_activity_at'] = time();
        $_SERVER['REQUEST_URI'] = '/dashboard';
        ob_start();
        View::render('partials/forbidden', ['permission' => 'security.headers.test']);
        $admin = (string)ob_get_clean();
        $this->same(true, str_contains($admin, 'class="topbar"'), 'authenticated layout still renders');
        $this->same(true, str_contains($admin, 'assets/vendor/datatables/dataTables.min.js'), 'authenticated layout retains local DataTables assets');
        $this->same(true, str_contains($admin, 'https://cdn.jsdelivr.net/npm/bootstrap@5.3.3'), 'authenticated layout retains the permitted Bootstrap CDN');
        $this->same(true, substr_count($admin, '<script data-cfasync="false" src=') === 7, 'all authenticated layout dependencies opt out of Rocket Loader');
        Auth::logout();
    }

    private function testResponseCompatibility(): void
    {
        $index = (string)file_get_contents(BASE_PATH . '/public/index.php');
        $this->same(true, str_contains($index, 'SecurityHeaders::apply();'), 'front controller emits centralized headers');
        $this->same(true, strpos($index, 'SecurityHeaders::apply();') < strpos($index, '$router->dispatch'), 'headers run before application output');

        $csv = (string)file_get_contents(BASE_PATH . '/app/Controllers/DataTableController.php');
        $json = (string)file_get_contents(BASE_PATH . '/app/Core/DataTableResponse.php');
        $this->same(true, str_contains($csv, 'Content-Type: text/csv'), 'CSV content type remains intact');
        $this->same(true, str_contains($csv, 'Content-Disposition:'), 'CSV download disposition remains intact');
        $this->same(true, str_contains($csv, 'Cache-Control: no-store, private'), 'CSV no-store behavior remains intact');
        $this->same(true, str_contains($json, 'Content-Type: application/json'), 'JSON content type remains intact');
        $this->same(true, str_contains($json, 'Cache-Control: no-store, private'), 'JSON no-store behavior remains intact');
        $this->same(false, array_key_exists('Content-Type', SecurityHeaders::headersFor('development', 'http://localhost', SecurityHeaders::generateNonce())), 'global headers do not overwrite response content types');
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
        $this->sessionDirectory = rtrim(sys_get_temp_dir(), '/\\') . DIRECTORY_SEPARATOR . 'dems-security-headers-test-' . bin2hex(random_bytes(8));
        if (!mkdir($this->sessionDirectory, 0700) && !is_dir($this->sessionDirectory)) {
            throw new RuntimeException('Unable to create the isolated security-headers session directory.');
        }
        if (ini_set('session.save_path', $this->sessionDirectory) === false) {
            throw new RuntimeException('Unable to configure the isolated security-headers session directory.');
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

exit((new SecurityHeadersTest())->run());
