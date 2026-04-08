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
use App\Models\LeaderboardModel;
use App\Models\Encryption;

class LeaderboardModelTest extends TestCase
{
    private \PDO $pdo;
    private LeaderboardModel $model;

    protected function setUp(): void
    {
        // Use SQLite in-memory database for testing
        $this->pdo = new \PDO('sqlite::memory:');
        $this->pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        $this->pdo->setAttribute(\PDO::ATTR_DEFAULT_FETCH_MODE, \PDO::FETCH_ASSOC);

        // Create table with SQLite-compatible schema
        $this->pdo->exec("
            CREATE TABLE leaderboard (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                encrypted_name TEXT NOT NULL,
                score INTEGER NOT NULL DEFAULT 0,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
            )
        ");

        $encryption = new Encryption('test_key_for_leaderboard_tests!');
        $this->model = new LeaderboardModel($this->pdo, $encryption);
    }

    public function testAddEntryReturnsId(): void
    {
        $id = $this->model->addEntry('Alice', 85);
        $this->assertGreaterThan(0, $id);
    }

    public function testGetTopEntriesReturnsDecryptedNames(): void
    {
        $this->model->addEntry('Alice', 90);
        $this->model->addEntry('Bob', 75);
        $this->model->addEntry('Charlie', 100);

        $entries = $this->model->getTopEntries(10);
        $this->assertCount(3, $entries);

        // Should be sorted by score DESC
        $this->assertEquals('Charlie', $entries[0]['player_name']);
        $this->assertEquals(100, $entries[0]['score']);
        $this->assertEquals('Alice', $entries[1]['player_name']);
        $this->assertEquals('Bob', $entries[2]['player_name']);
    }

    public function testGetTopEntriesRespectsLimit(): void
    {
        for ($i = 0; $i < 15; $i++) {
            $this->model->addEntry("Player$i", $i * 5);
        }

        $entries = $this->model->getTopEntries(5);
        $this->assertCount(5, $entries);
    }

    public function testPurgeAllRemovesAllEntries(): void
    {
        $this->model->addEntry('Alice', 90);
        $this->model->addEntry('Bob', 75);

        $this->model->purgeAll();

        $entries = $this->model->getTopEntries(10);
        $this->assertCount(0, $entries);
    }

    public function testEncryptedNameIsStoredNotPlaintext(): void
    {
        $this->model->addEntry('SecretName', 50);

        // Query raw data
        $stmt = $this->pdo->query('SELECT encrypted_name FROM leaderboard LIMIT 1');
        $row = $stmt->fetch();

        $this->assertNotEquals('SecretName', $row['encrypted_name']);
        $this->assertNotEmpty($row['encrypted_name']);
    }
}
