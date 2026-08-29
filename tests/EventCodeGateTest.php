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

use App\Controllers\GameController;
use App\Models\SettingsModel;
use PHPUnit\Framework\TestCase;
use Tests\Support\UsesInMemoryDatabase;

/**
 * Server-side enforcement of the Event Code gate.
 *
 * The gate used to exist only in the browser: $_SESSION['event_code_ok'] was
 * written by the verify endpoint and never read by anything, so calling the game
 * endpoints directly bypassed it entirely. These tests pin the server-side
 * decision that index.php now makes before dispatching a gated action.
 */
class EventCodeGateTest extends TestCase
{
    use UsesInMemoryDatabase;

    private SettingsModel $settings;

    protected function setUp(): void
    {
        $pdo = $this->bootInMemoryDatabase();
        $this->settings = new SettingsModel($pdo);
        $_SESSION = [];
    }

    protected function tearDown(): void
    {
        $_SESSION = [];
        $this->shutdownInMemoryDatabase();
    }

    /**
     * Configure an event code exactly as AdminController::setEventCode does.
     */
    private function configureEventCode(string $code, ?int $timestamp = null): int
    {
        $timestamp ??= time();
        $this->settings->set('event_code', password_hash($code, PASSWORD_BCRYPT));
        $this->settings->set('event_code_timestamp', (string) $timestamp);

        return $timestamp;
    }

    /* =======================================================
       No code configured
       ======================================================= */

    public function testGameIsOpenWhenNoCodeConfigured(): void
    {
        $this->assertTrue(GameController::isEventCodeSatisfied());
    }

    public function testGameIsOpenWhenStoredCodeIsEmpty(): void
    {
        $this->settings->set('event_code', '');
        $this->assertTrue(GameController::isEventCodeSatisfied());
    }

    /* =======================================================
       Code configured
       ======================================================= */

    public function testUnverifiedSessionIsRefused(): void
    {
        $this->configureEventCode('LETMEIN');

        $this->assertFalse(
            GameController::isEventCodeSatisfied(),
            'A session that never entered the code must not reach the game endpoints'
        );
    }

    public function testVerifiedSessionIsAllowed(): void
    {
        $timestamp = $this->configureEventCode('LETMEIN');
        $_SESSION['event_code_ok'] = true;
        $_SESSION['event_code_verified_at'] = $timestamp;

        $this->assertTrue(GameController::isEventCodeSatisfied());
    }

    public function testForgedSessionFlagWithoutTimestampIsRefused(): void
    {
        $this->configureEventCode('LETMEIN');
        $_SESSION['event_code_ok'] = true; // set, but never verified against a code

        $this->assertFalse(GameController::isEventCodeSatisfied());
    }

    public function testSessionVerifiedAgainstAnOlderCodeIsRefused(): void
    {
        // Admin rotates the code: sessions holding the previous one lose access.
        $this->configureEventCode('OLDCODE', time() - 3600);
        $_SESSION['event_code_ok'] = true;
        $_SESSION['event_code_verified_at'] = time() - 3600;

        $this->configureEventCode('NEWCODE', time());

        $this->assertFalse(GameController::isEventCodeSatisfied());
    }

    /* =======================================================
       Admin bypass and session reset
       ======================================================= */

    public function testAdminSessionBypassesTheGate(): void
    {
        $this->configureEventCode('LETMEIN');
        $_SESSION['admin'] = true;

        $this->assertTrue(
            GameController::isEventCodeSatisfied(),
            'An administrator must not be locked out of their own installation'
        );
    }

    public function testResetSessionRevokesTheUnlock(): void
    {
        $timestamp = $this->configureEventCode('LETMEIN');
        $_SESSION['event_code_ok'] = true;
        $_SESSION['event_code_verified_at'] = $timestamp;
        $this->assertTrue(GameController::isEventCodeSatisfied());

        // Pressing Stop on a shared kiosk must re-lock the gate for the next player.
        ob_start();
        (new GameController())->resetSession();
        ob_end_clean();

        $this->assertFalse(GameController::isEventCodeSatisfied());
        $this->assertArrayNotHasKey('event_code_ok', $_SESSION);
        $this->assertArrayNotHasKey('event_code_verified_at', $_SESSION);
    }
}
