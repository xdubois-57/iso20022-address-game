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

/**
 * GDPR Retention Cleanup Script
 *
 * Deletes leaderboard entries past their retention period, and rate-limit
 * rows that no longer hold anyone back. What "past retention" means lives in
 * App\Models\RetentionCleanup, which the poor man's cron fallback in
 * public/index.php runs too — so a host with a real cron job and one without
 * delete exactly the same things.
 *
 * Schedule via cron: 0 3 * * * php /path/to/scripts/cleanup.php
 */

require_once __DIR__ . '/../vendor/autoload.php';

use App\Models\Database;
use App\Models\RetentionCleanup;

$db = Database::getInstance();
if (!$db->connect()) {
    echo "[CLEANUP] ERROR: Cannot connect to database.\n";
    exit(1);
}

$deleted = (new RetentionCleanup($db->getPdo()))->run();

$timestamp = date('Y-m-d H:i:s');
echo "[CLEANUP] $timestamp — Deleted {$deleted['leaderboard']} expired leaderboard entries"
    . " and {$deleted['rate_limits']} spent rate-limit rows.\n";
