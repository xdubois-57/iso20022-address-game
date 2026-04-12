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
<section class="admin-screen" id="adminScreen">
    <!-- PIN Entry -->
    <div id="pinEntry" class="pin-panel">
        <h2>Admin Access</h2>
        <div class="pin-display" id="pinDisplay">
            <span class="pin-dot"></span>
            <span class="pin-dot"></span>
            <span class="pin-dot"></span>
            <span class="pin-dot"></span>
        </div>
        <div class="pin-pad">
            <button class="pin-key" data-digit="1">1</button>
            <button class="pin-key" data-digit="2">2</button>
            <button class="pin-key" data-digit="3">3</button>
            <button class="pin-key" data-digit="4">4</button>
            <button class="pin-key" data-digit="5">5</button>
            <button class="pin-key" data-digit="6">6</button>
            <button class="pin-key" data-digit="7">7</button>
            <button class="pin-key" data-digit="8">8</button>
            <button class="pin-key" data-digit="9">9</button>
            <button class="pin-key pin-key-clear" data-action="clear">C</button>
            <button class="pin-key" data-digit="0">0</button>
            <button class="pin-key pin-key-submit" data-action="submit">&#10003;</button>
        </div>
        <p class="pin-error hidden" id="pinError">Invalid PIN</p>
    </div>

    <!-- Admin Dashboard (shown after successful login) -->
    <div id="adminDashboard" class="admin-dashboard hidden">
        <h2>Admin Dashboard</h2>

        <div class="admin-section">
            <h3>Upload Scenarios</h3>
            <p>Upload an Excel file (.xlsx) with scenario data.</p>
            <form class="dropzone" id="excelDropzone" action="#">
                <div class="dz-message">
                    <span>Drop .xlsx file here or tap to browse</span>
                </div>
            </form>
            <div id="uploadStatus" class="upload-status hidden"></div>
        </div>

        <div class="admin-section">
            <h3>Change PIN</h3>
            <div class="pin-change-form">
                <input type="password" id="newPinInput" placeholder="New PIN (4-8 digits)"
                       pattern="\d{4,8}" maxlength="8" inputmode="numeric">
                <button class="btn-primary" id="changePinBtn">Update PIN</button>
            </div>
        </div>

        <div class="admin-section">
            <h3>Purge Hall of Fame</h3>
            <p>Permanently delete all leaderboard entries.</p>
            <button class="btn-danger" id="purgeBtn">Purge All Entries</button>
        </div>

        <button class="btn-secondary" id="adminLogoutBtn">Logout</button>
    </div>
</section>
