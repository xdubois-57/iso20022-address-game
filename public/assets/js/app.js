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
    let selectedGoalType = 'Structured';
    let touchDragChip = null;
    let touchDragClone = null;
    var factsCache = [];
    var factRotationInterval = null;
    var currentFactIndex = -1;
    const FACT_ROTATION_INTERVAL = 20000;
    var kioskMode = false;
    var screenSaverTimer = null;
    var screenSaverActive = false;
    var screenSaverFactInterval = null;
    const SCREENSAVER_TIMEOUT = 60000;

    /* =======================================================
       Score Computation
       ======================================================= */
    function computeGameScore(pct, seconds) {
        var timeBonus = 1 + Math.max(0, 300 - seconds) / 300;
        return Math.round(pct * timeBonus * 50);
    }

    function animateScore(el, target, duration, onComplete) {
        var start = null;
        function step(timestamp) {
            if (!start) start = timestamp;
            var progress = Math.min((timestamp - start) / duration, 1);
            var eased = 1 - Math.pow(1 - progress, 3);
            el.textContent = Math.round(eased * target);
            if (progress < 1) {
                requestAnimationFrame(step);
            } else {
                el.textContent = target;
                if (onComplete) onComplete();
            }
        }
        requestAnimationFrame(step);
    }

    /* =======================================================
       DOM References
       ======================================================= */
    const appContainer = document.getElementById('appContainer');
    const inactivityOverlay = document.getElementById('inactivityOverlay');
    const countdownTimer = document.getElementById('countdownTimer');
    const continueBtn = document.getElementById('continueBtn');
    const stopBtn = document.getElementById('stopBtn');
    const hamburgerBtn = document.getElementById('hamburgerBtn');
    const headerNav = document.getElementById('headerNav');

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
            // Database connection failed, redirect to setup page
            window.location.href = 'index.php';
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
        dismissScreenSaver();
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
            closeHamburger();
            showScreen(this.dataset.screen);
        });
    });

    stopBtn.addEventListener('click', function () {
        closeHamburger();
        resetSession();
    });

    /* =======================================================
       Hamburger Menu (mobile)
       ======================================================= */
    function closeHamburger() {
        hamburgerBtn.classList.remove('open');
        hamburgerBtn.setAttribute('aria-expanded', 'false');
        headerNav.classList.remove('open');
    }

    hamburgerBtn.addEventListener('click', function () {
        var isOpen = headerNav.classList.toggle('open');
        hamburgerBtn.classList.toggle('open', isOpen);
        hamburgerBtn.setAttribute('aria-expanded', String(isOpen));
    });

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
        resetScreenSaverTimer();
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
    var deadlineCountdownInterval = null;

    function stopDeadlineCountdown() {
        if (deadlineCountdownInterval) { clearInterval(deadlineCountdownInterval); deadlineCountdownInterval = null; }
    }

    function updateCountdown(targetDate, el) {
        var now = new Date();
        var diff = targetDate.getTime() - now.getTime();
        if (diff <= 0) {
            el.innerHTML = '<div class="countdown-label">Support for unstructured addresses has ended</div>'
                + '<div class="countdown-expired">Deadline reached</div>';
            stopDeadlineCountdown();
            return;
        }
        var totalSeconds = Math.floor(diff / 1000);
        var days = Math.floor(totalSeconds / 86400);
        var hours = Math.floor((totalSeconds % 86400) / 3600);
        var minutes = Math.floor((totalSeconds % 3600) / 60);
        var seconds = totalSeconds % 60;

        function pad(n) { return n < 10 ? '0' + n : '' + n; }

        el.innerHTML = '<div class="countdown-label">Unstructured address support ends in</div>'
            + '<div class="countdown-timer">'
            + '<span class="countdown-unit">' + days + '</span><span class="countdown-suffix">d</span>'
            + '<span class="countdown-sep">:</span>'
            + '<span class="countdown-unit">' + pad(hours) + '</span><span class="countdown-suffix">h</span>'
            + '<span class="countdown-sep">:</span>'
            + '<span class="countdown-unit">' + pad(minutes) + '</span><span class="countdown-suffix">m</span>'
            + '<span class="countdown-sep">:</span>'
            + '<span class="countdown-unit">' + pad(seconds) + '</span><span class="countdown-suffix">s</span>'
            + '</div>';
    }

    function stopFactRotation() {
        if (factRotationInterval) { clearInterval(factRotationInterval); factRotationInterval = null; }
    }

    function nextFact() {
        if (factsCache.length === 0) return null;
        if (currentFactIndex < 0) {
            currentFactIndex = Math.floor(Math.random() * factsCache.length);
        } else {
            currentFactIndex = (currentFactIndex + 1) % factsCache.length;
        }
        return factsCache[currentFactIndex];
    }

    function renderFactInto(el) {
        var fact = nextFact();
        if (!fact) { el.innerHTML = ''; return; }
        el.innerHTML = '<h2>Did you know?</h2><p>' + fact.content + '</p>';
    }

    function startFactRotation(el) {
        stopFactRotation();
        currentFactIndex = -1;
        renderFactInto(el);
        factRotationInterval = setInterval(function () {
            el.style.opacity = '0';
            setTimeout(function () {
                renderFactInto(el);
                el.style.opacity = '1';
            }, 400);
        }, FACT_ROTATION_INTERVAL);
    }

    function renderGameScreen() {
        gameActive = false;
        stopInactivityTimer();
        stopGameTimer();
        stopDeadlineCountdown();
        stopFactRotation();

        var html = '<section class="game-welcome">';
        html += '<div id="countdownBanner"></div>';
        html += '<div class="welcome-card">';
        html += '<h2>ISO 20022 Address Game</h2>';
        html += '<p>Structure <strong>' + TOTAL_ROUNDS + ' addresses</strong> into ISO 20022 format as fast as you can!</p>';
        html += '<input type="text" id="welcomeNameInput" placeholder="Enter your name to start" maxlength="50" class="name-input"';
        if (playerName) html += ' value="' + escapeHtml(playerName) + '"';
        html += '>';
        html += '<button class="btn-primary btn-start" id="startGameBtn">Start Game</button>';
        html += '</div>';
        html += '<div id="welcomeFactDisplay" class="fact-display-card"></div>';
        html += '</section>';
        appContainer.innerHTML = html;

        // Fetch deadline and start countdown
        (async function () {
            var data = await api('game/deadline', {});
            if (data && data.deadline) {
                var banner = document.getElementById('countdownBanner');
                if (!banner) return;
                banner.className = 'countdown-banner';
                var target = new Date(data.deadline);
                updateCountdown(target, banner);
                deadlineCountdownInterval = setInterval(function () {
                    updateCountdown(target, banner);
                }, 1000);
            }
        })();

        // Fetch facts and start rotation
        (async function () {
            var data = await api('game/facts', {});
            if (data && data.facts) {
                factsCache = data.facts;
                var factEl = document.getElementById('welcomeFactDisplay');
                if (factEl && factsCache.length > 0) {
                    startFactRotation(factEl);
                }
            }
        })();

        var nameInput = document.getElementById('welcomeNameInput');
        document.getElementById('startGameBtn').addEventListener('click', async function () {
            playerName = nameInput.value.trim();
            if (!playerName) { nameInput.style.borderColor = '#c0392b'; nameInput.focus(); return; }
            // Check name for profanity
            var check = await api('game/check-name', { name: playerName });
            if (!check) return;
            if (!check.allowed) {
                nameInput.style.borderColor = '#c0392b';
                var warn = document.createElement('p');
                warn.className = 'profanity-warning';
                warn.textContent = check.message || 'Please choose a different name.';
                var existing = document.querySelector('.profanity-warning');
                if (existing) existing.remove();
                nameInput.parentNode.insertBefore(warn, nameInput.nextSibling);
                nameInput.value = '';
                playerName = '';
                nameInput.focus();
                return;
            }
            stopDeadlineCountdown();
            stopFactRotation();
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
                '<p>' + escapeHtml(data ? data.error : 'Network error') + '</p>' +
                '<button class="btn-primary" onclick="location.reload()">Retry</button></div>';
            return;
        }

        scenario = data.scenario;
        playedScenarioIds.push(scenario.id);
        slotMapping = {};
        renderRound(data);
    }

    function renderRound(data) {
        selectedGoalType = 'Structured';

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
            html += '<div class="chip" draggable="true" data-chip-id="' + escapeHtml(chip.id) +
                '" data-chip-field="' + escapeHtml(chip.field) + '" data-chip-value="' + escapeHtml(chip.value) + '">' +
                escapeHtml(chip.value) + '</div>';
        });
        html += '</div>';
        html += '</div>';

        // Right: Target panel with mode tabs
        html += '<div class="target-panel">';
        html += '<h2>ISO 20022 Address</h2>';
        html += '<div class="mode-tabs">';
        html += '<button class="mode-tab active" data-mode="Structured">Structured</button>';
        html += '<button class="mode-tab" data-mode="Hybrid">Hybrid</button>';
        html += '</div>';
        html += '<p class="mode-hint" id="modeHint">You can also try <strong>Hybrid</strong> mode using the tab above</p>';
        html += '<div class="slot-container" id="slotContainer">';
        html += getSlotsHtml(scenario.slots_structured);
        html += '</div>';
        html += '<button class="btn-primary btn-validate" id="validateBtn" disabled>Validate Answer</button>';
        html += '</div></div></section>';

        appContainer.innerHTML = html;
        updateTimerDisplay();
        initDragAndDrop();
        document.getElementById('validateBtn').addEventListener('click', validateRound);

        // Tab click handlers
        document.querySelectorAll('.mode-tab').forEach(function (tab) {
            tab.addEventListener('click', function () {
                switchMode(this.dataset.mode);
            });
        });
    }

    function getSlotsHtml(slots) {
        var html = '';
        slots.forEach(function (slot) {
            html += '<div class="slot' + (slot.mandatory ? ' mandatory' : '') +
                '" data-slot-id="' + slot.id + '" id="slot_' + slot.id + '">' +
                '<span class="slot-tag">' + escapeHtml(slot.tag) + '</span>' +
                '<span class="slot-label">' + escapeHtml(slot.label) + '</span>' +
                '<span class="slot-content" id="slotContent_' + slot.id + '"></span>' +
                '</div>';
        });
        return html;
    }

    function switchMode(newMode) {
        if (newMode === selectedGoalType) return;

        // Return all chips to source
        Object.keys(slotMapping).forEach(function (sid) {
            returnChipToSource(slotMapping[sid]);
        });
        slotMapping = {};

        // Show all chips
        document.querySelectorAll('.chip').forEach(function (c) {
            c.classList.remove('hidden');
        });

        selectedGoalType = newMode;

        // Update tab active state
        document.querySelectorAll('.mode-tab').forEach(function (tab) {
            tab.classList.toggle('active', tab.dataset.mode === newMode);
        });

        // Update mode hint text
        var modeHint = document.getElementById('modeHint');
        if (modeHint) {
            var otherMode = newMode === 'Structured' ? 'Hybrid' : 'Structured';
            modeHint.innerHTML = 'You can also try <strong>' + otherMode + '</strong> mode using the tab above';
        }

        // Re-render slots
        var slots = newMode === 'Structured' ? scenario.slots_structured : scenario.slots_hybrid;
        var slotContainer = document.getElementById('slotContainer');
        slotContainer.innerHTML = getSlotsHtml(slots);

        // Re-init slot drop listeners for new slot DOM elements
        initSlotDropListeners();
        updateValidateButton();
    }

    /* =======================================================
       Drag & Drop (Touch + Mouse)
       ======================================================= */
    function initSlotDropListeners() {
        document.querySelectorAll('.slot').forEach(function (slot) {
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
    }

    function startTouchDrag(el, chipId, e) {
        touchDragChip = { el: el, chipId: chipId };
        el.classList.add('dragging');

        touchDragClone = el.cloneNode(true);
        touchDragClone.style.position = 'fixed';
        touchDragClone.style.pointerEvents = 'none';
        touchDragClone.style.zIndex = '999';
        touchDragClone.style.opacity = '0.8';
        document.body.appendChild(touchDragClone);

        var touch = e.touches[0];
        touchDragClone.style.left = (touch.clientX - 40) + 'px';
        touchDragClone.style.top = (touch.clientY - 20) + 'px';
    }

    function initDragAndDrop() {
        var chips = document.querySelectorAll('.chip');

        // Mouse drag on source chips
        chips.forEach(function (chip) {
            chip.addEventListener('dragstart', function (e) {
                e.dataTransfer.setData('text/plain', chip.dataset.chipId);
                chip.classList.add('dragging');
            });
            chip.addEventListener('dragend', function () {
                chip.classList.remove('dragging');
            });
        });

        // Mouse drop on slots
        initSlotDropListeners();

        // Touch drag on source chips
        chips.forEach(function (chip) {
            chip.addEventListener('touchstart', function (e) {
                startTouchDrag(chip, chip.dataset.chipId, e);
            }, { passive: true });
        });

        // Global touch move/end (handles both source and slot chip drags)
        document.addEventListener('touchmove', function (e) {
            if (!touchDragClone) return;
            var touch = e.touches[0];
            touchDragClone.style.left = (touch.clientX - 40) + 'px';
            touchDragClone.style.top = (touch.clientY - 20) + 'px';

            // Highlight slot under touch (queries live DOM)
            document.querySelectorAll('.slot.drag-over').forEach(function (s) { s.classList.remove('drag-over'); });
            var el = document.elementFromPoint(touch.clientX, touch.clientY);
            if (el) {
                var slotEl = el.closest('.slot');
                if (slotEl) slotEl.classList.add('drag-over');
            }
        }, { passive: true });

        document.addEventListener('touchend', function () {
            if (!touchDragChip || !touchDragClone) return;

            var rect = touchDragClone.getBoundingClientRect();
            var centerX = rect.left + rect.width / 2;
            var centerY = rect.top + rect.height / 2;

            touchDragClone.remove();
            touchDragClone = null;
            touchDragChip.el.classList.remove('dragging');

            // Find slot under drop point (queries live DOM)
            document.querySelectorAll('.slot.drag-over').forEach(function (s) { s.classList.remove('drag-over'); });
            var el = document.elementFromPoint(centerX, centerY);
            if (el) {
                var slotEl = el.closest('.slot');
                if (slotEl) {
                    placeChipInSlot(touchDragChip.chipId, slotEl.dataset.slotId);
                }
            }
            touchDragChip = null;
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
                inner += '<span class="slot-chip" draggable="true" data-chip-id="' + escapeHtml(c.id) + '">' + escapeHtml(c.value) +
                    '<button class="slot-remove" data-remove-chip="' + escapeHtml(c.id) +
                    '" data-slot="' + escapeHtml(slotId) + '">&times;</button></span> ';
            });
            contentEl.innerHTML = inner;
            slotEl.classList.add('filled');

            // Make placed chips re-draggable (mouse)
            contentEl.querySelectorAll('.slot-chip[draggable]').forEach(function (sc) {
                sc.addEventListener('dragstart', function (e) {
                    e.dataTransfer.setData('text/plain', sc.dataset.chipId);
                    sc.classList.add('dragging');
                });
                sc.addEventListener('dragend', function () {
                    sc.classList.remove('dragging');
                });
            });

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
            if (isAdrLineSlot(slotId) && Array.isArray(v)) {
                // Hybrid AdrLine: send field names in placement order
                mapping[slotId] = v.map(function (c) { return c.field; });
            } else if (Array.isArray(v)) {
                mapping[slotId] = v.map(function (c) { return decodeHtml(c.value); }).join(' ');
            } else {
                mapping[slotId] = decodeHtml(v.value);
            }
        });

        var data = await api('game/validate', {
            scenario_id: scenario.id,
            goal_type: selectedGoalType,
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
        // Party confetti on perfect round
        if (data.perfect && typeof confetti === 'function') {
            confetti({ particleCount: 100, spread: 70, origin: { y: 0.6 } });
            setTimeout(function () {
                confetti({ particleCount: 50, angle: 60, spread: 55, origin: { x: 0 } });
                confetti({ particleCount: 50, angle: 120, spread: 55, origin: { x: 1 } });
            }, 300);
        }

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
        var finalGameScore = computeGameScore(finalPct, gameElapsedSeconds);
        var mins = Math.floor(gameElapsedSeconds / 60);
        var secs = gameElapsedSeconds % 60;
        var timeStr = mins + ':' + (secs < 10 ? '0' : '') + secs;

        var html = '<section class="final-score-screen"><div class="final-score-card">';
        html += '<h1>\uD83C\uDF89 Game Over!</h1>';
        html += '<div class="final-score-value" id="animatedScore">0</div>';
        html += '<p class="final-score-detail">' + finalPct + '% accuracy &middot; ' + timeStr + '</p>';
        html += '<p class="final-score-detail">' + perfectCount + ' / ' + roundScores.length + ' perfect rounds</p>';
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

        // Animate score counter, then launch confetti
        var scoreEl = document.getElementById('animatedScore');
        animateScore(scoreEl, finalGameScore, 2000, function () {
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
        });

        document.getElementById('submitFinalScoreBtn').addEventListener('click', async function () {
            var data = await api('leaderboard/submit', {
                player_name: playerName,
                score: finalPct,
                time_seconds: gameElapsedSeconds,
            });
            if (data && data.success) {
                lastSubmittedEntry = { name: playerName, score: finalPct, time: gameElapsedSeconds, gameScore: finalGameScore };
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
        var recentEntries = data.recent || [];

        // Compute game score for all entries and sort by it
        function addGameScore(arr) {
            return arr.map(function (entry) {
                var pct = parseInt(entry.score) || 0;
                var ts = parseInt(entry.time_seconds) || 0;
                entry.gameScore = computeGameScore(pct, ts);
                return entry;
            });
        }
        entries = addGameScore(entries);
        recentEntries = addGameScore(recentEntries);
        entries.sort(function (a, b) { return b.gameScore - a.gameScore; });

        var highlightIdx = -1;
        var html = '<section class="leaderboard-screen"><h2>Hall of Fame</h2>';
        html += '<div class="leaderboard-table-wrap">';

        if (entries.length === 0 && recentEntries.length === 0) {
            html += '<p class="empty-state">No entries yet. Be the first to play!</p>';
        } else {
            html += '<table class="leaderboard-table"><thead><tr>';
            html += '<th>Rank</th><th>Player</th><th>Score</th><th>Date</th>';
            html += '</tr></thead><tbody>';
            
            // Display top 50 entries sorted by game score
            entries.forEach(function (entry, i) {
                var isMe = lastSubmittedEntry &&
                    entry.player_name === lastSubmittedEntry.name &&
                    entry.gameScore === lastSubmittedEntry.gameScore &&
                    highlightIdx === -1;
                if (isMe) highlightIdx = i;
                html += '<tr' + (isMe ? ' class="my-entry"' : '') + '><td>' + (i + 1) + '</td>';
                html += '<td>' + escapeHtml(entry.player_name) + '</td>';
                html += '<td>' + entry.gameScore + '</td>';
                html += '<td>' + formatDate(entry.created_at) + '</td></tr>';
            });

            // Display 5 most recent entries not in top 50 (below separator)
            if (recentEntries.length > 0) {
                html += '<tr class="leaderboard-separator"><td colspan="4">Recent Entries</td></tr>';
                recentEntries.forEach(function (entry) {
                    var isMe = lastSubmittedEntry &&
                        entry.player_name === lastSubmittedEntry.name &&
                        entry.gameScore === lastSubmittedEntry.gameScore &&
                        highlightIdx === -1;
                    if (isMe) highlightIdx = entries.length + recentEntries.indexOf(entry);
                    html += '<tr' + (isMe ? ' class="my-entry"' : '') + '><td>-</td>';
                    html += '<td>' + escapeHtml(entry.player_name) + '</td>';
                    html += '<td>' + entry.gameScore + '</td>';
                    html += '<td>' + formatDate(entry.created_at) + '</td></tr>';
                });
            }

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

        // Kiosk Mode
        html += '<div class="admin-section kiosk-section"><h3>Kiosk Mode</h3>';
        html += '<p>Enable fullscreen kiosk mode with screen saver for this session.</p>';
        html += '<label class="kiosk-toggle">';
        html += '<input type="checkbox" id="kioskToggle"' + (kioskMode ? ' checked' : '') + '>';
        html += '<span class="kiosk-slider"></span>';
        html += '<span class="kiosk-label">' + (kioskMode ? 'Enabled' : 'Disabled') + '</span>';
        html += '</label>';
        html += '</div>';

        // Upload section
        html += '<div class="admin-section"><h3>Upload Scenarios</h3>';
        html += '<p>Upload an Excel file (.xlsx) with scenario data.</p>';
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

        // Deadline
        html += '<div class="admin-section"><h3>Unstructured Address Deadline</h3>';
        html += '<p>Set the date/time when support for unstructured addresses will stop. A countdown is shown to players.</p>';
        html += '<div class="deadline-form">';
        html += '<input type="datetime-local" id="deadlineInput" class="deadline-input">';
        html += '<button class="btn-primary" id="setDeadlineBtn">Save Deadline</button>';
        html += '<button class="btn-secondary" id="clearDeadlineBtn">Clear</button>';
        html += '</div>';
        html += '<p id="deadlineStatus" class="deadline-status hidden"></p>';
        html += '</div>';

        // Did You Know Facts
        html += '<div class="admin-section"><h3>\uD83D\uDCA1 Did You Know — Quick Facts</h3>';
        html += '<p>Add fun facts displayed on the welcome screen. Use the buttons to format text.</p>';
        html += '<div class="fact-add-form">';
        html += factToolbarHtml('factContentInput');
        html += '<input type="text" id="factContentInput" placeholder="Enter a fun fact..." maxlength="500" class="fact-input">';
        html += '<button class="btn-primary" id="addFactBtn">Add Fact</button>';
        html += '</div>';
        html += '<div id="adminFactsList"><p>Loading facts...</p></div>';
        html += '</div>';

        // Hall of Fame management
        html += '<div class="admin-section"><h3>Hall of Fame Management</h3>';
        html += '<div id="adminLeaderboard"><p>Loading entries...</p></div>';
        html += '<div style="margin-top:1rem;"><button class="btn-danger" id="purgeBtn">Purge All Entries</button></div>';
        html += '</div>';

        html += '<button class="btn-secondary" id="adminLogoutBtn">Logout</button>';
        html += '</div></section>';

        appContainer.innerHTML = html;
        initAdminActions();
        initDropzone();
        loadAdminLeaderboard();
        loadAdminDeadline();
        loadAdminFacts();
    }

    async function loadAdminLeaderboard() {
        var container = document.getElementById('adminLeaderboard');
        if (!container) return;

        var data = await api('admin/leaderboard-entries');
        if (!data || !data.entries) {
            container.innerHTML = '<p>Could not load entries.</p>';
            return;
        }

        var entries = data.entries;
        if (entries.length === 0) {
            container.innerHTML = '<p class="empty-state">No entries yet.</p>';
            return;
        }

        var html = '<table class="leaderboard-table admin-leaderboard-table"><thead><tr>';
        html += '<th>Rank</th><th>Player</th><th>Score</th><th>Time</th><th>Date</th><th></th>';
        html += '</tr></thead><tbody>';
        entries.forEach(function (entry, i) {
            var ts = parseInt(entry.time_seconds) || 0;
            var tm = Math.floor(ts / 60);
            var tss = ts % 60;
            var timeDisplay = tm + ':' + (tss < 10 ? '0' : '') + tss;
            html += '<tr data-entry-id="' + entry.id + '">';
            html += '<td>' + (i + 1) + '</td>';
            html += '<td>' + escapeHtml(entry.player_name) + '</td>';
            html += '<td>' + entry.score + '%</td>';
            html += '<td>' + timeDisplay + '</td>';
            html += '<td>' + formatDate(entry.created_at) + '</td>';
            html += '<td><button class="btn-delete-entry" data-id="' + entry.id + '" title="Delete">&times;</button></td>';
            html += '</tr>';
        });
        html += '</tbody></table>';
        container.innerHTML = html;

        container.querySelectorAll('.btn-delete-entry').forEach(function (btn) {
            btn.addEventListener('click', async function () {
                var id = parseInt(this.dataset.id);
                var confirmed = await showConfirm('Delete this entry?');
                if (!confirmed) return;
                var resp = await api('admin/delete-entry', { id: id });
                if (resp && resp.success) {
                    var row = container.querySelector('tr[data-entry-id="' + id + '"]');
                    if (row) row.remove();
                } else {
                    await showModal(resp ? resp.error : 'Error deleting entry');
                }
            });
        });
    }

    function factToolbarHtml(inputId) {
        return '<div class="fact-toolbar">'
            + '<button type="button" class="fact-fmt-btn" data-fmt="bold" data-input="' + inputId + '" title="Bold"><b>B</b></button>'
            + '<button type="button" class="fact-fmt-btn" data-fmt="italic" data-input="' + inputId + '" title="Italic"><i>I</i></button>'
            + '<button type="button" class="fact-fmt-btn" data-fmt="link" data-input="' + inputId + '" title="Link">\uD83D\uDD17</button>'
            + '</div>';
    }

    function initFactToolbar(container) {
        container.querySelectorAll('.fact-fmt-btn').forEach(function (btn) {
            btn.addEventListener('click', function (e) {
                e.preventDefault();
                var inputId = this.dataset.input;
                var fmt = this.dataset.fmt;
                var input = document.getElementById(inputId);
                if (!input) return;
                var start = input.selectionStart;
                var end = input.selectionEnd;
                var text = input.value;
                var selected = text.substring(start, end);
                var replacement = '';
                if (fmt === 'bold') {
                    replacement = '<b>' + selected + '</b>';
                } else if (fmt === 'italic') {
                    replacement = '<i>' + selected + '</i>';
                } else if (fmt === 'link') {
                    var url = prompt('Enter URL:', 'https://');
                    if (!url) return;
                    replacement = '<a href="' + url + '">' + (selected || url) + '</a>';
                }
                input.value = text.substring(0, start) + replacement + text.substring(end);
                input.focus();
                var cursorPos = start + replacement.length;
                input.setSelectionRange(cursorPos, cursorPos);
            });
        });
    }

    async function loadAdminDeadline() {
        var data = await api('admin/get-deadline');
        if (data && data.deadline) {
            document.getElementById('deadlineInput').value = data.deadline;
            var status = document.getElementById('deadlineStatus');
            status.textContent = 'Current deadline: ' + new Date(data.deadline).toLocaleString();
            status.classList.remove('hidden');
        }
    }

    async function loadAdminFacts() {
        var container = document.getElementById('adminFactsList');
        if (!container) return;

        var data = await api('admin/get-facts');
        if (!data || !data.facts) {
            container.innerHTML = '<p>Could not load facts.</p>';
            return;
        }

        if (data.facts.length === 0) {
            container.innerHTML = '<p class="empty-state">No facts yet. Add one above!</p>';
            return;
        }

        var html = '<ul class="facts-list">';
        data.facts.forEach(function (fact) {
            html += '<li class="fact-item" data-fact-id="' + fact.id + '">';
            html += '<div class="fact-content-display" id="factDisplay' + fact.id + '">' + fact.content + '</div>';
            html += '<div class="fact-actions">';
            html += '<button class="btn-edit-fact" data-id="' + fact.id + '" title="Edit">Edit</button>';
            html += '<button class="btn-delete-fact" data-id="' + fact.id + '" title="Delete">Del</button>';
            html += '</div>';
            html += '</li>';
        });
        html += '</ul>';
        container.innerHTML = html;

        container.querySelectorAll('.btn-delete-fact').forEach(function (btn) {
            btn.addEventListener('click', async function () {
                var id = parseInt(this.dataset.id);
                var confirmed = await showConfirm('Delete this fact?');
                if (!confirmed) return;
                var resp = await api('admin/delete-fact', { id: id });
                if (resp && resp.success) {
                    loadAdminFacts();
                } else {
                    await showModal(resp ? resp.error : 'Error deleting fact');
                }
            });
        });

        container.querySelectorAll('.btn-edit-fact').forEach(function (btn) {
            btn.addEventListener('click', function () {
                var id = parseInt(this.dataset.id);
                var display = document.getElementById('factDisplay' + id);
                if (!display) return;
                var currentContent = display.innerHTML;
                var li = display.closest('.fact-item');
                li.innerHTML = '<div class="fact-edit-form">'
                    + factToolbarHtml('factEditInput' + id)
                    + '<input type="text" class="fact-input" id="factEditInput' + id + '" maxlength="500" value="' + escapeHtml(currentContent) + '">'
                    + '<div class="fact-edit-actions">'
                    + '<button class="btn-primary btn-save-fact" data-id="' + id + '">Save</button>'
                    + '<button class="btn-secondary btn-cancel-edit">Cancel</button>'
                    + '</div></div>';
                initFactToolbar(li);
                li.querySelector('.btn-save-fact').addEventListener('click', async function () {
                    var newContent = document.getElementById('factEditInput' + id).value.trim();
                    if (!newContent || newContent.length > 500) { await showModal('Fact must be 1-500 characters'); return; }
                    var resp = await api('admin/update-fact', { id: id, content: newContent });
                    if (resp && resp.success) {
                        loadAdminFacts();
                    } else {
                        await showModal(resp ? resp.error : 'Error updating fact');
                    }
                });
                li.querySelector('.btn-cancel-edit').addEventListener('click', function () {
                    loadAdminFacts();
                });
            });
        });
    }

    function initAdminActions() {
        document.getElementById('kioskToggle').addEventListener('change', function () {
            var label = this.parentElement.querySelector('.kiosk-label');
            if (this.checked) {
                enableKioskMode();
                if (label) label.textContent = 'Enabled';
            } else {
                disableKioskMode();
                if (label) label.textContent = 'Disabled';
            }
        });

        document.getElementById('changePinBtn').addEventListener('click', async function () {
            var newPin = document.getElementById('newPinInput').value;
            if (!/^\d{4,8}$/.test(newPin)) {
                await showModal('PIN must be 4-8 digits');
                return;
            }
            var data = await api('admin/change-pin', { new_pin: newPin });
            if (data && data.success) {
                await showModal('PIN updated successfully');
                document.getElementById('newPinInput').value = '';
            } else {
                await showModal(data ? data.error : 'Error');
            }
        });

        document.getElementById('setDeadlineBtn').addEventListener('click', async function () {
            var val = document.getElementById('deadlineInput').value;
            if (!val) { await showModal('Please select a date and time.'); return; }
            var data = await api('admin/set-deadline', { deadline: val });
            if (data && data.success) {
                var status = document.getElementById('deadlineStatus');
                status.textContent = 'Deadline saved: ' + new Date(val).toLocaleString();
                status.classList.remove('hidden');
                await showModal('Deadline saved successfully');
            } else {
                await showModal(data ? data.error : 'Error saving deadline');
            }
        });

        document.getElementById('clearDeadlineBtn').addEventListener('click', async function () {
            var data = await api('admin/set-deadline', { deadline: '' });
            if (data && data.success) {
                document.getElementById('deadlineInput').value = '';
                var status = document.getElementById('deadlineStatus');
                status.textContent = 'Deadline cleared';
                status.classList.remove('hidden');
                await showModal('Deadline cleared');
            }
        });

        document.getElementById('purgeBtn').addEventListener('click', async function () {
            var confirmed = await showConfirm('Are you sure? This cannot be undone.');
            if (!confirmed) return;
            var data = await api('admin/purge-leaderboard');
            if (data && data.success) {
                await showModal('Leaderboard purged');
                loadAdminLeaderboard();
            }
        });

        // Init formatting toolbar for add-fact input
        initFactToolbar(document.querySelector('.fact-add-form'));

        document.getElementById('addFactBtn').addEventListener('click', async function () {
            var input = document.getElementById('factContentInput');
            var content = input.value.trim();
            if (!content || content.length > 500) {
                await showModal('Fact must be 1-500 characters');
                return;
            }
            var data = await api('admin/add-fact', { content: content });
            if (data && data.success) {
                input.value = '';
                loadAdminFacts();
            } else {
                await showModal(data ? data.error : 'Error adding fact');
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
            headers: { 'X-Action': 'admin/upload', 'X-CSRF-Token': csrfToken },
            dictDefaultMessage: 'Drop .xlsx file here or tap to browse',
            init: function () {
                this.on('success', function (file, response) {
                    var status = document.getElementById('uploadStatus');
                    if (response.success) {
                        status.textContent = 'Imported ' + response.imported.scenarios + ' scenarios.';
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
        html += '<tr><td>Player name</td><td>Display on Hall of Fame leaderboard</td><td>Encrypted at rest (AES-256-GCM)</td><td>30 days, then automatically deleted</td></tr>';
        html += '<tr><td>Game score &amp; time</td><td>Leaderboard ranking</td><td>Database (not personal data)</td><td>30 days</td></tr>';
        html += '<tr><td>Session cookie (PHPSESSID)</td><td>CSRF protection &amp; admin authentication</td><td>Server-side; cookie contains only a random session ID</td><td>Browser session (deleted on close)</td></tr>';
        html += '</tbody></table>';
        html += '<p>No other personal data (e-mail, IP address, device fingerprint, location, etc.) is collected, stored, or processed. The session cookie is a strictly necessary technical cookie and does not require consent under GDPR (Recital 30, ePrivacy Directive Art. 5(3) exemption).</p>';

        html += '<h3>4. Data Minimisation (Art. 5(1)(c))</h3>';
        html += '<p>This application strictly follows the principle of data minimisation. Only the player name is collected &mdash; and only when the player voluntarily submits it. ';
        html += 'No personal data is required to play the game. The game can be played without submitting any personal information.</p>';

        html += '<h3>5. Cookies, Tracking &amp; Analytics</h3>';
        html += '<p>This application:</p>';
        html += '<ul>';
        html += '<li>Uses a single <strong>strictly necessary</strong> session cookie (PHPSESSID) for security (CSRF protection). This cookie contains no personal data and is deleted when the browser is closed.</li>';
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
        html += '<li><strong>Encryption at rest</strong> &mdash; Player names are encrypted using AES-256-GCM (authenticated encryption) with a unique initialisation vector (IV) per entry before storage. The encryption key is stored separately from the database.</li>';
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
        var ta = document.createElement('textarea');
        ta.textContent = str || '';
        return ta.value;
    }

    function formatDate(dateStr) {
        if (!dateStr) return '';
        var d = new Date(dateStr);
        return d.toLocaleDateString('en-GB', { day: '2-digit', month: 'short', year: 'numeric' });
    }

    /**
     * Custom overlay modal to replace window.alert() — stays in fullscreen.
     */
    function showModal(message) {
        return new Promise(function (resolve) {
            var overlay = document.createElement('div');
            overlay.className = 'overlay';
            overlay.innerHTML =
                '<div class="overlay-content">' +
                '<p style="margin-bottom:1.5rem;font-size:1.05rem;">' + escapeHtml(message) + '</p>' +
                '<button class="btn-primary" id="modalOkBtn">OK</button>' +
                '</div>';
            document.body.appendChild(overlay);
            document.getElementById('modalOkBtn').addEventListener('click', function () {
                overlay.remove();
                resolve();
            });
        });
    }

    /**
     * Custom overlay confirm dialog to replace window.confirm() — stays in fullscreen.
     * Returns a Promise that resolves to true (confirm) or false (cancel).
     */
    function showConfirm(message) {
        return new Promise(function (resolve) {
            var overlay = document.createElement('div');
            overlay.className = 'overlay';
            overlay.innerHTML =
                '<div class="overlay-content">' +
                '<p style="margin-bottom:1.5rem;font-size:1.05rem;">' + escapeHtml(message) + '</p>' +
                '<div style="display:flex;gap:0.75rem;justify-content:center;">' +
                '<button class="btn-secondary" id="confirmCancelBtn">Cancel</button>' +
                '<button class="btn-danger" id="confirmOkBtn">Confirm</button>' +
                '</div></div>';
            document.body.appendChild(overlay);
            document.getElementById('confirmOkBtn').addEventListener('click', function () {
                overlay.remove();
                resolve(true);
            });
            document.getElementById('confirmCancelBtn').addEventListener('click', function () {
                overlay.remove();
                resolve(false);
            });
        });
    }

    /* =======================================================
       Kiosk Mode
       ======================================================= */
    function exitFullscreen() {
        if (document.fullscreenElement) {
            document.exitFullscreen().catch(function(){});
        } else if (document.webkitFullscreenElement) {
            document.webkitExitFullscreen();
        }
    }

    function enableKioskMode() {
        kioskMode = true;
        enterFullscreen();
        document.addEventListener('fullscreenchange', onFullscreenChange);
        document.addEventListener('webkitfullscreenchange', onFullscreenChange);
        resetScreenSaverTimer();
    }

    function disableKioskMode() {
        kioskMode = false;
        document.removeEventListener('fullscreenchange', onFullscreenChange);
        document.removeEventListener('webkitfullscreenchange', onFullscreenChange);
        exitFullscreen();
        stopScreenSaver();
    }

    function onFullscreenChange() {
        if (kioskMode && !document.fullscreenElement && !document.webkitFullscreenElement) {
            setTimeout(function () {
                if (kioskMode) enterFullscreen();
            }, 300);
        }
    }

    /* =======================================================
       Screen Saver (Kiosk mode only)
       ======================================================= */
    var hasTouchScreen = ('ontouchstart' in window) || (navigator.maxTouchPoints > 0);

    function resetScreenSaverTimer() {
        clearTimeout(screenSaverTimer);
        if (kioskMode && !gameActive) {
            screenSaverTimer = setTimeout(showScreenSaver, SCREENSAVER_TIMEOUT);
        }
    }

    function stopScreenSaver() {
        clearTimeout(screenSaverTimer);
        screenSaverTimer = null;
        dismissScreenSaver();
    }

    function showScreenSaver() {
        if (!kioskMode || screenSaverActive) return;
        screenSaverActive = true;

        var overlay = document.getElementById('screenSaverOverlay');
        if (!overlay) {
            overlay = document.createElement('div');
            overlay.id = 'screenSaverOverlay';
            overlay.className = 'screen-saver-overlay';
            document.body.appendChild(overlay);
        }

        var actionWord = hasTouchScreen ? 'Touch' : 'Click';

        overlay.innerHTML = '<div class="screen-saver-inner">'
            + '<div id="ssCountdown" class="ss-countdown"></div>'
            + '<h1 class="ss-cta">' + actionWord + ' to play the<br>ISO 20022 Address Game</h1>'
            + '<div id="ssFactDisplay" class="ss-fact"></div>'
            + '</div>';
        overlay.classList.add('visible');

        // Start countdown in screen saver
        (async function () {
            var data = await api('game/deadline', {});
            if (data && data.deadline) {
                var banner = document.getElementById('ssCountdown');
                if (!banner) return;
                var target = new Date(data.deadline);
                updateCountdown(target, banner);
                overlay._ssCountdownInterval = setInterval(function () {
                    updateCountdown(target, banner);
                }, 1000);
            }
        })();

        // Start fact rotation in screen saver
        var factEl = document.getElementById('ssFactDisplay');
        if (factEl && factsCache.length > 0) {
            renderFactInto(factEl);
            screenSaverFactInterval = setInterval(function () {
                factEl.style.opacity = '0';
                setTimeout(function () {
                    renderFactInto(factEl);
                    factEl.style.opacity = '1';
                }, 400);
            }, FACT_ROTATION_INTERVAL);
        }

        overlay.addEventListener('click', dismissScreenSaver, { once: true });
        overlay.addEventListener('touchstart', dismissScreenSaver, { once: true });
    }

    function dismissScreenSaver() {
        if (!screenSaverActive) return;
        screenSaverActive = false;
        var overlay = document.getElementById('screenSaverOverlay');
        if (overlay) {
            if (overlay._ssCountdownInterval) {
                clearInterval(overlay._ssCountdownInterval);
                overlay._ssCountdownInterval = null;
            }
            if (screenSaverFactInterval) {
                clearInterval(screenSaverFactInterval);
                screenSaverFactInterval = null;
            }
            overlay.classList.remove('visible');
        }
        resetScreenSaverTimer();
    }

    // Reset screen saver timer on any user activity
    ['touchstart', 'mousedown', 'keydown'].forEach(function (evt) {
        document.addEventListener(evt, function () {
            if (kioskMode && !screenSaverActive) {
                resetScreenSaverTimer();
            }
        }, { passive: true });
    });

    /* =======================================================
       Init
       ======================================================= */
    showScreen('game');

})();
