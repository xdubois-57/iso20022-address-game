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
use App\Models\GameCounterModel;
use App\Models\LeaderboardModel;
use App\Models\Encryption;

class GameCounterModelTest extends TestCase
{
    private \PDO $pdo;
    private GameCounterModel $counter;

    protected function setUp(): void
    {
        $this->pdo = new \PDO('sqlite::memory:');
        $this->pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        $this->pdo->setAttribute(\PDO::ATTR_DEFAULT_FETCH_MODE, \PDO::FETCH_ASSOC);

        $this->pdo->exec("
            CREATE TABLE game_counter (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                played_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
            )
        ");

        $this->pdo->exec("
            CREATE TABLE leaderboard (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                encrypted_name TEXT NOT NULL,
                score INTEGER NOT NULL DEFAULT 0,
                time_seconds INTEGER NOT NULL DEFAULT 0,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
            )
        ");

        $this->counter = new GameCounterModel($this->pdo);
    }

    public function testGetTotalCountReturnsZeroInitially(): void
    {
        $this->assertEquals(0, $this->counter->getTotalCount());
    }

    public function testIncrementIncreasesCount(): void
    {
        $this->counter->increment();
        $this->assertEquals(1, $this->counter->getTotalCount());

        $this->counter->increment();
        $this->counter->increment();
        $this->assertEquals(3, $this->counter->getTotalCount());
    }

    public function testResetFromLeaderboardClearsAndSeedsFromLeaderboard(): void
    {
        // Add some game counter entries
        $this->counter->increment();
        $this->counter->increment();
        $this->counter->increment();
        $this->assertEquals(3, $this->counter->getTotalCount());

        // Add leaderboard entries
        $encryption = new Encryption('test_key_for_game_counter_tests!');
        $leaderboard = new LeaderboardModel($this->pdo, $encryption);
        $leaderboard->addEntry('Alice', 90, 60);
        $leaderboard->addEntry('Bob', 80, 120);

        // Reset - should now have 2 (from leaderboard entries)
        $newCount = $this->counter->resetFromLeaderboard();
        $this->assertEquals(2, $newCount);
        $this->assertEquals(2, $this->counter->getTotalCount());
    }

    public function testResetFromEmptyLeaderboardClearsAll(): void
    {
        $this->counter->increment();
        $this->counter->increment();
        $this->assertEquals(2, $this->counter->getTotalCount());

        $newCount = $this->counter->resetFromLeaderboard();
        $this->assertEquals(0, $newCount);
    }

    public function testCounterNeverAutoResets(): void
    {
        // Simulate many games
        for ($i = 0; $i < 100; $i++) {
            $this->counter->increment();
        }
        $this->assertEquals(100, $this->counter->getTotalCount());

        // Still 100 after accessing count multiple times
        $this->assertEquals(100, $this->counter->getTotalCount());
        $this->assertEquals(100, $this->counter->getTotalCount());
    }
}
