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
?>
<section class="leaderboard-screen" id="leaderboardScreen">
    <h2>Hall of Fame</h2>
    <div class="leaderboard-table-wrap">
        <table class="leaderboard-table" id="leaderboardTable">
            <thead>
                <tr>
                    <th>Rank</th>
                    <th>Player</th>
                    <th>Score</th>
                    <th>Date</th>
                </tr>
            </thead>
            <tbody id="leaderboardBody">
                <!-- Populated by JS -->
            </tbody>
        </table>
        <p class="empty-state hidden" id="leaderboardEmpty">
            No entries yet. Be the first to play!
        </p>
    </div>
</section>
