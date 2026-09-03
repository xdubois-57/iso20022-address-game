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
use App\Controllers\GameController;
use App\Controllers\LeaderboardController;
use App\Controllers\SetupController;
use App\Controllers\ShareController;
use App\Models\ScenarioModel;
use App\Models\SettingsModel;
use App\Support\Input;
use PHPUnit\Framework\TestCase;
use Tests\Support\UsesInMemoryDatabase;

/**
 * A JSON body's shape belongs to the caller: nothing stops a client from
 * sending an array or object where an endpoint expects a string. Every
 * endpoint probed here used to die on that with an uncaught TypeError — an
 * HTTP 500 with a stack trace in the error log, triggerable by any visitor
 * on the unauthenticated ones (login, name check, leaderboard submit, share
 * token, setup). Not an authentication bypass, but a crash on
 * attacker-shaped input is a defect regardless, and the log noise it makes
 * buries the SECURITY: lines worth reading.
 *
 * The contract these tests pin: malformed shapes get the same orderly
 * refusal a wrong VALUE gets — never a fatal — and where an empty string is
 * itself a command ("clear the deadline") a malformed value must be rejected
 * outright rather than coerced into that command.
 */
class MalformedJsonInputTest extends TestCase
{
    use UsesInMemoryDatabase;

    private SettingsModel $settings;
    private string $configDir;

