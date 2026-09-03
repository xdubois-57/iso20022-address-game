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

namespace App\Models;

use PDO;

/**
 * Server-side rate limiting keyed on the caller's address.
 *
 * The admin PIN, event code and leaderboard limiters all counted attempts in
 * $_SESSION, which meant an attacker only had to drop the cookie between
 * requests to reset the counter — no real obstacle to brute-forcing a four-digit
 * PIN. Counters live in the database instead, so they survive the client
 * throwing its session away.
 *
 * GDPR: the address is never stored. Only a keyed hash of it is, which is enough
 * to recognise a repeat caller and useless for identifying anybody.
 */
class RateLimitModel
{
    private PDO $pdo;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    /**
     * Identifier for the current caller within a named limiter.
     */
    public static function bucketFor(string $scope, ?string $ip = null): string
    {
        $ip ??= ($_SERVER['REMOTE_ADDR'] ?? 'unknown');

        return $scope . ':' . substr(hash('sha256', $scope . '|' . $ip), 0, 32);
    }

    /**
     * Seconds remaining before this bucket may try again; 0 when not locked.
     */
    public function retryAfter(string $bucket): int
    {
        $stmt = $this->pdo->prepare('SELECT locked_until FROM rate_limits WHERE bucket = ?');
        $stmt->execute([$bucket]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$row) {
            return 0;
        }

        $remaining = ((int) $row['locked_until']) - time();
        return $remaining > 0 ? $remaining : 0;
    }

    /**
     * Record a failed attempt and lock the bucket once $max is reached.
     *
     * @return int Seconds the caller must now wait; 0 while below the threshold.
     */
    public function recordFailure(string $bucket, int $max, int $lockSeconds): int
    {
        $now = time();

        $stmt = $this->pdo->prepare('SELECT attempts, locked_until FROM rate_limits WHERE bucket = ?');
        $stmt->execute([$bucket]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        // A lock that has run out resets the count rather than leaving the
        // caller one attempt away from an immediate re-lock forever.
        $attempts = 1;
        if ($row) {
            $lockedUntil = (int) $row['locked_until'];
            $attempts = ($lockedUntil !== 0 && $now >= $lockedUntil)
                ? 1
                : ((int) $row['attempts']) + 1;
        }

        $lockedUntil = $attempts >= $max ? $now + $lockSeconds : 0;

        $this->upsert($bucket, $attempts, $now, $lockedUntil);

        return $lockedUntil > 0 ? $lockSeconds : 0;
    }

    /**
     * Forget a bucket, e.g. after a successful login.
     */
    public function clear(string $bucket): void
    {
        $this->pdo->prepare('DELETE FROM rate_limits WHERE bucket = ?')->execute([$bucket]);
    }

    /**
     * Drop rows that are no longer holding anyone back.
     *
     * Called opportunistically so the table cannot grow without bound, and so
     * that hashed addresses are not retained beyond their purpose.
     */
    public function purgeExpired(int $olderThanSeconds = 86400): int
    {
        $stmt = $this->pdo->prepare(
            'DELETE FROM rate_limits WHERE locked_until < ? AND updated_at < ?'
        );
        $stmt->execute([time(), time() - $olderThanSeconds]);

        return $stmt->rowCount();
    }

    private function upsert(string $bucket, int $attempts, int $updatedAt, int $lockedUntil): void
    {
        $driver = (string) $this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME);

        $sql = $driver === 'sqlite'
            ? 'INSERT INTO rate_limits (bucket, attempts, updated_at, locked_until) VALUES (?, ?, ?, ?) '
                . 'ON CONFLICT(bucket) DO UPDATE SET attempts = excluded.attempts, '
                . 'updated_at = excluded.updated_at, locked_until = excluded.locked_until'
            : 'INSERT INTO rate_limits (bucket, attempts, updated_at, locked_until) VALUES (?, ?, ?, ?) '
                . 'ON DUPLICATE KEY UPDATE attempts = VALUES(attempts), '
                . 'updated_at = VALUES(updated_at), locked_until = VALUES(locked_until)';

        $this->pdo->prepare($sql)->execute([$bucket, $attempts, $updatedAt, $lockedUntil]);
    }
}
