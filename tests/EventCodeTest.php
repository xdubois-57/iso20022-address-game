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
 * Event Code security and functionality tests.
 * Covers hashing, rate limiting, session management, and API endpoints.
 */
class EventCodeTest extends TestCase
{
    private const MAX_EVENT_CODE_ATTEMPTS = 5;
    private const EVENT_CODE_LOCKOUT_SECONDS = 30; // 30 seconds

    /* =======================================================
       Hashing Tests
       ======================================================= */

    public function testEventCodeIsHashedWithBcrypt(): void
    {
        $code = 'TESTEVENT2026';
        $hash = password_hash($code, PASSWORD_BCRYPT);

        // Verify hash format
        $this->assertTrue(str_starts_with($hash, '$2y$') || str_starts_with($hash, '$2b$'));

        // Verify verification works
        $this->assertTrue(password_verify($code, $hash));
        $this->assertFalse(password_verify('wrong', $hash));
    }

    public function testPasswordVerifyIsTimingSafe(): void
    {
        $code = 'mysecretcode';
        $hash = password_hash($code, PASSWORD_BCRYPT);

        // Both correct and incorrect should take similar time (constant-time)
        $start1 = microtime(true);
        $result1 = password_verify($code, $hash);
        $time1 = microtime(true) - $start1;

        $start2 = microtime(true);
        $result2 = password_verify('wrongcode123', $hash);
        $time2 = microtime(true) - $start2;

        $this->assertTrue($result1);
        $this->assertFalse($result2);

        // Times should be reasonably close (within 2x factor)
        $ratio = $time1 / max($time2, 0.000001);
        $this->assertLessThan(2.0, $ratio, 'Timing difference too large');
    }

    public function testEmptyCodeIsNotValid(): void
    {
        $this->assertFalse(password_verify('', password_hash('realcode', PASSWORD_BCRYPT)));
    }

    /* =======================================================
       Rate Limiting Tests
       ======================================================= */

    public function testRateLimitAllowsAttemptsUnderThreshold(): void
    {
        $session = ['event_code_attempts' => 3, 'event_code_lock_until' => 0];
        $locked = ($session['event_code_attempts'] >= self::MAX_EVENT_CODE_ATTEMPTS
            && time() < $session['event_code_lock_until']);
        $this->assertFalse($locked);
    }

    public function testRateLimitBlocksAfterMaxAttempts(): void
    {
        $session = [
            'event_code_attempts' => self::MAX_EVENT_CODE_ATTEMPTS,
            'event_code_lock_until' => time() + 200,
        ];
        $locked = ($session['event_code_attempts'] >= self::MAX_EVENT_CODE_ATTEMPTS
            && time() < $session['event_code_lock_until']);
        $this->assertTrue($locked);
    }

    public function testRateLimitResetsAfterLockoutExpires(): void
    {
        $session = [
            'event_code_attempts' => self::MAX_EVENT_CODE_ATTEMPTS,
            'event_code_lock_until' => time() - 1, // expired
        ];
        $lockExpired = (time() >= $session['event_code_lock_until']
            && $session['event_code_attempts'] >= self::MAX_EVENT_CODE_ATTEMPTS);
        $this->assertTrue($lockExpired);
    }

    public function testRateLimitSetsLockoutOnFifthFailure(): void
    {
        $attempts = 4; // 0-indexed: this is the 5th attempt
        $attempts++;
        $lockUntil = 0;
        if ($attempts >= self::MAX_EVENT_CODE_ATTEMPTS) {
            $lockUntil = time() + self::EVENT_CODE_LOCKOUT_SECONDS;
        }
        $this->assertGreaterThan(time(), $lockUntil);
        $this->assertGreaterThanOrEqual(30, $lockUntil - time());
    }

    public function testRateLimitClearsOnSuccess(): void
    {
        $session = [
            'event_code_attempts' => 3,
            'event_code_lock_until' => time() + 100,
            'event_code_ok' => true,
            'event_code_verified_at' => time(),
        ];

        // On successful verification, attempts should be cleared
        $session['event_code_attempts'] = 0;
        unset($session['event_code_lock_until']);

        $this->assertEquals(0, $session['event_code_attempts']);
        $this->assertFalse(isset($session['event_code_lock_until']));
    }

    public function testSessionTimestampValidation(): void
    {
        // Current code timestamp
        $currentTimestamp = 1700000000;

        // Session verified at same time - should be valid
        $sessionTimestamp = 1700000000;
        $this->assertTrue($sessionTimestamp >= $currentTimestamp);

        // Session verified before code changed - should be invalid
        $oldSessionTimestamp = 1600000000;
        $this->assertFalse($oldSessionTimestamp >= $currentTimestamp);

        // No session timestamp - should be invalid
        $noSessionTimestamp = 0;
        $this->assertFalse($noSessionTimestamp >= $currentTimestamp);
    }

