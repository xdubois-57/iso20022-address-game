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

namespace App\Models;

use PDO;

class LeaderboardModel
{
    private PDO $pdo;
    private ?Encryption $encryption;

    public function __construct(PDO $pdo, ?Encryption $encryption = null)
    {
        $this->pdo = $pdo;
        $this->encryption = $encryption;
    }

    /**
     * The cipher, built on first use rather than in the constructor.
     *
     * Encryption's constructor throws when no usable key is configured, which
     * is right for reading or writing a player name — but purgeExpired(),
     * purgeAll(), deleteEntry() and getTotalCount() touch no names at all.
     * Constructing it eagerly made every one of them fail on an install whose
     * key was missing, including the retention cleanup that runs off ordinary
     * visitor traffic: a lost key turned into an unreachable site rather than
     * an unreadable leaderboard.
     */
    private function encryption(): Encryption
    {
        return $this->encryption ??= new Encryption();
    }

    /**
     * Add a new leaderboard entry.
     */
    public function addEntry(string $playerName, int $score, int $timeSeconds = 0): int
    {
        $encryptedName = $this->encryption()->encrypt($playerName);
        $stmt = $this->pdo->prepare(
            'INSERT INTO leaderboard (encrypted_name, score, time_seconds) VALUES (?, ?, ?)'
        );
        $stmt->execute([$encryptedName, $score, $timeSeconds]);
        return (int) $this->pdo->lastInsertId();
    }

    /**
     * SQL expression for computing game score (cross-platform MySQL/SQLite).
     * Mirrors JS: Math.round(pct * pct * (1 + 500 / Math.max(1, seconds)) / 10)
     */
    private const GAME_SCORE_EXPR = 'ROUND(score * score * (1.0 + 500.0 / '
        . '(CASE WHEN time_seconds < 1 THEN 1 ELSE time_seconds END)) / 10.0)';

    /**
     * Get top N entries by game score, decrypting names for display.
     *
     * Ordering used to be by raw accuracy, while the admin screen re-sorted the
     * rows it received by game score in JavaScript. Past the fetch limit those
     * two orderings disagree, so a fast-but-imperfect run could outrank
     * everything displayed and still never appear. The database now applies the
     * same ordering the Hall of Fame uses.
     *
     * @return list<array{id: int, score: int, time_seconds: int, created_at: string, game_score: float, player_name: string}>
     */
    public function getTopEntries(int $limit = 10): array
    {
        $expr = self::GAME_SCORE_EXPR;
        $stmt = $this->pdo->prepare(
            "SELECT id, encrypted_name, score, time_seconds, created_at, $expr AS game_score "
            . 'FROM leaderboard ORDER BY game_score DESC, time_seconds ASC, created_at ASC LIMIT :limit'
        );
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->execute();

        return $this->hydrate($stmt->fetchAll());
    }

    /**
     * Get paginated entries sorted by game_score, decrypting names for display.
     *
     * @return list<array{id: int, score: int, time_seconds: int, created_at: string, game_score: float, player_name: string}>
     */
    public function getPaginatedEntries(int $page = 1, int $perPage = 50): array
    {
        $offset = ($page - 1) * $perPage;
        $expr = self::GAME_SCORE_EXPR;
        $stmt = $this->pdo->prepare(
            "SELECT id, encrypted_name, score, time_seconds, created_at, $expr AS game_score "
            . 'FROM leaderboard ORDER BY game_score DESC, time_seconds ASC, created_at ASC '
            . 'LIMIT :limit OFFSET :offset'
        );
        // Bound as integers explicitly: with ATTR_EMULATE_PREPARES off, values
        // passed through execute() are sent as strings, which MySQL rejects in
        // LIMIT/OFFSET. The SQLite-backed tests would never catch it.
        $stmt->bindValue(':limit', $perPage, PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
        $stmt->execute();

        return $this->hydrate($stmt->fetchAll());
    }

    /**
     * The default time window the wall shows, in hours. 0 means "all time".
     */
    public const DEFAULT_WINDOW_HOURS = 24;

    /**
     * A `created_at >= now - N hours` fragment, or '' when there is no window.
     *
     * Spelled out per driver for the same reason purgeExpired() is: MySQL's
     * DATE_SUB()/INTERVAL parses on neither SQLite nor the other way round.
     * The cutoff is computed by the database rather than by PHP because
     * created_at is written by the database — MySQL stamps rows in the
     * session timezone, SQLite always in UTC — so asking each engine for its
     * own "now" keeps both sides of the comparison in one frame of reference.
     *
     * A null or zero window means no filtering at all, not a window of zero
     * hours: the Admin field documents 0 as "since forever", and a literal
     * reading of it would blank the wall.
     */
    private function windowClause(?int $windowHours, string $keyword = 'WHERE'): string
    {
        if ($windowHours === null || $windowHours <= 0) {
            return '';
        }

        $driver = (string) $this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME);

        return $driver === 'sqlite'
            ? " $keyword created_at >= datetime('now', :window_spec) "
            : " $keyword created_at >= DATE_SUB(NOW(), INTERVAL :window_hours HOUR) ";
    }

