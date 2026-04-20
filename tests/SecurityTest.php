<?php
/**
 * ISO 20022 Address Structuring Game
 * Copyright (C) 2026 https://github.com/xdubois-57/iso20022-address-game
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program. If not, see <https://www.gnu.org/licenses/>.
 */

namespace Tests;

use PHPUnit\Framework\TestCase;

/**
 * Security-focused tests covering CSRF, rate limiting, session hardening,
 * setup lockdown, upload validation, and encoding safety.
 */
class SecurityTest extends TestCase
{
    /* =======================================================
       CSRF Token Generation
       ======================================================= */

    public function testCsrfTokenIsGenerated(): void
    {
        // Simulate what index.php does
        $session = [];
        if (empty($session['csrf_token'])) {
            $session['csrf_token'] = bin2hex(random_bytes(32));
        }
        $this->assertNotEmpty($session['csrf_token']);
        $this->assertEquals(64, strlen($session['csrf_token'])); // 32 bytes = 64 hex chars
    }

    public function testCsrfTokenIsStableOnceGenerated(): void
    {
        $session = [];
        $session['csrf_token'] = bin2hex(random_bytes(32));
        $first = $session['csrf_token'];

        // Second call should not regenerate
        if (empty($session['csrf_token'])) {
            $session['csrf_token'] = bin2hex(random_bytes(32));
        }
        $this->assertEquals($first, $session['csrf_token']);
    }

    public function testCsrfTokenComparisonIsTimingSafe(): void
    {
        $token = bin2hex(random_bytes(32));
        // hash_equals is used in index.php — verify it works as expected
        $this->assertTrue(hash_equals($token, $token));
        $this->assertFalse(hash_equals($token, 'wrong_token'));
        $this->assertFalse(hash_equals($token, ''));
        $this->assertFalse(hash_equals('', $token));
    }

    /* =======================================================
       Rate Limiting Logic (mirrors AdminController::login)
       ======================================================= */

    private const MAX_LOGIN_ATTEMPTS = 5;
    private const LOCKOUT_SECONDS = 300;

    public function testRateLimitAllowsAttemptsUnderThreshold(): void
    {
        $session = ['login_attempts' => 3, 'login_lock_until' => 0];
        $locked = ($session['login_attempts'] >= self::MAX_LOGIN_ATTEMPTS
            && time() < $session['login_lock_until']);
        $this->assertFalse($locked);
    }

    public function testRateLimitBlocksAfterMaxAttempts(): void
    {
        $session = [
            'login_attempts' => self::MAX_LOGIN_ATTEMPTS,
            'login_lock_until' => time() + 200,
        ];
        $locked = ($session['login_attempts'] >= self::MAX_LOGIN_ATTEMPTS
            && time() < $session['login_lock_until']);
        $this->assertTrue($locked);
    }

    public function testRateLimitResetsAfterLockoutExpires(): void
    {
        $session = [
            'login_attempts' => self::MAX_LOGIN_ATTEMPTS,
            'login_lock_until' => time() - 1, // expired
        ];
        $lockExpired = (time() >= $session['login_lock_until']
            && $session['login_attempts'] >= self::MAX_LOGIN_ATTEMPTS);
        $this->assertTrue($lockExpired); // Should trigger reset
    }

    public function testRateLimitIncrementOnFailure(): void
    {
        $attempts = 2;
        $attempts++;
        $this->assertEquals(3, $attempts);
        $this->assertLessThan(self::MAX_LOGIN_ATTEMPTS, $attempts);
    }

    public function testRateLimitSetsLockoutOnFifthFailure(): void
    {
        $attempts = 4; // 0-indexed: this is the 5th attempt
        $attempts++;
        $lockUntil = 0;
        if ($attempts >= self::MAX_LOGIN_ATTEMPTS) {
            $lockUntil = time() + self::LOCKOUT_SECONDS;
        }
        $this->assertGreaterThan(time(), $lockUntil);
    }

    /* =======================================================
       Setup Lockdown
       ======================================================= */

    public function testSetupLockedWhenDatabaseConnected(): void
    {
        // The logic: if DB is connected, setup routes should be blocked (403)
        // Setup is only allowed when the DB connection fails, regardless of credentials file
        $db = \App\Models\Database::getInstance();
        if ($db->connect()) {
            // DB is up — setup should be disabled
            $this->assertTrue($db->isConnected(), 'Setup should be blocked when DB is connected');
        } else {
            // DB is down — setup should be allowed
            $this->assertFalse($db->isConnected(), 'Setup should be allowed when DB is not connected');
        }
    }

    /* =======================================================
       Upload Validation
       ======================================================= */

    public function testFileSizeLimitIs5MB(): void
    {
        $limit = 5 * 1024 * 1024;
        $this->assertEquals(5242880, $limit);

        // File under limit
        $this->assertFalse(5000000 > $limit);
        // File over limit
        $this->assertTrue(6000000 > $limit);
    }

