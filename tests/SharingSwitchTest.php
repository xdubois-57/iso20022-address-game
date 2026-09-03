<?php
/**
 * ISO 20022 Address Structuring Game
 * Copyright (C) 2026 https://github.com/xdubois-57/iso20022-address-game
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Affero General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU Affero General Public License for more details.
 *
 * You should have received a copy of the GNU Affero General Public License
 * along with this program. If not, see <https://www.gnu.org/licenses/>.
 */

namespace Tests;

use App\Controllers\AdminController;
use App\Models\SettingsModel;
use PHPUnit\Framework\TestCase;
use Tests\Support\UsesInMemoryDatabase;

/**
 * The `sharing_enabled` setting: its default, its endpoints, and the one
 * property the whole design rests on — that it changes what is RENDERED and
 * nothing about what the server answers.
 *
 * The shell half (the <body> attribute) is asserted in
 * SharingShellTest.php against the real template; the browser half is
 * asserted end to end in tests/e2e/specs/sharing.spec.js.
 */
class SharingSwitchTest extends TestCase
{
    use UsesInMemoryDatabase;

    private SettingsModel $settings;
    private string $configDir;

    protected function setUp(): void
    {
        $this->bootInMemoryDatabase();
        $this->settings = new SettingsModel($this->memoryPdo());
        $_SESSION = [];

        $this->configDir = sys_get_temp_dir() . '/iso20022-sharing-test-' . bin2hex(random_bytes(6));
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

    /** @return array{0: mixed, 1: int} */
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
    // The default, and how a stored value is read back
    // -----------------------------------------------------------------

    /**
     * A fresh installation shares, exactly as every installation did before
     * the setting existed. Nothing seeds the key, so this is what "absent"
     * has to mean.
     */
    public function testSharingIsEnabledOnAFreshInstallation(): void
    {
        $this->assertNull($this->settings->get(AdminController::SHARING_ENABLED_KEY));
        $this->assertTrue(AdminController::sharingEnabledStatic());
    }

    public function testOnlyAStoredZeroDisablesIt(): void
    {
        $this->settings->set(AdminController::SHARING_ENABLED_KEY, '0');
        $this->assertFalse(AdminController::sharingEnabledStatic());

        $this->settings->set(AdminController::SHARING_ENABLED_KEY, '1');
        $this->assertTrue(AdminController::sharingEnabledStatic());
    }

    /**
     * Anything unexpected in the row falls back to the behaviour that was
     * there before — a value somebody typed by hand must not silently take
     * the share buttons away.
     */
    public function testAnUnrecognisedValueReadsAsEnabled(): void
    {
        foreach (['yes', 'false', 'off', '2', ' 0'] as $stored) {
            $this->settings->set(AdminController::SHARING_ENABLED_KEY, $stored);
            $this->assertTrue(
                AdminController::sharingEnabledStatic(),
                "a stored '{$stored}' must not be read as disabled"
            );
        }
    }

    // -----------------------------------------------------------------
    // The endpoints
    // -----------------------------------------------------------------

    public function testGetSharingRequiresAnAdminSession(): void
    {
        [$json, $status] = $this->call('getSharing');

        $this->assertSame(401, $status);
        $this->assertArrayHasKey('error', $json);
    }

    public function testSetSharingRequiresAnAdminSession(): void
    {
        [$json, $status] = $this->call('setSharing', ['sharing_enabled' => false]);

        $this->assertSame(401, $status);
        $this->assertArrayHasKey('error', $json);
        $this->assertNull(
            $this->settings->get(AdminController::SHARING_ENABLED_KEY),
            'a refused request must not have written anything'
        );
    }

    public function testSetSharingStoresBothStates(): void
    {
        $this->loginAsAdmin();

        [$off, $status] = $this->call('setSharing', ['sharing_enabled' => false]);
        $this->assertSame(200, $status);
        $this->assertTrue($off['success']);
        $this->assertFalse($off['sharing_enabled']);
        $this->assertSame('0', $this->settings->get(AdminController::SHARING_ENABLED_KEY));

        // Switched back ON as a stored '1' rather than by deleting the row, so
        // "never touched" and "deliberately re-enabled" read the same.
        [$on] = $this->call('setSharing', ['sharing_enabled' => true]);
        $this->assertTrue($on['sharing_enabled']);
        $this->assertSame('1', $this->settings->get(AdminController::SHARING_ENABLED_KEY));
    }

    public function testGetSharingReportsWhatWasStored(): void
    {
        $this->loginAsAdmin();
        $this->settings->set(AdminController::SHARING_ENABLED_KEY, '0');

        [$json, $status] = $this->call('getSharing');

        $this->assertSame(200, $status);
        $this->assertFalse($json['sharing_enabled']);
    }

    /**
     * Rejected rather than coerced: (bool) of anything is a value, and a
     * malformed request must not quietly decide this either way.
     */
    public function testAMalformedValueIsRejectedAndNothingIsStored(): void
    {
        $this->loginAsAdmin();

        foreach ([null, 'yes', 2, [], 'true'] as $bad) {
            [$json, $status] = $this->call('setSharing', ['sharing_enabled' => $bad]);
            $this->assertSame(400, $status, var_export($bad, true));
            $this->assertArrayHasKey('error', $json);
        }

        $this->assertNull($this->settings->get(AdminController::SHARING_ENABLED_KEY));
    }

    // -----------------------------------------------------------------
    // The line this switch must not cross
    // -----------------------------------------------------------------

    /**
     * The front controller declares the five share routes without reference
     * to the setting.
     *
     * This is the test that locks the intention. A link a player has already
     * posted must keep working, /share/home-image is the site's own social
     * preview rather than anybody's score, and hiding a feature is a product
     * decision rather than an access control. Asserted against index.php's
     * source because these routes exit before the session even starts, so
     * there is no seam to call them through — and because what is being
     * guarded is precisely the absence of a condition.
     */
    public function testTheShareRoutesAreDeclaredWithoutReferenceToTheSetting(): void
    {
        $source = (string) file_get_contents(__DIR__ . '/../public/index.php');

        // Every share route's dispatch line, and the region of the file that
        // holds them: from the first share route to the end of the POST
        // dispatch table.
        $start = strpos($source, "if (\$requestUri === '/share/image')");
        $this->assertNotFalse($start, 'the share routes must still be dispatched from index.php');

        $end = strpos($source, "default => jsonError('Unknown action', 404),", $start);
        $this->assertNotFalse($end);

        $routing = substr($source, $start, $end - $start);

        foreach ([
            "'/share/image'",
            "'/share/home-image'",
            "'/share/go'",
            "'/share'",
            "'share/token' => (new ShareController())->generateToken(),",
        ] as $route) {
            $this->assertStringContainsString($route, $routing, "route {$route} must still be dispatched");
        }

        $this->assertStringNotContainsString(
            AdminController::SHARING_ENABLED_KEY,
            $routing,
            'the share routes must not consult the sharing setting — it hides UI, it does not close routes'
        );
        $this->assertStringNotContainsString(
            'sharingEnabled',
            $routing,
            'the share routes must not consult the sharing setting — it hides UI, it does not close routes'
        );
    }

    /**
     * And the flag is resolved only where the shell is built, after every
     * early-exiting route has already had its turn.
     */
    public function testTheFlagIsResolvedOnlyOnTheWayToTheShell(): void
    {
        $source = (string) file_get_contents(__DIR__ . '/../public/index.php');

        $this->assertSame(
            1,
            substr_count($source, 'AdminController::sharingEnabledStatic()'),
            'resolved exactly once'
        );

        $resolvedAt = strpos($source, '$sharingEnabled = AdminController::sharingEnabledStatic();');
        $shellAt = strpos($source, "require __DIR__ . '/../app/Views/layout.php';");
        $shareRouteAt = strpos($source, "if (\$requestUri === '/share')");

        $this->assertNotFalse($resolvedAt);
        $this->assertNotFalse($shellAt);
        $this->assertGreaterThan($shareRouteAt, $resolvedAt, 'resolved after the share routes have exited');
        $this->assertLessThan($shellAt, $resolvedAt, 'and before the shell that carries it is rendered');
    }
}
