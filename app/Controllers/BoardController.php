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

namespace App\Controllers;

use App\Models\Database;
use App\Models\LeaderboardModel;
use App\Models\SettingsModel;

/**
 * GET /board/data — the wall's data source.
 *
 * Deliberately a GET route with no session and no CSRF token, declared in
 * public/index.php alongside /bg and /share and therefore BEFORE
 * session_start(). That is the whole point of this class, and it is worth
 * saying why rather than leaving it to look like an oversight.
 *
 * Every other API route in this application is a POST carrying a CSRF token
 * tied to the PHP session, whose default lifetime is 24 minutes. A wall that
 * polls the server from six in the evening until two in the morning would
 * watch its session expire and every subsequent call fall to 403 — silently,
 * around midnight, with nobody in front of the screen to notice the numbers
 * had stopped moving. A public GET removes the failure mode at the root
 * instead of papering over it with token refreshes.
 *
 * Nothing here is exposed that the Hall of Fame does not already show to any
 * anonymous visitor: the same names, the same scores, the same ordering.
 */
class BoardController
{
    /**
     * The hard ceiling on `entries`, applied to whatever the client asks for.
     *
     * Server-side because the client is a page on a screen nobody is
     * watching; a mistyped ?limit must not be able to ask this install for
     * every row it has.
     */
    public const MAX_ENTRIES = 50;

    /** How many recent arrivals the banner queue can ever need at once. */
    public const MAX_RECENT = 10;

    /** Upper bound on the configurable window: one year, in hours. */
    public const MAX_WINDOW_HOURS = 8760;

    public function data(): void
    {
        $db = Database::getInstance();
        if (!$db->connect()) {
            // A wall mid-evening should keep its last good screen rather than
            // be handed something it would have to render as an error, so this
            // stays a plain JSON failure the client can ignore.
            $this->json(['error' => 'Database unavailable'], 503);
            return;
        }

        $pdo = $db->getPdo();
        $model = new LeaderboardModel($pdo);

        $windowHours = $this->windowHours(new SettingsModel($pdo));

        $limit = isset($_GET['limit']) ? (int) $_GET['limit'] : self::MAX_ENTRIES;
        $limit = max(1, min(self::MAX_ENTRIES, $limit));

        $entries = $model->getBoardEntries($limit, $windowHours);
        $recent = $model->getRecentEntries(self::MAX_RECENT, $windowHours);

        $this->json([
            'window_hours' => $windowHours,
            'total_count' => $model->getCountSince($windowHours),
            'server_time' => gmdate('c'),
            'entries' => $this->rankTopEntries($entries),
            'recent' => $this->rankRecentEntries($model, $entries, $recent, $windowHours),
        ]);
    }

    /**
     * The configured window in hours, clamped, with 0 meaning "all time".
     *
     * Validated on read as well as on write: the value reaches the database
     * through the Admin screen, but a hand-edited row would otherwise put an
     * arbitrary integer into a SQL interval.
     */
    private function windowHours(SettingsModel $settings): int
    {
        $stored = $settings->get('board_window_hours');
        if ($stored === null || !is_numeric($stored)) {
            return LeaderboardModel::DEFAULT_WINDOW_HOURS;
        }

        return max(0, min(self::MAX_WINDOW_HOURS, (int) $stored));
    }

    /**
     * Rank the top slice, which starts at 1 by construction.
     *
     * The ordering the model applies — game_score DESC, time_seconds ASC,
     * created_at ASC — is total: created_at breaks every remaining tie, so no
     * two rows can share a position and the offset is the rank. Computed here
     * regardless, rather than left to the browser: the client must never be
     * the thing that decides what number appears beside a name.
     *
     * @param  list<array<string, mixed>> $entries  rows from LeaderboardModel
     * @return list<array{id: int, player_name: string, game_score: int, time_seconds: int,
     *               created_at: string, rank: int}>
     */
    private function rankTopEntries(array $entries): array
    {
        $ranked = [];
        foreach ($entries as $i => $entry) {
            $entry['rank'] = $i + 1;
            $ranked[] = $this->publicFields($entry);
        }

        return $ranked;
    }

    /**
     * Rank the recent arrivals, which can sit anywhere in the table.
     *
     * A recent entry that is also in the visible top already has its rank, so
     * it is reused rather than re-queried; only the ones that placed below the
     * fold cost a query. Those are exactly the players the banner exists for,
     * and there are at most ten of them.
     *
     * @param  list<array<string, mixed>> $topEntries  the visible top, in order
     * @param  list<array<string, mixed>> $recent      newest first
     * @return list<array{id: int, player_name: string, game_score: int, time_seconds: int,
     *               created_at: string, rank: int}>
     */
    private function rankRecentEntries(
        LeaderboardModel $model,
        array $topEntries,
        array $recent,
        ?int $windowHours
    ): array {
        $rankById = [];
        foreach ($topEntries as $i => $entry) {
            $rankById[(int) $entry['id']] = $i + 1;
        }

        $ranked = [];
        foreach ($recent as $entry) {
            $id = (int) $entry['id'];
            $entry['rank'] = $rankById[$id] ?? $model->getRankInWindow($id, $windowHours);
            $ranked[] = $this->publicFields($entry);
        }

        return $ranked;
    }

    /**
     * Exactly the columns the Hall of Fame already displays, and no others.
     *
     * An allowlist rather than a blocklist: a column added to the leaderboard
     * table later must not appear on an unauthenticated route because nobody
     * remembered this one existed.
     *
     * @param  array<string, mixed> $entry
     * @return array{id: int, player_name: string, game_score: int, time_seconds: int,
     *         created_at: string, rank: int}
     */
    private function publicFields(array $entry): array
    {
        return [
            'id' => (int) $entry['id'],
            'player_name' => (string) $entry['player_name'],
            'game_score' => (int) $entry['game_score'],
            'time_seconds' => (int) $entry['time_seconds'],
            'created_at' => (string) $entry['created_at'],
            'rank' => (int) $entry['rank'],
        ];
    }

    /**
     * @param array<string, mixed> $data
     */
    private function json(array $data, int $code = 200): void
    {
        http_response_code($code);
        header('Content-Type: application/json; charset=utf-8');
        // The wall polls every five seconds and a stale body would defeat the
        // whole exercise — shared hosting and corporate proxies both cache
        // GETs happily unless told not to.
        header('Cache-Control: no-store');
        echo json_encode($data);
    }
}
