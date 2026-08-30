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
