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

    public function testGetRecentEntriesReturnsMostRecent(): void
    {
        $this->model->addEntry('First', 50);
        $this->model->addEntry('Second', 60);
        $this->model->addEntry('Third', 70);

        $recent = $this->model->getRecentEntries(2);
        $this->assertCount(2, $recent);
        // Most recent first
        $this->assertEquals('Third', $recent[0]['player_name']);
        $this->assertEquals('Second', $recent[1]['player_name']);
    }

    public function testGetRecentEntriesRespectsLimit(): void
    {
        for ($i = 0; $i < 10; $i++) {
            $this->model->addEntry("Player$i", $i * 10);
        }

        $recent = $this->model->getRecentEntries(5);
        $this->assertCount(5, $recent);
    }

    public function testGetRecentEntriesRemovesEncryptedNameField(): void
    {
        $this->model->addEntry('Alice', 50);
        $recent = $this->model->getRecentEntries(5);
        $this->assertArrayNotHasKey('encrypted_name', $recent[0]);
        $this->assertArrayHasKey('player_name', $recent[0]);
    }

    public function testGetRecentEntriesEmptyTable(): void
    {
        $recent = $this->model->getRecentEntries(5);
        $this->assertCount(0, $recent);
    }

    /* =======================================================
       Pagination: getTotalCount
       ======================================================= */

    public function testGetTotalCountReturnsZeroForEmptyTable(): void
    {
        $this->assertEquals(0, $this->model->getTotalCount());
    }

    public function testGetTotalCountReturnsCorrectCount(): void
    {
        $this->model->addEntry('Alice', 90);
        $this->model->addEntry('Bob', 80);
        $this->model->addEntry('Charlie', 70);
        $this->assertEquals(3, $this->model->getTotalCount());
    }

    public function testGetTotalCountUpdatesAfterDelete(): void
    {
        $id = $this->model->addEntry('Alice', 90);
        $this->model->addEntry('Bob', 80);
        $this->assertEquals(2, $this->model->getTotalCount());

        $this->model->deleteEntry($id);
        $this->assertEquals(1, $this->model->getTotalCount());
    }

    /* =======================================================
       Pagination: getPaginatedEntries
       ======================================================= */

    public function testGetPaginatedEntriesReturnsCorrectPage(): void
    {
        // Insert 5 entries with different game scores
        $this->model->addEntry('Best', 100, 10);    // game_score = 100*100*(1+500/10)/10 = 51000
        $this->model->addEntry('Second', 90, 20);   // game_score = 90*90*(1+500/20)/10 = 21060
        $this->model->addEntry('Third', 80, 30);     // game_score = 80*80*(1+500/30)/10 = 17066
        $this->model->addEntry('Fourth', 70, 40);
        $this->model->addEntry('Fifth', 60, 50);

        // Page 1, 3 per page
        $page1 = $this->model->getPaginatedEntries(1, 3);
        $this->assertCount(3, $page1);
        $this->assertEquals('Best', $page1[0]['player_name']);
        $this->assertEquals('Second', $page1[1]['player_name']);
        $this->assertEquals('Third', $page1[2]['player_name']);

        // Page 2, 3 per page
        $page2 = $this->model->getPaginatedEntries(2, 3);
        $this->assertCount(2, $page2);
        $this->assertEquals('Fourth', $page2[0]['player_name']);
        $this->assertEquals('Fifth', $page2[1]['player_name']);
    }

    public function testGetPaginatedEntriesIncludesGameScore(): void
    {
        $this->model->addEntry('Alice', 100, 10);
        $entries = $this->model->getPaginatedEntries(1, 10);
        $this->assertArrayHasKey('game_score', $entries[0]);
        $this->assertGreaterThan(0, $entries[0]['game_score']);
    }

    public function testGetPaginatedEntriesSortsByGameScoreDesc(): void
    {
        // Same accuracy but different speeds => different game scores
        $this->model->addEntry('Slow', 80, 300);
        $this->model->addEntry('Fast', 80, 10);

        $entries = $this->model->getPaginatedEntries(1, 10);
        // Fast player should rank first (higher game_score)
        $this->assertEquals('Fast', $entries[0]['player_name']);
        $this->assertEquals('Slow', $entries[1]['player_name']);
        $this->assertGreaterThan($entries[1]['game_score'], $entries[0]['game_score']);
    }

    public function testGetPaginatedEntriesSortsDifferentAccuracyCorrectly(): void
    {
        // Lower accuracy but very fast vs higher accuracy but slow
        $this->model->addEntry('LowPctFast', 50, 5);  // game_score = 50*50*(1+500/5)/10 = 25250
        $this->model->addEntry('HighPctSlow', 90, 500); // game_score = 90*90*(1+500/500)/10 = 1620

        $entries = $this->model->getPaginatedEntries(1, 10);
        $this->assertEquals('LowPctFast', $entries[0]['player_name']);
    }

    public function testGetPaginatedEntriesEmptyTable(): void
    {
        $entries = $this->model->getPaginatedEntries(1, 50);
        $this->assertCount(0, $entries);
    }

    public function testGetPaginatedEntriesRemovesEncryptedName(): void
    {
        $this->model->addEntry('Alice', 50);
        $entries = $this->model->getPaginatedEntries(1, 10);
        $this->assertArrayNotHasKey('encrypted_name', $entries[0]);
        $this->assertArrayHasKey('player_name', $entries[0]);
    }

    public function testGetPaginatedEntriesPageBeyondDataReturnsEmpty(): void
    {
        $this->model->addEntry('Alice', 50);
        $entries = $this->model->getPaginatedEntries(100, 50);
        $this->assertCount(0, $entries);
    }

    public function testGetPaginatedEntriesHandlesZeroTimeSeconds(): void
    {
        $this->model->addEntry('ZeroTime', 80, 0);
        $entries = $this->model->getPaginatedEntries(1, 10);
        // Should not error (CASE WHEN handles division by zero)
        $this->assertCount(1, $entries);
        $this->assertArrayHasKey('game_score', $entries[0]);
    }

    /* =======================================================
       Pagination: getRankById
       ======================================================= */

    public function testGetRankByIdReturnsCorrectRank(): void
    {
        $id1 = $this->model->addEntry('Best', 100, 10);
        $id2 = $this->model->addEntry('Middle', 80, 30);
        $id3 = $this->model->addEntry('Worst', 50, 100);

        $this->assertEquals(1, $this->model->getRankById($id1));
        $this->assertEquals(2, $this->model->getRankById($id2));
        $this->assertEquals(3, $this->model->getRankById($id3));
    }

    public function testGetRankByIdReturnsZeroForNonExistent(): void
    {
        $this->assertEquals(0, $this->model->getRankById(9999));
    }

    public function testGetRankByIdWithSameScoreDifferentTime(): void
    {
        $idFast = $this->model->addEntry('Fast', 90, 30);
        $idSlow = $this->model->addEntry('Slow', 90, 300);

        // Fast should rank higher (lower time = higher game_score)
        $this->assertLessThan(
            $this->model->getRankById($idSlow),
            $this->model->getRankById($idFast)
        );
    }

    public function testGetRankByIdPageCalculation(): void
    {
        // Insert 60 entries - rank 1 to 60
        $ids = [];
        for ($i = 60; $i >= 1; $i--) {
            $ids[$i] = $this->model->addEntry("Player$i", $i, 100);
        }

        // Best player (score 60) should be rank 1, page 1
        $rank = $this->model->getRankById($ids[60]);
        $this->assertEquals(1, $rank);
        $this->assertEquals(1, (int) ceil($rank / 50));

        // 51st best player (score 10) should be on page 2
        $rank = $this->model->getRankById($ids[10]);
        $this->assertGreaterThan(50, $rank);
        $this->assertEquals(2, (int) ceil($rank / 50));
    }
}