    /** Bind whichever window parameter windowClause() just used. */
    private function bindWindow(\PDOStatement $stmt, ?int $windowHours): void
    {
        if ($windowHours === null || $windowHours <= 0) {
            return;
        }

        $driver = (string) $this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
        if ($driver === 'sqlite') {
            $stmt->bindValue(':window_spec', '-' . $windowHours . ' hours');
        } else {
            $stmt->bindValue(':window_hours', $windowHours, PDO::PARAM_INT);
        }
    }

    /**
     * The top of the leaderboard inside a time window, for the wall.
     *
     * A separate method rather than an extra argument on getTopEntries() or
     * getPaginatedEntries(): those two serve the classic Hall of Fame, which
     * stays all-time on every device, and widening their signatures would put
     * a window parameter within reach of screens that must never have one.
     *
     * The ordering is deliberately identical to theirs — game_score DESC,
     * time_seconds ASC, created_at ASC. A different tie-break would let the
     * wall and the Hall of Fame disagree about who is ahead, in front of the
     * two people concerned.
     *
     * @return list<array{id: int, score: int, time_seconds: int, created_at: string, game_score: float, player_name: string}>
     */
    public function getBoardEntries(int $limit, ?int $windowHours): array
    {
        $expr = self::GAME_SCORE_EXPR;
        $stmt = $this->pdo->prepare(
            "SELECT id, encrypted_name, score, time_seconds, created_at, $expr AS game_score "
            . 'FROM leaderboard'
            . $this->windowClause($windowHours)
            . ' ORDER BY game_score DESC, time_seconds ASC, created_at ASC LIMIT :limit'
        );
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $this->bindWindow($stmt, $windowHours);
        $stmt->execute();

        return $this->hydrate($stmt->fetchAll());
    }

    /**
     * The most recently finished runs inside the window, newest first.
     *
     * Feeds the wall's "just arrived" banner, which has to name players who
     * placed too low to appear in the visible top — the ones for whom the
     * wall is the only acknowledgement they will get.
     *
     * @return list<array{id: int, score: int, time_seconds: int, created_at: string, game_score: float, player_name: string}>
     */
    public function getRecentEntries(int $limit, ?int $windowHours): array
    {
        $expr = self::GAME_SCORE_EXPR;
        $stmt = $this->pdo->prepare(
            "SELECT id, encrypted_name, score, time_seconds, created_at, $expr AS game_score "
            . 'FROM leaderboard'
            . $this->windowClause($windowHours)
            . ' ORDER BY created_at DESC, id DESC LIMIT :limit'
        );
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $this->bindWindow($stmt, $windowHours);
        $stmt->execute();

        return $this->hydrate($stmt->fetchAll());
    }

    /** How many entries fall inside the window. */
    public function getCountSince(?int $windowHours): int
    {
        $stmt = $this->pdo->prepare(
            'SELECT COUNT(*) FROM leaderboard' . $this->windowClause($windowHours)
        );
        $this->bindWindow($stmt, $windowHours);
        $stmt->execute();

        return (int) $stmt->fetchColumn();
    }

    /**
     * The 1-based rank of an entry WITHIN a time window.
     *
     * Computed here rather than inferred by the browser from a row's position
     * in the array. Two players who tie on score and time share a rank, and a
     * client counting rows would hand the second of them a number one too
     * high — on a wall, in front of both.
     *
     * Returns 0 when the entry does not exist, or falls outside the window.
     */
    public function getRankInWindow(int $id, ?int $windowHours): int
    {
        $window = $this->windowClause($windowHours, 'AND');

        $check = $this->pdo->prepare(
            'SELECT id FROM leaderboard WHERE id = :id' . $window
        );
        $check->bindValue(':id', $id, PDO::PARAM_INT);
        $this->bindWindow($check, $windowHours);
        $check->execute();
        if (!$check->fetch()) {
            return 0;
        }

        $lbExpr = 'ROUND(lb.score * lb.score * (1.0 + 500.0 / '
            . '(CASE WHEN lb.time_seconds < 1 THEN 1 ELSE lb.time_seconds END)) / 10.0)';
        $tExpr = 'ROUND(t.score * t.score * (1.0 + 500.0 / '
            . '(CASE WHEN t.time_seconds < 1 THEN 1 ELSE t.time_seconds END)) / 10.0)';

        // The window applies to the rows being counted as well as to the
        // subject: a rank "within the last 24 hours" that counted yesterday's
        // entries above it would not be a rank in that window at all.
        $lbWindow = $window === ''
            ? ''
            : str_replace('created_at', 'lb.created_at', $window);

        $sql = 'SELECT COUNT(*) FROM leaderboard lb, '
            . '(SELECT score, time_seconds, created_at FROM leaderboard WHERE id = :id) t '
            . "WHERE (($lbExpr > $tExpr) "
            . "OR ($lbExpr = $tExpr AND lb.time_seconds < t.time_seconds) "
            . "OR ($lbExpr = $tExpr AND lb.time_seconds = t.time_seconds AND lb.created_at < t.created_at))"
            . $lbWindow;

        $stmt = $this->pdo->prepare($sql);
        $stmt->bindValue(':id', $id, PDO::PARAM_INT);
        $this->bindWindow($stmt, $windowHours);
        $stmt->execute();

        return (int) $stmt->fetchColumn() + 1;
    }

