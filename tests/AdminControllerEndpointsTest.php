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
use App\Models\Database;
use App\Models\SettingsModel;
use PHPUnit\Framework\TestCase;
use Tests\Support\UsesInMemoryDatabase;

/**
 * The admin dashboard's endpoints, driven through the real controller.
 *
 * These were the least-covered code in the project: the browser suite only
 * logs in and reads the update panel, so facts, deadline, theme, event code,
 * leaderboard management and the game counter had no test touching them at
 * all. Each is covered here for both what it does and who it refuses — the
 * refusal half matters most, since every one of them mutates the running
 * installation.
 */
class AdminControllerEndpointsTest extends TestCase
{
    use UsesInMemoryDatabase;

    private string $configDir;
    private SettingsModel $settings;

    protected function setUp(): void
    {
        $this->bootInMemoryDatabase();
        $this->settings = new SettingsModel($this->memoryPdo());

        // logout() calls session_regenerate_id(), which warns without a live
        // session. Starting one keeps the controller on its real code path
        // rather than testing a variant that avoids the call.
        if (session_status() === PHP_SESSION_NONE) {
            @session_start();
        }
        $_SESSION = [];

        $this->configDir = sys_get_temp_dir() . '/iso20022-admin-ep-' . bin2hex(random_bytes(6));
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
        foreach (glob($this->configDir . '/*') ?: [] as $f) {
            @unlink($f);
        }
        @rmdir($this->configDir);
        $_SESSION = [];
        $this->shutdownInMemoryDatabase();
    }

