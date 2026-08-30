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

    protected function setUp(): void
    {
        $this->pdo = $this->bootInMemoryDatabase();
    }

    protected function tearDown(): void
    {
        $this->shutdownInMemoryDatabase();
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
}
