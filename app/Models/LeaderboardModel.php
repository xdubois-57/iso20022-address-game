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
     * Get top N entries, decrypting names for display.
     */
    public function getTopEntries(int $limit = 10): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT id, encrypted_name, score, time_seconds, created_at FROM leaderboard ORDER BY score DESC, time_seconds ASC, created_at ASC LIMIT ?'
        );
        $stmt->execute([$limit]);
        $rows = $stmt->fetchAll();

        return array_map(function ($row) {
            try {
                $decrypted = $this->encryption->decrypt($row['encrypted_name']);
                $row['player_name'] = $decrypted !== false ? $decrypted : '[redacted]';
            } catch (\Throwable $e) {
                // Decryption failed (likely due to key change) - show anonymized
                $row['player_name'] = '[redacted]';
            }
            unset($row['encrypted_name']);
            return $row;
        }, $rows);
    }

    /**
     * Purge all leaderboard data (admin action).
     */
    public function purgeAll(): void
    {
        $this->pdo->exec('DELETE FROM leaderboard');
    }

    /**
     * Delete entries older than 30 days (GDPR retention policy).
     */
    public function purgeExpired(int $days = 30): int
    {
        $stmt = $this->pdo->prepare(
            'DELETE FROM leaderboard WHERE created_at < DATE_SUB(NOW(), INTERVAL ? DAY)'
        );
        $stmt->execute([$days]);
        return $stmt->rowCount();
    }
}
