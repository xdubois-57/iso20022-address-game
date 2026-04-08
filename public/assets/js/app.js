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

(function () {
    'use strict';

    /* =======================================================
       Constants
       ======================================================= */
    const INACTIVITY_TIMEOUT = 30000; // 30s
    const COUNTDOWN_SECONDS = 10;
    const API_URL = 'index.php';

    /* =======================================================
       State
       ======================================================= */
    let currentScreen = 'game';
    let scenario = null;
    let slotMapping = {};       // slotId -> chip object
    let inactivityTimer = null;
    let countdownInterval = null;
    let countdownValue = COUNTDOWN_SECONDS;
    let adminPin = '';
    let adminLoggedIn = false;
    let lastValidationResult = null;

    /* =======================================================
       DOM References
       ======================================================= */
    const appContainer = document.getElementById('appContainer');
    const inactivityOverlay = document.getElementById('inactivityOverlay');
    const countdownTimer = document.getElementById('countdownTimer');
    const continueBtn = document.getElementById('continueBtn');
    const stopBtn = document.getElementById('stopBtn');

    /* =======================================================
       API Helper
       ======================================================= */
    async function api(action, body, isUpload) {
        const opts = { method: 'POST' };
        if (isUpload) {
            opts.headers = { 'X-Action': action };
            opts.body = body;
        } else {
            opts.headers = {
                'Content-Type': 'application/json',
                'X-Action': action,
            };
            opts.body = JSON.stringify(body || {});
        }
        const resp = await fetch(API_URL, opts);
        const data = await resp.json();
        if (data.setup_required) {
            window.location.reload();
            return null;
        }
        return data;
    }

    /* =======================================================
       Screen Router
       ======================================================= */
    function showScreen(name) {
        currentScreen = name;
        // Update nav active state
        document.querySelectorAll('.nav-btn[data-screen]').forEach(function (btn) {
            btn.classList.toggle('active', btn.dataset.screen === name);
        });
        switch (name) {
            case 'game':
                renderGameScreen();
                break;
            case 'leaderboard':
                renderLeaderboardScreen();
                break;
            case 'admin':
                renderAdminScreen();
                break;
            case 'privacy':
                renderPrivacyScreen();
                break;
        }
    }

    /* =======================================================
       Navigation
       ======================================================= */
    document.querySelectorAll('[data-screen]').forEach(function (el) {
        el.addEventListener('click', function (e) {
            e.preventDefault();
            showScreen(this.dataset.screen);
        });
    });

    stopBtn.addEventListener('click', resetSession);

    /* =======================================================
       Inactivity Timer
       ======================================================= */
    function resetInactivityTimer() {
        clearTimeout(inactivityTimer);
        clearInterval(countdownInterval);
        inactivityOverlay.classList.add('hidden');
        countdownValue = COUNTDOWN_SECONDS;

        inactivityTimer = setTimeout(showInactivityWarning, INACTIVITY_TIMEOUT);
    }

    function showInactivityWarning() {
        countdownValue = COUNTDOWN_SECONDS;
        countdownTimer.textContent = countdownValue;
        inactivityOverlay.classList.remove('hidden');

        countdownInterval = setInterval(function () {
            countdownValue--;
            countdownTimer.textContent = countdownValue;
            if (countdownValue <= 0) {
                clearInterval(countdownInterval);
                resetSession();
            }
        }, 1000);
    }

    continueBtn.addEventListener('click', function () {
        resetInactivityTimer();
    });

    function resetSession() {
        clearTimeout(inactivityTimer);
        clearInterval(countdownInterval);
        inactivityOverlay.classList.add('hidden');
        scenario = null;
        slotMapping = {};
        adminPin = '';
        adminLoggedIn = false;
        lastValidationResult = null;
        showScreen('game');
        resetInactivityTimer();
    }

    // Track user activity
    ['touchstart', 'mousedown', 'keydown'].forEach(function (evt) {
        document.addEventListener(evt, resetInactivityTimer, { passive: true });
    });

    /* =======================================================
       Game Screen
       ======================================================= */
    async function renderGameScreen() {
        appContainer.innerHTML = '<p style="text-align:center;padding:2rem;">Loading scenario...</p>';

        const data = await api('game/scenario');
        if (!data || data.error) {
            appContainer.innerHTML =
                '<div style="text-align:center;padding:2rem;">' +
                '<h2>No Scenarios Available</h2>' +
                '<p>' + (data ? data.error : 'Network error') + '</p>' +
                '<button class="btn-primary" onclick="location.reload()">Retry</button>' +
                '</div>';
            return;
        }

        scenario = data.scenario;
        slotMapping = {};
        lastValidationResult = null;

        // Build the game view HTML
        var html = '<section class="game-screen"><div class="game-layout">';

        // Left: Source panel
        html += '<div class="source-panel">';
        html += '<h2>Unstructured Address</h2>';
        html += '<p class="hint-text">Drag chips to the correct ISO 20022 slots \u2192</p>';
        html += '<div class="chip-container" id="chipContainer">';
        scenario.chips.forEach(function (chip) {
            html += '<div class="chip" draggable="true" data-chip-id="' + chip.id +
                '" data-chip-field="' + chip.field + '" data-chip-value="' + chip.value + '">' +
                '<span class="chip-label">' + chip.label + '</span> ' + chip.value + '</div>';
        });
        html += '</div>';

        if (data.fact) {
            html += '<div class="fact-box"><strong>Did you know?</strong><p>' +
                escapeHtml(data.fact) + '</p></div>';
        }
        html += '</div>';

        // Right: Target panel
        html += '<div class="target-panel">';
        html += '<h2>ISO 20022 Structured Address</h2>';
        html += '<div class="goal-badge">' + scenario.goal_type + '</div>';
        html += '<div class="slot-container" id="slotContainer">';
        scenario.slots.forEach(function (slot) {
            html += '<div class="slot' + (slot.mandatory ? ' mandatory' : '') +
                '" data-slot-id="' + slot.id + '" id="slot_' + slot.id + '">' +
                '<span class="slot-tag">' + escapeHtml(slot.tag) + '</span>' +
                '<span class="slot-label">' + escapeHtml(slot.label) + '</span>' +
                '<span class="slot-content" id="slotContent_' + slot.id + '"></span>' +
                '</div>';
        });
        html += '</div>';
        html += '<button class="btn-primary btn-validate" id="validateBtn" disabled>Validate Answer</button>';
        html += '</div></div>';

        // Result overlay
        html += '<div id="resultOverlay" class="overlay hidden"><div class="overlay-content result-card">';
        html += '<div id="resultIcon"></div><h2 id="resultTitle"></h2><p id="resultScore"></p>';
        html += '<div id="resultErrors" class="error-list"></div>';
        html += '<div class="result-actions">';
        html += '<input type="text" id="playerNameInput" placeholder="Your name (for Hall of Fame)" maxlength="50" class="name-input">';
        html += '<button class="btn-primary" id="submitScoreBtn">Submit Score</button>';
        html += '<button class="btn-secondary" id="playAgainBtn">Play Again</button>';
        html += '</div></div></div>';

        html += '</section>';
        appContainer.innerHTML = html;

        initDragAndDrop();
        initGameButtons();
    }

    /* =======================================================
       Drag & Drop (Touch + Mouse)
       ======================================================= */
    function initDragAndDrop() {
        var chips = document.querySelectorAll('.chip');
        var slots = document.querySelectorAll('.slot');

        // Mouse drag
        chips.forEach(function (chip) {
            chip.addEventListener('dragstart', function (e) {
                e.dataTransfer.setData('text/plain', chip.dataset.chipId);
                chip.classList.add('dragging');
            });
            chip.addEventListener('dragend', function () {
                chip.classList.remove('dragging');
            });
        });

        slots.forEach(function (slot) {
            slot.addEventListener('dragover', function (e) {
                e.preventDefault();
                slot.classList.add('drag-over');
            });
            slot.addEventListener('dragleave', function () {
                slot.classList.remove('drag-over');
            });
            slot.addEventListener('drop', function (e) {
                e.preventDefault();
                slot.classList.remove('drag-over');
                var chipId = e.dataTransfer.getData('text/plain');
                placeChipInSlot(chipId, slot.dataset.slotId);
            });
        });

        // Touch drag
        var draggedChip = null;
        var dragClone = null;

        chips.forEach(function (chip) {
            chip.addEventListener('touchstart', function (e) {
                draggedChip = chip;
                chip.classList.add('dragging');

                dragClone = chip.cloneNode(true);
                dragClone.style.position = 'fixed';
                dragClone.style.pointerEvents = 'none';
                dragClone.style.zIndex = '999';
                dragClone.style.opacity = '0.8';
                document.body.appendChild(dragClone);

                var touch = e.touches[0];
                dragClone.style.left = (touch.clientX - 40) + 'px';
                dragClone.style.top = (touch.clientY - 20) + 'px';
            }, { passive: true });
        });

        document.addEventListener('touchmove', function (e) {
            if (!dragClone) return;
            var touch = e.touches[0];
            dragClone.style.left = (touch.clientX - 40) + 'px';
            dragClone.style.top = (touch.clientY - 20) + 'px';

            // Highlight slot under touch
            slots.forEach(function (s) { s.classList.remove('drag-over'); });
            var el = document.elementFromPoint(touch.clientX, touch.clientY);
            if (el) {
                var slotEl = el.closest('.slot');
                if (slotEl) slotEl.classList.add('drag-over');
            }
        }, { passive: true });

        document.addEventListener('touchend', function () {
            if (!draggedChip || !dragClone) return;

            var rect = dragClone.getBoundingClientRect();
            var centerX = rect.left + rect.width / 2;
            var centerY = rect.top + rect.height / 2;

            dragClone.remove();
            dragClone = null;
            draggedChip.classList.remove('dragging');

            // Find slot under drop point
            slots.forEach(function (s) { s.classList.remove('drag-over'); });
            var el = document.elementFromPoint(centerX, centerY);
            if (el) {
                var slotEl = el.closest('.slot');
                if (slotEl) {
                    placeChipInSlot(draggedChip.dataset.chipId, slotEl.dataset.slotId);
                }
            }
            draggedChip = null;
        });
    }

    function placeChipInSlot(chipId, slotId) {
        var chip = scenario.chips.find(function (c) { return c.id === chipId; });
        if (!chip) return;

        // If slot already has a chip, return it to source
        if (slotMapping[slotId]) {
            returnChipToSource(slotMapping[slotId]);
        }

        // If chip was in another slot, free it
        Object.keys(slotMapping).forEach(function (sid) {
            if (slotMapping[sid] && slotMapping[sid].id === chipId) {
                slotMapping[sid] = null;
                renderSlotContent(sid);
            }
        });

        slotMapping[slotId] = chip;
        renderSlotContent(slotId);

        // Hide chip from source
        var chipEl = document.querySelector('[data-chip-id="' + chipId + '"]');
        if (chipEl) chipEl.classList.add('hidden');

        updateValidateButton();
    }

    function returnChipToSource(chip) {
        if (!chip) return;
        var chipEl = document.querySelector('[data-chip-id="' + chip.id + '"]');
        if (chipEl) chipEl.classList.remove('hidden');
    }

    function renderSlotContent(slotId) {
        var contentEl = document.getElementById('slotContent_' + slotId);
        if (!contentEl) return;

        var chip = slotMapping[slotId];
        var slotEl = document.getElementById('slot_' + slotId);

        if (chip) {
            contentEl.innerHTML = '<span class="slot-chip">' + escapeHtml(chip.value) +
                '<button class="slot-remove" data-remove-slot="' + slotId + '">&times;</button></span>';
            slotEl.classList.add('filled');

            contentEl.querySelector('.slot-remove').addEventListener('click', function () {
                returnChipToSource(slotMapping[slotId]);
                slotMapping[slotId] = null;
                renderSlotContent(slotId);
                updateValidateButton();
            });
        } else {
            contentEl.innerHTML = '';
            slotEl.classList.remove('filled');
        }
    }

    function updateValidateButton() {
        var btn = document.getElementById('validateBtn');
        if (!btn) return;
        // Enable if at least one chip is placed
        var hasChip = Object.values(slotMapping).some(function (v) { return v !== null; });
        btn.disabled = !hasChip;
    }

    /* =======================================================
       Game Validation & Result
       ======================================================= */
    function initGameButtons() {
        var validateBtn = document.getElementById('validateBtn');
        if (validateBtn) {
            validateBtn.addEventListener('click', validateAnswer);
        }

        var playAgainBtn = document.getElementById('playAgainBtn');
        if (playAgainBtn) {
            playAgainBtn.addEventListener('click', function () {
                showScreen('game');
            });
        }

        var submitScoreBtn = document.getElementById('submitScoreBtn');
        if (submitScoreBtn) {
            submitScoreBtn.addEventListener('click', submitScore);
        }
    }

    async function validateAnswer() {
        // Build mapping from slotId -> value
        var mapping = {};
        Object.keys(slotMapping).forEach(function (slotId) {
            if (slotMapping[slotId]) {
                mapping[slotId] = slotMapping[slotId].value;
            }
        });

        var data = await api('game/validate', {
            scenario_id: scenario.id,
            mapping: mapping,
        });

        if (!data) return;
        lastValidationResult = data;

        var overlay = document.getElementById('resultOverlay');
        var icon = document.getElementById('resultIcon');
        var title = document.getElementById('resultTitle');
        var score = document.getElementById('resultScore');
        var errors = document.getElementById('resultErrors');

        if (data.perfect) {
            icon.innerHTML = '<span class="result-icon-success">\u2705</span>';
            title.textContent = 'Perfect Score!';
            // Fire confetti
            if (typeof confetti === 'function') {
                confetti({ particleCount: 150, spread: 70, origin: { y: 0.6 } });
            }
        } else {
            icon.innerHTML = '<span class="result-icon-fail">\u274C</span>';
            title.textContent = 'Almost There!';
        }

        score.textContent = 'Score: ' + data.percentage + '% (' + data.score + '/' + data.maxScore + ')';

        errors.innerHTML = '';
        if (data.errors && data.errors.length > 0) {
            data.errors.forEach(function (err) {
                var msg = err.field + ': ';
                if (err.expected) {
                    msg += 'Expected "' + err.expected + '"';
                    if (err.got) msg += ', got "' + err.got + '"';
                } else if (err.error) {
                    msg += err.error;
                }
                errors.innerHTML += '<div class="error-item">' + escapeHtml(msg) + '</div>';
            });
        }

        overlay.classList.remove('hidden');
    }

    async function submitScore() {
        var nameInput = document.getElementById('playerNameInput');
        var name = nameInput ? nameInput.value.trim() : '';
        if (!name) {
            nameInput.style.borderColor = '#c0392b';
            nameInput.focus();
            return;
        }

        var scoreVal = lastValidationResult ? lastValidationResult.percentage : 0;
        var data = await api('leaderboard/submit', {
            player_name: name,
            score: scoreVal,
        });

        if (data && data.success) {
            showScreen('leaderboard');
        }
    }

    /* =======================================================
       Leaderboard Screen
       ======================================================= */
    async function renderLeaderboardScreen() {
        appContainer.innerHTML = '<section class="leaderboard-screen"><h2>Hall of Fame</h2>' +
            '<p style="text-align:center;">Loading...</p></section>';

        var data = await api('leaderboard/top');
        if (!data) return;

        var entries = data.entries || [];
        var html = '<section class="leaderboard-screen"><h2>Hall of Fame</h2>';
        html += '<div class="leaderboard-table-wrap">';

        if (entries.length === 0) {
            html += '<p class="empty-state">No entries yet. Be the first to play!</p>';
        } else {
            html += '<table class="leaderboard-table"><thead><tr>';
            html += '<th>Rank</th><th>Player</th><th>Score</th><th>Date</th>';
            html += '</tr></thead><tbody>';
            entries.forEach(function (entry, i) {
                html += '<tr><td>' + (i + 1) + '</td>';
                html += '<td>' + escapeHtml(entry.player_name) + '</td>';
                html += '<td>' + entry.score + '%</td>';
                html += '<td>' + formatDate(entry.created_at) + '</td></tr>';
            });
            html += '</tbody></table>';
        }

        html += '</div></section>';
        appContainer.innerHTML = html;
    }

    /* =======================================================
       Admin Screen
       ======================================================= */
    function renderAdminScreen() {
        if (adminLoggedIn) {
            renderAdminDashboard();
            return;
        }

        adminPin = '';
        var html = '<section class="admin-screen"><div class="pin-panel">';
        html += '<h2>Admin Access</h2>';
        html += '<div class="pin-display" id="pinDisplay">';
        for (var i = 0; i < 4; i++) {
            html += '<span class="pin-dot"></span>';
        }
        html += '</div>';
        html += '<div class="pin-pad">';
        for (var d = 1; d <= 9; d++) {
            html += '<button class="pin-key" data-digit="' + d + '">' + d + '</button>';
        }
        html += '<button class="pin-key pin-key-clear" data-action="clear">C</button>';
        html += '<button class="pin-key" data-digit="0">0</button>';
        html += '<button class="pin-key pin-key-submit" data-action="submit">&#10003;</button>';
        html += '</div>';
        html += '<p class="pin-error hidden" id="pinError">Invalid PIN</p>';
        html += '</div></section>';

        appContainer.innerHTML = html;
        initPinPad();
    }

    function initPinPad() {
        document.querySelectorAll('.pin-key').forEach(function (key) {
            key.addEventListener('click', function () {
                var digit = this.dataset.digit;
                var action = this.dataset.action;

                if (digit !== undefined) {
                    if (adminPin.length < 8) {
                        adminPin += digit;
                        updatePinDots();
                    }
                } else if (action === 'clear') {
                    adminPin = '';
                    updatePinDots();
                    var err = document.getElementById('pinError');
                    if (err) err.classList.add('hidden');
                } else if (action === 'submit') {
                    submitPin();
                }
            });
        });
    }

    function updatePinDots() {
        var dots = document.querySelectorAll('.pin-dot');
        dots.forEach(function (dot, i) {
            dot.classList.toggle('filled', i < adminPin.length);
        });
    }

    async function submitPin() {
        var data = await api('admin/login', { pin: adminPin });
        if (data && data.success) {
            adminLoggedIn = true;
            renderAdminDashboard();
        } else {
            adminPin = '';
            updatePinDots();
            var err = document.getElementById('pinError');
            if (err) err.classList.remove('hidden');
        }
    }

    function renderAdminDashboard() {
        var html = '<section class="admin-screen"><div class="admin-dashboard">';
        html += '<h2>Admin Dashboard</h2>';

        // Upload section
        html += '<div class="admin-section"><h3>Upload Scenarios</h3>';
        html += '<p>Upload an Excel file (.xlsx) with scenario data and "Did you know?" facts.</p>';
        html += '<form class="dropzone" id="excelDropzone" action="index.php"></form>';
        html += '<div id="uploadStatus" class="upload-status hidden"></div></div>';

        // Change PIN
        html += '<div class="admin-section"><h3>Change PIN</h3>';
        html += '<div class="pin-change-form">';
        html += '<input type="password" id="newPinInput" placeholder="New PIN (4-8 digits)" pattern="\\d{4,8}" maxlength="8" inputmode="numeric">';
        html += '<button class="btn-primary" id="changePinBtn">Update PIN</button>';
        html += '</div></div>';

        // Purge
        html += '<div class="admin-section"><h3>Purge Hall of Fame</h3>';
        html += '<p>Permanently delete all leaderboard entries.</p>';
        html += '<button class="btn-danger" id="purgeBtn">Purge All Entries</button></div>';

        html += '<button class="btn-secondary" id="adminLogoutBtn">Logout</button>';
        html += '</div></section>';

        appContainer.innerHTML = html;
        initAdminActions();
        initDropzone();
    }

    function initAdminActions() {
        document.getElementById('changePinBtn').addEventListener('click', async function () {
            var newPin = document.getElementById('newPinInput').value;
            if (!/^\d{4,8}$/.test(newPin)) {
                alert('PIN must be 4-8 digits');
                return;
            }
            var data = await api('admin/change-pin', { new_pin: newPin });
            if (data && data.success) {
                alert('PIN updated successfully');
                document.getElementById('newPinInput').value = '';
            } else {
                alert(data ? data.error : 'Error');
            }
        });

        document.getElementById('purgeBtn').addEventListener('click', async function () {
            if (!confirm('Are you sure? This cannot be undone.')) return;
            var data = await api('admin/purge-leaderboard');
            if (data && data.success) {
                alert('Leaderboard purged');
            }
        });

        document.getElementById('adminLogoutBtn').addEventListener('click', async function () {
            await api('admin/logout');
            adminLoggedIn = false;
            showScreen('game');
        });
    }

    function initDropzone() {
        if (typeof Dropzone === 'undefined') return;

        Dropzone.autoDiscover = false;
        var dzEl = document.getElementById('excelDropzone');
        if (!dzEl) return;

        var dz = new Dropzone(dzEl, {
            url: API_URL,
            method: 'post',
            paramName: 'file',
            maxFiles: 1,
            acceptedFiles: '.xlsx',
            headers: { 'X-Action': 'admin/upload' },
            dictDefaultMessage: 'Drop .xlsx file here or tap to browse',
            init: function () {
                this.on('success', function (file, response) {
                    var status = document.getElementById('uploadStatus');
                    if (response.success) {
                        status.textContent = 'Imported ' + response.imported.scenarios +
                            ' scenarios and ' + response.imported.facts + ' facts.';
                        status.className = 'upload-status status-success';
                    } else {
                        status.textContent = (response.errors || []).join('; ');
                        status.className = 'upload-status status-error';
                    }
                    status.classList.remove('hidden');
                    this.removeAllFiles();
                });
                this.on('error', function (file, errorMessage) {
                    var status = document.getElementById('uploadStatus');
                    var msg = typeof errorMessage === 'string' ? errorMessage : (errorMessage.error || 'Upload failed');
                    status.textContent = msg;
                    status.className = 'upload-status status-error';
                    status.classList.remove('hidden');
                    this.removeAllFiles();
                });
            },
        });
    }

    /* =======================================================
       Privacy Screen
       ======================================================= */
    function renderPrivacyScreen() {
        var html = '<section class="privacy-screen"><article>';
        html += '<h2>Privacy &amp; GDPR Information</h2>';
        html += '<h3>Data Minimisation</h3>';
        html += '<p>This application follows the principle of data minimisation. We collect only the information strictly necessary for the game experience:</p>';
        html += '<ul><li><strong>Player name</strong> &mdash; Entered voluntarily for the Hall of Fame leaderboard.</li></ul>';
        html += '<h3>No Cookies &amp; No Tracking</h3>';
        html += '<p>This application does not use cookies for tracking. No analytics, fingerprinting, or third-party tracking services are used. A minimal server-side session is used solely for admin authentication.</p>';
        html += '<h3>Pseudonymisation &amp; Encryption</h3>';
        html += '<p>Player names are encrypted at rest using AES-256-CTR. They are only decrypted momentarily for display and never shared with third parties.</p>';
        html += '<h3>Data Retention</h3>';
        html += '<p>Leaderboard entries are automatically deleted after <strong>30 days</strong> via an automated cleanup process.</p>';
        html += '<h3>Your Rights</h3>';
        html += '<p>Under the GDPR, you have the right to request access to, erasure of, or withdraw consent for your personal data. Contact the event organiser to exercise these rights.</p>';
        html += '<h3>Open Source</h3>';
        html += '<p>Licensed under <a href="https://www.gnu.org/licenses/gpl-3.0.html" target="_blank" rel="noopener">GPL v3.0</a>. ';
        html += 'Source: <a href="https://github.com/xdubois-57/iso20022-address-game" target="_blank" rel="noopener">GitHub</a>.</p>';
        html += '</article></section>';
        appContainer.innerHTML = html;
    }

    /* =======================================================
       Utilities
       ======================================================= */
    function escapeHtml(str) {
        var div = document.createElement('div');
        div.textContent = str || '';
        return div.innerHTML;
    }

    function formatDate(dateStr) {
        if (!dateStr) return '';
        var d = new Date(dateStr);
        return d.toLocaleDateString('en-GB', { day: '2-digit', month: 'short', year: 'numeric' });
    }

    /* =======================================================
       Init
       ======================================================= */
    showScreen('game');
    resetInactivityTimer();

})();
