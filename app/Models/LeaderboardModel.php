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
    private Encryption $encryption;

    public function __construct(PDO $pdo, ?Encryption $encryption = null)
    {
        $this->pdo = $pdo;
        $this->encryption = $encryption ?? new Encryption();
    }

    /**
     * Add a new leaderboard entry.
     */
    public function addEntry(string $playerName, int $score, int $timeSeconds = 0): int
    {
        $encryptedName = $this->encryption->encrypt($playerName);
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
     * Attach a display name to each row and drop the ciphertext.
     *
     * Names that cannot be decrypted — typically after a key change — are shown
     * as [redacted] rather than failing the whole listing.
     */
    private function hydrate(array $rows): array
    {
        return array_map(function ($row) {
            try {
                // Rows predating the GCM migration are in the legacy CTR format,
                // so this is the one call site allowed to accept it.
                $decrypted = $this->encryption->decrypt($row['encrypted_name'], true);
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
     */
    public function purgeExpired(int $days = 365): int
    {
        $stmt = $this->pdo->prepare(
            'DELETE FROM leaderboard WHERE created_at < DATE_SUB(NOW(), INTERVAL ? DAY)'
        );
        $stmt->execute([$days]);
        return $stmt->rowCount();
    }
}