    protected function setUp(): void
    {
        $this->bootInMemoryDatabase();
        $this->settings = new SettingsModel($this->memoryPdo());
        $_SESSION = [];

        // Controllers that touch Encryption need a real key; give them a
        // throwaway config directory rather than the developer's own.
        $this->configDir = sys_get_temp_dir() . '/iso20022-malformed-test-' . bin2hex(random_bytes(6));
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

    private function invoke(object $controller, string $method): array
    {
        http_response_code(200);
        ob_start();
        $controller->{$method}();
        $raw = (string) ob_get_clean();

        return [json_decode($raw, true), http_response_code()];
    }

    private function admin(array $body): AdminController
    {
        return new class ($body) extends AdminController {
            public function __construct(private array $testBody)
            {
                parent::__construct();
            }

            protected function getJsonInput(): array
            {
                return $this->testBody;
            }
        };
    }

    private function game(array $body): GameController
    {
        return new class ($body) extends GameController {
            public function __construct(private array $testBody)
            {
                parent::__construct();
            }

            protected function getJsonInput(): array
            {
                return $this->testBody;
            }
        };
    }

    private function leaderboard(array $body): LeaderboardController
    {
        return new class ($body) extends LeaderboardController {
            public function __construct(private array $testBody)
            {
                parent::__construct();
            }

            protected function getJsonInput(): array
            {
                return $this->testBody;
            }
        };
    }

    private function share(array $body): ShareController
    {
        // No parent::__construct(): ShareController declares no constructor.
        return new class ($body) extends ShareController {
            public function __construct(private array $testBody)
            {
            }

            protected function getJsonInput(): array
            {
                return $this->testBody;
            }
        };
    }

    private function setupController(array $body): SetupController
    {
        // No parent::__construct(): SetupController declares no constructor.
        return new class ($body) extends SetupController {
            public function __construct(private array $testBody)
            {
            }

            protected function getJsonInput(): array
            {
                return $this->testBody;
            }
        };
    }

    // -----------------------------------------------------------------
    // Unauthenticated endpoints — the surface any visitor can reach
    // -----------------------------------------------------------------

    public function testLoginWithAnArrayPinIsAnOrdinaryRefusal(): void
    {
        [$json, $status] = $this->invoke($this->admin(['pin' => ['1', '2', '3', '4']]), 'login');

        $this->assertSame(401, $status, 'an array PIN used to fatal in password_verify()');
        $this->assertSame('Invalid PIN', $json['error'] ?? null);
        $this->assertArrayNotHasKey('admin', $_SESSION, 'and it must certainly not log anyone in');
    }

    public function testCheckNameWithAnArrayNameIsRejectedNotFatal(): void
    {
        [$json, $status] = $this->invoke($this->game(['name' => [1, 2, 3]]), 'checkName');

        $this->assertSame(400, $status);
        $this->assertArrayHasKey('error', $json);
    }

    public function testLeaderboardSubmitWithAnArrayNameIsRejectedNotFatal(): void
    {
        [$json, $status] = $this->invoke(
            $this->leaderboard(['player_name' => ['x'], 'score' => 50, 'time_seconds' => 10]),
            'submit'
        );

        $this->assertSame(400, $status);
        $this->assertSame(
            0,
            (int) $this->memoryPdo()->query('SELECT COUNT(*) FROM leaderboard')->fetchColumn(),
            'nothing may be stored for a malformed submission'
        );
    }

    public function testShareTokenWithAnObjectNameFallsBackToTheDefaultName(): void
    {
        [$json, $status] = $this->invoke($this->share(['score' => 100, 'name' => ['x' => 1]]), 'generateToken');

        // This endpoint's sanitizeName() already maps unusable names to
        // 'A player', so the right behaviour for an unusable TYPE is the
        // same fallback — not the TypeError it used to be.
        $this->assertSame(200, $status);
        $this->assertNotEmpty($json['token'] ?? '');
    }

    public function testSetupTestConnectionWithArrayFieldsIsRejectedNotFatal(): void
    {
        // Setup is the one surface reachable before ANY authentication
        // exists — a fresh install. index.php's configured-install lockdown
        // is not in front of the controller here, which is the point: the
        // controller itself must not fatal.
        [$json, $status] = $this->invoke(
            $this->setupController([
                'host' => ['h'], 'port' => ['p'], 'name' => ['db'],
                'username' => ['u'], 'password' => ['s'],
            ]),
            'testConnection'
        );

        $this->assertSame(400, $status, 'array fields used to fatal in trim()');
        $this->assertArrayHasKey('error', $json);
    }

    public function testValidateWithAStringMappingIsRejectedNotFatal(): void
    {
        [$json, $status] = $this->invoke(
            $this->game(['scenario_id' => 1, 'goal_type' => 'Structured', 'mapping' => 'not-an-array']),
            'validate'
        );

        $this->assertSame(400, $status, 'a string mapping used to reach an array-typed parameter and fatal');
        $this->assertArrayHasKey('error', $json);
    }

    public function testValidateWithArrayValuesInsideTheMappingGradesThemWrongNotFatal(): void
    {
        $scenarioId = (new \App\Models\ScenarioModel($this->memoryPdo()))->create([
            'StrtNm' => 'Main St', 'BldgNb' => '1', 'PstCd' => '10001',
            'TwnNm' => 'Springfield', 'Ctry' => 'US', 'AdtlAdrInf' => '',
        ]);

        [$json, $status] = $this->invoke(
            $this->game([
                'scenario_id' => $scenarioId,
                'goal_type' => 'Structured',
                'mapping' => ['TwnNm' => ['nested'], 'Ctry' => 'US'],
            ]),
            'validate'
        );

        $this->assertSame(200, $status, 'an array VALUE inside the mapping used to fatal in trim()');
        $this->assertLessThan(100, $json['percentage'], 'and it is graded wrong, not right');
    }

    // -----------------------------------------------------------------
    // Admin endpoint where '' is a command — reject, never coerce
    // -----------------------------------------------------------------

    public function testMalformedDeadlineDoesNotClearTheStoredOne(): void
    {
        $_SESSION['admin'] = true;
        $this->settings->set('unstructured_deadline', '2026-11-14T18:00');

        [$json, $status] = $this->invoke($this->admin(['deadline' => ['x']]), 'setDeadline');

        $this->assertSame(400, $status);
        $this->assertSame(
            '2026-11-14T18:00',
            $this->settings->get('unstructured_deadline'),
            "coercing a malformed deadline to '' would have CLEARED the real one"
        );
    }

    public function testChangePinWithAnArrayIsRejectedNotFatal(): void
    {
        $_SESSION['admin'] = true;

        [$json, $status] = $this->invoke($this->admin(['new_pin' => ['1', '2']]), 'changePin');

        $this->assertSame(400, $status, 'an array PIN used to fatal in preg_match()');
    }

    /**
     * A deadline that is well-formed but impossible. createFromFormat()
     * accepts it and rolls it over with only a warning, so it used to be
     * stored verbatim — and the browser's Date() of it is Invalid Date,
     * which turned the countdown into NaN on every screen.
     *
     * @dataProvider impossibleDeadlineProvider
     */
    public function testImpossibleDeadlineIsRejected(string $deadline): void
    {
        $_SESSION['admin'] = true;
        $this->settings->set('unstructured_deadline', '2026-11-14T18:00');

        [$json, $status] = $this->invoke($this->admin(['deadline' => $deadline]), 'setDeadline');

        $this->assertSame(400, $status, "'$deadline' is not a date that exists");
        $this->assertSame('2026-11-14T18:00', $this->settings->get('unstructured_deadline'));
    }

    public static function impossibleDeadlineProvider(): array
    {
        return [
            '31 February'        => ['2027-02-31T00:00'],
            '25th hour'          => ['2027-11-28T25:00'],
            '99th minute'        => ['2027-11-28T10:99'],
            'month 13'           => ['2027-13-01T00:00'],
            'unpadded fields'    => ['2027-1-5T9:05'],
        ];
    }

    public function testRealDeadlineStillSaves(): void
    {
        $_SESSION['admin'] = true;

        [$json, $status] = $this->invoke($this->admin(['deadline' => '2028-02-29T09:30']), 'setDeadline');

        $this->assertSame(200, $status, 'a leap day is a real date');
        $this->assertSame('2028-02-29T09:30', $this->settings->get('unstructured_deadline'));
    }

    // -----------------------------------------------------------------
    // Shapes that used to reach an array-typed parameter and fatal
    // -----------------------------------------------------------------

    /**
     * @dataProvider nonListExcludeIdsProvider
     */
    public function testScenarioRequestWithNonListExcludeIdsIsNotFatal(mixed $excludeIds): void
    {
        (new ScenarioModel($this->memoryPdo()))->create([
            'StrtNm' => 'Main St', 'BldgNb' => '1', 'PstCd' => '10001',
            'TwnNm' => 'New York', 'Ctry' => 'US', 'AdtlAdrInf' => '',
        ]);

        [$json, $status] = $this->invoke($this->game(['exclude_ids' => $excludeIds]), 'getScenario');

        $this->assertSame(200, $status, 'a non-list used to fatal in getRandom()');
        $this->assertArrayHasKey('scenario', $json, 'and a non-list simply excludes nothing');
    }

    public static function nonListExcludeIdsProvider(): array
    {
        return [
            'string' => ['abc'],
            'int'    => [5],
            'bool'   => [true],
        ];
    }

    public function testThemeWithAnArrayColourIsSkippedNotFatal(): void
    {
        $_SESSION['admin'] = true;

        [$json, $status] = $this->invoke(
            $this->admin(['theme' => ['color_primary' => ['#ffffff'], 'color_bg' => '#123456']]),
            'saveTheme'
        );

        $this->assertSame(200, $status, 'an array colour used to fatal in isValidHex()');
        $theme = (new \App\Models\ThemeModel($this->memoryPdo()))->get();
        $this->assertSame('#123456', $theme['color_bg'], 'the valid colour in the same request is kept');
        $this->assertSame(
            \App\Models\ThemeModel::defaults()['color_primary'],
            $theme['color_primary'],
            'the malformed one is ignored'
        );
    }

    /**
     * ?d[]=x on /share, /share/go or /share/image hands the controller an
     * array, which used to reach the string-typed decryptToken() and fatal
     * on three public routes.
     *
     * @dataProvider nonStringShareTokenProvider
     */
    public function testShareTokenThatIsNotAStringIsNotAToken(mixed $token): void
    {
        $decrypt = new \ReflectionMethod(ShareController::class, 'decryptToken');

        $this->assertNull($decrypt->invoke(new ShareController(), $token));
    }

    public static function nonStringShareTokenProvider(): array
    {
        return [
            'array'  => [['x']],
            'int'    => [42],
            'null'   => [null],
            'empty'  => [''],
        ];
    }

    // -----------------------------------------------------------------
    // The helper itself
    // -----------------------------------------------------------------

    /**
     * Scalars must keep behaving exactly as PHP's own coercion did, so a
     * numeric PIN or code sent as a JSON number keeps working; only the
     * types PHP fataled on become the default.
     *
     * @dataProvider inputStringProvider
     */
    public function testInputStringMirrorsPhpScalarCoercion(mixed $value, string $expected): void
    {
        $this->assertSame($expected, Input::string($value));
    }

    public static function inputStringProvider(): array
    {
        return [
            'string'       => ['abc', 'abc'],
            'empty string' => ['', ''],
            'int'          => [1234, '1234'],
            'float'        => [1.5, '1.5'],
            'true'         => [true, '1'],
            'false'        => [false, ''],
            'null'         => [null, ''],
            'array'        => [['a'], ''],
            'nested array' => [[['a']], ''],
        ];
    }
}
