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
                time_seconds INTEGER NOT NULL DEFAULT 0,
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

    public function testAddEntryWithTimeSeconds(): void
    {
        $id = $this->model->addEntry('Alice', 85, 120);
        $this->assertGreaterThan(0, $id);

        $stmt = $this->pdo->query('SELECT time_seconds FROM leaderboard WHERE id = ' . $id);
        $row = $stmt->fetch();
        $this->assertEquals(120, $row['time_seconds']);
    }

    public function testGetTopEntriesSortsByScoreThenTime(): void
    {
        $this->model->addEntry('Slow', 90, 300);
        $this->model->addEntry('Fast', 90, 60);

        $entries = $this->model->getTopEntries(10);
        // Same score, lower time should come first
        $this->assertEquals('Fast', $entries[0]['player_name']);
        $this->assertEquals('Slow', $entries[1]['player_name']);
    }

    public function testGetTopEntriesRemovesEncryptedNameField(): void
    {
        $this->model->addEntry('Alice', 50);
        $entries = $this->model->getTopEntries(10);
        $this->assertArrayNotHasKey('encrypted_name', $entries[0]);
        $this->assertArrayHasKey('player_name', $entries[0]);
    }

    public function testDeleteEntryRemovesSpecificEntry(): void
    {
        $id1 = $this->model->addEntry('Alice', 90);
        $id2 = $this->model->addEntry('Bob', 80);

        $result = $this->model->deleteEntry($id1);
        $this->assertTrue($result);

        $entries = $this->model->getTopEntries(10);
        $this->assertCount(1, $entries);
        $this->assertEquals('Bob', $entries[0]['player_name']);
    }

    public function testDeleteEntryReturnsFalseForNonExistent(): void
    {
        $result = $this->model->deleteEntry(9999);
        $this->assertFalse($result);
    }

    public function testDeleteEntryDoesNotAffectOtherEntries(): void
    {
        $id1 = $this->model->addEntry('Alice', 90);
        $id2 = $this->model->addEntry('Bob', 80);
        $id3 = $this->model->addEntry('Charlie', 70);

        $this->model->deleteEntry($id2);

        $entries = $this->model->getTopEntries(10);
        $this->assertCount(2, $entries);
        $this->assertEquals('Alice', $entries[0]['player_name']);
        $this->assertEquals('Charlie', $entries[1]['player_name']);
    }
}