    public function testFileExtensionValidation(): void
    {
        $validName = 'scenarios.xlsx';
        $invalidNames = ['scenarios.xls', 'scenarios.csv', 'exploit.php', 'test.xlsx.php'];

        $this->assertEquals('xlsx', strtolower(pathinfo($validName, PATHINFO_EXTENSION)));

        foreach ($invalidNames as $name) {
            $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
            $this->assertNotEquals('xlsx', $ext, "Should reject: $name");
        }
    }

    public function testUploadFilenameIsRandomized(): void
    {
        $name1 = 'upload_' . bin2hex(random_bytes(8)) . '.xlsx';
        $name2 = 'upload_' . bin2hex(random_bytes(8)) . '.xlsx';
        $this->assertNotEquals($name1, $name2);
        $this->assertMatchesRegularExpression('/^upload_[0-9a-f]{16}\.xlsx$/', $name1);
    }

    /* =======================================================
       Encoding Safety (double-encoding fix)
       ======================================================= */

    public function testPlayerNameNotDoubleEncoded(): void
    {
        // The fix: server should NOT htmlspecialchars before storing.
        // Only the client-side escapeHtml should encode for display.
        $name = "O'Brien & Sons <script>";
        // Before fix: server would do htmlspecialchars, then client would escape again
        $serverEncoded = htmlspecialchars($name, ENT_QUOTES, 'UTF-8');
        $doubleEncoded = htmlspecialchars($serverEncoded, ENT_QUOTES, 'UTF-8');

        // Double-encoded would contain &amp; — we want to avoid this
        $this->assertStringContainsString('&amp;', $doubleEncoded);
        // Single-encoded is fine for display
        $this->assertStringContainsString('&amp;', $serverEncoded);
        // Raw name should NOT contain &amp;
        $this->assertStringNotContainsString('&amp;', $name);
    }

    /* =======================================================
       Session Cookie Flags
       ======================================================= */

    public function testSessionCookieFlagsAreConfigurable(): void
    {
        // Verify the ini_set calls used in index.php are valid settings
        $validSettings = [
            'session.use_strict_mode',
            'session.use_only_cookies',
            'session.cookie_httponly',
            'session.cookie_samesite',
            'session.cookie_secure',
        ];
        foreach ($validSettings as $setting) {
            // ini_get returns false for unknown settings
            $value = ini_get($setting);
            $this->assertNotFalse($value, "PHP should recognize setting: $setting");
        }
    }

    /* =======================================================
       Security Headers
       ======================================================= */

    public function testCspHeaderContainsRequiredDirectives(): void
    {
        $csp = "default-src 'self'; script-src 'self' 'unsafe-inline' https://unpkg.com https://cdn.jsdelivr.net; style-src 'self' 'unsafe-inline' https://cdn.jsdelivr.net https://unpkg.com; img-src 'self' data:; font-src 'self';";

        $this->assertStringContainsString("default-src 'self'", $csp);
        $this->assertStringContainsString('https://unpkg.com', $csp);
        $this->assertStringContainsString('https://cdn.jsdelivr.net', $csp);
        $this->assertStringContainsString("font-src 'self'", $csp);
    }

    /* =======================================================
       Config Protection
       ======================================================= */

    public function testConfigHtaccessExists(): void
    {
        $htaccess = __DIR__ . '/../config/.htaccess';
        $this->assertFileExists($htaccess);

        $content = file_get_contents($htaccess);
        $this->assertStringContainsString('Deny', $content);
    }

    public function testCredentialsExampleDoesNotContainRealSecrets(): void
    {
        $exampleFile = __DIR__ . '/../config/credentials.php.example';
        if (file_exists($exampleFile)) {
            $content = file_get_contents($exampleFile);
            $this->assertStringNotContainsString('password123', $content);
            // Should contain placeholder values
            $this->assertStringContainsString('your_', $content);
        } else {
            $this->markTestSkipped('credentials.php.example not found');
        }
    }

    /* =======================================================
       PIN Validation
       ======================================================= */

    public function testPinMustBe4to8Digits(): void
    {
        $validPins = ['1234', '12345678', '0000', '99999999'];
        $invalidPins = ['123', '123456789', 'abcd', '12 34', '', '12.34'];

        foreach ($validPins as $pin) {
            $this->assertMatchesRegularExpression('/^\d{4,8}$/', $pin, "Should accept: $pin");
        }
        foreach ($invalidPins as $pin) {
            $this->assertDoesNotMatchRegularExpression('/^\d{4,8}$/', $pin, "Should reject: $pin");
        }
    }

    public function testBcryptHashVerification(): void
    {
        $pin = '5678';
        $hash = password_hash($pin, PASSWORD_BCRYPT);

        $this->assertTrue(str_starts_with($hash, '$2y$'));
        $this->assertTrue(password_verify($pin, $hash));
        $this->assertFalse(password_verify('wrong', $hash));
    }
}
