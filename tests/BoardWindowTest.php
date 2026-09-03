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

use PHPUnit\Framework\TestCase;
use App\Models\LeaderboardModel;
use App\Models\Encryption;

/**
 * The wall's time window, at its edges rather than in the middle.
 *
 * A window test that only inserts one row from last week and one from five
 * minutes ago proves nothing interesting: any implementation that compiles
 * passes it. What can actually go wrong is the boundary (is an entry exactly
 * at the cutoff in or out?), the zero case (0 means "since forever", not "a
 * window of zero hours" — reading it literally would blank the wall), and the
 * ordering agreeing with the Hall of Fame's.
 */
class BoardWindowTest extends TestCase
{
    private \PDO $pdo;
    private LeaderboardModel $model;

    protected function setUp(): void
    {
        $this->pdo = new \PDO('sqlite::memory:');
        $this->pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        $this->pdo->setAttribute(\PDO::ATTR_DEFAULT_FETCH_MODE, \PDO::FETCH_ASSOC);

        $this->pdo->exec("
            CREATE TABLE leaderboard (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                encrypted_name TEXT NOT NULL,
                score INTEGER NOT NULL DEFAULT 0,
                time_seconds INTEGER NOT NULL DEFAULT 0,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
            )
        ");

        $this->model = new LeaderboardModel(
            $this->pdo,
            new Encryption('test_key_for_board_window_tests!')
        );
    }

    /**
     * Add an entry stamped a given number of hours in the past.
     *
     * created_at is normally written by the database, so it is overwritten
     * here with SQLite's own datetime('now', ...) rather than a PHP-formatted
     * string: the comparison the model makes is between two database-side
     * clocks, and a PHP-side timestamp would test a different thing.
     */
    private function addAt(string $name, int $score, int $timeSeconds, float $hoursAgo): int
    {
        $id = $this->model->addEntry($name, $score, $timeSeconds);

        $stmt = $this->pdo->prepare(
            "UPDATE leaderboard SET created_at = datetime('now', :offset) WHERE id = :id"
        );
        $stmt->execute([
            ':offset' => '-' . (int) round($hoursAgo * 60) . ' minutes',
            ':id' => $id,
        ]);

        return $id;
    }

    private function names(array $entries): array
    {
        return array_column($entries, 'player_name');
    }

    public function testWindowExcludesEntriesOlderThanTheCutoff(): void
    {
        $this->addAt('Inside', 90, 60, 23.0);
        $this->addAt('Outside', 95, 60, 25.0);

        $this->assertSame(['Inside'], $this->names($this->model->getBoardEntries(50, 24)));
        $this->assertSame(1, $this->model->getCountSince(24));
    }

    /**
     * The two rows either side of the boundary, minutes apart.
     *
     * An off-by-one in the interval arithmetic — hours where minutes were
     * meant, or a cutoff computed in PHP's timezone rather than the
     * database's — shows up here and nowhere else.
     */
    public function testTheBoundaryItselfIsHandled(): void
    {
        $this->addAt('JustInside', 90, 60, 23.9);
        $this->addAt('JustOutside', 90, 61, 24.1);

        $names = $this->names($this->model->getBoardEntries(50, 24));

        $this->assertContains('JustInside', $names);
        $this->assertNotContains('JustOutside', $names);
    }

    public function testAOneHourWindowIsNotAOneDayWindow(): void
    {
        $this->addAt('Recent', 90, 60, 0.5);
        $this->addAt('TwoHoursAgo', 95, 60, 2.0);

        $this->assertSame(['Recent'], $this->names($this->model->getBoardEntries(50, 1)));
        $this->assertSame(1, $this->model->getCountSince(1));
    }

    /**
     * 0 means "since forever". The Admin field documents it that way, and a
     * literal reading — a window of zero hours — would leave the wall empty
     * for the whole evening.
     */
    public function testZeroWindowReturnsEverything(): void
    {
        $this->addAt('Ancient', 90, 60, 24 * 400);
        $this->addAt('Fresh', 80, 60, 0.1);

        $this->assertCount(2, $this->model->getBoardEntries(50, 0));
        $this->assertSame(2, $this->model->getCountSince(0));
    }

    public function testNullWindowReturnsEverything(): void
    {
        $this->addAt('Ancient', 90, 60, 24 * 400);
        $this->addAt('Fresh', 80, 60, 0.1);

        $this->assertCount(2, $this->model->getBoardEntries(50, null));
        $this->assertSame(2, $this->model->getCountSince(null));
    }

    public function testNegativeWindowIsTreatedAsNoWindow(): void
    {
        $this->addAt('Ancient', 90, 60, 24 * 400);

        $this->assertCount(1, $this->model->getBoardEntries(50, -5));
    }

    /**
     * The wall and the Hall of Fame must not contradict each other about who
     * is ahead — the two screens stand side by side.
     */
    public function testBoardOrderingMatchesTheHallOfFame(): void
    {
        // The speed bonus dominates: a fast 40% outranks a slow 100%. That is
        // the existing scoring rule, and the point here is precisely that the
        // wall reproduces it rather than inventing a friendlier one.
        $this->addAt('Slow', 100, 300, 1.0);
        $this->addAt('Fast', 100, 30, 1.0);
        $this->addAt('LowButQuick', 40, 10, 1.0);

        $board = $this->names($this->model->getBoardEntries(50, 24));
        $hallOfFame = $this->names($this->model->getTopEntries(50));

        $this->assertSame(['Fast', 'LowButQuick', 'Slow'], $board);
        $this->assertSame($hallOfFame, $board);
    }

    public function testBoardEntriesRespectTheLimit(): void
    {
        for ($i = 0; $i < 12; $i++) {
            $this->addAt("Player$i", 50 + $i, 60, 1.0);
        }

        $this->assertCount(5, $this->model->getBoardEntries(5, 24));
    }

    public function testRecentEntriesAreNewestFirstAndCapped(): void
    {
        $this->addAt('Oldest', 90, 60, 5.0);
        $this->addAt('Middle', 91, 60, 3.0);
        $this->addAt('Newest', 10, 60, 1.0);

        $recent = $this->names($this->model->getRecentEntries(10, 24));
        $this->assertSame(['Newest', 'Middle', 'Oldest'], $recent);

        $this->assertCount(2, $this->model->getRecentEntries(2, 24));
    }

    public function testRecentEntriesHonourTheWindow(): void
    {
        $this->addAt('Yesterday', 90, 60, 30.0);
        $this->addAt('Tonight', 50, 60, 1.0);

        $this->assertSame(['Tonight'], $this->names($this->model->getRecentEntries(10, 24)));
    }

    /**
     * A rank that counted rows outside the window would not be a rank in that
     * window: the wall would tell a player they came 47th out of an evening
     * that only had 12 players in it.
     */
    public function testRankInWindowIgnoresEntriesOutsideIt(): void
    {
        $this->addAt('LastWeekChampion', 100, 10, 24 * 7);
        $this->addAt('LastWeekRunnerUp', 99, 10, 24 * 7);
        $tonight = $this->addAt('Tonight', 60, 60, 1.0);

        $this->assertSame(1, $this->model->getRankInWindow($tonight, 24));
        // All-time, the same run sits behind both of last week's entries.
        $this->assertSame(3, $this->model->getRankInWindow($tonight, 0));
    }

    public function testRankInWindowPlacesEntriesInOrder(): void
    {
        $first = $this->addAt('First', 100, 10, 1.0);
        $second = $this->addAt('Second', 90, 10, 1.0);
        $third = $this->addAt('Third', 80, 10, 1.0);

        $this->assertSame(1, $this->model->getRankInWindow($first, 24));
        $this->assertSame(2, $this->model->getRankInWindow($second, 24));
        $this->assertSame(3, $this->model->getRankInWindow($third, 24));
    }

    /**
     * Two runs that agree on score AND on time are the case a client counting
     * array positions would get wrong. created_at breaks the tie, so the ranks
     * are 1 and 2 — and, crucially, never both 1 or both 2.
     */
    public function testTiedRunsStillGetDistinctRanks(): void
    {
        $earlier = $this->addAt('Earlier', 88, 45, 3.0);
        $later = $this->addAt('Later', 88, 45, 1.0);

        $this->assertSame(1, $this->model->getRankInWindow($earlier, 24));
        $this->assertSame(2, $this->model->getRankInWindow($later, 24));
    }

    public function testRankInWindowReturnsZeroForAnEntryOutsideTheWindow(): void
    {
        $old = $this->addAt('LastWeek', 90, 60, 24 * 7);

        $this->assertSame(0, $this->model->getRankInWindow($old, 24));
        $this->assertSame(0, $this->model->getRankInWindow(999999, 24));
    }

    /**
     * The classic Hall of Fame stays all-time on every device.
     *
     * getPaginatedEntries() and getTopEntries() keep the signatures they had:
     * widening them would put a window parameter within reach of screens that
     * must never have one, and would invalidate their existing tests.
     */
    public function testTheClassicHallOfFameIsNotWindowed(): void
    {
        $this->addAt('Ancient', 90, 60, 24 * 400);
        $this->addAt('Fresh', 80, 60, 0.1);

        $this->assertCount(2, $this->model->getTopEntries(50));
        $this->assertCount(2, $this->model->getPaginatedEntries(1, 50));
        $this->assertSame(2, $this->model->getTotalCount());
    }

    public function testTheAllTimeAccessorsKeepTheirSignatures(): void
    {
        $paginated = new \ReflectionMethod(LeaderboardModel::class, 'getPaginatedEntries');
        $this->assertSame(2, $paginated->getNumberOfParameters());
        $this->assertSame(0, $paginated->getNumberOfRequiredParameters());
        $this->assertSame(['page', 'perPage'], array_map(
            fn (\ReflectionParameter $p) => $p->getName(),
            $paginated->getParameters()
        ));

        $top = new \ReflectionMethod(LeaderboardModel::class, 'getTopEntries');
        $this->assertSame(1, $top->getNumberOfParameters());
        $this->assertSame(['limit'], array_map(
            fn (\ReflectionParameter $p) => $p->getName(),
            $top->getParameters()
        ));
    }
}
