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

use App\Models\Encryption;
use App\Models\LeaderboardModel;
use App\Models\RetentionCleanup;
use PHPUnit\Framework\TestCase;
use Tests\Support\UsesInMemoryDatabase;

/**
 * The scheduled deletions, exercised against SQLite.
 *
 * Which is the whole point of this file: LeaderboardModel::purgeExpired()
 * was written in MySQL-only dialect and had no test at all, so nothing
 * noticed that the poor man's cron in public/index.php — the code path a
 * `composer serve` instance and the Playwright harness both take on their
 * very first request — died with a syntax error before rendering anything.
 */
class RetentionCleanupTest extends TestCase
{
    use UsesInMemoryDatabase;

    private \PDO $pdo;

    /** Scratch directory for the poor man's cron stamp. */
    private string $scratch;

    protected function setUp(): void
    {
        $this->pdo = $this->bootInMemoryDatabase();
        $this->scratch = sys_get_temp_dir() . '/retention-' . bin2hex(random_bytes(8));
        mkdir($this->scratch, 0755, true);
    }

    protected function tearDown(): void
    {
        $this->shutdownInMemoryDatabase();

        if (is_dir($this->scratch)) {
            foreach (glob($this->scratch . '/*') ?: [] as $file) {
                @unlink($file);
            }
            @rmdir($this->scratch);
        }
    }

    /** Path to a stamp inside this test's scratch directory. */
    private function stampPath(string $name = 'last_cleanup.txt'): string
    {
        return $this->scratch . '/' . $name;
    }

    private function leaderboard(): LeaderboardModel
    {
        return new LeaderboardModel($this->pdo, new Encryption(str_repeat('k', 32)));
    }

    /** Insert a leaderboard row stamped a given number of days in the past. */
    private function addAgedEntry(string $name, int $daysAgo): void
    {
        $id = $this->leaderboard()->addEntry($name, 90, 42);
        $this->pdo->prepare('UPDATE leaderboard SET created_at = ? WHERE id = ?')->execute([
            gmdate('Y-m-d H:i:s', time() - ($daysAgo * 86400)),
            $id,
        ]);
    }

    public function testPurgeExpiredRunsOnSqlite(): void
    {
        $this->addAgedEntry('Recent', 1);

        // The assertion that matters is that this does not throw: the MySQL
        // spelling raised "near \"?\": syntax error" here.
        $this->assertSame(0, $this->leaderboard()->purgeExpired(365));
    }

    public function testPurgeExpiredDeletesOnlyEntriesPastRetention(): void
    {
        $this->addAgedEntry('Ancient', 400);
        $this->addAgedEntry('Borderline', 366);
        $this->addAgedEntry('Recent', 10);

        $this->assertSame(2, $this->leaderboard()->purgeExpired(365));

        $remaining = $this->leaderboard()->getTopEntries(10);
        $this->assertCount(1, $remaining);
        $this->assertSame('Recent', $remaining[0]['player_name']);
    }

    public function testRunPurgesBothLeaderboardAndRateLimits(): void
    {
        $this->addAgedEntry('Ancient', 400);

        // A spent rate-limit row: unlocked, and untouched for longer than the
        // retention window. RateLimitModel has no way to write an aged row,
        // so this goes in directly.
        $this->pdo->prepare(
            'INSERT INTO rate_limits (bucket, attempts, updated_at, locked_until) VALUES (?, ?, ?, ?)'
        )->execute(['admin_login:stale', 3, time() - (RetentionCleanup::RATE_LIMIT_RETENTION_SECONDS + 60), 0]);

        // ...and one still holding somebody out, which must survive.
        $this->pdo->prepare(
            'INSERT INTO rate_limits (bucket, attempts, updated_at, locked_until) VALUES (?, ?, ?, ?)'
        )->execute(['admin_login:active', 5, time(), time() + 300]);

        $deleted = (new RetentionCleanup($this->pdo))->run();

        $this->assertSame(1, $deleted['leaderboard']);
        $this->assertSame(1, $deleted['rate_limits'], 'rate_limits was never pruned before this existed');

        $survivors = $this->pdo->query('SELECT bucket FROM rate_limits')->fetchAll(\PDO::FETCH_COLUMN);
        $this->assertSame(['admin_login:active'], $survivors);
    }

    public function testHousekeepingNeedsNoEncryptionKey(): void
    {
        $this->addAgedEntry('Ancient', 400);

        // No key passed, and none configured in this process. Deleting rows
        // reads no player name, so it must not depend on being able to
        // decrypt one — building the cipher eagerly used to turn a lost key
        // into a fatal on every page load, via the poor man's cron.
        $keyless = new LeaderboardModel($this->pdo);

        $this->assertSame(1, $keyless->purgeExpired(365));
        $this->assertSame(0, $keyless->getTotalCount());
    }

