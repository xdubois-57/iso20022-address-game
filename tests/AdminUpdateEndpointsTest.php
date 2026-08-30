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
 * The four Automatic Updates admin endpoints, exercised through the real
 * controller.
 *
 * The authorisation half matters most: these endpoints hand out and rotate
 * the webhook secret and can trigger an install that replaces the running
 * code, so "does an unauthenticated caller get 401" is the single assertion
 * whose regression would be worst and quietest. tests/e2e/specs only ever
 * drive them as a logged-in admin, so nothing else covers the refusal path.
 */
class AdminUpdateEndpointsTest extends TestCase
{
    use UsesInMemoryDatabase;

    private SettingsModel $settings;
    private string $configDir;

    protected function setUp(): void
    {
        $this->bootInMemoryDatabase();
        $this->settings = new SettingsModel($this->memoryPdo());
        $_SESSION = [];

        // AdminController's constructor builds a LeaderboardModel, which
        // builds an Encryption and refuses to exist without a real key. Point
        // the app at a throwaway config directory holding one, so the real
        // constructor runs exactly as in production instead of the test
        // having to work around it. Nothing here touches the developer's own
        // config/ — that is the whole point of ISO20022_CONFIG_DIR.
        $this->configDir = sys_get_temp_dir() . '/iso20022-admin-test-' . bin2hex(random_bytes(6));
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
    // Authorisation
    // -----------------------------------------------------------------

    /**
     * @dataProvider protectedEndpointProvider
     */
    public function testEndpointRefusesAnonymousCaller(string $method): void
    {
        [$json, $status] = $this->call($method, []);

        $this->assertSame(401, $status, "{$method} must refuse an unauthenticated caller");
        $this->assertSame('Unauthorized', $json['error'] ?? null);
    }

    /**
     * @dataProvider protectedEndpointProvider
     */
    public function testEndpointRefusesWhenAdminFlagIsFalsy(string $method): void
    {
        // A logged-out session keeps the key with a falsy value
        // (AdminController::logout()), so this is the real shape of "not
        // logged in", not merely an absent key.
        $_SESSION['admin'] = false;
        [, $status] = $this->call($method, []);

        $this->assertSame(401, $status);
    }

    public static function protectedEndpointProvider(): array
    {
        return [
            'get-update-settings' => ['getUpdateSettings'],
            'save-update-settings' => ['saveUpdateSettings'],
            'generate-webhook-secret' => ['generateWebhookSecret'],
            'install-update-now' => ['installUpdateNow'],
        ];
    }

    public function testAnonymousCallerCannotGenerateOrLeakASecret(): void
    {
        $this->settings->set('update_webhook_secret', str_repeat('a', 64));

        [$json, $status] = $this->call('generateWebhookSecret', []);

        $this->assertSame(401, $status);
        $this->assertArrayNotHasKey('secret', (array) $json);
        // The stored secret must be untouched by a refused call.
        $this->assertSame(str_repeat('a', 64), $this->settings->get('update_webhook_secret'));
    }

    // -----------------------------------------------------------------
    // getUpdateSettings
    // -----------------------------------------------------------------

    public function testGetUpdateSettingsReturnsDefaultsOnAFreshInstall(): void
    {
        $this->loginAsAdmin();
        [$json, $status] = $this->call('getUpdateSettings');

        $this->assertSame(200, $status);
        $this->assertFalse($json['enabled']);
        $this->assertSame('release', $json['channel']);
        $this->assertFalse($json['has_secret']);
        $this->assertSame('/webhook/github', $json['webhook_path']);
        $this->assertFalse($json['install_pending']);
    }

    public function testGetUpdateSettingsNeverReturnsTheSecretItself(): void
    {
        $secret = bin2hex(random_bytes(32));
        $this->settings->set('update_webhook_secret', $secret);
        $this->loginAsAdmin();

        [$json, $status] = $this->call('getUpdateSettings');

        $this->assertSame(200, $status);
        $this->assertTrue($json['has_secret'], 'the panel still needs to know one is set');
        $this->assertNotContains($secret, $json, 'the secret must never be echoed back');
        $this->assertStringNotContainsString($secret, json_encode($json));
    }

    public function testGetUpdateSettingsReportsAQueuedInstall(): void
    {
        $this->settings->set('update_pending', json_encode([
            'source_type' => 'release', 'download_url' => 'https://api.github.com/x',
            'version_to' => 'v1.0.0', 'commit' => 'abc1234', 'queued_at' => time(),
        ]));
        $this->loginAsAdmin();

        [$json] = $this->call('getUpdateSettings');

        $this->assertTrue($json['install_pending']);
    }

    // -----------------------------------------------------------------
    // saveUpdateSettings — the channel allowlist
    // -----------------------------------------------------------------

    /**
     * @dataProvider validChannelProvider
     */
    public function testSaveAcceptsEachSupportedChannel(string $channel): void
    {
        $this->loginAsAdmin();
        [$json, $status] = $this->call('saveUpdateSettings', [
            'enabled' => true, 'channel' => $channel,
            'github_owner' => 'xdubois-57', 'github_repo' => 'iso20022-address-game',
        ]);

        $this->assertSame(200, $status);
        $this->assertTrue($json['success']);
        $this->assertSame($channel, $this->settings->get('update_channel'));
        $this->assertSame('1', $this->settings->get('update_enabled'));
    }

    public static function validChannelProvider(): array
    {
        return [['release'], ['main']];
    }

    /**
     * @dataProvider invalidChannelProvider
     */
    public function testSaveRejectsAnyOtherChannel(mixed $channel): void
    {
        $this->settings->set('update_channel', 'release');
        $this->loginAsAdmin();

        [, $status] = $this->call('saveUpdateSettings', [
            'enabled' => true, 'channel' => $channel,
            'github_owner' => 'o', 'github_repo' => 'r',
        ]);

        $this->assertSame(400, $status);
        $this->assertSame('release', $this->settings->get('update_channel'), 'a refused save must not change anything');
    }

    public static function invalidChannelProvider(): array
    {
        return [
            'unknown channel' => ['dev'],
            'empty' => [''],
            'wrong case' => ['Release'],
            'array' => [['release']],
            'null' => [null],
        ];
    }

    public function testSaveRequiresOwnerAndRepo(): void
    {
        $this->loginAsAdmin();

        foreach ([['', 'repo'], ['owner', ''], ['   ', 'repo']] as [$owner, $repo]) {
            [, $status] = $this->call('saveUpdateSettings', [
                'enabled' => true, 'channel' => 'release',
                'github_owner' => $owner, 'github_repo' => $repo,
            ]);
            $this->assertSame(400, $status, "owner='{$owner}' repo='{$repo}' must be refused");
        }

        $this->assertNull($this->settings->get('update_github_owner'));
    }

    public function testSaveTrimsOwnerAndRepo(): void
    {
        $this->loginAsAdmin();
        $this->call('saveUpdateSettings', [
            'enabled' => false, 'channel' => 'main',
            'github_owner' => '  spaced-owner  ', 'github_repo' => "  spaced-repo\t",
        ]);

        $this->assertSame('spaced-owner', $this->settings->get('update_github_owner'));
        $this->assertSame('spaced-repo', $this->settings->get('update_github_repo'));
    }

    public function testSaveRejectsOverlongOwnerOrRepo(): void
    {
        $this->loginAsAdmin();
        [, $status] = $this->call('saveUpdateSettings', [
            'enabled' => true, 'channel' => 'release',
            'github_owner' => str_repeat('a', 101), 'github_repo' => 'r',
        ]);

        $this->assertSame(400, $status);
    }

    public function testSaveCanDisableAutomaticUpdates(): void
    {
        $this->settings->set('update_enabled', '1');
        $this->loginAsAdmin();

        $this->call('saveUpdateSettings', [
            'enabled' => false, 'channel' => 'release',
            'github_owner' => 'o', 'github_repo' => 'r',
        ]);

        $this->assertSame('0', $this->settings->get('update_enabled'));
    }

    // -----------------------------------------------------------------
    // generateWebhookSecret
    // -----------------------------------------------------------------

    public function testGenerateWebhookSecretReturnsAndStoresA256BitHexSecret(): void
    {
        $this->loginAsAdmin();
        [$json, $status] = $this->call('generateWebhookSecret', []);

        $this->assertSame(200, $status);
        $this->assertTrue($json['success']);
        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $json['secret']);
        $this->assertSame($json['secret'], $this->settings->get('update_webhook_secret'));
    }

    public function testRegeneratingReplacesThePreviousSecret(): void
    {
        $this->loginAsAdmin();
        [$first] = $this->call('generateWebhookSecret', []);
        [$second] = $this->call('generateWebhookSecret', []);

        $this->assertNotSame($first['secret'], $second['secret']);
        $this->assertSame($second['secret'], $this->settings->get('update_webhook_secret'));
    }
}
