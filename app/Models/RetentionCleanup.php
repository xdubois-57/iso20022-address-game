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

/**
 * Everything this application deletes on a schedule, in one place.
 *
 * There are two callers — scripts/cleanup.php (the documented cron job) and
 * the "poor man's cron" in public/index.php that runs it off visitor traffic
 * when no cron is configured — and they used to carry separate, and unequal,
 * ideas of what a cleanup is. Both purged the leaderboard; neither purged
 * `rate_limits`, even though RateLimitModel::purgeExpired() was written for
 * exactly that and documented as being "called opportunistically". It never
 * was: the table grew without bound, and the hashed caller addresses in it
 * were kept indefinitely rather than for as long as they hold someone back —
 * which is not what scripts/schema.sql and DESIGN.md § 5 promise.
 *
 * Retention periods live here as constants so the cron job, the fallback and
 * the documentation cannot drift apart again.
 */
final class RetentionCleanup
{
    /** GDPR retention for Hall of Fame entries (README, DESIGN § 5). */
    public const LEADERBOARD_RETENTION_DAYS = 365;

    /**
     * How long a spent rate-limit row is kept. A row is only deleted once it
     * locks nobody out AND has not been touched for this long, so an
     * in-progress lockout is never cleared early by a cleanup run.
     */
    public const RATE_LIMIT_RETENTION_SECONDS = 86400;

    /** How often the poor man's cron in public/index.php repeats this. */
    public const INTERVAL_SECONDS = 86400;

    public function __construct(private PDO $pdo)
    {
    }

    /**
     * Decide whether the poor man's cron is due, and claim the slot if it is.
     *
     * Lifted out of public/index.php so it can be tested at all. The gate is
     * the whole reliability of the fallback: get it wrong in one direction and
     * the retention promised by the privacy notice silently stops happening;
     * get it wrong in the other and every visitor pays for a full sweep.
     * Neither failure is visible from the outside, which is why this now has
     * tests rather than only a comment.
     *
     * CLAIMS RATHER THAN ASKS. The stamp is written BEFORE the caller runs the
     * cleanup, not after, so two requests arriving together do not both start
     * a sweep. It is a cheap lock, not a correct one — two processes can still
     * interleave between the read and the write — but the cost of losing that
     * race is a duplicated DELETE, which is idempotent here.
     *
     * A stamp that cannot be read (absent, unreadable, or garbage) reads as
     * "never ran" and the run goes ahead: on the two occasions this matters —
     * a fresh install and a corrupted file — deleting expired data one time
     * too many beats never deleting it.
     *
     * @param string   $stampPath where the last-run timestamp lives
     * @param int|null $now       current unix time; injected by the tests
     */
    public static function claimDueSlot(string $stampPath, ?int $now = null): bool
    {
        $now ??= time();

        $directory = dirname($stampPath);
        if (!is_dir($directory)) {
            @mkdir($directory, 0755, true);
        }

        $stamp = @file_get_contents($stampPath);
        if ($stamp !== false && ($now - (int)$stamp) <= self::INTERVAL_SECONDS) {
            return false;
        }

        @file_put_contents($stampPath, (string)$now);

        return true;
    }

    /**
     * Delete everything that is past its retention period.
     *
     * @return array{leaderboard: int, rate_limits: int} rows deleted per table
     */
    public function run(): array
    {
        return [
            'leaderboard' => (new LeaderboardModel($this->pdo))
                ->purgeExpired(self::LEADERBOARD_RETENTION_DAYS),
            'rate_limits' => (new RateLimitModel($this->pdo))
                ->purgeExpired(self::RATE_LIMIT_RETENTION_SECONDS),
        ];
    }
}