    /** @return array{0: mixed, 1: int} decoded JSON and HTTP status */
    private function call(string $method, array $body = []): array
    {
        $controller = new class ($body) extends AdminController {
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

        return [json_decode((string) ob_get_clean(), true), http_response_code()];
    }

    private function asAdmin(): void
    {
        $_SESSION['admin'] = true;
    }

    // -----------------------------------------------------------------
    // Everything here mutates the installation, so nothing may run anonymously
    // -----------------------------------------------------------------

    /**
     * @dataProvider protectedEndpointProvider
     */
    public function testEndpointRefusesAnonymousCallers(string $method): void
    {
        [$json, $status] = $this->call($method);

        $this->assertSame(401, $status, "{$method} must refuse an anonymous caller");
        $this->assertSame('Unauthorized', $json['error'] ?? null);
    }

    public static function protectedEndpointProvider(): array
    {
        return array_map(fn ($m) => [$m], [
            'changePin', 'getLeaderboardEntries', 'deleteLeaderboardEntry', 'purgeLeaderboard',
            'setDeadline', 'getDeadline', 'getFacts', 'addFact', 'updateFact', 'deleteFact',
            'getGameStats', 'resetGameCounter', 'getEventCode', 'setEventCode',
            'getTheme', 'saveTheme',
        ]);
    }

    // -----------------------------------------------------------------
    // Facts
    // -----------------------------------------------------------------

    public function testFactsCanBeAddedListedUpdatedAndDeleted(): void
    {
        $this->asAdmin();

        [$added, $status] = $this->call('addFact', ['content' => 'ISO 20022 is a messaging standard']);
        $this->assertSame(200, $status);
        $this->assertTrue($added['success']);

        [$listed] = $this->call('getFacts');
        $mine = array_values(array_filter(
            $listed['facts'],
            fn ($f) => $f['content'] === 'ISO 20022 is a messaging standard'
        ));
        $this->assertCount(1, $mine);
        $id = (int) $mine[0]['id'];

        [$updated] = $this->call('updateFact', ['id' => $id, 'content' => 'Updated fact text']);
        $this->assertTrue($updated['success']);

        [$afterUpdate] = $this->call('getFacts');
        $contents = array_column($afterUpdate['facts'], 'content');
        $this->assertContains('Updated fact text', $contents);
        $this->assertNotContains('ISO 20022 is a messaging standard', $contents);

        [$deleted] = $this->call('deleteFact', ['id' => $id]);
        $this->assertTrue($deleted['success']);

        [$afterDelete] = $this->call('getFacts');
        $this->assertNotContains('Updated fact text', array_column($afterDelete['facts'], 'content'));
    }

    public function testFactMarkupIsSanitisedOnWrite(): void
    {
        $this->asAdmin();
        $this->call('addFact', ['content' => 'Safe <b>bold</b><script>alert(1)</script>']);

        [$listed] = $this->call('getFacts');
        $stored = array_column($listed['facts'], 'content');
        $match = array_values(array_filter($stored, fn ($c) => str_contains($c, 'Safe')))[0];

        $this->assertStringContainsString('<b>bold</b>', $match, 'the allowlisted tag survives');
        $this->assertStringNotContainsString('<script', $match, 'the script tag must not');
    }

    public function testAnEmptyFactIsRefused(): void
    {
        $this->asAdmin();
        [, $status] = $this->call('addFact', ['content' => '   ']);
        $this->assertSame(400, $status);
    }

    public function testAnOverlongFactIsRefused(): void
    {
        $this->asAdmin();
        [, $status] = $this->call('addFact', ['content' => str_repeat('x', 501)]);
        $this->assertSame(400, $status);
    }

    public function testUpdatingAFactRequiresAValidId(): void
    {
        $this->asAdmin();
        [, $status] = $this->call('updateFact', ['id' => 0, 'content' => 'x']);
        $this->assertSame(400, $status);
    }

    public function testDeletingAFactRequiresAValidId(): void
    {
        $this->asAdmin();
        [, $status] = $this->call('deleteFact', ['id' => 0]);
        $this->assertSame(400, $status);
    }

    // -----------------------------------------------------------------
    // Deadline
    // -----------------------------------------------------------------

    public function testDeadlineRoundTripsAndCanBeCleared(): void
    {
        $this->asAdmin();

        [$saved, $status] = $this->call('setDeadline', ['deadline' => '2026-11-14T18:00']);
        $this->assertSame(200, $status);
        $this->assertTrue($saved['success']);

        [$read] = $this->call('getDeadline');
        $this->assertSame('2026-11-14T18:00', $read['deadline']);

        $this->call('setDeadline', ['deadline' => '']);
        [$cleared] = $this->call('getDeadline');
        $this->assertNull($cleared['deadline']);
    }

    public function testAMalformedDeadlineIsRefused(): void
    {
        $this->asAdmin();
        [, $status] = $this->call('setDeadline', ['deadline' => 'the day after tomorrow']);
        $this->assertSame(400, $status);
    }

    // -----------------------------------------------------------------
    // Theme
    // -----------------------------------------------------------------

    public function testThemeColoursRoundTrip(): void
    {
        $this->asAdmin();

        [$saved, $status] = $this->call('saveTheme', ['theme' => ['color_primary' => '#123456']]);
        $this->assertSame(200, $status);
        $this->assertTrue($saved['success']);

        [$read] = $this->call('getTheme');
        $this->assertSame('#123456', $read['theme']['color_primary']);
    }

    public function testInvalidThemePayloadIsRefused(): void
    {
        $this->asAdmin();
        [, $status] = $this->call('saveTheme', ['theme' => 'not-an-array']);
        $this->assertSame(400, $status);
    }

    // -----------------------------------------------------------------
    // Event code
    // -----------------------------------------------------------------

    public function testEventCodeIsHashedNeverReturnedAndCanBeCleared(): void
    {
        $this->asAdmin();

        [$saved, $status] = $this->call('setEventCode', ['event_code' => 'LetMeIn2026']);
        $this->assertSame(200, $status);
        $this->assertTrue($saved['has_code']);

        $stored = $this->settings->get('event_code');
        $this->assertStringStartsWith('$2y$', (string) $stored, 'stored as a bcrypt hash');
        $this->assertTrue(password_verify('LetMeIn2026', (string) $stored));

        [$status_] = $this->call('getEventCode');
        $this->assertTrue($status_['has_code']);
        $this->assertArrayNotHasKey('event_code', $status_, 'the code itself must never come back');
        $this->assertStringNotContainsString('LetMeIn2026', json_encode($status_));

        [$clearedResp] = $this->call('setEventCode', ['event_code' => '']);
        $this->assertFalse($clearedResp['has_code']);
        $this->assertNull($this->settings->get('event_code'));
    }

    public function testAnOverlongEventCodeIsRefused(): void
    {
        $this->asAdmin();
        [, $status] = $this->call('setEventCode', ['event_code' => str_repeat('a', 65)]);
        $this->assertSame(400, $status);
    }

    // -----------------------------------------------------------------
    // Leaderboard management and game counter
    // -----------------------------------------------------------------

    private function seedLeaderboard(int $rows = 3): void
    {
        $model = new \App\Models\LeaderboardModel($this->memoryPdo());
        for ($i = 1; $i <= $rows; $i++) {
            $model->addEntry("Player {$i}", $i * 10, $i * 5);
        }
    }

    public function testLeaderboardEntriesAreListedAndCanBeDeletedIndividually(): void
    {
        $this->seedLeaderboard();
        $this->asAdmin();

        [$listed, $status] = $this->call('getLeaderboardEntries');
        $this->assertSame(200, $status);
        $this->assertCount(3, $listed['entries']);

        $id = (int) $listed['entries'][0]['id'];
        [$deleted] = $this->call('deleteLeaderboardEntry', ['id' => $id]);
        $this->assertTrue($deleted['success']);

        [$after] = $this->call('getLeaderboardEntries');
        $this->assertCount(2, $after['entries']);
    }

    public function testDeletingAnEntryRequiresAValidId(): void
    {
        $this->asAdmin();
        [, $status] = $this->call('deleteLeaderboardEntry', ['id' => 0]);
        $this->assertSame(400, $status);
    }

    public function testPurgeEmptiesTheLeaderboard(): void
    {
        $this->seedLeaderboard(4);
        $this->asAdmin();

        [$purged] = $this->call('purgeLeaderboard');
        $this->assertTrue($purged['success']);

        [$after] = $this->call('getLeaderboardEntries');
        $this->assertSame([], $after['entries']);
    }

    public function testGameStatsReportTotalsAndWeeklyBuckets(): void
    {
        $counter = new \App\Models\GameCounterModel($this->memoryPdo());
        $counter->increment();
        $counter->increment();
        $this->asAdmin();

        [$stats, $status] = $this->call('getGameStats');
        $this->assertSame(200, $status);
        $this->assertSame(2, (int) $stats['total_games']);
        $this->assertIsArray($stats['weekly_stats']);
    }

    public function testResettingTheCounterRebuildsItFromTheLeaderboard(): void
    {
        $this->seedLeaderboard(5);
        $this->asAdmin();

        [$reset, $status] = $this->call('resetGameCounter');
        $this->assertSame(200, $status);
        $this->assertTrue($reset['success']);

        [$stats] = $this->call('getGameStats');
        $this->assertSame(5, (int) $stats['total_games'], 'one game per leaderboard row');
    }

    // -----------------------------------------------------------------
    // Session
    // -----------------------------------------------------------------

    public function testLogoutClearsTheAdminFlag(): void
    {
        $this->asAdmin();
        [$json, $status] = $this->call('logout');

        $this->assertSame(200, $status);
        $this->assertTrue($json['success']);
        $this->assertEmpty($_SESSION['admin']);
    }

    public function testChangePinRejectsAMalformedPin(): void
    {
        $this->asAdmin();
        foreach (['12', '123456789', 'abcd', ''] as $bad) {
            [, $status] = $this->call('changePin', ['new_pin' => $bad]);
            $this->assertSame(400, $status, "PIN '{$bad}' must be refused");
        }
    }
}
