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
<section class="game-screen" id="gameScreen">
    <div class="game-layout">
        <!-- Left panel: Unstructured Source -->
        <div class="source-panel">
            <h2>Unstructured Address</h2>
            <p class="hint-text">Drag chips to the correct ISO 20022 slots</p>
            <div class="chip-container" id="chipContainer">
                <!-- Chips rendered dynamically by JS -->
            </div>
        </div>

        <!-- Right panel: ISO 20022 Target Slots -->
        <div class="target-panel">
            <h2>ISO 20022 Structured Address</h2>
            <div class="goal-badge" id="goalBadge">Structured</div>
            <div class="slot-container" id="slotContainer">
                <!-- Slots rendered dynamically by JS -->
            </div>
            <button class="btn-primary btn-validate" id="validateBtn" disabled>
                Validate Answer
            </button>
        </div>
    </div>

    <!-- Result overlay -->
    <div id="resultOverlay" class="overlay hidden">
        <div class="overlay-content result-card">
            <div id="resultIcon"></div>
            <h2 id="resultTitle"></h2>
            <p id="resultScore"></p>
            <div id="resultErrors" class="error-list"></div>
            <div class="result-actions">
                <input type="text" id="playerNameInput" placeholder="Your name (for Hall of Fame)"
                       maxlength="50" class="name-input">
                <button class="btn-primary" id="submitScoreBtn">Submit Score</button>
                <button class="btn-secondary" id="playAgainBtn">Play Again</button>
            </div>
        </div>
    </div>
</section>