    /*
     * The poor man's cron gate.
     *
     * Everything above tests what the cleanup DELETES. These test whether it
     * ever RUNS, which on an install with no real cron job is the same
     * question as whether the 365-day retention in the privacy notice is true.
     */

    public function testFirstEverRequestIsDueAndCreatesTheStamp(): void
    {
        $stamp = $this->stampPath();
        $this->assertFileDoesNotExist($stamp);

        $this->assertTrue(RetentionCleanup::claimDueSlot($stamp, 1_000_000));
        $this->assertSame('1000000', file_get_contents($stamp));
    }

    public function testMissingDirectoryIsCreated(): void
    {
        // storage/ does not exist on a fresh checkout.
        $stamp = $this->scratch . '/nested/deeper/last_cleanup.txt';

        $this->assertTrue(RetentionCleanup::claimDueSlot($stamp, 1_000_000));
        $this->assertFileExists($stamp);

        foreach ([$stamp, dirname($stamp), dirname($stamp, 2)] as $path) {
            is_dir($path) ? @rmdir($path) : @unlink($path);
        }
    }

    public function testASecondRequestInTheSameDayIsNotDue(): void
    {
        $stamp = $this->stampPath();

        $this->assertTrue(RetentionCleanup::claimDueSlot($stamp, 1_000_000));
        $this->assertFalse(
            RetentionCleanup::claimDueSlot($stamp, 1_000_060),
            'the slot is claimed by the first caller, so traffic does not re-run the sweep'
        );
    }

    /**
     * The boundary, spelled out in both directions. An off-by-one here is
     * invisible: the sweep simply drifts, or stops.
     */
    public function testDueExactlyOnceTheIntervalHasElapsed(): void
    {
        $start = 1_000_000;

        $atTheLimit = $this->stampPath('limit.txt');
        file_put_contents($atTheLimit, (string)$start);
        $this->assertFalse(
            RetentionCleanup::claimDueSlot($atTheLimit, $start + RetentionCleanup::INTERVAL_SECONDS),
            'a full interval has not yet ELAPSED at the instant it is reached'
        );

        $pastTheLimit = $this->stampPath('past.txt');
        file_put_contents($pastTheLimit, (string)$start);
        $this->assertTrue(
            RetentionCleanup::claimDueSlot($pastTheLimit, $start + RetentionCleanup::INTERVAL_SECONDS + 1)
        );
    }

    public function testTheClaimIsWrittenBeforeTheCallerSweeps(): void
    {
        // Two requests arriving together: only one may start a sweep. The
        // stamp is written by claimDueSlot() itself, so the second caller is
        // turned away without the first having finished — or even started.
        $stamp = $this->stampPath();

        $first = RetentionCleanup::claimDueSlot($stamp, 2_000_000);
        $second = RetentionCleanup::claimDueSlot($stamp, 2_000_000);

        $this->assertTrue($first);
        $this->assertFalse($second);
    }

    /**
     * @dataProvider unusableStamps
     */
    public function testAnUnreadableStampReadsAsNeverRan(string $contents): void
    {
        $stamp = $this->stampPath();
        file_put_contents($stamp, $contents);

        $this->assertTrue(
            RetentionCleanup::claimDueSlot($stamp, 1_000_000),
            'a stamp that cannot be believed must not be allowed to suppress the sweep forever'
        );
        $this->assertSame('1000000', file_get_contents($stamp), 'and it is repaired on the way through');
    }

    /** @return array<string, array{string}> */
    public static function unusableStamps(): array
    {
        return [
            'empty' => [''],
            'not a number' => ['corrupted'],
            'whitespace' => ["   \n"],
            'a date rather than a timestamp' => ['2026-09-03 03:00:00'],
        ];
    }

    /**
     * A stamp that cannot be WRITTEN is the one case with no good answer, so
     * the choice is pinned here rather than left to chance: the sweep still
     * runs. It then runs on every request, which is wasteful and shows up in
     * the logs — the opposite failure, a silently skipped retention, does not.
     *
     * The unwritable path is a directory that is really a FILE, rather than a
     * chmod: a permission bit means nothing when the tests run as root, which
     * is exactly what happens in a container, and a test that quietly skips
     * itself on the machine you read the output on is not a test.
     */
    public function testAnUnwritableStampStillLetsTheSweepRun(): void
    {
        $blocker = $this->stampPath('not-a-directory');
        file_put_contents($blocker, 'this is a file, so nothing can live under it');

        $impossible = $blocker . '/last_cleanup.txt';

        $this->assertTrue(RetentionCleanup::claimDueSlot($impossible, 1_000_000));
        $this->assertTrue(
            RetentionCleanup::claimDueSlot($impossible, 1_000_060),
            'with nowhere to record the claim, every request is due — noisy, but never silent'
        );
        $this->assertFileDoesNotExist($impossible);
    }
}
