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

class GameCounterModel
{
    private \PDO $pdo;

    public function __construct(\PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    /**
     * Record a new game played.
     */
    public function increment(): void
    {
        $this->pdo->exec('INSERT INTO game_counter (played_at) VALUES (CURRENT_TIMESTAMP)');
    }

    /**
     * Get total number of games played.
     */
    public function getTotalCount(): int
    {
        return (int) $this->pdo->query('SELECT COUNT(*) FROM game_counter')->fetchColumn();
    }

    /**
     * Reset the counter by deleting all records, then seeding one row per
     * existing leaderboard entry (preserving historical count based on Hall of Fame).
     */
    public function resetFromLeaderboard(): int
    {
        $this->pdo->exec('DELETE FROM game_counter');

        // Re-seed based on leaderboard entries' created_at timestamps
        $this->pdo->exec(
            'INSERT INTO game_counter (played_at) SELECT created_at FROM leaderboard ORDER BY created_at ASC'
        );

        return $this->getTotalCount();
    }

    /**
     * Get games per week for the last year (52 weeks).
     * Returns an array of ['week' => 'YYYY-Www', 'count' => int] sorted chronologically.
     */
    public function getWeeklyStats(int $weeks = 52): array
    {
        $driver = $this->pdo->getAttribute(\PDO::ATTR_DRIVER_NAME);
        $cutoff = date('Y-m-d', strtotime("-{$weeks} weeks"));

        if ($driver === 'sqlite') {
            $stmt = $this->pdo->prepare(
                "SELECT strftime('%Y', played_at) || 'W' || strftime('%W', played_at) AS yw, COUNT(*) AS cnt "
                . "FROM game_counter "
                . "WHERE played_at >= ? "
                . "GROUP BY yw ORDER BY yw ASC"
            );
        } else {
            $stmt = $this->pdo->prepare(
                "SELECT YEARWEEK(played_at, 1) AS yw, COUNT(*) AS cnt "
                . "FROM game_counter "
                . "WHERE played_at >= ? "
                . "GROUP BY yw ORDER BY yw ASC"
            );
        }
        $stmt->execute([$cutoff]);
        $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC);

        return array_map(function ($row) use ($driver) {
            if ($driver === 'sqlite') {
                // Format: "2026W22"
                return [
                    'week' => str_replace('W', '-W', $row['yw']),
                    'count' => (int) $row['cnt'],
                ];
            }
            $year = (int) substr($row['yw'], 0, 4);
            $week = (int) substr($row['yw'], 4);
            return [
                'week' => sprintf('%d-W%02d', $year, $week),
                'count' => (int) $row['cnt'],
            ];
        }, $rows);
    }
}