    /**
     * Attach a display name to each row and drop the ciphertext.
     *
     * Names that cannot be decrypted — typically after a key change — are shown
     * as [redacted] rather than failing the whole listing.
     *
     * @param  list<array<string, mixed>> $rows  raw rows, each carrying encrypted_name
     * @return list<array{id: int, score: int, time_seconds: int, created_at: string, game_score: float, player_name: string}>
     */
    private function hydrate(array $rows): array
    {
        return array_map(function ($row) {
            try {
                // Rows predating the GCM migration are in the legacy CTR format,
                // so this is the one call site allowed to accept it.
                $decrypted = $this->encryption()->decrypt($row['encrypted_name'], true);
                $row['player_name'] = $decrypted !== false ? $decrypted : '[redacted]';
            } catch (\Throwable $e) {
                $row['player_name'] = '[redacted]';
            }
            unset($row['encrypted_name']);
            return $row;
        }, $rows);
    }

    /**
     * Get total number of leaderboard entries.
     */
    public function getTotalCount(): int
    {
        return (int) $this->pdo->query('SELECT COUNT(*) FROM leaderboard')->fetchColumn();
    }

    /**
     * Get the 1-based rank of a specific entry by ID using game_score ordering.
     * Returns 0 if not found.
     */
    public function getRankById(int $id): int
    {
        // Check the entry exists
        $check = $this->pdo->prepare('SELECT id FROM leaderboard WHERE id = ?');
        $check->execute([$id]);
        if (!$check->fetch()) {
            return 0;
        }

        // Build game_score expressions with table aliases
        $lbExpr = 'ROUND(lb.score * lb.score * (1.0 + 500.0 / '
            . '(CASE WHEN lb.time_seconds < 1 THEN 1 ELSE lb.time_seconds END)) / 10.0)';
        $tExpr = 'ROUND(t.score * t.score * (1.0 + 500.0 / '
            . '(CASE WHEN t.time_seconds < 1 THEN 1 ELSE t.time_seconds END)) / 10.0)';

        $sql = 'SELECT COUNT(*) FROM leaderboard lb, '
            . '(SELECT score, time_seconds, created_at FROM leaderboard WHERE id = ?) t '
            . "WHERE ($lbExpr > $tExpr) "
            . "OR ($lbExpr = $tExpr AND lb.time_seconds < t.time_seconds) "
            . "OR ($lbExpr = $tExpr AND lb.time_seconds = t.time_seconds AND lb.created_at < t.created_at)";

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([$id]);

        return (int) $stmt->fetchColumn() + 1;
    }

    /**
     * Delete a single leaderboard entry by ID.
     */
    public function deleteEntry(int $id): bool
    {
        $stmt = $this->pdo->prepare('DELETE FROM leaderboard WHERE id = ?');
        $stmt->execute([$id]);
        return $stmt->rowCount() > 0;
    }

    /**
     * Purge all leaderboard data (admin action).
     */
    public function purgeAll(): void
    {
        $this->pdo->exec('DELETE FROM leaderboard');
    }

    /**
     * Delete entries older than 365 days (GDPR retention policy).
     *
     * The cutoff is computed by the database rather than by PHP, because
     * created_at is written by the database (CURRENT_TIMESTAMP) and the two
     * clocks need not agree — MySQL stamps rows in the session timezone,
     * SQLite always in UTC. Asking each engine for its own "now" keeps the
     * comparison in one frame of reference.
     *
     * The dialect is spelled out per driver for the same reason the upserts
     * in SettingsModel and RateLimitModel are: DATE_SUB()/INTERVAL is MySQL
     * only, and SQLite parses none of it. This ran unguarded until now, so
     * the poor man's cron in public/index.php fataled on the FIRST page load
     * of any SQLite-backed instance — `composer serve` on a fresh clone, and
     * the Playwright harness — serving an empty HTTP 500 instead of the app.
     */
    public function purgeExpired(int $days = 365): int
    {
        $driver = (string) $this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME);

        if ($driver === 'sqlite') {
            $stmt = $this->pdo->prepare(
                "DELETE FROM leaderboard WHERE created_at < datetime('now', ?)"
            );
            $stmt->execute(['-' . $days . ' days']);
        } else {
            $stmt = $this->pdo->prepare(
                'DELETE FROM leaderboard WHERE created_at < DATE_SUB(NOW(), INTERVAL ? DAY)'
            );
            $stmt->execute([$days]);
        }

        return $stmt->rowCount();
    }
}
