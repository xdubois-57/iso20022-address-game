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

use App\Controllers\AdminController;
use App\Models\SettingsModel;
use PHPUnit\Framework\TestCase;
use Tests\Support\UsesInMemoryDatabase;

/**
 * The two admin endpoints that WRITE to the settings table, driven through
 * the real controller.
 *
 * Everything around them was already covered — AdminFeaturesTest and
 * EventCodeTest both read settings back, and both seed the rows with raw
 * INSERTs of their own. That is precisely how these two endpoints came to
 * carry a hand-written MySQL `ON DUPLICATE KEY UPDATE` that SQLite cannot
 * parse: no test ever asked the controller to do the writing, so on a
 * SQLite-backed instance (`composer serve`, the Playwright harness) setting
 * a deadline or an event code was a 500 and nothing said so.
 */
class AdminSettingsEndpointsTest extends TestCase
{
    use UsesInMemoryDatabase;

    private SettingsModel $settings;
    private string $configDir;

    protected function setUp(): void
    {
        $this->bootInMemoryDatabase();
        $this->settings = new SettingsModel($this->memoryPdo());
        $_SESSION = [];

        // AdminController's constructor needs a real encryption key; give it
        // a throwaway config directory rather than the developer's own.
        $this->configDir = sys_get_temp_dir() . '/iso20022-settings-test-' . bin2hex(random_bytes(6));
        mkdir($this->configDir, 0700, true);
        file_put_contents(
            $this->configDir . '/credentials.php',
            "<?php return ['encryption' => ['key' => " . var_export(bin2hex(random_bytes(32)), true) . "]];\n"
        );
        putenv('ISO20022_CONFIG_DIR=' . $this->configDir);
    }

    protected function tearDown(): void
    {
        putenv('ISO20022_CONFIG_DIR');
        @unlink($this->configDir . '/credentials.php');
        @rmdir($this->configDir);
        $_SESSION = [];
        $this->shutdownInMemoryDatabase();
    }

    /**
     * Calls a controller method with $body as its request body and returns
     * [decodedJson, httpStatus].
     */
    private function call(string $method, ?array $body = null): array
    {
        $controller = new class ($body ?? []) extends AdminController {
            public function __construct(private array $testBody)
            {
                parent::__construct();
            }

            protected function getJsonInput(): array
            {
                return $this->testBody;
            }
        };

        http_response_code(200);
        ob_start();
        $controller->{$method}();
        $raw = (string) ob_get_clean();

        return [json_decode($raw, true), http_response_code()];
    }

    private function loginAsAdmin(): void
    {
        $_SESSION['admin'] = true;
    }

    // -----------------------------------------------------------------
    // Deadline
    // -----------------------------------------------------------------

    public function testSetDeadlineStoresTheValue(): void
    {
        $this->loginAsAdmin();

        [$json, $status] = $this->call('setDeadline', ['deadline' => '2027-03-01T09:30']);

        $this->assertSame(200, $status);
        $this->assertTrue($json['success']);
        $this->assertSame('2027-03-01T09:30', AdminController::fetchDeadlineStatic());
    }

    public function testSetDeadlineOverwritesAnExistingValue(): void
    {
        $this->loginAsAdmin();
        $this->settings->set('unstructured_deadline', '2026-11-14T18:00');

        $this->call('setDeadline', ['deadline' => '2028-01-02T03:04']);

        $this->assertSame('2028-01-02T03:04', AdminController::fetchDeadlineStatic());
    }

    public function testEmptyDeadlineClearsIt(): void
    {
        $this->loginAsAdmin();
        $this->settings->set('unstructured_deadline', '2026-11-14T18:00');

        [$json, $status] = $this->call('setDeadline', ['deadline' => '']);

        $this->assertSame(200, $status);
        $this->assertNull($json['deadline']);
        $this->assertNull(AdminController::fetchDeadlineStatic());
    }

