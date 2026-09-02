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
 * The token behind the two dedicated screen URLs: how it is made, how it is
 * replaced, and the two things it must never be.
 *
 * The front controller's use of it cannot be exercised from here — index.php
 * starts a session and dispatches on the request method — so the HTTP
 * behaviour (right token, wrong token, no token, regenerated token) is
 * asserted end to end in tests/e2e/specs/display-token.spec.js. What lives
 * here is the model and the endpoints.
 */
class DisplayModeTokenTest extends TestCase
{
    use UsesInMemoryDatabase;

    private SettingsModel $settings;
    private string $configDir;

    protected function setUp(): void
    {
        $this->bootInMemoryDatabase();
        $this->settings = new SettingsModel($this->memoryPdo());
        $_SESSION = [];

        $this->configDir = sys_get_temp_dir() . '/iso20022-token-test-' . bin2hex(random_bytes(6));
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
    // Minting
    // -----------------------------------------------------------------

    /**
     * Nothing seeds the key, so the first read has to mint one — which is what
     * lets an installation that already exists acquire a token with no
     * migration to run.
     */
    public function testTheFirstReadMintsAndStoresAToken(): void
    {
        $this->assertNull($this->settings->get(AdminController::DISPLAY_MODE_TOKEN_KEY));

        $token = AdminController::displayModeTokenStatic();

        $this->assertMatchesRegularExpression('/^[0-9a-f]{32}$/', $token, '16 random bytes, hex');
        $this->assertSame($token, $this->settings->get(AdminController::DISPLAY_MODE_TOKEN_KEY));
    }

    /** And every read after that returns the same one. */
    public function testTheTokenIsStableAcrossReads(): void
    {
        $first = AdminController::displayModeTokenStatic();
        $this->assertSame($first, AdminController::displayModeTokenStatic());
        $this->assertSame($first, AdminController::displayModeTokenStatic());
    }

    /**
     * The token is a random value to compare, not a ciphertext to open.
     *
     * Reaching for App\Models\Encryption here would add a key, an IV and an
     * auth tag to a problem that is a string comparison — and would make the
     * token's validity depend on the encryption key surviving, which has
     * nothing to do with what a screen URL is for.
     */
    public function testTheTokenIsNotEncryptedButRandom(): void
    {
        $source = (string) file_get_contents(__DIR__ . '/../app/Controllers/AdminController.php');

        $start = strpos($source, 'public static function displayModeTokenStatic()');
        $end = strpos($source, 'public function getDisplayToken()', (int) $start);
        $body = substr($source, (int) $start, (int) $end - (int) $start);

        $this->assertStringContainsString('bin2hex(random_bytes(', $body);
        $this->assertStringNotContainsString('Encryption', $body);

        // Two fresh installations must not get the same token.
        $first = AdminController::displayModeTokenStatic();
        $this->settings->delete(AdminController::DISPLAY_MODE_TOKEN_KEY);
        $second = AdminController::displayModeTokenStatic();

        $this->assertNotSame($first, $second);
    }

    /** An empty stored value is treated as absent rather than honoured. */
    public function testAnEmptyStoredValueIsReplaced(): void
    {
        $this->settings->set(AdminController::DISPLAY_MODE_TOKEN_KEY, '');

        $token = AdminController::displayModeTokenStatic();

        $this->assertMatchesRegularExpression('/^[0-9a-f]{32}$/', $token);
    }

    // -----------------------------------------------------------------
    // The endpoints
    // -----------------------------------------------------------------

    public function testGetDisplayTokenRequiresAnAdminSession(): void
    {
        [$json, $status] = $this->call('getDisplayToken');

        $this->assertSame(401, $status);
        $this->assertArrayHasKey('error', $json);
        $this->assertArrayNotHasKey('token', $json);
        $this->assertNull(
            $this->settings->get(AdminController::DISPLAY_MODE_TOKEN_KEY),
            'a refused read must not even mint one'
        );
    }

    public function testRegenerateRequiresAnAdminSession(): void
    {
        $before = AdminController::displayModeTokenStatic();

        [$json, $status] = $this->call('regenerateDisplayToken');

        $this->assertSame(401, $status);
        $this->assertArrayHasKey('error', $json);
        $this->assertSame(
            $before,
            $this->settings->get(AdminController::DISPLAY_MODE_TOKEN_KEY),
            'a refused regeneration must leave the two screens working'
        );
    }

    public function testGetDisplayTokenReturnsTheStoredValue(): void
    {
        $this->loginAsAdmin();
        $stored = AdminController::displayModeTokenStatic();

        [$json, $status] = $this->call('getDisplayToken');

        $this->assertSame(200, $status);
        $this->assertSame($stored, $json['token']);
    }

    public function testRegenerateReplacesTheTokenAndReturnsTheNewOne(): void
    {
        $this->loginAsAdmin();
        $before = AdminController::displayModeTokenStatic();

        [$json, $status] = $this->call('regenerateDisplayToken');

        $this->assertSame(200, $status);
        $this->assertTrue($json['success']);
        $this->assertMatchesRegularExpression('/^[0-9a-f]{32}$/', $json['token']);
        $this->assertNotSame($before, $json['token']);

        // Returned in the response on purpose: the panel repaints the URLs,
        // the QR codes and the launch commands from it without a reload,
        // because putting two screens back on air has to be quick.
        $this->assertSame($json['token'], $this->settings->get(AdminController::DISPLAY_MODE_TOKEN_KEY));
        $this->assertSame($json['token'], AdminController::displayModeTokenStatic());
    }

    // -----------------------------------------------------------------
    // The two things it must never be
    // -----------------------------------------------------------------

    /**
     * Never logged. On a match or a miss.
     *
     * A value whose whole purpose is to be unguessable does not belong in a
     * file that gets tailed into a support ticket, and a "SECURITY:" line on
     * every wrong token would put one there on every scan of the site.
     */
    public function testTheTokenIsNeverLogged(): void
    {
        foreach ([
            __DIR__ . '/../public/index.php',
            __DIR__ . '/../app/Controllers/AdminController.php',
        ] as $file) {
            $source = (string) file_get_contents($file);

            foreach (preg_split('/\r?\n/', $source) ?: [] as $number => $line) {
                if (!str_contains($line, 'error_log') && !str_contains($line, 'trigger_error')) {
                    continue;
                }
                // The identifiers that actually HOLD the value, not the word
                // "token": index.php legitimately logs 'CSRF token mismatch',
                // which names a different token and interpolates none of it.
                foreach ([
                    '$suppliedToken',
                    '$expectedToken',
                    "\$_GET['t']",
                    'displayModeTokenStatic',
                    'DISPLAY_MODE_TOKEN_KEY',
                    'display_mode_token',
                ] as $needle) {
                    $this->assertStringNotContainsString(
                        $needle,
                        $line,
                        basename($file) . ':' . ($number + 1) . ' logs the display mode token'
                    );
                }

                // AdminController's own $token, but only inside the two
                // methods that hold one.
                if (str_contains($file, 'AdminController')) {
                    $this->assertStringNotContainsString(
                        '$token',
                        $line,
                        basename($file) . ':' . ($number + 1) . ' logs a token variable'
                    );
                }
            }
        }
    }

    /**
     * The front controller compares in constant time and falls back silently.
     *
     * index.php cannot be included from a test, so this reads its source for
     * the three properties that carry the decision: hash_equals rather than
     * ===, an explicit null check before it, and a fallback to '' rather than
     * an error response.
     */
    public function testTheFrontControllerComparesSafelyAndFallsBackSilently(): void
    {
        $source = (string) file_get_contents(__DIR__ . '/../public/index.php');

        $start = strpos($source, "if (\$displayMode !== '') {");
        $this->assertNotFalse($start, 'the token gate must still be in index.php');
        $end = strpos($source, '// Serve the SPA shell', $start);
        $gate = substr($source, $start, (int) $end - $start);

        $this->assertStringContainsString('hash_equals($expectedToken, $suppliedToken)', $gate);
        $this->assertStringContainsString('$expectedToken === null', $gate);
        $this->assertStringContainsString("\$displayMode = '';", $gate);

        // No error page and no HTTP status change: a wall must never show an
        // error to a room nobody is standing in.
        $this->assertStringNotContainsString('http_response_code', $gate);
        $this->assertStringNotContainsString('jsonError', $gate);
        $this->assertStringNotContainsString('exit', $gate);
        $this->assertStringNotContainsString('error_log', $gate);
    }
}