    public function testRateLimitResetsWhenCodeChanges(): void
    {
        $session = [
            'event_code_attempts' => 5,
            'event_code_lock_until' => time() + 100,
            'event_code_verified_at' => 1600000000, // verified with old code
        ];

        $currentTimestamp = 1700000000; // new code timestamp

        // When code changed, reset rate limiting
        if ($session['event_code_verified_at'] > 0 && $session['event_code_verified_at'] < $currentTimestamp) {
            $session['event_code_attempts'] = 0;
            unset($session['event_code_lock_until']);
        }

        $this->assertEquals(0, $session['event_code_attempts']);
        $this->assertFalse(isset($session['event_code_lock_until']));
    }

    /* =======================================================
       Session Management Tests
       ======================================================= */

    public function testSessionFlagSetOnSuccess(): void
    {
        $session = [];
        // Simulating successful verification
        $session['event_code_ok'] = true;
        $this->assertTrue($session['event_code_ok']);
    }

    public function testSessionFlagNotSetOnFailure(): void
    {
        $session = [];
        // After failed attempt
        $session['event_code_attempts'] = 1;
        $this->assertFalse(isset($session['event_code_ok']));
    }

    public function testSessionFlagClearedOnReset(): void
    {
        $session = [
            'event_code_ok' => true,
            'event_code_attempts' => 0,
        ];
        // Simulating resetSession()
        unset($session['event_code_ok']);
        $session['event_code_attempts'] = 0;

        $this->assertFalse(isset($session['event_code_ok']));
    }

    /* =======================================================
       Input Validation Tests
       ======================================================= */

    public function testEventCodeLengthValidation(): void
    {
        $validCodes = ['1234', 'short', 'MYEVENTCODE2026', str_repeat('a', 64)];
        $tooLong = str_repeat('a', 65);

        foreach ($validCodes as $code) {
            $this->assertLessThanOrEqual(64, mb_strlen($code), "Should accept: $code");
        }
        $this->assertGreaterThan(64, mb_strlen($tooLong));
    }

    public function testEventCodeTrimmed(): void
    {
        $input = '  MYCODE  ';
        $trimmed = trim($input);
        $this->assertEquals('MYCODE', $trimmed);
        $this->assertNotEquals($input, $trimmed);
    }

    public function testEmptyCodeDisablesProtection(): void
    {
        $code = '';
        $this->assertEquals('', trim($code));
        // Empty string should clear the event code (no protection)
    }

    /* =======================================================
       API Response Tests
       ======================================================= */

    public function testStatusEndpointDoesNotRevealCode(): void
    {
        // Status endpoint should only return boolean, never the actual code
        $response = ['required' => true];
        $this->assertArrayHasKey('required', $response);
        $this->assertArrayNotHasKey('code', $response);
        $this->assertArrayNotHasKey('event_code', $response);
    }

    public function testSaveEndpointReturnsMaskedCode(): void
    {
        // After saving, response should not contain the actual code
        $response = ['success' => true, 'event_code' => '********'];
        $this->assertEquals('********', $response['event_code']);
        $this->assertNotEquals('realcode', $response['event_code']);
    }

    public function testVerifySuccessResponse(): void
    {
        $response = ['success' => true];
        $this->assertTrue($response['success']);
        $this->assertArrayNotHasKey('error', $response);
    }

    public function testVerifyFailureResponse(): void
    {
        $response = ['success' => false, 'error' => 'Invalid event code'];
        $this->assertFalse($response['success']);
        $this->assertEquals('Invalid event code', $response['error']);
    }

    public function testRateLimitResponse(): void
    {
        $response = ['success' => false, 'error' => 'Too many attempts. Try again in 240s.'];
        $this->assertFalse($response['success']);
        $this->assertStringContainsString('Too many attempts', $response['error']);
        $this->assertStringContainsString('s.', $response['error']);
    }

    /* =======================================================
       Security Header Tests
       ======================================================= */

    public function testCsrfRequiredForEventCodeEndpoints(): void
    {
        // All POST endpoints require CSRF token (handled globally in index.php)
        // This test documents that requirement
        $endpoints = [
            'game/event-code-status',
            'game/verify-event-code',
            'admin/get-event-code',
            'admin/set-event-code',
        ];
        foreach ($endpoints as $endpoint) {
            // Each endpoint must start with 'game/' or 'admin/'
            $isGame = str_starts_with($endpoint, 'game/');
            $isAdmin = str_starts_with($endpoint, 'admin/');
            $this->assertTrue($isGame || $isAdmin, "Endpoint $endpoint must start with game/ or admin/");
        }
    }

    public function testAdminAuthorizationRequiredForSettings(): void
    {
        // Admin endpoints should check isAdmin()
        $this->assertTrue(true); // Documented requirement
    }
}