    public function testMalformedDeadlineIsRejectedAndNothingIsStored(): void
    {
        $this->loginAsAdmin();

        [$json, $status] = $this->call('setDeadline', ['deadline' => 'next tuesday']);

        $this->assertSame(400, $status);
        $this->assertArrayHasKey('error', $json);
        $this->assertNull(AdminController::fetchDeadlineStatic());
    }

    public function testSetDeadlineRefusesAnonymousCaller(): void
    {
        [$json, $status] = $this->call('setDeadline', ['deadline' => '2027-03-01T09:30']);

        $this->assertSame(401, $status);
        $this->assertSame('Unauthorized', $json['error'] ?? null);
        $this->assertNull(AdminController::fetchDeadlineStatic());
    }

    // -----------------------------------------------------------------
    // Event code
    // -----------------------------------------------------------------

    public function testSetEventCodeStoresABcryptHashAndATimestamp(): void
    {
        $this->loginAsAdmin();

        [$json, $status] = $this->call('setEventCode', ['event_code' => 'letmein']);

        $this->assertSame(200, $status);
        $this->assertTrue($json['has_code']);

        $stored = AdminController::fetchEventCodeStatic();
        $this->assertNotNull($stored);
        $this->assertNotSame('letmein', $stored, 'the code must never be stored in clear');
        $this->assertTrue(password_verify('letmein', $stored));
        $this->assertGreaterThan(0, AdminController::fetchEventCodeTimestampStatic());
    }

    public function testChangingTheEventCodeReplacesBothRows(): void
    {
        $this->loginAsAdmin();
        $this->call('setEventCode', ['event_code' => 'first']);
        $firstStamp = AdminController::fetchEventCodeTimestampStatic();

        $this->call('setEventCode', ['event_code' => 'second']);

        $stored = AdminController::fetchEventCodeStatic();
        $this->assertTrue(password_verify('second', $stored));
        $this->assertFalse(password_verify('first', $stored));
        $this->assertGreaterThanOrEqual($firstStamp, AdminController::fetchEventCodeTimestampStatic());
    }

    public function testEmptyEventCodeRemovesTheGateAndItsTimestamp(): void
    {
        $this->loginAsAdmin();
        $this->call('setEventCode', ['event_code' => 'temporary']);

        [$json, $status] = $this->call('setEventCode', ['event_code' => '']);

        $this->assertSame(200, $status);
        $this->assertFalse($json['has_code']);
        $this->assertNull(AdminController::fetchEventCodeStatic());
        $this->assertSame(0, AdminController::fetchEventCodeTimestampStatic());
    }

    public function testOverlongEventCodeIsRejectedAndNothingIsStored(): void
    {
        $this->loginAsAdmin();

        [$json, $status] = $this->call('setEventCode', ['event_code' => str_repeat('a', 65)]);

        $this->assertSame(400, $status);
        $this->assertArrayHasKey('error', $json);
        $this->assertNull(AdminController::fetchEventCodeStatic());
    }

    public function testSetEventCodeRefusesAnonymousCaller(): void
    {
        [$json, $status] = $this->call('setEventCode', ['event_code' => 'sneaky']);

        $this->assertSame(401, $status);
        $this->assertSame('Unauthorized', $json['error'] ?? null);
        $this->assertNull(AdminController::fetchEventCodeStatic());
    }

    public function testSettingAnEventCodeReleasesAnyoneLockedOutUnderTheOldOne(): void
    {
        $this->loginAsAdmin();
        $this->memoryPdo()->prepare(
            'INSERT INTO rate_limits (bucket, attempts, updated_at, locked_until) VALUES (?, ?, ?, ?)'
        )->execute(['event_code:somecaller', 5, time(), time() + 30]);

        $this->call('setEventCode', ['event_code' => 'brand-new']);

        $remaining = $this->memoryPdo()
            ->query("SELECT COUNT(*) FROM rate_limits WHERE bucket LIKE 'event_code:%'")
            ->fetchColumn();
        $this->assertSame(0, (int) $remaining);
    }
}
