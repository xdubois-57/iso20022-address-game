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
    const TOTAL_ROUNDS = 5;
    let currentScreen = 'game';
    let scenario = null;
    let slotMapping = {};
    let inactivityTimer = null;
    let countdownInterval = null;
    let countdownValue = COUNTDOWN_SECONDS;
    let adminPin = '';
    let adminLoggedIn = false;
    // Multi-round game state
    let playerName = '';
    let currentRound = 0;
    let roundScores = [];
    let gameActive = false;
    let gameTimerInterval = null;
    let gameElapsedSeconds = 0;
    let playedScenarioIds = [];
    let lastSubmittedEntry = null;

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
    var csrfToken = (document.querySelector('meta[name="csrf-token"]') || {}).content || '';

    async function api(action, body, isUpload) {
        const opts = { method: 'POST' };
        if (isUpload) {
            opts.headers = { 'X-Action': action, 'X-CSRF-Token': csrfToken };
            opts.body = body;
        } else {
            opts.headers = {
                'Content-Type': 'application/json',
                'X-Action': action,
                'X-CSRF-Token': csrfToken,
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
       Fullscreen
       ======================================================= */
    function enterFullscreen() {
        var el = document.documentElement;
        if (document.fullscreenElement) return;
        if (el.requestFullscreen) el.requestFullscreen().catch(function(){});
        else if (el.webkitRequestFullscreen) el.webkitRequestFullscreen();
    }

    /* =======================================================
       Screen Router
       ======================================================= */
    function showScreen(name) {
        currentScreen = name;
        // Enter fullscreen on any navigation (kiosk mode)
        enterFullscreen();
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

        if (gameActive) {
            inactivityTimer = setTimeout(showInactivityWarning, INACTIVITY_TIMEOUT);
        }
    }

    function stopInactivityTimer() {
        clearTimeout(inactivityTimer);
        clearInterval(countdownInterval);
        inactivityOverlay.classList.add('hidden');
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
        stopInactivityTimer();
        stopGameTimer();
        gameActive = false;
        scenario = null;
        slotMapping = {};
        adminPin = '';
        adminLoggedIn = false;
        currentRound = 0;
        roundScores = [];
        playedScenarioIds = [];
        playerName = '';
        lastSubmittedEntry = null;
        showScreen('game');
    }

    // Track user activity
    ['touchstart', 'mousedown', 'keydown'].forEach(function (evt) {
        document.addEventListener(evt, resetInactivityTimer, { passive: true });
    });

    /* =======================================================
       Game Timer
       ======================================================= */
    function startGameTimer() {
        gameElapsedSeconds = 0;
        clearInterval(gameTimerInterval);
        gameTimerInterval = setInterval(function () {
            gameElapsedSeconds++;
            updateTimerDisplay();
        }, 1000);
    }

    function stopGameTimer() { clearInterval(gameTimerInterval); }

    function updateTimerDisplay() {
        var el = document.getElementById('gameTimer');
        if (!el) return;
        var mins = Math.floor(gameElapsedSeconds / 60);
        var secs = gameElapsedSeconds % 60;
        el.textContent = '\u23F1 ' + mins + ':' + (secs < 10 ? '0' : '') + secs;
    }

    /* =======================================================
       Game Screen — Welcome (ask name first)
       ======================================================= */
    function renderGameScreen() {
        gameActive = false;
        stopInactivityTimer();
        stopGameTimer();

        var html = '<section class="game-welcome"><div class="welcome-card">';
        html += '<h2>ISO 20022 Address Game</h2>';
        html += '<p>Structure <strong>' + TOTAL_ROUNDS + ' addresses</strong> into ISO 20022 format as fast as you can!</p>';
        html += '<input type="text" id="welcomeNameInput" placeholder="Enter your name to start" maxlength="50" class="name-input"';
        if (playerName) html += ' value="' + escapeHtml(playerName) + '"';
        html += '>';
        html += '<button class="btn-primary btn-start" id="startGameBtn">Start Game</button>';
        html += '</div></section>';
        appContainer.innerHTML = html;

        var nameInput = document.getElementById('welcomeNameInput');
        document.getElementById('startGameBtn').addEventListener('click', function () {
            playerName = nameInput.value.trim();
            if (!playerName) { nameInput.style.borderColor = '#c0392b'; nameInput.focus(); return; }
            startGame();
        });
        nameInput.addEventListener('keydown', function (e) {
            if (e.key === 'Enter') document.getElementById('startGameBtn').click();
        });
        nameInput.focus();
    }

    function startGame() {
        currentRound = 0;
        roundScores = [];
        playedScenarioIds = [];
        gameActive = true;
        enterFullscreen();
        startGameTimer();
        loadNextRound();
    }

    async function loadNextRound() {
        currentRound++;
        if (currentRound > TOTAL_ROUNDS) { showFinalScore(); return; }
        resetInactivityTimer();

        appContainer.innerHTML = '<p style="text-align:center;padding:2rem;">Loading round ' +
            currentRound + ' / ' + TOTAL_ROUNDS + '...</p>';

        var data = await api('game/scenario', { exclude_ids: playedScenarioIds });
        if (!data || data.error) {
            if (currentRound > 1) { showFinalScore(); return; }
            appContainer.innerHTML = '<div style="text-align:center;padding:2rem;">' +
                '<h2>No Scenarios Available</h2>' +
                '<p>' + (data ? data.error : 'Network error') + '</p>' +
                '<button class="btn-primary" onclick="location.reload()">Retry</button></div>';
            return;
        }

        scenario = data.scenario;
        playedScenarioIds.push(scenario.id);
        slotMapping = {};
        renderRound(data);
    }

    function renderRound(data) {
        var html = '<section class="game-screen">';
        // Header bar: round, timer, player
        html += '<div class="game-header-bar">';
        html += '<div class="round-info">Round <strong>' + currentRound + '</strong> / ' + TOTAL_ROUNDS + '</div>';
        html += '<div class="game-timer" id="gameTimer">\u23F1 0:00</div>';
        html += '<div class="player-info">' + escapeHtml(playerName) + '</div>';
        html += '</div>';
        html += '<div class="game-layout">';

        // Left: Source panel — address block + chips without labels
        html += '<div class="source-panel">';
        html += '<h2>Unstructured Address</h2>';
        if (data.scenario.address_display) {
            html += '<div class="address-block">' +
                escapeHtml(data.scenario.address_display).replace(/\n/g, '<br>') + '</div>';
        }
        html += '<p class="hint-text">Drag the value chips to the correct ISO 20022 fields \u2192</p>';
        html += '<div class="chip-container" id="chipContainer">';
        scenario.chips.forEach(function (chip) {
            html += '<div class="chip" draggable="true" data-chip-id="' + chip.id +
                '" data-chip-field="' + chip.field + '" data-chip-value="' + chip.value + '">' +
                chip.value + '</div>';
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
        html += '</div></div></section>';

        appContainer.innerHTML = html;
        updateTimerDisplay();
        initDragAndDrop();
        document.getElementById('validateBtn').addEventListener('click', validateRound);
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

    function isAdrLineSlot(slotId) {
        return slotId.indexOf('AdrLine') === 0;
    }

    function placeChipInSlot(chipId, slotId) {
        var chip = scenario.chips.find(function (c) { return c.id === chipId; });
        if (!chip) return;

        // Remove chip from any slot it was previously in
        Object.keys(slotMapping).forEach(function (sid) {
            if (isAdrLineSlot(sid) && Array.isArray(slotMapping[sid])) {
                var idx = slotMapping[sid].findIndex(function (c) { return c.id === chipId; });
                if (idx !== -1) {
                    slotMapping[sid].splice(idx, 1);
                    if (slotMapping[sid].length === 0) slotMapping[sid] = null;
                    renderSlotContent(sid);
                }
            } else if (slotMapping[sid] && slotMapping[sid].id === chipId) {
                slotMapping[sid] = null;
                renderSlotContent(sid);
            }
        });

        if (isAdrLineSlot(slotId)) {
            // AdrLine slots accept multiple chips (append)
            if (!Array.isArray(slotMapping[slotId])) {
                slotMapping[slotId] = slotMapping[slotId] ? [slotMapping[slotId]] : [];
            }
            slotMapping[slotId].push(chip);
        } else {
            // Non-AdrLine: single chip; return old chip if present
            if (slotMapping[slotId]) returnChipToSource(slotMapping[slotId]);
            slotMapping[slotId] = chip;
        }

        renderSlotContent(slotId);

        // Hide chip from source
        var chipEl = document.querySelector('[data-chip-id="' + chipId + '"]');
        if (chipEl) chipEl.classList.add('hidden');

        updateValidateButton();
    }

    function returnChipToSource(chip) {
        if (!chip) return;
        if (Array.isArray(chip)) {
            chip.forEach(function (c) {
                var el = document.querySelector('[data-chip-id="' + c.id + '"]');
                if (el) el.classList.remove('hidden');
            });
        } else {
            var chipEl = document.querySelector('[data-chip-id="' + chip.id + '"]');
            if (chipEl) chipEl.classList.remove('hidden');
        }
    }

    function renderSlotContent(slotId) {
        var contentEl = document.getElementById('slotContent_' + slotId);
        if (!contentEl) return;
        var slotEl = document.getElementById('slot_' + slotId);
        var data = slotMapping[slotId];

        // Normalise: array for AdrLine, single object otherwise
        var chips = [];
        if (Array.isArray(data)) chips = data;
        else if (data) chips = [data];

        if (chips.length > 0) {
            var inner = '';
            chips.forEach(function (c) {
                inner += '<span class="slot-chip">' + escapeHtml(c.value) +
                    '<button class="slot-remove" data-remove-chip="' + c.id +
                    '" data-slot="' + slotId + '">&times;</button></span> ';
            });
            contentEl.innerHTML = inner;
            slotEl.classList.add('filled');

            contentEl.querySelectorAll('.slot-remove').forEach(function (btn) {
                btn.addEventListener('click', function () {
                    var cid = this.dataset.removeChip;
                    var sid = this.dataset.slot;
                    if (isAdrLineSlot(sid) && Array.isArray(slotMapping[sid])) {
                        var removed = null;
                        slotMapping[sid] = slotMapping[sid].filter(function (c) {
                            if (c.id === cid) { removed = c; return false; }
                            return true;
                        });
                        if (slotMapping[sid].length === 0) slotMapping[sid] = null;
                        returnChipToSource(removed);
                    } else {
                        returnChipToSource(slotMapping[sid]);
                        slotMapping[sid] = null;
                    }
                    renderSlotContent(sid);
                    updateValidateButton();
                });
            });
        } else {
            contentEl.innerHTML = '';
            slotEl.classList.remove('filled');
        }
    }

    function updateValidateButton() {
        var btn = document.getElementById('validateBtn');
        if (!btn) return;
        var hasChip = Object.keys(slotMapping).some(function (k) {
            var v = slotMapping[k];
            return Array.isArray(v) ? v.length > 0 : v !== null;
        });
        btn.disabled = !hasChip;
    }

    /* =======================================================
       Game Validation & Result (multi-round)
       ======================================================= */
    async function validateRound() {
        var mapping = {};
        Object.keys(slotMapping).forEach(function (slotId) {
            var v = slotMapping[slotId];
            if (!v) return;
            if (Array.isArray(v)) {
                mapping[slotId] = v.map(function (c) { return decodeHtml(c.value); }).join(' ');
            } else {
                mapping[slotId] = decodeHtml(v.value);
            }
        });

        var data = await api('game/validate', {
            scenario_id: scenario.id,
            mapping: mapping,
        });
        if (!data) return;

        roundScores.push({
            round: currentRound,
            percentage: data.percentage,
            score: data.score,
            maxScore: data.maxScore,
            perfect: data.perfect,
        });

        showRoundResult(data);
    }

    function showRoundResult(data) {
        var overlay = document.createElement('div');
        overlay.className = 'overlay';
        overlay.id = 'roundResultOverlay';

        var content = '<div class="overlay-content result-card">';
        if (data.perfect) {
            content += '<span class="result-icon-success">\u2705</span>';
            content += '<h2>Perfect!</h2>';
        } else {
            content += '<span class="result-icon-fail">\u274C</span>';
            content += '<h2>Almost!</h2>';
        }
        content += '<p>Score: ' + data.percentage + '% (' + data.score + '/' + data.maxScore + ')</p>';

        if (data.errors && data.errors.length > 0) {
            content += '<div class="error-list">';
            data.errors.forEach(function (err) {
                var msg = err.field + ': ';
                if (err.expected) {
                    msg += 'Expected "' + err.expected + '"';
                    if (err.got) msg += ', got "' + err.got + '"';
                } else if (err.error) {
                    msg += err.error;
                }
                content += '<div class="error-item">' + escapeHtml(msg) + '</div>';
            });
            content += '</div>';
        }

        var isLastRound = currentRound >= TOTAL_ROUNDS;
        content += '<button class="btn-primary" id="nextRoundBtn">' +
            (isLastRound ? 'See Final Score' : 'Next Round \u2192') + '</button>';
        content += '</div>';
        overlay.innerHTML = content;
        document.body.appendChild(overlay);

        document.getElementById('nextRoundBtn').addEventListener('click', function () {
            overlay.remove();
            if (isLastRound) { showFinalScore(); } else { loadNextRound(); }
        });
    }

    function showFinalScore() {
        gameActive = false;
        stopGameTimer();
        stopInactivityTimer();

        var totalScore = 0, totalMax = 0, perfectCount = 0;
        roundScores.forEach(function (r) {
            totalScore += r.score;
            totalMax += r.maxScore;
            if (r.perfect) perfectCount++;
        });
        var finalPct = totalMax > 0 ? Math.round((totalScore / totalMax) * 100) : 0;
        var mins = Math.floor(gameElapsedSeconds / 60);
        var secs = gameElapsedSeconds % 60;
        var timeStr = mins + ':' + (secs < 10 ? '0' : '') + secs;

        var html = '<section class="final-score-screen"><div class="final-score-card">';
        html += '<h1>\uD83C\uDF89 Game Over!</h1>';
        html += '<div class="final-score-value">' + finalPct + '%</div>';
        html += '<p class="final-score-detail">' + totalScore + ' / ' + totalMax + ' points</p>';
        html += '<p class="final-score-detail">' + perfectCount + ' / ' + roundScores.length + ' perfect rounds</p>';
        html += '<p class="final-score-detail">Time: ' + timeStr + '</p>';
        html += '<div class="final-score-rounds">';
        roundScores.forEach(function (r) {
            html += '<span class="round-badge ' + (r.perfect ? 'perfect' : 'partial') + '">' + r.percentage + '%</span>';
        });
        html += '</div>';
        html += '<div class="result-actions">';
        html += '<button class="btn-primary" id="submitFinalScoreBtn">Submit to Hall of Fame</button>';
        html += '<button class="btn-secondary" id="playAgainFinalBtn">Play Again</button>';
        html += '</div></div></section>';
        appContainer.innerHTML = html;

        // Party confetti bursts
        if (typeof confetti === 'function') {
            confetti({ particleCount: 100, spread: 70, origin: { y: 0.6 } });
            setTimeout(function () {
                confetti({ particleCount: 50, angle: 60, spread: 55, origin: { x: 0 } });
                confetti({ particleCount: 50, angle: 120, spread: 55, origin: { x: 1 } });
            }, 300);
            setTimeout(function () {
                confetti({ particleCount: 100, spread: 100, origin: { y: 0.4 } });
            }, 600);
        }

        document.getElementById('submitFinalScoreBtn').addEventListener('click', async function () {
            var data = await api('leaderboard/submit', {
                player_name: playerName,
                score: finalPct,
                time_seconds: gameElapsedSeconds,
            });
            if (data && data.success) {
                lastSubmittedEntry = { name: playerName, score: finalPct, time: gameElapsedSeconds };
                showScreen('leaderboard');
            }
        });

        document.getElementById('playAgainFinalBtn').addEventListener('click', function () {
            showScreen('game');
        });
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
        var highlightIdx = -1;
        var html = '<section class="leaderboard-screen"><h2>Hall of Fame</h2>';
        html += '<div class="leaderboard-table-wrap">';

        if (entries.length === 0) {
            html += '<p class="empty-state">No entries yet. Be the first to play!</p>';
        } else {
            html += '<table class="leaderboard-table"><thead><tr>';
            html += '<th>Rank</th><th>Player</th><th>Score</th><th>Time</th><th>Date</th>';
            html += '</tr></thead><tbody>';
            entries.forEach(function (entry, i) {
                var ts = parseInt(entry.time_seconds) || 0;
                var tm = Math.floor(ts / 60);
                var tss = ts % 60;
                var timeDisplay = tm + ':' + (tss < 10 ? '0' : '') + tss;
                var isMe = lastSubmittedEntry &&
                    entry.player_name === lastSubmittedEntry.name &&
                    parseInt(entry.score) === lastSubmittedEntry.score &&
                    parseInt(entry.time_seconds) === lastSubmittedEntry.time &&
                    highlightIdx === -1;
                if (isMe) highlightIdx = i;
                html += '<tr' + (isMe ? ' class="my-entry"' : '') + '><td>' + (i + 1) + '</td>';
                html += '<td>' + escapeHtml(entry.player_name) + '</td>';
                html += '<td>' + entry.score + '%</td>';
                html += '<td>' + timeDisplay + '</td>';
                html += '<td>' + formatDate(entry.created_at) + '</td></tr>';
            });
            html += '</tbody></table>';
        }

        html += '</div></section>';
        appContainer.innerHTML = html;

        // Party effect on highlighted entry
        if (highlightIdx >= 0) {
            var myRow = document.querySelector('.my-entry');
            if (myRow) {
                myRow.scrollIntoView({ behavior: 'smooth', block: 'center' });
            }
            if (typeof confetti === 'function') {
                setTimeout(function () {
                    confetti({ particleCount: 80, spread: 70, origin: { y: 0.6 } });
                }, 200);
                setTimeout(function () {
                    confetti({ particleCount: 40, angle: 60, spread: 55, origin: { x: 0 } });
                    confetti({ particleCount: 40, angle: 120, spread: 55, origin: { x: 1 } });
                }, 500);
            }
        }
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
        html += '<div id="uploadStatus" class="upload-status hidden"></div>';
        html += '<div style="margin-top:1rem;display:flex;gap:0.75rem;flex-wrap:wrap;">';
        html += '<a href="assets/Scenarios.xlsx" download class="btn-secondary" style="text-decoration:none;display:inline-block;">\u2B07 Download Example Excel</a>';
        html += '<button class="btn-secondary" id="exportScenariosBtn">\u2B07 Export Current Scenarios</button>';
        html += '</div></div>';

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

        document.getElementById('exportScenariosBtn').addEventListener('click', function () {
            window.location.href = API_URL + '?action=admin/export';
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
        html += '<h2>Privacy Notice &amp; GDPR Compliance</h2>';
        html += '<p><em>Last updated: April 2026</em></p>';

        html += '<h3>1. Data Controller</h3>';
        html += '<p>The data controller for this application is the event organiser who deploys and operates this instance of the ISO 20022 Address Structuring Game. ';
        html += 'For questions regarding data processing, contact the event organiser directly at the venue or via the contact details provided at the event.</p>';

        html += '<h3>2. Legal Basis for Processing (Art. 6 GDPR)</h3>';
        html += '<p>Personal data is processed on the following legal basis:</p>';
        html += '<ul>';
        html += '<li><strong>Consent (Art. 6(1)(a))</strong> &mdash; By voluntarily entering your name and submitting your score to the Hall of Fame, you explicitly consent to the processing of your name for leaderboard display purposes. You may withdraw consent at any time by contacting the event organiser.</li>';
        html += '<li><strong>Legitimate interest (Art. 6(1)(f))</strong> &mdash; A minimal server-side session identifier is used solely to authenticate the administrator. No session data is stored for regular players.</li>';
        html += '</ul>';

        html += '<h3>3. Categories of Personal Data Collected</h3>';
        html += '<table class="leaderboard-table" style="margin-bottom:1rem;"><thead><tr><th>Data</th><th>Purpose</th><th>Storage</th><th>Retention</th></tr></thead><tbody>';
        html += '<tr><td>Player name</td><td>Display on Hall of Fame leaderboard</td><td>Encrypted at rest (AES-256-CTR)</td><td>30 days, then automatically deleted</td></tr>';
        html += '<tr><td>Game score &amp; time</td><td>Leaderboard ranking</td><td>Database (not personal data)</td><td>30 days</td></tr>';
        html += '<tr><td>Admin session ID</td><td>Admin authentication only</td><td>Server-side session (no cookie for players)</td><td>Session duration only</td></tr>';
        html += '</tbody></table>';
        html += '<p>No other personal data (e-mail, IP address, device fingerprint, location, etc.) is collected, stored, or processed.</p>';

        html += '<h3>4. Data Minimisation (Art. 5(1)(c))</h3>';
        html += '<p>This application strictly follows the principle of data minimisation. Only the player name is collected &mdash; and only when the player voluntarily submits it. ';
        html += 'No personal data is required to play the game. The game can be played without submitting any personal information.</p>';

        html += '<h3>5. No Cookies, No Tracking, No Analytics</h3>';
        html += '<p>This application:</p>';
        html += '<ul>';
        html += '<li>Does <strong>not</strong> use cookies for tracking or advertising.</li>';
        html += '<li>Does <strong>not</strong> use any analytics services (Google Analytics, Matomo, etc.).</li>';
        html += '<li>Does <strong>not</strong> employ browser fingerprinting or any other tracking technology.</li>';
        html += '<li>Does <strong>not</strong> load any third-party advertising or social media scripts.</li>';
        html += '<li>Does <strong>not</strong> share data with any third party.</li>';
        html += '</ul>';
        html += '<p>External resources loaded (fonts, CSS frameworks) are fetched from CDNs solely for UI rendering and do not transmit personal data.</p>';

        html += '<h3>6. Pseudonymisation &amp; Security Measures (Art. 32)</h3>';
        html += '<p>The following technical and organisational measures are implemented to protect personal data:</p>';
        html += '<ul>';
        html += '<li><strong>Encryption at rest</strong> &mdash; Player names are encrypted using AES-256-CTR with a unique initialisation vector (IV) per entry before storage. The encryption key is stored separately from the database.</li>';
        html += '<li><strong>Encryption in transit</strong> &mdash; HTTPS/TLS should be enabled on the hosting server (deployment responsibility of the data controller).</li>';
        html += '<li><strong>Hashed credentials</strong> &mdash; The admin PIN is stored as a bcrypt hash. Plaintext PINs are never stored.</li>';
        html += '<li><strong>Session security</strong> &mdash; Session IDs are regenerated on authentication events to prevent session fixation attacks.</li>';
        html += '<li><strong>Input validation</strong> &mdash; All user inputs are validated and sanitised to prevent injection attacks.</li>';
        html += '<li><strong>No database exposure</strong> &mdash; All database operations use parameterised queries (prepared statements) to prevent SQL injection.</li>';
        html += '</ul>';

        html += '<h3>7. Data Retention &amp; Automated Deletion (Art. 5(1)(e))</h3>';
        html += '<p>Leaderboard entries (player name + score) are automatically and permanently deleted after <strong>30 days</strong> via an automated cleanup script. ';
        html += 'The administrator may also manually purge all leaderboard data at any time via the admin panel. ';
        html += 'Once deleted, encrypted player names cannot be recovered.</p>';

        html += '<h3>8. Your Rights as a Data Subject (Art. 15\u201322)</h3>';
        html += '<p>Under the General Data Protection Regulation (EU 2016/679), you have the following rights:</p>';
        html += '<ul>';
        html += '<li><strong>Right of access (Art. 15)</strong> &mdash; You may request confirmation of whether your personal data is being processed and obtain a copy.</li>';
        html += '<li><strong>Right to rectification (Art. 16)</strong> &mdash; You may request correction of inaccurate personal data.</li>';
        html += '<li><strong>Right to erasure / "Right to be forgotten" (Art. 17)</strong> &mdash; You may request the deletion of your personal data at any time.</li>';
        html += '<li><strong>Right to restriction of processing (Art. 18)</strong> &mdash; You may request that processing of your data be restricted under certain circumstances.</li>';
        html += '<li><strong>Right to data portability (Art. 20)</strong> &mdash; You may request your data in a structured, commonly used, machine-readable format.</li>';
        html += '<li><strong>Right to object (Art. 21)</strong> &mdash; You may object to the processing of your data at any time.</li>';
        html += '<li><strong>Right to withdraw consent (Art. 7(3))</strong> &mdash; You may withdraw your consent at any time without affecting the lawfulness of processing prior to withdrawal.</li>';
        html += '<li><strong>Right to lodge a complaint (Art. 77)</strong> &mdash; You have the right to lodge a complaint with a supervisory authority (e.g. your national Data Protection Authority).</li>';
        html += '</ul>';
        html += '<p>To exercise any of these rights, please contact the event organiser who operates this application instance.</p>';

        html += '<h3>9. International Data Transfers</h3>';
        html += '<p>This application does not transfer personal data outside the jurisdiction where it is hosted. ';
        html += 'The data controller is responsible for ensuring the hosting environment complies with applicable data protection regulations.</p>';

        html += '<h3>10. Automated Decision-Making (Art. 22)</h3>';
        html += '<p>This application does not perform any automated decision-making or profiling that produces legal or similarly significant effects on individuals. ';
        html += 'Game scores are calculated algorithmically but have no real-world consequences.</p>';

        html += '<h3>11. Children\u2019s Data</h3>';
        html += '<p>This application is designed for professional educational events and is not directed at children under 16. ';
        html += 'If a child\u2019s data has been inadvertently collected, the event organiser will delete it promptly upon request.</p>';

        html += '<h3>12. Data Breach Notification (Art. 33\u201334)</h3>';
        html += '<p>In the event of a personal data breach, the data controller will notify the competent supervisory authority within 72 hours of becoming aware of the breach, ';
        html += 'and will inform affected data subjects without undue delay if the breach is likely to result in a high risk to their rights and freedoms.</p>';

        html += '<h3>13. Open Source &amp; Transparency</h3>';
        html += '<p>This application is fully open source, enabling complete transparency and independent audit of all data processing activities.</p>';
        html += '<ul>';
        html += '<li><strong>License:</strong> <a href="https://www.gnu.org/licenses/gpl-3.0.html" target="_blank" rel="noopener">GNU GPL v3.0</a></li>';
        html += '<li><strong>Source code:</strong> <a href="https://github.com/xdubois-57/iso20022-address-game" target="_blank" rel="noopener">github.com/xdubois-57/iso20022-address-game</a></li>';
        html += '</ul>';

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

    function decodeHtml(str) {
        var div = document.createElement('div');
        div.innerHTML = str || '';
        return div.textContent;
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

})();
