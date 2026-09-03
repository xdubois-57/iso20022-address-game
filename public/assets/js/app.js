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

import { computeGameScore } from './lib/scoring.js';
import { formatAddressForDisplay, isAdrLineSlot } from './lib/address.js';
import { escapeHtml, decodeHtml, formatDate, stripLinks, countdownParts } from './lib/format.js';
import { setSanitizedHtml } from './lib/sanitize.js';
import { randomIndex } from './lib/random.js';
import { createApi } from './lib/api.js';
import {
    createArrivalTracker,
    createBannerQueue,
    diffArrivals,
    enqueueBanners,
    nextBanner,
    releaseBanner,
    backoffDelay,
    boardNumber,
    resolveDisplayData,
    rowsThatFit,
} from './lib/board.js';

(function () {
    'use strict';

    /* =======================================================
       Constants
       ======================================================= */
    const INACTIVITY_TIMEOUT = 30000; // 30s
    const COUNTDOWN_SECONDS = 10;
    const API_URL = 'index.php';

    /**
     * The PMPG lockup, served locally so the CSP (`img-src 'self' data:`)
     * needs no widening for it.
     *
     * The `?v=` is pinned by hand rather than derived from the file's mtime:
     * assetUrl() in layout.php can stat a file because it runs in PHP, and
     * these strings are built in the browser where nothing can. A trademark
     * lockup does not change, so a constant is the honest mechanism — bump
     * this literal on the day the asset is ever replaced.
     */
    // Versioned by the server (layout.php), so replacing the logo file
    // actually reaches a browser that already has the old one. The literal
    // fallback only applies if the meta tag is missing, which no served page
    // does — an unversioned URL beats a broken image.
    const PMPG_LOGO_SRC =
        document.querySelector('meta[name="pmpg-logo-url"]')?.content
        || 'assets/images/pmpg-logo.png';

    /* =======================================================
       State
       ======================================================= */
    const TOTAL_ROUNDS = 5;
    let scenario = null;
    let slotMapping = {};
    let inactivityTimer = null;
    let countdownInterval = null;
    let countdownValue = COUNTDOWN_SECONDS;
    let adminPin = '';

    // Multi-round game state
    let playerName = '';
    let currentRound = 0;
    let roundScores = [];
    let gameActive = false;
    let gameTimerInterval = null;
    let gameElapsedSeconds = 0;
    let playedScenarioIds = [];
    let lastSubmittedEntryId = null;
    let lastSubmittedPage = null;
    let selectedGoalType = 'Structured';
    let touchDragChip = null;
    let touchDragClone = null;
    var factsCache = [];
    var factRotationInterval = null;
    var currentFactIndex = -1;
    const FACT_ROTATION_INTERVAL = 20000;
    var kioskMode = false;

    /**
     * The deployment context, decided by the server from ?mode and written
     * onto <body data-mode>: '' (mobile, desktop, iPad kiosk), 'hof' (the
     * Hall of Fame wall) or 'play' (the standing play station).
     *
     * Read once, here, and never recomputed: the mode of a screen cannot
     * change without a navigation, and re-reading it elsewhere would invite
     * two parts of the app to disagree about which context they are in.
     *
     * Distinct from kioskMode, which is a session flag toggled from the Admin
     * screen on the current device. A URL survives the reboot that a session
     * flag does not — which is the whole reason this exists.
     */
    var displayMode = document.body.dataset.mode || '';

    /**
     * Whether this installation offers sharing, decided by the server from
     * the `sharing_enabled` setting and written onto <body data-sharing>.
     *
     * Read once here, like displayMode, and for the same reason. The
     * attribute is ABSENT unless sharing has been switched off, so the
     * default reads as enabled and a default installation is untouched.
     *
     * What this governs is what gets RENDERED. It is not an access control
     * and must never be described as one: /share, /share/go, /share/image,
     * /share/home-image and the share/token action all keep answering, so a
     * link a player already posted goes on working and the site's own
     * OpenGraph preview goes on being generated.
     *
     * Orthogonal to the play station's own refusal to share. ?mode=play
     * blocks the whole share path for a different reason — navigator.share
     * opens an OS sheet on top of a locked-down kiosk — and it does so
     * whatever this flag says.
     */
    var sharingEnabled = document.body.dataset.sharing !== 'off';

    var screenSaverTimer = null;
    var screenSaverActive = false;
    var screenSaverFactInterval = null;
    const SCREENSAVER_TIMEOUT = 60000;

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
    // Null on a dedicated screen: layout.php omits the nav and the hamburger
    // outright for ?mode=hof and ?mode=play rather than hiding them, so every
    // use below has to tolerate their absence.
    const stopBtn = document.getElementById('stopBtn');
    const hamburgerBtn = document.getElementById('hamburgerBtn');
    const headerNav = document.getElementById('headerNav');

    /* =======================================================
       Confetti (iOS Safari compatible)
       Bind to a pre-existing canvas to avoid position:fixed
       clipping caused by overflow-x:hidden on body and the
       background-attachment:fixed GPU compositing layer.
       ======================================================= */
    var boundConfetti = null;
    (function () {
        if (typeof confetti !== 'function') return;
        var canvas = document.getElementById('confettiCanvas');
        if (!canvas) return;
        canvas.width = window.innerWidth;
        canvas.height = window.innerHeight;
        window.addEventListener('resize', function () {
            canvas.width = window.innerWidth;
            canvas.height = window.innerHeight;
        });
        boundConfetti = confetti.create(canvas, {
            resize: true,
            useWorker: false,
            disableForReducedMotion: true,
        });
    }());

    /* =======================================================
       API Helper
       ======================================================= */
    var csrfToken = document.querySelector('meta[name="csrf-token"]')?.content || '';

    const api = createApi({ apiUrl: API_URL, getCsrfToken: function () { return csrfToken; } });

    /* =======================================================
       Fullscreen
       ======================================================= */
    var isStandalone = (window.navigator.standalone === true);

    function enterFullscreen() {
        if (isStandalone) return; // Already fullscreen in iOS standalone (home screen web app)
        var el = document.documentElement;
        if (document.fullscreenElement) return;
        if (el.requestFullscreen) el.requestFullscreen().catch(function(){});
        else if (el.webkitRequestFullscreen) el.webkitRequestFullscreen();
    }

    // Hoisted out of the function that used to nest it: neither closes
    // over any local state, so redefining findComponentPosition() on every
    // call bought nothing.
    function findComponentPosition(haystack, needle) {
        if (!needle) return -1;

        var escaped = needle.replace(/[.*+?^${}()|[\]\\]/g, String.raw`\$&`);
        // Boundaries are "not a letter, digit or dash" rather than \b, which
        // mishandles accented characters and values that start or end with
        // punctuation.
        var pattern = new RegExp(String.raw`(^|[^\p{L}\p{N}-])` + escaped + String.raw`($|[^\p{L}\p{N}-])`, 'u');
        var match = pattern.exec(haystack);
        if (match) {
            return match.index + match[1].length;
        }

        // Fall back to a loose match so a component the formatter rendered
        // differently still lands somewhere sensible rather than at the end.
        return haystack.indexOf(needle);
    }

    // Hoisted out of the function that used to nest it: neither closes
    // over any local state, so redefining isoWeekToDate() on every
    // call bought nothing.
    function isoWeekToDate(isoWeek) {
        // isoWeek: "YYYY-Www"
        var parts = isoWeek.split('-W');
        if (parts.length !== 2) return null;
        var year = Number.parseInt(parts[0], 10);
        var week = Number.parseInt(parts[1], 10);
        // ISO week 1 contains the first Thursday of the year; calculate Monday of that week
        var jan4 = new Date(Date.UTC(year, 0, 4));
        var dayOfWeek = jan4.getUTCDay() || 7; // Mon=1..Sun=7
        var monday = new Date(jan4);
        monday.setUTCDate(jan4.getUTCDate() - (dayOfWeek - 1) + (week - 1) * 7);
        return monday;
    }

    /* =======================================================
       Screen Router
       ======================================================= */
    /**
     * Which screen the SPA is currently showing, as a number that changes on
     * every navigation.
     *
     * An async render writes appContainer AFTER awaiting the network. If the
     * player navigated away in the meantime, that write lands on top of the
     * screen they actually chose — the Hall of Fame reappearing over the admin
     * panel a second after it was opened. Fast enough locally that it never
     * showed; through a proxy, or on a slow connection, it is reproducible.
     *
     * Each async render captures this value before its first await and checks
     * it afterwards through screenIsStale(). Cheaper and far easier to reason
     * about than cancelling requests: the response still arrives, it simply is
     * not allowed to paint over a screen it no longer belongs to.
     */
    var screenGeneration = 0;

    /** True once the caller's screen has been navigated away from. */
    function screenIsStale(generation) {
        return generation !== screenGeneration;
    }

    function showScreen(name) {
        // The wall renders one thing and never navigates. Nothing on that
        // screen calls this — there is no nav and no handler — but a future
        // caller reaching it by accident would replace the board with a
        // welcome card on a display nobody is watching.
        if (displayMode === 'hof') return;

        // Every navigation invalidates any render still waiting on the
        // network, so its late write is dropped rather than painted over
        // whatever this call is about to draw.
        screenGeneration++;

        // Any navigation away from the play station's result screen cancels
        // its hand-back timer. Without this, a player who taps Play again
        // would be thrown back to the welcome card mid-game by a timer left
        // running from the previous round.
        stopPlayReturn();

        window.scrollTo(0, 0);
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

    if (stopBtn) {
        stopBtn.addEventListener('click', function () {
            closeHamburger();
            resetSession();
        });
    }

    /* =======================================================
       Hamburger Menu (mobile)
       ======================================================= */
    function closeHamburger() {
        if (!hamburgerBtn || !headerNav) return;
        hamburgerBtn.classList.remove('open');
        hamburgerBtn.setAttribute('aria-expanded', 'false');
        headerNav.classList.remove('open');
    }

    if (hamburgerBtn && headerNav) {
        hamburgerBtn.addEventListener('click', function () {
            var isOpen = headerNav.classList.toggle('open');
            hamburgerBtn.classList.toggle('open', isOpen);
            hamburgerBtn.setAttribute('aria-expanded', String(isOpen));
        });
    }

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
        currentRound = 0;
        roundScores = [];
        playedScenarioIds = [];
        playerName = '';
        lastSubmittedEntryId = null;
        lastSubmittedPage = null;
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

    /**
     * Fetch the deadline and tick the banner once a second.
     *
     * This body used to be duplicated verbatim across two welcome screens.
     * It also leaked: the interval was assigned inside an async callback
     * that resolved after the synchronous stopDeadlineCountdown() had already
     * run, so every re-render — and a kiosk re-renders on each inactivity reset
     * — stranded another 1 Hz timer writing to a detached node. Clearing
     * immediately before assigning, and bailing out if the banner has since been
     * replaced, keeps exactly one timer alive.
     */
    function startDeadlineCountdown() {
        (async function () {
            var data = await api('game/deadline', {});
            if (!data?.deadline) return;

            var banner = document.getElementById('countdownBanner');
            if (!banner) return;

            banner.className = 'countdown-banner';
            var target = new Date(data.deadline);
            updateCountdown(target, banner);

            stopDeadlineCountdown();
            deadlineCountdownInterval = setInterval(function () {
                // The screen may have been swapped out while we were awaiting.
                if (!document.body.contains(banner)) {
                    stopDeadlineCountdown();
                    return;
                }
                updateCountdown(target, banner);
            }, 1000);
        })();
    }

    function updateCountdown(targetDate, el) {
        var parts = countdownParts(targetDate, new Date());
        if (parts.expired) {
            el.innerHTML = '<div class="countdown-label">Support for unstructured addresses has ended</div>'
                + '<div class="countdown-expired">Deadline reached</div>';
            stopDeadlineCountdown();
            return;
        }

        el.innerHTML = '<div class="countdown-label">Unstructured address support ends in</div>'
            + '<div class="countdown-timer">'
            + '<span class="countdown-unit">' + parts.days + '</span><span class="countdown-suffix">d</span>'
            + '<span class="countdown-sep">:</span>'
            + '<span class="countdown-unit">' + parts.hours + '</span><span class="countdown-suffix">h</span>'
            + '<span class="countdown-sep">:</span>'
            + '<span class="countdown-unit">' + parts.minutes + '</span><span class="countdown-suffix">m</span>'
            + '<span class="countdown-sep">:</span>'
            + '<span class="countdown-unit">' + parts.seconds + '</span><span class="countdown-suffix">s</span>'
            + '</div>';
    }

    function stopFactRotation() {
        if (factRotationInterval) { clearInterval(factRotationInterval); factRotationInterval = null; }
    }

    function nextFact() {
        if (factsCache.length === 0) return null;
        if (currentFactIndex < 0) {
            currentFactIndex = randomIndex(factsCache.length);
        } else {
            currentFactIndex = (currentFactIndex + 1) % factsCache.length;
        }
        return factsCache[currentFactIndex];
    }

    function renderFactInto(el) {
        var fact = nextFact();
        if (!fact) { el.replaceChildren(); return; }

        // The heading is ours, so it is built as a real element; only the
        // fact body is untrusted, and it goes through the same allowlist the
        // server applies (lib/sanitize.js) instead of into innerHTML.
        var heading = document.createElement('h2');
        heading.textContent = 'Did you know?';
        var body = document.createElement('p');
        setSanitizedHtml(body, kioskMode ? stripLinks(fact.content) : fact.content);

        el.replaceChildren(heading, body);
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

        renderWelcomeCard();
    }

    /**
     * The PMPG endorsement block that closes the .welcome-card.
     *
     * Wholly static markup — no interpolation, so no escapeHtml() call to
     * make here. Anything dynamic added alongside it still needs one.
     *
     * The logo is deliberately not a link. A kiosk runs in Guided Access,
     * where an outbound navigation strands the player in a browser they
     * cannot leave.
     *
     * The alt text is not decorative, and it carries more weight now that the
     * visible "Supported by" label has been dropped: the lockup alone is the
     * whole statement, so a screen reader has to announce it rather than skip
     * it.
     */
    function endorsementHtml() {
        return '<div class="card-endorsement">'
            + '<img src="' + PMPG_LOGO_SRC + '" alt="Payments Market Practice Group" '
            + 'width="1095" height="282">'
            + '</div>';
    }

    function renderWelcomeCard() {
        var html = '<section class="game-welcome">';
        html += '<div id="countdownBanner"></div>';
        html += '<div class="welcome-card">';
        html += '<h2>ISO 20022 Address Game</h2>';
        html += '<p>Structure <strong>' + TOTAL_ROUNDS + ' addresses</strong> into ISO 20022 format as fast as you can!</p>';
        html += '<input type="text" id="welcomeNameInput" placeholder="Enter your name to start" maxlength="50" class="name-input"';
        if (playerName) html += ' value="' + escapeHtml(playerName) + '"';
        html += '>';
        html += '<button class="btn-primary btn-start" id="startGameBtn">Start Game</button>';
        // Below the button, which puts it below the spot the profanity
        // warning is inserted into as well — see renderTouchKeyboard().
        if (displayMode === 'play') html += touchKeyboardHtml();
        html += endorsementHtml();
        html += '</div>';
        html += '<div id="welcomeFactDisplay" class="fact-display-card"></div>';
        html += '</section>';
        appContainer.innerHTML = html;

        startDeadlineCountdown();

        // Fetch facts and start rotation
        (async function () {
            var data = await api('game/facts', {});
            if (data?.facts) {
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
            if (!playerName) { nameInput.style.borderColor = 'var(--game-danger)'; nameInput.focus(); return; }
            // Check name for profanity
            var check = await api('game/check-name', { name: playerName });
            if (!check) return;
            if (!check.allowed) {
                nameInput.style.borderColor = 'var(--game-danger)';
                var warn = document.createElement('p');
                warn.className = 'profanity-warning';
                warn.textContent = check.message || 'Please choose a different name.';
                var existing = document.querySelector('.profanity-warning');
                if (existing) existing.remove();
                nameInput.parentNode.insertBefore(warn, nameInput.nextSibling);
                nameInput.value = '';
                playerName = '';
                nameInput.focus();
                // On the play station the on-screen keyboard sits below all
                // of this and makes the card tall. The refusal has to be
                // READ, or the player simply types the same name again — so
                // make sure it is actually on screen.
                if (displayMode === 'play') {
                    try { warn.scrollIntoView({ block: 'center', behavior: 'smooth' }); }
                    catch (e) { warn.scrollIntoView(false); }
                }
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

        if (displayMode === 'play') bindTouchKeyboard(nameInput);
    }

    /* =======================================================
       On-screen keyboard (?mode=play only)

       Not a convenience. Windows only offers its own touch keyboard when it
       detects NO physical keyboard, and the play station has one — plugged
       in, tucked out of sight. Windows therefore concludes the user can type
       and shows nothing, so without this component the field stays empty no
       matter what a player taps, and there is no way to start a game.

       Only in play mode. A phone and an iPad both raise a perfectly good
       system keyboard of their own; putting this one in front of it would be
       a regression for the three contexts that already work.
       ======================================================= */

    /**
     * QWERTY, because the event is in Miami, the audience international and
     * the game in English. For the handful of letters in a first name,
     * recognising the layout at a glance beats every other consideration.
     */
    const TOUCH_KEY_ROWS = [
        ['Q', 'W', 'E', 'R', 'T', 'Y', 'U', 'I', 'O', 'P'],
        ['A', 'S', 'D', 'F', 'G', 'H', 'J', 'K', 'L', "'"],
        ['Z', 'X', 'C', 'V', 'B', 'N', 'M', '-'],
    ];

    /**
     * The accented characters, which are not a nicety either.
     *
     * A payments standards forum fills a room with Scandinavian, Irish,
     * German, French, Portuguese and Spanish names. Without these keys every
     * one of them goes up on the wall misspelt, in front of the person whose
     * name it is.
     */
    const TOUCH_ACCENT_ROWS = [
        ['Á', 'À', 'Â', 'É', 'È', 'Ê', 'Í', 'Ó', 'Ô', 'Ú'],
        ['Ü', 'Ö', 'Ä', 'Ñ', 'Ç', 'Ø', 'Å', 'Æ'],
    ];

    function touchKeyHtml(label, action, value, extraClass) {
        return '<button type="button" class="touch-key' + (extraClass ? ' ' + extraClass : '')
            + '" data-key-action="' + action + '"'
            + (value === null ? '' : ' data-key-value="' + escapeHtml(value) + '"')
            + '>' + escapeHtml(label) + '</button>';
    }

    function touchKeyboardHtml() {
        var html = '<div class="touch-keyboard" id="touchKeyboard">';

        TOUCH_KEY_ROWS.concat(TOUCH_ACCENT_ROWS).forEach(function (row, i) {
            html += '<div class="touch-key-row' + (i >= TOUCH_KEY_ROWS.length ? ' touch-key-row-accents' : '') + '">';
            row.forEach(function (label) {
                html += touchKeyHtml(label, 'char', label, null);
            });
            html += '</div>';
        });

        html += '<div class="touch-key-row">';
        html += touchKeyHtml('space', 'char', ' ', 'touch-key-wide');
        html += touchKeyHtml('⌫', 'backspace', null, null);
        html += touchKeyHtml('clear', 'clear', null, null);
        html += touchKeyHtml('Start', 'start', null, 'touch-key-go');
        html += '</div>';

        return html + '</div>';
    }

    /**
     * Should the next character be a capital?
     *
     * Every key shows an uppercase letter, as keyboards do, but inserting
     * uppercase for all of them would put RAFAEL COSTA on the wall. So the
     * first letter of each word is capitalised and the rest are not, which is
     * what a phone keyboard does and what produces a name that looks like a
     * name. It gets "van Dijk" wrong; all-caps gets every name wrong.
     */
    function startsAWord(value) {
        return value === '' || /[\s'-]$/.test(value);
    }

    /**
     * Wire the keys to the REAL input rather than to a state of their own.
     *
     * Writing into the field itself is what lets the existing validation, the
     * maxlength and the profanity check keep working untouched — none of them
     * need to know this keyboard exists. maxLength is the one thing that has
     * to be enforced by hand, because the attribute only constrains typing,
     * not assignment.
     */
    function bindTouchKeyboard(nameInput) {
        var keyboard = document.getElementById('touchKeyboard');
        if (!keyboard || !nameInput) return;

        function insert(text) {
            var max = Number.parseInt(nameInput.getAttribute('maxlength'), 10) || 50;
            var next = nameInput.value + text;
            if (next.length > max) return;
            nameInput.value = next;
            // The border turns red on an empty submit; typing should take
            // that back, exactly as it does with a physical keyboard.
            nameInput.style.borderColor = '';
        }

        keyboard.querySelectorAll('.touch-key').forEach(function (key) {
            // The caret must stay in the field. A button takes focus on
            // press, which would move it away and leave the player unable to
            // see where their next character is going.
            key.addEventListener('mousedown', function (e) { e.preventDefault(); });

            key.addEventListener('click', function () {
                var action = this.dataset.keyAction;

                if (action === 'char') {
                    var ch = this.dataset.keyValue;
                    insert(startsAWord(nameInput.value) ? ch.toUpperCase() : ch.toLowerCase());
                } else if (action === 'backspace') {
                    nameInput.value = nameInput.value.slice(0, -1);
                } else if (action === 'clear') {
                    nameInput.value = '';
                } else if (action === 'start') {
                    document.getElementById('startGameBtn').click();
                    return;
                }

                nameInput.focus();
            });
        });
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
        // Same hazard as renderLeaderboardScreen: this writes appContainer
        // after awaiting the scenario, so a player who leaves the game while a
        // round is loading would be dragged back into it a moment later.
        var generation = screenGeneration;

        currentRound++;
        if (currentRound > TOTAL_ROUNDS) { showFinalScore(); return; }
        resetInactivityTimer();

        appContainer.innerHTML = '<p class="screen-notice">Loading round ' +
            currentRound + ' / ' + TOTAL_ROUNDS + '...</p>';

        var data = await api('game/scenario', { exclude_ids: playedScenarioIds });
        if (screenIsStale(generation)) return;
        if (!data || data.error) {
            if (currentRound > 1) { showFinalScore(); return; }
            appContainer.innerHTML = '<div class="screen-notice">' +
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
            // Format address according to country-specific rules
            var formattedAddress = formatAddressForDisplay(data.scenario.address_display);
            html += '<div class="address-block">' +
                escapeHtml(formattedAddress).replaceAll('\n', '<br>') + '</div>';
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

        var validatePayload = {
            scenario_id: scenario.id,
            goal_type: selectedGoalType,
            mapping: mapping,
        };

        // Locate an address component in the formatted text, matching only on
        // whole tokens. A plain indexOf let a short building number match digits
        // inside a postcode or a street name ("10" inside "10115", "8" inside
        // "8 Mai Straße"), which silently produced the wrong expected order and
        // then marked a correct answer wrong.
        if (selectedGoalType === 'Hybrid' && scenario.address_display) {
            // Derive the country-specific AdrLine field order from the formatted address.
            // Fields that go into AdrLine (all except TwnNm and Ctry).
            var adrFields = [
                { field: 'StrtNm',    value: scenario.address_display.road },
                { field: 'BldgNb',   value: scenario.address_display.houseNumber },
                { field: 'AdtlAdrInf', value: scenario.address_display.attention },
                { field: 'PstCd',    value: scenario.address_display.postcode },
            ];
            var formattedText = formatAddressForDisplay(scenario.address_display);
            var withPos = adrFields
                .filter(function (f) { return f.value && f.value.trim() !== ''; })
                .map(function (f) {
                    return { field: f.field, pos: findComponentPosition(formattedText, f.value.trim()) };
                });
            // Sort by position in formatted output (fields not found go to end)
            withPos.sort(function (a, b) {
                var pa = a.pos === -1 ? Infinity : a.pos;
                var pb = b.pos === -1 ? Infinity : b.pos;
                return pa - pb;
            });
            validatePayload.adr_field_order = withPos.map(function (f) { return f.field; });
        }

        var data = await api('game/validate', validatePayload);
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

    /**
     * The numbers a finished game is described by.
     *
     * Extracted so the play station's end screen and the existing one can
     * agree on them without either recomputing the other's arithmetic — the
     * one thing that must not drift between the two.
     */
    function summariseGame() {
        var totalScore = 0, totalMax = 0, perfectCount = 0;
        roundScores.forEach(function (r) {
            totalScore += r.score;
            totalMax += r.maxScore;
            if (r.perfect) perfectCount++;
        });
        var finalPct = totalMax > 0 ? Math.round((totalScore / totalMax) * 100) : 0;
        var mins = Math.floor(gameElapsedSeconds / 60);
        var secs = gameElapsedSeconds % 60;

        return {
            finalPct: finalPct,
            perfectCount: perfectCount,
            roundCount: roundScores.length,
            finalGameScore: computeGameScore(finalPct, gameElapsedSeconds),
            timeStr: mins + ':' + (secs < 10 ? '0' : '') + secs,
        };
    }

    function showFinalScore() {
        gameActive = false;
        stopGameTimer();
        stopInactivityTimer();

        var summary = summariseGame();

        // The play station gets an entirely different ending, and takes it
        // before anything below runs. Not a variation on this screen: the
        // path below reaches the Hall of Fame and the share sheet, and on a
        // standing kiosk both are things a player cannot get back out of.
        if (displayMode === 'play') {
            showPlayStationResult(summary);
            return;
        }

        var perfectCount = summary.perfectCount;
        var finalPct = summary.finalPct;
        var finalGameScore = summary.finalGameScore;
        var timeStr = summary.timeStr;

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
        // Nothing rendered at all when sharing is off — not hidden with CSS.
        // A display:none button is still in the DOM, still reachable by
        // keyboard, and still there for anyone reading the markup, which is
        // not what "the interface does not offer this" means.
        if (!sharingEnabled) {
            // Deliberately empty: no QR block, no share buttons, no status
            // line. The score, the round badges and Play Again all stay.
        } else if (kioskMode) {
            html += '<div class="kiosk-qr-container" id="kioskQrContainer"><p class="kiosk-qr-label">Scan to share your score</p><div id="kioskQrCode"></div></div>';
        } else {
            // Mobile: native share button, Desktop: LinkedIn + Copy Link side by side
            // Both sets rendered; JavaScript shows/hides based on device
            html += '<button class="btn-share" id="shareScoreBtn">\uD83D\uDCE4 Challenge a Friend</button>';
            html += '<div class="share-actions-row is-collapsed" id="desktopShareRow">';
            html += '<a class="btn-share btn-linkedin" id="linkedinShareBtn" href="#" target="_blank" rel="noopener"><svg width="18" height="18" viewBox="0 0 24 24" fill="currentColor"><path d="M20.447 20.452h-3.554v-5.569c0-1.328-.027-3.037-1.852-3.037-1.853 0-2.136 1.445-2.136 2.939v5.667H9.351V9h3.414v1.561h.046c.477-.9 1.637-1.85 3.37-1.85 3.601 0 4.267 2.37 4.267 5.455v6.286zM5.337 7.433c-1.144 0-2.063-.926-2.063-2.065 0-1.138.92-2.063 2.063-2.063 1.14 0 2.064.925 2.064 2.063 0 1.139-.925 2.065-2.064 2.065zm1.782 13.019H3.555V9h3.564v11.452zM22.225 0H1.771C.792 0 0 .774 0 1.729v20.542C0 23.227.792 24 1.771 24h20.451C23.2 24 24 23.227 24 22.271V1.729C24 .774 23.2 0 22.222 0h.003z"/></svg>Share on LinkedIn</a>';
            html += '<button class="btn-share btn-share-copy" id="copyLinkBtn">\uD83D\uDCCB Copy Link</button>';
            html += '</div>';
            html += '<p id="copyLinkStatus" class="copy-link-status"></p>';
        }
        html += '</div></div></section>';
        appContainer.innerHTML = html;
        window.scrollTo(0, 0);

        // Track game completion (regardless of Hall of Fame submission)
        api('game/complete', {});

        // Animate score counter, then launch confetti
        var scoreEl = document.getElementById('animatedScore');
        animateScore(scoreEl, finalGameScore, 2000, function () {
            if (boundConfetti) {
                boundConfetti({ particleCount: 100, spread: 70, origin: { y: 0.6 } });
                setTimeout(function () {
                    boundConfetti({ particleCount: 50, angle: 60, spread: 55, origin: { x: 0 } });
                    boundConfetti({ particleCount: 50, angle: 120, spread: 55, origin: { x: 1 } });
                }, 300);
                setTimeout(function () {
                    boundConfetti({ particleCount: 100, spread: 100, origin: { y: 0.4 } });
                }, 600);
            }
        });

        document.getElementById('submitFinalScoreBtn').addEventListener('click', async function (event) {
            // Nothing used to stop repeated taps, so an impatient player could
            // file the same run several times over before the first response
            // arrived. Disable for the whole round trip and re-enable only if
            // the submission actually failed.
            var btn = event.currentTarget;
            if (btn.disabled) return;
            btn.disabled = true;
            var originalLabel = btn.textContent;
            btn.textContent = 'Submitting…';

            var data = await api('leaderboard/submit', {
                player_name: playerName,
                score: finalPct,
                time_seconds: gameElapsedSeconds,
            });

            if (data?.success) {
                lastSubmittedEntryId = data.entry_id;
                lastSubmittedPage = data.page || 1;
                showScreen('leaderboard');
                return;
            }

            btn.disabled = false;
            btn.textContent = originalLabel;
            await showModal((data?.error) ? data.error : 'Could not submit your score. Please try again.');
        });

        document.getElementById('playAgainFinalBtn').addEventListener('click', function () {
            showScreen('game');
        });

        // No element was rendered above, so nothing is looked up and no
        // handler is bound — and, just as importantly, share/token is never
        // called, so an installation with sharing off mints no tokens.
        if (!sharingEnabled) {
            return;
        }

        if (kioskMode) {
            renderKioskShareQr(finalGameScore);
        } else {
            setupShareButtons(finalGameScore);
        }
    }

    /* =======================================================
       The play station's end of game (?mode=play)

       A queue forms behind this screen. Everything here is shaped by that:
       the player is thanked, shown something worth having, and pointed at the
       wall next door — and then the station makes itself available again
       without being asked.

       What it deliberately does NOT contain:

       - the Hall of Fame. Reaching it is what makes a player stand there
         scrolling while five people wait;
       - any rank, standing or top-three. Same reason;
       - any share button, and no entry into the share path AT ALL.
         hasNativeShare() returns true as soon as 'ontouchstart' is in window,
         which it is on a Windows touch screen, and navigator.share opens the
         OS share pane OVER the kiosk with no way out that a player will find.
         Hiding the button would not help — the requirement is that this code
         path is never entered.
       ======================================================= */
    const PLAY_AUTO_RETURN_MS = 8000;

    var playReturnTimer = null;
    var playCountdownInterval = null;

    function stopPlayReturn() {
        clearTimeout(playReturnTimer);
        clearInterval(playCountdownInterval);
        playReturnTimer = null;
        playCountdownInterval = null;
    }

    /** "Nice one, Rafael" — the first name only, and only if there is one. */
    function firstNameOf(name) {
        var first = String(name || '').trim().split(/\s+/)[0];
        return first || 'you';
    }

    function playStatsHtml(summary) {
        // Three figures on one line: enough to compare a run against your own
        // last one, and nothing that implies a standing.
        //
        // Not "hints used", which the roadmap asks for: this game has no hint
        // mechanism to count, and inventing one to fill a slot would be a
        // change to the game rather than to this screen. Accuracy is the
        // nearest honest third figure and is already computed.
        return '<div class="play-stats">'
            + '<div class="play-stat"><div class="play-stat-value">'
            + summary.perfectCount + ' / ' + summary.roundCount
            + '</div><div class="play-stat-label">addresses</div></div>'
            + '<div class="play-stat"><div class="play-stat-value">'
            + summary.timeStr
            + '</div><div class="play-stat-label">time</div></div>'
            + '<div class="play-stat"><div class="play-stat-value">'
            + summary.finalPct + '%'
            + '</div><div class="play-stat-label">accuracy</div></div>'
            + '</div>';
    }

    function showPlayStationResult(summary) {
        stopPlayReturn();

        var html = '<section class="play-result">';
        html += '<p class="play-hook">Nice one, ' + escapeHtml(firstNameOf(playerName)) + '</p>';
        html += '<div class="play-score" id="playScoreValue">0</div>';
        html += playStatsHtml(summary);
        // The centrepiece. The reward is not removed on this screen, it is
        // redirected: the arrow points at the physical panel beside it.
        html += '<div class="play-wall-cue">Your name is going up on the wall '
            + '<span class="play-arrow" aria-hidden="true">→</span></div>';
        html += '<button class="btn-primary play-again" id="playAgainBtn">Play again'
            + '<span class="play-again-bar" id="playAgainBar"></span></button>';
        html += '<p class="play-next">Next player in <span id="playCountdown">'
            + (PLAY_AUTO_RETURN_MS / 1000) + '</span>s</p>';
        html += '</section>';

        appContainer.innerHTML = html;
        window.scrollTo(0, 0);

        // The public celebration belongs to the wall, but the player's own
        // screen is allowed to react.
        if (boundConfetti) {
            boundConfetti({ particleCount: 120, spread: 80, origin: { y: 0.55 } });
            setTimeout(function () {
                boundConfetti({ particleCount: 60, angle: 60, spread: 55, origin: { x: 0 } });
                boundConfetti({ particleCount: 60, angle: 120, spread: 55, origin: { x: 1 } });
            }, 250);
        }

        api('game/complete', {});

        // Filed without being asked. On a station with a queue behind it,
        // "Submit to Hall of Fame" is one more thing to explain to every
        // player, and a run nobody submitted is a name that never reaches the
        // wall it was just promised.
        (async function () {
            var data = await api('leaderboard/submit', {
                player_name: playerName,
                score: summary.finalPct,
                time_seconds: gameElapsedSeconds,
            });
            // Nothing is shown either way. The rank came back in that
            // response and is deliberately ignored, and a failure is not this
            // player's problem to solve standing at a kiosk.
            if (data?.success) {
                lastSubmittedEntryId = data.entry_id;
            }
        })();

        var scoreEl = document.getElementById('playScoreValue');
        animateScore(scoreEl, summary.finalGameScore, 1200, function () {
            scoreEl.classList.add('play-score-pop');
        });

        document.getElementById('playAgainBtn').addEventListener('click', function () {
            stopPlayReturn();
            showScreen('game');
        });

        startPlayAutoReturn();
    }

    /**
     * Hand the station back to the next player on its own.
     *
     * The bar draining under the button is the point: a player who wants
     * another go can see how long they have, and one who has walked off does
     * not leave their score on screen for the person behind them.
     */
    function startPlayAutoReturn() {
        var bar = document.getElementById('playAgainBar');
        if (bar) {
            // Set on the next frame so the transition has a start value to
            // animate from; assigning both in one go would simply jump.
            bar.style.transition = 'none';
            bar.style.width = '100%';
            requestAnimationFrame(function () {
                bar.style.transition = 'width ' + (PLAY_AUTO_RETURN_MS / 1000) + 's linear';
                bar.style.width = '0%';
            });
        }

        var remaining = PLAY_AUTO_RETURN_MS / 1000;
        playCountdownInterval = setInterval(function () {
            remaining--;
            var el = document.getElementById('playCountdown');
            if (el) el.textContent = String(Math.max(0, remaining));
        }, 1000);

        playReturnTimer = setTimeout(function () {
            stopPlayReturn();
            showScreen('game');
        }, PLAY_AUTO_RETURN_MS);
    }

    /**
     * Kiosk mode has no browser chrome to share from, so the score leaves the
     * machine as a QR code the player photographs.
     */
    async function renderKioskShareQr(finalGameScore) {
        var tokenData = await api('share/token', { score: finalGameScore, name: playerName });
        if (!tokenData?.token) return;

        var qrContainer = document.getElementById('kioskQrCode');
        if (!qrContainer || typeof qrcode !== 'function') return;

        var qr = qrcode(0, 'M');
        qr.addData(window.location.origin + '/share/go?d=' + encodeURIComponent(tokenData.token));
        qr.make();
        qrContainer.innerHTML = qr.createSvgTag(4, 0);
    }

    /**
     * Extracted from showFinalScore(), which Sonar flagged twice for cognitive
     * complexity (S3776, 16 and 28 against a limit of 15). The work splits
     * cleanly in two because the device decides which half runs, and neither
     * half shares state with the other beyond the share URL.
     */
    async function setupShareButtons(finalGameScore) {
        var tokenData = await api('share/token', { score: finalGameScore, name: playerName });

        var shareBtn = document.getElementById('shareScoreBtn');
        if (!tokenData?.token) {
            if (shareBtn) shareBtn.style.display = 'none';
            return;
        }

        var shareUrl = window.location.origin + '/share?d=' + encodeURIComponent(tokenData.token);

        if (hasNativeShare()) {
            setupNativeShare(shareBtn, shareUrl, finalGameScore);
        } else {
            setupDesktopShare(shareBtn, shareUrl);
        }
    }

    /** Native share is only offered where it is actually a better experience. */
    function hasNativeShare() {
        return Boolean(navigator.share) && (
            /Android|webOS|iPhone|iPad|iPod|BlackBerry|IEMobile|Opera Mini/i.test(navigator.userAgent)
            || window.innerWidth <= 768
            || ('ontouchstart' in window)
        );
    }

    function setupNativeShare(shareBtn, shareUrl, finalGameScore) {
        if (!shareBtn) return;

        shareBtn.style.display = 'inline-block';
        shareBtn.addEventListener('click', function () {
            navigator.share({
                title: '\uD83C\uDFC6 I scored ' + finalGameScore + ' pts!',
                text: '\uD83C\uDFC6 I scored ' + finalGameScore + ' pts on the ISO 20022 Address Challenge! Can you beat me?',
                url: shareUrl
            }).catch(function () { /* user cancelled */ });
        });
    }

    function setupDesktopShare(shareBtn, shareUrl) {
        var desktopRow = document.getElementById('desktopShareRow');
        var linkedinBtn = document.getElementById('linkedinShareBtn');
        var copyLinkBtn = document.getElementById('copyLinkBtn');
        var copyLinkStatus = document.getElementById('copyLinkStatus');

        if (shareBtn) shareBtn.style.display = 'none';
        if (linkedinBtn) {
            linkedinBtn.href = 'https://www.linkedin.com/sharing/share-offsite/?url=' + encodeURIComponent(shareUrl);
        }
        if (desktopRow) desktopRow.style.display = 'grid';
        if (!copyLinkBtn) return;

        // copyToClipboard() already implements the Clipboard API call and the
        // textarea+execCommand fallback; this handler used to carry a second
        // copy of both.
        copyLinkBtn.addEventListener('click', function () {
            copyToClipboard(shareUrl).then(function (ok) {
                if (ok && copyLinkStatus) copyLinkStatus.textContent = 'Link copied!';
            });
        });
    }

    /* =======================================================
       Leaderboard Screen
       ======================================================= */
    /**
     * The Hall of Fame table, and whether it contains the row belonging to the
     * run just submitted (the caller scrolls to it and fires the confetti).
     *
     * Extracted from renderLeaderboardScreen() along with
     * buildPaginationControls(): between them the row loop and the pagination
     * branches carried most of that function's cognitive complexity (S3776),
     * and neither depends on anything else the caller is doing.
     */
    function buildLeaderboardTable(entries, startRank, highlightId) {
        var hasHighlight = false;
        var html = '<table class="leaderboard-table"><thead><tr>'
            + '<th>Rank</th><th>Player</th><th>Score</th><th>Date</th>'
            + '</tr></thead><tbody>';

        entries.forEach(function (entry, i) {
            var isMe = highlightId && Number.parseInt(entry.id, 10) === Number.parseInt(highlightId, 10);
            if (isMe) hasHighlight = true;

            var score = entry.game_score !== undefined
                ? entry.game_score
                : computeGameScore(Number.parseInt(entry.score, 10) || 0, Number.parseInt(entry.time_seconds, 10) || 0);

            html += '<tr' + (isMe ? ' class="my-entry"' : '') + '>'
                + '<td>' + (startRank + i + 1) + '</td>'
                + '<td>' + escapeHtml(entry.player_name) + '</td>'
                + '<td>' + score + '</td>'
                + '<td>' + formatDate(entry.created_at) + '</td></tr>';
        });

        return { html: html + '</tbody></table>', hasHighlight: hasHighlight };
    }

    /** Pagination row, or nothing at all when everything fits on one page. */
    function buildPaginationControls(currentPage, totalPages, totalCount) {
        if (totalPages <= 1) {
            return '';
        }

        var html = '<div class="pagination">';
        if (currentPage > 1) {
            html += '<button class="btn-page" data-page="1" title="First">&laquo;</button>'
                + '<button class="btn-page" data-page="' + (currentPage - 1) + '" title="Previous">&lsaquo;</button>';
        }

        var startP = Math.max(1, currentPage - 2);
        var endP = Math.min(totalPages, currentPage + 2);
        for (var p = startP; p <= endP; p++) {
            html += '<button class="btn-page' + (p === currentPage ? ' active' : '')
                + '" data-page="' + p + '">' + p + '</button>';
        }

        if (currentPage < totalPages) {
            html += '<button class="btn-page" data-page="' + (currentPage + 1) + '" title="Next">&rsaquo;</button>'
                + '<button class="btn-page" data-page="' + totalPages + '" title="Last">&raquo;</button>';
        }

        return html + '<span class="page-info">' + totalCount + ' entries</span></div>';
    }

    async function renderLeaderboardScreen(page) {
        var generation = screenGeneration;

        // Navigate to the submitted entry's page if available
        if (!page && lastSubmittedPage) {
            page = lastSubmittedPage;
        }
        page = page || 1;

        appContainer.innerHTML = '<section class="leaderboard-screen"><h2>Hall of Fame</h2>' +
            '<p class="text-centred">Loading...</p></section>';

        var data = await api('leaderboard/top', { page: page });
        // The player may have moved on while this was in flight; the answer is
        // no longer theirs to see.
        if (!data || screenIsStale(generation)) return;

        var entries = data.entries || [];
        var currentPage = data.page || 1;
        var totalPages = data.total_pages || 1;
        var totalCount = data.total_count || 0;
        var perPage = data.per_page || 20;
        var startRank = (currentPage - 1) * perPage;
        var highlightId = lastSubmittedEntryId;

        var hasHighlight = false;
        var html = '<section class="leaderboard-screen"><h2>Hall of Fame</h2>';
        html += '<div class="leaderboard-table-wrap">';

        if (totalCount === 0) {
            html += '<p class="empty-state">No entries yet. Be the first to play!</p>';
        } else {
            var table = buildLeaderboardTable(entries, startRank, highlightId);
            hasHighlight = table.hasHighlight;
            html += table.html;
            html += buildPaginationControls(currentPage, totalPages, totalCount);
        }

        html += '</div></section>';
        appContainer.innerHTML = html;

        // Bind pagination clicks
        document.querySelectorAll('.btn-page').forEach(function (btn) {
            btn.addEventListener('click', function () {
                var p = Number.parseInt(this.dataset.page, 10);
                if (p) {
                    lastSubmittedEntryId = null;
                    lastSubmittedPage = null;
                    renderLeaderboardScreen(p);
                }
            });
        });

        // Party effect on highlighted entry
        if (hasHighlight) {
            var myRow = document.querySelector('.my-entry');
            if (myRow) {
                try { myRow.scrollIntoView({ behavior: 'smooth', block: 'center' }); }
                catch (e) { myRow.scrollIntoView(false); }
            }
            if (boundConfetti) {
                setTimeout(function () {
                    boundConfetti({ particleCount: 80, spread: 70, origin: { y: 0.6 } });
                }, 200);
                setTimeout(function () {
                    boundConfetti({ particleCount: 40, angle: 60, spread: 55, origin: { x: 0 } });
                    boundConfetti({ particleCount: 40, angle: 120, spread: 55, origin: { x: 1 } });
                }, 500);
            }
        }
    }

    /* =======================================================
       Admin Screen
       ======================================================= */
    function renderAdminScreen() {
        // Always require password - no session persistence
        renderAdminLogin();
    }

    function renderAdminLogin() {
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
        if (data?.success) {
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

        // Display modes — the three ways this game gets onto a screen,
        // gathered into one section because an organiser setting up an event
        // needs to see them side by side to understand how they differ.
        html += '<div class="admin-section display-modes-section"><h3>Display modes</h3>';
        html += '<p class="display-modes-intro">Three ways to show the game. '
            + '<strong>Kiosk mode</strong> switches on for <em>this device</em>, in this browser session. '
            + 'The <strong>wall</strong> and the <strong>play station</strong> switch on through their URL, '
            + 'on their own machine — which is how they survive a reboot.</p>';

        // 1. Kiosk mode — the existing toggle, moved unchanged and renamed so
        //    that "this device" is impossible to misread.
        html += '<div class="display-mode-block kiosk-section"><h4>Kiosk mode — this device</h4>';
        html += '<p>Fullscreen and screen saver for the current browser session. For an iPad prepared by hand.</p>';
        html += '<label class="kiosk-toggle">';
        html += '<input type="checkbox" id="kioskToggle"' + (kioskMode ? ' checked' : '') + '>';
        html += '<span class="kiosk-slider"></span>';
        html += '<span class="kiosk-label">' + (kioskMode ? 'Enabled' : 'Disabled') + '</span>';
        html += '</label>';
        html += '<details class="kiosk-ipad-guide"><summary>iPad setup guide</summary>';
        html += '<ol>';
        html += '<li>Open this page in <strong>Safari</strong> on the iPad (other browsers do not support home screen shortcuts).</li>';
        html += '<li>Tap the <strong>Share</strong> button (the square with an arrow pointing up) in the Safari toolbar.</li>';
        html += '<li>Scroll down and tap <strong>Add to Home Screen</strong>.</li>';
        html += '<li>Edit the name if desired, then tap <strong>Add</strong>. An icon will appear on the home screen.</li>';
        html += '<li>Open the app from that icon — it will launch in full-screen mode without any browser chrome.</li>';
        html += '<li>To prevent users from switching apps, enable <strong>Guided Access</strong>: go to <em>Settings &rarr; Accessibility &rarr; Guided Access</em>, turn it on, and set a passcode.</li>';
        html += '<li>Start a Guided Access session: triple-click the <strong>Side button</strong> (or Home button on older iPads) while the app is open, then tap <strong>Start</strong>.</li>';
        html += '<li>To end Guided Access, triple-click the Side/Home button again and enter the passcode.</li>';
        html += '</ol>';
        html += '<p><strong>Note:</strong> Guided Access locks the iPad to this single app — users cannot switch to Safari, the home screen, or any other app.</p>';
        html += '</details>';
        html += '</div>';

        // 2. The two dedicated screens. Instructions, NOT switches.
        //
        //    There is deliberately no button here that would put the wall into
        //    wall mode from the admin screen. It would go out at the first
        //    reboot of that PC with nobody in the room — which is the exact
        //    problem the URL exists to solve.
        html += '<div class="display-mode-block"><h4>Dedicated screens — by URL</h4>';
        html += '<p>Open these on the machine concerned. The mode is in the address, so it survives '
            + 'a reboot, a Windows update and a crashed tab — unlike the kiosk toggle above.</p>';
        html += '<div id="displayModeUrls"></div>';
        html += '<p class="display-mode-launch-label">Launch each screen with:</p>';
        html += '<div class="display-mode-cmd" id="displayModeCommands"></div>';
        html += '<p class="display-mode-note"><strong>Use the browser\'s own kiosk switch, not fullscreen from the page.</strong> '
            + 'Fullscreen triggered by the page needs someone to click something first, and after a '
            + 'reboot at three in the morning there is nobody there to click it. '
            + '<code>--kiosk</code> comes back fullscreen on its own and keeps the address bar out of reach.</p>';
        // Regenerating is a silent breaking change to two screens nobody is
        // watching, so the button says what it costs before it is pressed and
        // the panel reprints everything the moment it is done.
        html += '<div class="display-mode-token"><h5>Screen address token</h5>';
        html += '<p>The <code>&amp;t=</code> in the URLs above. It makes the two addresses '
            + 'unguessable so a visitor does not wander onto the wall. It is <strong>not</strong> a '
            + 'login: the addresses stop being guessable, they do not become private.</p>';
        html += '<button class="btn-danger" id="regenerateDisplayTokenBtn">Regenerate</button>';
        html += '<p class="display-mode-token-status" id="regenerateTokenStatus"></p>';
        html += '</div>';
        html += '</div>';

        // 3. The wall's time window, moved here from its own section in
        //    iteration 2. It belongs with the screen it affects.
        html += '<div class="display-mode-block"><h4>Wall window</h4>';
        html += '<p>How far back the <code>?mode=hof</code> wall looks. It affects that screen and nothing else — '
            + 'the Hall of Fame on phones and on the iPad kiosk stays all-time.</p>';
        html += '<div class="board-window-form">';
        html += '<input type="number" id="boardWindowInput" min="0" max="8760" step="1" class="board-window-input">';
        html += '<span class="board-window-unit">hours &middot; 0 = since forever</span>';
        html += '<button class="btn-primary" id="saveBoardWindowBtn">Save</button>';
        html += '</div>';
        html += '<p id="boardWindowStatus" class="board-window-status"></p>';
        html += '</div>';

        html += '</div>';

        // Sharing — a product switch, and labelled as one.
        //
        // The sentence about existing links is not decoration. Without it an
        // administrator switching this off will believe they have revoked
        // something, and will be wrong: every link already posted keeps
        // working, by design.
        html += '<div class="admin-section sharing-section"><h3>Sharing</h3>';
        html += '<p>Whether the end-of-game screen offers the share buttons, the LinkedIn link, '
            + 'the copy-link button and the kiosk QR code.</p>';
        // Its own class family, not the kiosk toggle's. The two switches look
        // identical on purpose — one switch shape in the panel — but they are
        // different controls, and sharing a class name made `.kiosk-toggle`
        // stop meaning "the kiosk toggle" to everything that selects on it.
        html += '<label class="admin-switch">';
        html += '<input type="checkbox" id="sharingToggle">';
        html += '<span class="admin-switch-slider"></span>';
        html += '<span class="admin-switch-label" id="sharingLabel">Loading\u2026</span>';
        html += '</label>';
        html += '<p class="sharing-note" id="sharingNote"><strong>Links already shared keep working.</strong> '
            + 'This hides the buttons; it does not revoke anything. A score link a player has already '
            + 'posted still opens, and the site\u2019s own social preview image is still generated.</p>';
        html += '<p class="sharing-status" id="sharingStatus"></p>';
        html += '</div>';

        // Game Counter section
        html += '<div class="admin-section"><h3>\uD83C\uDFAE Game Counter</h3>';
        html += '<div class="game-counter-info">';
        html += '<p>Total games played: <strong id="totalGamesCount">...</strong></p>';
        html += '<button class="btn-secondary" id="resetGameCounterBtn">Reset from Hall of Fame</button>';
        html += '</div>';
        html += '<div class="game-chart-wrap"><canvas id="gamesWeeklyChart" height="200"></canvas></div>';
        html += '</div>';

        // Change PIN
        html += '<div class="admin-section"><h3>Change PIN</h3>';
        html += '<div class="pin-change-form">';
        html += '<input type="password" id="newPinInput" placeholder="New PIN (4-8 digits)" pattern="' + String.raw`\d{4,8}` + '" maxlength="8" inputmode="numeric">';
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

        // Theme Colors
        html += '<div class="admin-section"><h3>Theme Colors</h3>';
        html += '<p>Customize the brand colors. Changes take effect after saving and reloading the page.</p>';
        html += '<div class="theme-color-grid" id="themeColorGrid"><p>Loading...</p></div>';
        html += '<div class="theme-actions">';
        html += '<button class="btn-primary" id="saveThemeBtn">Save Colors</button>';
        html += '<button class="btn-secondary" id="resetThemeBtn">Reset to PMPG colours</button>';
        html += '</div>';
        html += '<p class="theme-status" id="themeStatus"></p>';
        html += '</div>';

        // Did You Know Facts
        html += '<div class="admin-section"><h3>\uD83D\uDCA1 Did You Know — Quick Facts</h3>';
        html += '<p>Add fun facts displayed on the welcome screen.</p>';
        html += '<button class="btn-primary" id="addFactBtn">+ Add Fact</button>';
        html += '<div id="adminFactsList"><p>Loading facts...</p></div>';
        html += '</div>';

        // Upload section
        html += '<div class="admin-section"><h3>Upload Scenarios</h3>';
        html += '<p>Upload an Excel file (.xlsx) with scenario data.</p>';
        html += '<form class="dropzone" id="excelDropzone" action="index.php"></form>';
        html += '<div id="uploadStatus" class="upload-status hidden"></div>';
        html += '<div class="admin-button-row">';
        html += '<a href="assets/Scenarios.xlsx" download class="btn-secondary btn-secondary-link">\u2B07 Download Example Excel</a>';
        html += '<button class="btn-secondary" id="exportScenariosBtn">\u2B07 Export Current Scenarios</button>';
        html += '</div></div>';

        // Hall of Fame management
        html += '<div class="admin-section"><h3>Hall of Fame Management</h3>';
        html += '<div id="adminLeaderboard"><p>Loading entries...</p></div>';
        html += '<div class="admin-purge-row"><button class="btn-danger" id="purgeBtn">Purge All Entries</button></div>';
        html += '</div>';

        html += '<button class="btn-secondary" id="adminLogoutBtn">Logout</button>';
        html += '</div></section>';

        appContainer.innerHTML = html;
        initAdminActions();
        initDropzone();
        loadGameStats();
        loadAdminLeaderboard();
        renderDisplayModeUrls();
        bindRegenerateDisplayToken();
        loadAdminDeadline();
        loadAdminBoardWindow();
        loadAdminSharing();
        loadAdminFacts();
        loadAdminTheme();
    }

    /**
     * The two dedicated screens, as instructions an organiser can follow.
     *
     * Built from window.location.origin rather than from anything stored, so
     * the URLs are right on a laptop, on a staging host and in production
     * without anybody configuring a base address — and so what is copied is
     * demonstrably reachable from where the organiser is standing.
     */
    const DISPLAY_MODE_SCREENS = [
        {
            mode: 'hof',
            name: 'Hall of Fame — portrait screen',
            what: 'No menus · refreshes every 5s · nothing is touchable',
        },
        {
            mode: 'play',
            name: 'Play station — landscape screen',
            what: 'No menus · Hall of Fame unreachable · on-screen keyboard',
        },
    ];

    /**
     * Build one screen's URL, token included.
     *
     * The token is a query parameter like any other, so it goes through
     * encodeURIComponent even though it is hex — a helper that only escapes
     * the values it expects is a helper that stops escaping the day the value
     * changes shape.
     */
    function displayModeUrl(mode, token) {
        return window.location.origin + '/?mode=' + mode
            + (token ? '&t=' + encodeURIComponent(token) : '');
    }

    /**
     * Fetch the token and (re)draw the two screens' URLs, QR codes and launch
     * commands.
     *
     * Takes the token as an argument so regeneration can hand in the value the
     * server just returned and repaint without a reload — the whole point
     * being that putting two screens back on air is thirty seconds of work,
     * not a hunt for where the new address went.
     */
    async function renderDisplayModeUrls(knownToken) {
        var container = document.getElementById('displayModeUrls');
        if (!container) return;

        var token = knownToken;
        if (!token) {
            var data = await api('admin/get-display-token');
            token = (data?.token) || '';
        }

        drawDisplayModeUrls(token);
    }

    function drawDisplayModeUrls(token) {
        var container = document.getElementById('displayModeUrls');
        var commands = document.getElementById('displayModeCommands');
        if (!container) return;

        var html = '';
        DISPLAY_MODE_SCREENS.forEach(function (screen) {
            var url = displayModeUrl(screen.mode, token);
            html += '<div class="display-mode-row">';
            html += '<div class="display-mode-qr" id="displayModeQr_' + screen.mode + '"></div>';
            html += '<div class="display-mode-info">';
            html += '<p class="display-mode-name">' + escapeHtml(screen.name) + '</p>';
            html += '<p class="display-mode-url">' + escapeHtml(url) + '</p>';
            html += '<p class="display-mode-what">' + escapeHtml(screen.what) + '</p>';
            html += '</div>';
            html += '<button class="btn-secondary display-mode-copy" data-copy="' + escapeHtml(url)
                + '">Copy</button>';
            html += '</div>';
        });
        container.innerHTML = html;

        if (commands) {
            var cmdText = DISPLAY_MODE_SCREENS.map(function (screen) {
                return 'chrome --kiosk --app="' + displayModeUrl(screen.mode, token) + '"';
            }).join('\n');
            commands.innerHTML = '<pre>' + escapeHtml(cmdText) + '</pre>'
                + '<button class="btn-secondary display-mode-copy" data-copy="'
                + escapeHtml(cmdText) + '">Copy</button>';
        }

        // The QR library already loaded for kiosk share codes — a second one
        // would be a second thing to keep patched for no gain.
        DISPLAY_MODE_SCREENS.forEach(function (screen) {
            var target = document.getElementById('displayModeQr_' + screen.mode);
            if (!target || typeof qrcode !== 'function') return;
            var qr = qrcode(0, 'M');
            qr.addData(displayModeUrl(screen.mode, token));
            qr.make();
            target.innerHTML = qr.createSvgTag(3, 0);
        });

        document.querySelectorAll('.display-mode-copy').forEach(function (btn) {
            btn.addEventListener('click', function () {
                // getAttribute rather than .dataset: querySelectorAll yields
                // Element, which has the attribute API but not the HTMLElement
                // dataset property, and the typecheck gate is right to say so.
                copyToClipboard(btn.getAttribute('data-copy')).then(function (ok) {
                    if (!ok) return;
                    var original = btn.textContent;
                    btn.textContent = 'Copied';
                    setTimeout(function () { btn.textContent = original; }, 1500);
                });
            });
        });
    }

    /**
     * What the confirmation says, in one place so it cannot drift from what
     * the button does.
     *
     * Every clause is load-bearing. Regenerating does not break the two
     * screens loudly — they fall back to the ordinary game WITH MENUS, on
     * purpose, because an error page in front of a room is worse. That makes
     * it a silent failure, and a silent failure that nobody warned you about
     * is the kind you diagnose at seven in the evening with a queue forming.
     */
    const REGENERATE_TOKEN_WARNING =
        'Regenerate the screen address token?\n\n'
        + 'The Hall of Fame wall and the play station will drop back to the ordinary game '
        + 'with menus, immediately and WITHOUT ANY WARNING ON THEIR SCREENS \u2014 they show no '
        + 'error, they just stop being dedicated screens.\n\n'
        + 'You will have to reopen both of them with the new addresses, which appear here as '
        + 'soon as you confirm.';

    function bindRegenerateDisplayToken() {
        var btn = document.getElementById('regenerateDisplayTokenBtn');
        if (!btn) return;

        btn.addEventListener('click', async function () {
            var status = document.getElementById('regenerateTokenStatus');

            var confirmed = await showConfirm(REGENERATE_TOKEN_WARNING);
            if (!confirmed) return;

            var data = await api('admin/regenerate-display-token');
            if (!data?.success || !data.token) {
                if (status) status.textContent = (data?.error) || 'Could not regenerate the token.';
                return;
            }

            // Repainted from the value the server just returned, with no
            // reload: getting two screens back on air has to be thirty
            // seconds of work, not a hunt for where the new address went.
            // Only the URL rows and the command block are redrawn. This
            // button lives outside them and survives, so it is NOT rebound —
            // doing so would add a second listener and pop two confirmations
            // on the next press.
            drawDisplayModeUrls(data.token);
            if (status) {
                status.textContent = 'New token in force. Reopen both screens with the addresses above '
                    + '\u2014 until you do, they are showing the ordinary game.';
            }
        });
    }

    /**
     * The sharing switch's current value, and its handler.
     *
     * Read from the server rather than from the shell's own data-sharing
     * attribute: an administrator on a page loaded before the change would
     * otherwise see the stale value they just moved away from.
     */
    async function loadAdminSharing() {
        var toggle = document.getElementById('sharingToggle');
        var label = document.getElementById('sharingLabel');
        if (!toggle) return;

        var data = await api('admin/get-sharing');
        var enabled = !data || data.sharing_enabled !== false;
        toggle.checked = enabled;
        if (label) label.textContent = enabled ? 'Enabled' : 'Disabled';

        toggle.addEventListener('change', async function () {
            var status = document.getElementById('sharingStatus');
            var wanted = toggle.checked;

            var saved = await api('admin/set-sharing', { sharing_enabled: wanted });
            if (!saved?.success) {
                // Put the switch back where the server still has it, rather
                // than leaving it showing a state that was never stored.
                toggle.checked = !wanted;
                if (status) status.textContent = (saved?.error) || 'Could not save the setting.';
                return;
            }

            if (label) label.textContent = saved.sharing_enabled ? 'Enabled' : 'Disabled';
            if (status) {
                status.textContent = saved.sharing_enabled
                    ? 'Saved \u2014 the share buttons are offered again. Reload a player\u2019s page to see it.'
                    : 'Saved \u2014 the share buttons are hidden. Links already shared keep working.';
            }
        });
    }

    async function loadAdminBoardWindow() {
        var input = document.getElementById('boardWindowInput');
        if (!input) return;

        var data = await api('admin/get-board-window');
        if (data && typeof data.window_hours === 'number') {
            input.value = String(data.window_hours);
        }
    }

    var gamesChart = null;

    async function loadGameStats() {
        var data = await api('admin/game-stats');
        if (!data) return;

        var countEl = document.getElementById('totalGamesCount');
        if (countEl) countEl.textContent = data.total_games;

        // Render weekly chart
        var canvas = document.getElementById('gamesWeeklyChart');
        if (!canvas || typeof Chart === 'undefined') return;

        var stats = data.weekly_stats || [];
        var labels = stats.map(function (s) { return s.week; });
        var counts = stats.map(function (s) { return s.count; });

        // Pre-compute a month label for each week label (e.g. "2026-W05" → "Feb 2026")
        // Only show the label when the month changes, to get clean monthly markers.
        var MONTHS = ['Jan','Feb','Mar','Apr','May','Jun','Jul','Aug','Sep','Oct','Nov','Dec'];
        var monthLabels = labels.map(function (lbl) {
            var d = isoWeekToDate(lbl);
            if (!d) return '';
            return MONTHS[d.getUTCMonth()] + ' ' + d.getUTCFullYear();
        });
        var tickLabels = labels.map(function (lbl, i) {
            return (i === 0 || monthLabels[i] !== monthLabels[i - 1]) ? monthLabels[i] : '';
        });

        if (gamesChart) gamesChart.destroy();
        gamesChart = new Chart(canvas, {
            type: 'bar',
            data: {
                labels: labels,
                datasets: [{
                    label: 'Games per week',
                    data: counts,
                    backgroundColor: getComputedStyle(document.documentElement).getPropertyValue('--game-emerald').trim().replace(/^(#[0-9a-f]{6})$/i, function (_, h) { var r = Number.parseInt(h.slice(1,3),16), g = Number.parseInt(h.slice(3,5),16), b = Number.parseInt(h.slice(5,7),16); return 'rgba(' + r + ',' + g + ',' + b + ',0.6)'; }) || 'rgba(1,169,144,0.6)',
                    borderColor: getComputedStyle(document.documentElement).getPropertyValue('--game-emerald').trim() || '#01a990',
                    borderWidth: 1
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: { legend: { display: false } },
                scales: {
                    y: { beginAtZero: true, ticks: { precision: 0 } },
                    x: {
                        ticks: {
                            maxRotation: 45,
                            autoSkip: false,
                            callback: function (val, i) { return tickLabels[i] || null; }
                        }
                    }
                }
            }
        });
    }

    async function loadAdminLeaderboard() {
        var container = document.getElementById('adminLeaderboard');
        if (!container) return;

        var data = await api('admin/leaderboard-entries');
        if (!data?.entries) {
            container.innerHTML = '<p>Could not load entries.</p>';
            return;
        }

        var entries = data.entries;
        if (entries.length === 0) {
            container.innerHTML = '<p class="empty-state">No entries yet.</p>';
            return;
        }

        // Compute game score and sort (same as Hall of Fame)
        entries.forEach(function (entry) {
            var pct = Number.parseInt(entry.score) || 0;
            var ts = Number.parseInt(entry.time_seconds) || 0;
            entry.gameScore = computeGameScore(pct, ts);
        });
        entries.sort(function (a, b) { return b.gameScore - a.gameScore; });

        var html = '<table class="leaderboard-table admin-leaderboard-table"><thead><tr>';
        html += '<th>Rank</th><th>Player</th><th>Score</th><th>Date</th><th></th>';
        html += '</tr></thead><tbody>';
        entries.forEach(function (entry, i) {
            var safeId = Number.parseInt(entry.id) || 0;
            html += '<tr data-entry-id="' + safeId + '">';
            html += '<td>' + (i + 1) + '</td>';
            html += '<td>' + escapeHtml(entry.player_name) + '</td>';
            html += '<td>' + entry.gameScore + '</td>';
            html += '<td>' + formatDate(entry.created_at) + '</td>';
            html += '<td><button class="btn-delete-entry" data-id="' + safeId + '" title="Delete">&times;</button></td>';
            html += '</tr>';
        });
        html += '</tbody></table>';
        container.innerHTML = html;

        container.querySelectorAll('.btn-delete-entry').forEach(function (btn) {
            btn.addEventListener('click', async function () {
                var id = Number.parseInt(this.dataset.id);
                var confirmed = await showConfirm('Delete this entry?');
                if (!confirmed) return;
                var resp = await api('admin/delete-entry', { id: id });
                if (resp?.success) {
                    var row = container.querySelector('tr[data-entry-id="' + id + '"]');
                    if (row) row.remove();
                } else {
                    await showModal(resp ? resp.error : 'Error deleting entry');
                }
            });
        });
    }

    function showFactEditor(factId, existingContent) {
        return new Promise(function (resolve) {
            var overlay = document.createElement('div');
            overlay.className = 'overlay';
            overlay.id = 'factEditorOverlay';

            var title = factId ? 'Edit Fact' : 'Add Fact';
            var content = '<div class="overlay-content fact-editor-modal">';
            content += '<h2>' + title + '</h2>';
            content += '<div class="fact-toolbar">';
            content += '<button type="button" class="fact-fmt-btn" data-fmt="bold" title="Bold"><b>B</b></button>';
            content += '<button type="button" class="fact-fmt-btn" data-fmt="italic" title="Italic"><i>I</i></button>';
            content += '<button type="button" class="fact-fmt-btn" data-fmt="link" title="Link">\uD83D\uDD17</button>';
            content += '</div>';
            content += '<div class="fact-editor" id="factEditorArea" contenteditable="true"></div>';
            content += '<div class="fact-edit-actions">';
            content += '<button class="btn-primary" id="factEditorSave">Save</button>';
            content += '<button class="btn-secondary" id="factEditorCancel">Cancel</button>';
            content += '</div>';
            content += '</div>';
            overlay.innerHTML = content;
            document.body.appendChild(overlay);

            var editor = document.getElementById('factEditorArea');
            // The existing content goes through the allowlist here rather
            // than being concatenated into the markup above. Today every
            // caller happens to hand over content read back from an
            // already-sanitised node, but that is a property of the callers,
            // not of this editor — sanitising at the sink is what keeps a
            // future call site from turning this contenteditable into an
            // injection point.
            setSanitizedHtml(editor, existingContent || '');
            editor.focus();

            // Update toolbar active states on selection change
            function updateToolbarState() {
                overlay.querySelectorAll('.fact-fmt-btn').forEach(function (btn) {
                    var fmt = btn.dataset.fmt;
                    var active = false;
                    if (fmt === 'bold') active = document.queryCommandState('bold');
                    else if (fmt === 'italic') active = document.queryCommandState('italic');
                    else if (fmt === 'link') {
                        var sel = window.getSelection();
                        if (sel.rangeCount > 0) {
                            var node = sel.anchorNode;
                            while (node && node !== editor) {
                                if (node.nodeName === 'A') { active = true; break; }
                                node = node.parentNode;
                            }
                        }
                    }
                    btn.classList.toggle('active', active);
                });
            }
            document.addEventListener('selectionchange', updateToolbarState);

            // Formatting buttons
            overlay.querySelectorAll('.fact-fmt-btn').forEach(function (btn) {
                btn.addEventListener('mousedown', function (e) {
                    e.preventDefault(); // Prevent losing focus/selection
                });
                btn.addEventListener('click', function (e) {
                    e.preventDefault();
                    var fmt = this.dataset.fmt;
                    editor.focus();
                    if (fmt === 'bold') {
                        document.execCommand('bold');
                    } else if (fmt === 'italic') {
                        document.execCommand('italic');
                    } else if (fmt === 'link') {
                        var sel = window.getSelection();
                        var node = sel.anchorNode;
                        var existingLink = null;
                        while (node && node !== editor) {
                            if (node.nodeName === 'A') { existingLink = node; break; }
                            node = node.parentNode;
                        }
                        if (existingLink) {
                            document.execCommand('unlink');
                        } else {
                            var url = prompt('Enter URL:', 'https://');
                            if (url) document.execCommand('createLink', false, url);
                        }
                    }
                    updateToolbarState();
                });
            });

            function close(result) {
                document.removeEventListener('selectionchange', updateToolbarState);
                overlay.remove();
                resolve(result);
            }

            document.getElementById('factEditorSave').addEventListener('click', function () {
                var html = editor.innerHTML.trim();
                // Clean up: remove trailing <br> and empty content
                html = html.replace(/<br\s*\/?>$/i, '').trim();
                if (!html || html === '<br>') {
                    editor.focus();
                    return;
                }
                close(html);
            });
            document.getElementById('factEditorCancel').addEventListener('click', function () {
                close(null);
            });
        });
    }

    // Must match App\Models\ThemeModel::DEFAULTS exactly — tests/ThemeDefaultsSyncTest.php
    // reads this literal out of this file and fails if the two drift. A
    // divergence would show up as an admin panel whose reset restores
    // different colours from those a fresh install starts with.
    var themeDefaults = {
        color_primary:       '#3d345f',
        color_primary_hover: '#2c2646',
        color_primary_light: '#dceaf3',
        color_bg:            '#8abed9',
        color_text:          '#3d345f'
    };

    var themeLabels = {
        color_primary:       'Primary (buttons, chips, accents)',
        color_primary_hover: 'Primary Hover (darker shade)',
        color_primary_light: 'Primary Light (highlights, filled slots)',
        color_bg:            'Background',
        color_text:          'Text / Headings'
    };

    async function loadAdminTheme() {
        var grid = document.getElementById('themeColorGrid');
        if (!grid) return;
        var data = await api('admin/get-theme');
        var theme = (data?.theme) ? data.theme : { ...themeDefaults };
        var html = '';
        Object.keys(themeLabels).forEach(function (key) {
            var val = theme[key] || themeDefaults[key];
            html += '<div class="theme-color-row">';
            html += '<label class="theme-color-label" for="tc_' + key + '">' + themeLabels[key] + '</label>';
            html += '<div class="theme-color-inputs">';
            html += '<input type="color" id="tc_' + key + '" data-key="' + key + '" value="' + escapeHtml(val) + '" class="theme-color-swatch">';
            html += '<input type="text" id="tc_text_' + key + '" value="' + escapeHtml(val) + '" maxlength="7" class="theme-color-hex" pattern="#[0-9a-fA-F]{6}">';
            html += '</div>';
            html += '</div>';
        });
        grid.innerHTML = html;

        // Sync color picker <-> text field
        Object.keys(themeLabels).forEach(function (key) {
            var picker = document.getElementById('tc_' + key);
            var text   = document.getElementById('tc_text_' + key);
            if (!picker || !text) return;
            picker.addEventListener('input', function () { text.value = picker.value; });
            text.addEventListener('input', function () {
                if (/^#[0-9a-fA-F]{6}$/.test(text.value)) {
                    picker.value = text.value;
                }
            });
        });

        // Save button
        var saveBtn = document.getElementById('saveThemeBtn');
        if (saveBtn) {
            saveBtn.onclick = async function () {
                var colors = {};
                Object.keys(themeLabels).forEach(function (key) {
                    var t = document.getElementById('tc_text_' + key);
                    if (t && /^#[0-9a-fA-F]{6}$/.test(t.value)) colors[key] = t.value;
                });
                var resp = await api('admin/save-theme', { theme: colors });
                // Both branches did the same two things behind the same
                // `if (status)` guard, which left an `if` as the sole statement
                // of an `else` (S6660) and the guard written twice.
                var status = document.getElementById('themeStatus');
                if (!status) return;

                var saved = Boolean(resp?.success);
                status.textContent = saved
                    ? 'Colors saved. Reload the page to apply.'
                    : 'Error saving colors.';
                status.style.color = saved ? 'var(--game-emerald)' : 'var(--game-danger)';
            };
        }

        // Reset to PMPG colours.
        //
        // One click, and it persists. This used to only fill the form in and
        // ask for a second click on "Save Colors", which was wrong twice
        // over: an admin who reset, saw the swatches change and navigated
        // away had migrated nothing, and the second click wrote the five hex
        // values as explicit rows — leaving the installation pinned to
        // today's palette instead of tracking the defaults.
        //
        // admin/reset-theme DELETES those rows instead. Behind the app's own
        // confirmation modal because it discards an admin's customisation
        // irreversibly.
        var resetBtn = document.getElementById('resetThemeBtn');
        if (resetBtn) {
            resetBtn.onclick = async function () {
                var confirmed = await showConfirm(
                    'Reset the theme to the PMPG colours? Any custom colours saved for this '
                    + 'installation will be discarded.'
                );
                if (!confirmed) return;

                var status = document.getElementById('themeStatus');
                var resp = await api('admin/reset-theme', {});
                if (!resp?.success) {
                    if (status) { status.textContent = 'Error resetting colors.'; status.style.color = 'var(--game-danger)'; }
                    return;
                }

                // Repaint from what the server says now applies, not from the
                // local copy of the defaults — the server is authoritative.
                var theme = resp.theme || themeDefaults;
                Object.keys(themeDefaults).forEach(function (key) {
                    var value  = theme[key] || themeDefaults[key];
                    var picker = document.getElementById('tc_' + key);
                    var text   = document.getElementById('tc_text_' + key);
                    if (picker) picker.value = value;
                    if (text)   text.value   = value;
                });
                if (status) { status.textContent = 'Reset to PMPG colours. Reload to apply.'; status.style.color = 'var(--game-emerald)'; }
            };
        }
    }

    async function loadAdminDeadline() {
        var data = await api('admin/get-deadline');
        if (data?.deadline) {
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
        if (!data?.facts) {
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
            html += '<div class="fact-content-display" id="factDisplay' + fact.id + '"></div>';
            html += '<div class="fact-actions">';
            html += '<button class="btn-edit-fact" data-id="' + fact.id + '" title="Edit">Edit</button>';
            html += '<button class="btn-delete-fact" data-id="' + fact.id + '" title="Delete">Del</button>';
            html += '</div>';
            html += '</li>';
        });
        html += '</ul>';
        container.innerHTML = html;

        // Fact bodies are admin-authored and may carry inline markup, so they
        // are never concatenated into the string above — they are filled in
        // here through the same allowlist the server applies.
        data.facts.forEach(function (fact) {
            var target = document.getElementById('factDisplay' + fact.id);
            if (target) setSanitizedHtml(target, fact.content);
        });

        container.querySelectorAll('.btn-delete-fact').forEach(function (btn) {
            btn.addEventListener('click', async function () {
                var id = Number.parseInt(this.dataset.id);
                var confirmed = await showConfirm('Delete this fact?');
                if (!confirmed) return;
                var resp = await api('admin/delete-fact', { id: id });
                if (resp?.success) {
                    loadAdminFacts();
                } else {
                    await showModal(resp ? resp.error : 'Error deleting fact');
                }
            });
        });

        container.querySelectorAll('.btn-edit-fact').forEach(function (btn) {
            btn.addEventListener('click', async function () {
                var id = Number.parseInt(this.dataset.id);
                var display = document.getElementById('factDisplay' + id);
                if (!display) return;
                var currentContent = display.innerHTML;
                var result = await showFactEditor(id, currentContent);
                if (result === null) return;
                var resp = await api('admin/update-fact', { id: id, content: result });
                if (resp?.success) {
                    loadAdminFacts();
                } else {
                    await showModal(resp ? resp.error : 'Error updating fact');
                }
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
            if (data?.success) {
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
            if (data?.success) {
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
            if (data?.success) {
                document.getElementById('deadlineInput').value = '';
                var status = document.getElementById('deadlineStatus');
                status.textContent = 'Deadline cleared';
                status.classList.remove('hidden');
                await showModal('Deadline cleared');
            }
        });

        document.getElementById('saveBoardWindowBtn').addEventListener('click', async function () {
            var input = document.getElementById('boardWindowInput');
            var status = document.getElementById('boardWindowStatus');
            var raw = input.value.trim();

            // Checked here as well as on the server, purely so a typo gets an
            // answer without a round trip. The server does not trust this.
            if (!/^\d+$/.test(raw) || Number.parseInt(raw, 10) > 8760) {
                status.textContent = 'Enter a whole number of hours between 0 and 8760.';
                return;
            }

            var data = await api('admin/set-board-window', { window_hours: Number.parseInt(raw, 10) });
            if (data?.success) {
                status.textContent = data.window_hours === 0
                    ? 'Saved — the wall shows every entry ever recorded.'
                    : 'Saved — the wall shows the last ' + data.window_hours + ' hours.';
            } else {
                status.textContent = (data?.error) ? data.error : 'Could not save the window.';
            }
        });

        document.getElementById('resetGameCounterBtn').addEventListener('click', async function () {
            var confirmed = await showConfirm('Reset game counter based on Hall of Fame entries?');
            if (!confirmed) return;
            var data = await api('admin/reset-game-counter');
            if (data?.success) {
                await showModal('Game counter reset. Total: ' + data.total_games);
                loadGameStats();
            }
        });

        document.getElementById('purgeBtn').addEventListener('click', async function () {
            var confirmed = await showConfirm('Are you sure? This cannot be undone.');
            if (!confirmed) return;
            var data = await api('admin/purge-leaderboard');
            if (data?.success) {
                await showModal('Leaderboard purged');
                loadAdminLeaderboard();
            }
        });

        document.getElementById('addFactBtn').addEventListener('click', async function () {
            var result = await showFactEditor(null, '');
            if (result === null) return;
            var data = await api('admin/add-fact', { content: result });
            if (data?.success) {
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
            showScreen('game');
        });
    }

    function initDropzone() {
        if (typeof Dropzone === 'undefined') return;

        Dropzone.autoDiscover = false;
        var dzEl = document.getElementById('excelDropzone');
        if (!dzEl) return;

        new Dropzone(dzEl, {
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
        html += '<p><em>Last updated: May 2026</em></p>';
        // This screen says who made the game and stops there. It names no
        // supporting organisation, and — just as deliberately — it does not
        // DENY one either.
        //
        // Both halves have been got wrong here before. The wording that
        // preceded this one ("not affiliated with or endorsed by any
        // organisation") became false the moment a lockup went onto the home
        // screen, and a reader seeing both would have trusted neither; the
        // wording that replaced it named a supporter this page has no reason
        // to speak for. Silence is the only position that cannot go stale.
        // Do not reintroduce either sentence.
        //
        // Section 1 below still names ONLY the authors as data controllers.
        // No third party processes anything here, and naming one there would
        // be an inaccurate GDPR declaration.
        html += '<p>This game was created as an educational tool by <strong>Xavier Dubois</strong> and <strong>Niel Buchan</strong>. It is developed and maintained by its authors.</p>';
        html += '<p>This program is distributed in the hope that it will be useful, but WITHOUT ANY WARRANTY; without even the implied warranty of MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the <a href="https://www.gnu.org/licenses/gpl-3.0.html" target="_blank" rel="noopener">GNU General Public License</a> for more details.</p>';

        html += '<h3>1. Data Controller</h3>';
        html += '<p>The data controllers for this application are <strong>Xavier Dubois</strong> and <strong>Niel Buchan</strong>, the developers and maintainers of the ISO 20022 Address Structuring Game. ';
        html += 'For questions regarding data processing, please raise an issue on ' + (kioskMode ? 'GitHub' : '<a href="https://github.com/xdubois-57/iso20022-address-game/issues" target="_blank" rel="noopener">GitHub</a>') + '.</p>';

        html += '<h3>2. Legal Basis for Processing (Art. 6 GDPR)</h3>';
        html += '<p>Personal data is processed on the following legal basis:</p>';
        html += '<ul>';
        html += '<li><strong>Consent (Art. 6(1)(a))</strong> &mdash; By voluntarily entering your name and submitting your score to the Hall of Fame, you explicitly consent to the processing of your name for leaderboard display purposes. You may withdraw consent at any time by raising an issue on GitHub.</li>';
        html += '<li><strong>Legitimate interest (Art. 6(1)(f))</strong> &mdash; A minimal server-side session identifier is used solely to authenticate the administrator and prevent CSRF attacks. No session data is stored for regular players.</li>';
        html += '</ul>';

        html += '<h3>3. Categories of Personal Data Collected</h3>';
        html += '<div class="overflow-auto"><table class="leaderboard-table privacy-data-table"><thead><tr><th>Data</th><th>Purpose</th><th>Storage</th><th>Retention</th></tr></thead><tbody>';
        html += '<tr><td>Player name</td><td>Display on Hall of Fame leaderboard</td><td>Database, encrypted at rest (AES-256-GCM)</td><td>365 days, then automatically deleted</td></tr>';
        html += '<tr><td>Game score &amp; time</td><td>Leaderboard ranking</td><td>Database (not personal data)</td><td>365 days</td></tr>';
        html += '<tr><td>Share token (name + score)</td><td>Social sharing URL generation</td><td>Client-side only, encrypted in URL parameter</td><td>Not stored server-side; expires when URL is no longer shared</td></tr>';
        html += '<tr><td>Session cookie (PHPSESSID)</td><td>CSRF protection &amp; admin authentication</td><td>Server-side; cookie contains only a random session ID</td><td>Browser session (deleted on close)</td></tr>';
        html += '<tr><td>IP address (logs only)</td><td>Security event logging (CSRF failures, admin login attempts)</td><td>Server error logs (not database)</td><td>Depends on server log rotation policy</td></tr>';
        html += '</tbody></table></div>';
        html += '<p>No other personal data (e-mail, device fingerprint, precise location, etc.) is collected, stored, or processed. The session cookie is a strictly necessary technical cookie and does not require consent under GDPR (Recital 30, ePrivacy Directive Art. 5(3) exemption). IP addresses are logged only for security purposes and are not linked to player names or stored in the application database. Share tokens are encrypted and exist only in URLs generated on-demand; they are never stored server-side.</p>';

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
        html += '<p>Leaderboard entries (player name + score) are automatically and permanently deleted after <strong>365 days</strong> via an automated cleanup script. ';
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
        html += '<p>To exercise any of these rights, please raise an issue on ' + (kioskMode ? 'GitHub' : '<a href="https://github.com/xdubois-57/iso20022-address-game/issues" target="_blank" rel="noopener">GitHub</a>') + ' with details of your request. The data controller will respond in a reasonable timeframe.</p>';

        html += '<h3>9. International Data Transfers</h3>';
        html += '<p>This application is hosted by <strong>LWS (Ligne Web Services)</strong>, a French hosting provider located in France (EU). ';
        html += 'All data is stored and processed exclusively within the European Union, and no personal data is transferred outside the EU/EEA.</p>';
        html += '<p>LWS complies with the General Data Protection Regulation (GDPR).</p>';

        html += '<h3>10. Automated Decision-Making (Art. 22)</h3>';
        html += '<p>This application does not perform any automated decision-making or profiling that produces legal or similarly significant effects on individuals. ';
        html += 'Game scores are calculated algorithmically but have no real-world consequences.</p>';

        html += '<h3>11. Children\u2019s Data</h3>';
        html += '<p>This application is designed for professional educational events and is not directed at children under 16. ';
        html += 'If a child\u2019s data has been inadvertently collected, it will be deleted promptly upon request via GitHub issues.</p>';

        html += '<h3>12. Data Breach Notification (Art. 33\u201334)</h3>';
        html += '<p>In the event of a personal data breach, the data controller will notify the competent supervisory authority within 72 hours of becoming aware of the breach, ';
        html += 'and will inform affected data subjects without undue delay if the breach is likely to result in a high risk to their rights and freedoms.</p>';

        html += '<h3>13. Open Source &amp; Transparency</h3>';
        html += '<p>This application is fully open source, enabling complete transparency and independent audit of all data processing activities.</p>';
        html += '<ul>';
        html += '<li><strong>License:</strong> ' + (kioskMode ? 'GNU GPL v3.0' : '<a href="https://www.gnu.org/licenses/gpl-3.0.html" target="_blank" rel="noopener">GNU GPL v3.0</a>') + '</li>';
        html += '<li><strong>Source code:</strong> ' + (kioskMode ? 'github.com/xdubois-57/iso20022-address-game' : '<a href="https://github.com/xdubois-57/iso20022-address-game" target="_blank" rel="noopener">github.com/xdubois-57/iso20022-address-game</a>') + '</li>';
        html += '</ul>';

        html += '</article></section>';
        appContainer.innerHTML = html;
    }

    /* =======================================================
       Utilities
       ======================================================= */
    function copyToClipboard(text) {
        if (navigator.clipboard && navigator.clipboard.writeText) {
            return navigator.clipboard.writeText(text).then(function () { return true; }).catch(function () { return fallbackCopy(text); });
        }
        return Promise.resolve(fallbackCopy(text));
    }

    function fallbackCopy(text) {
        var ta = document.createElement('textarea');
        ta.value = text;
        ta.style.position = 'fixed';
        ta.style.left = '-9999px';
        document.body.appendChild(ta);
        ta.select();
        var ok = false;
        try { ok = document.execCommand('copy'); } catch (e) { /* ignore */ }
        ta.remove();
        return ok;
    }

    /**
     * Custom overlay modal to replace window.alert() — stays in fullscreen.
     *
     * @param {string} message
     * @returns {Promise<void>} settles when the reader dismisses the overlay
     */
    function showModal(message) {
        return new Promise(function (resolve) {
            var overlay = document.createElement('div');
            overlay.className = 'overlay';
            overlay.innerHTML =
                '<div class="overlay-content">' +
                // .overlay-message-multiline keeps white-space:pre-line, so a
                // message can use blank lines to separate what will happen from
                // what it will cost. escapeHtml() still runs: the text is laid
                // out by CSS, never by markup in the string.
                '<p class="overlay-message overlay-message-multiline">'
                + escapeHtml(message) + '</p>' +
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
            // Its own class beside .overlay: the layout also carries the
            // inactivity overlay, so `.overlay-content` alone matches two
            // things and anything selecting on it gets whichever it finds.
            overlay.className = 'overlay confirm-overlay';
            overlay.innerHTML =
                '<div class="overlay-content">' +
                '<p class="overlay-message">' + escapeHtml(message) + '</p>' +
                '<div class="overlay-actions">' +
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
       The Hall of Fame wall (?mode=hof)

       A vertical 42-inch panel that has to live on its own all evening with
       nobody attending it. The screen is a touch screen, but no part of what
       follows binds a single handler: an elbow against the glass must do
       nothing at all.

       The decision-making — who is new, what to show when a request fails,
       how long to wait before retrying — lives in lib/board.js, where it can
       be tested without a screen. What is left here is rendering.
       ======================================================= */
    const WALL_POLL_MS = 5000;
    const WALL_MAX_BACKOFF_MS = 30000;
    const WALL_BANNER_MS = 4000;
    const WALL_HIGHLIGHT_MS = 6000;
    const WALL_PODIUM_SIZE = 3;
    const WALL_MEDALS = ['🥇', '🥈', '🥉'];
    // The server caps this at 50 anyway; asking for it plainly documents that
    // the page never needs more than one screen's worth.
    const WALL_FETCH_LIMIT = 50;

    var wallTracker = null;
    var wallQueue = null;
    var wallBannerTimer = null;
    var wallHighlightTimer = null;
    var wallFailures = 0;
    var wallData = null;
    var wallStale = false;
    var wallHighlightIds = [];
    // How many rows below the podium the viewport can hold. Seeded with a
    // value that is plainly a guess and replaced by a measurement on the
    // first render — never left as a constant, because a 42-inch portrait
    // panel is 1080x1920 on one machine and 2160x3840 on the next.
    var wallRowCapacity = 10;

    function formatWallTime(seconds) {
        var total = Math.max(0, boardNumber(seconds));
        var mins = Math.floor(total / 60);
        var secs = Math.floor(total % 60);
        return mins + ':' + (secs < 10 ? '0' : '') + secs;
    }

    function wallPodiumHtml(entries) {
        // Rendered 2-1-3 so the winner stands in the middle, as on a podium;
        // the CSS gives the centre column its extra height.
        var order = [1, 0, 2];
        var html = '<div class="wall-podium">';
        order.forEach(function (i) {
            var entry = entries[i];
            if (!entry) return;
            html += '<div class="wall-pod wall-pod-' + (i + 1) + '">'
                + '<div class="wall-pod-medal">' + WALL_MEDALS[i] + '</div>'
                + '<div class="wall-pod-name">' + escapeHtml(entry.player_name) + '</div>'
                + '<div class="wall-pod-score">' + boardNumber(entry.game_score) + '</div>'
                + '<div class="wall-pod-time">' + formatWallTime(entry.time_seconds) + '</div>'
                + '</div>';
        });
        return html + '</div>';
    }

    function wallListHtml(entries) {
        if (entries.length === 0) return '';

        var html = '<div class="wall-list"><table><thead><tr>'
            + '<th>#</th><th>Player</th><th>Score</th></tr></thead><tbody>';
        entries.forEach(function (entry) {
            var id = boardNumber(entry.id);
            var fresh = wallHighlightIds.includes(id) ? ' class="wall-fresh"' : '';
            html += '<tr' + fresh + ' data-entry-id="' + id + '">'
                + '<td>' + boardNumber(entry.rank) + '</td>'
                + '<td>' + escapeHtml(entry.player_name) + '</td>'
                + '<td>' + boardNumber(entry.game_score) + '</td></tr>';
        });
        return html + '</tbody></table></div>';
    }

    function renderWall() {
        var entries = (wallData?.entries) || [];
        var podium = entries.slice(0, WALL_PODIUM_SIZE);
        var rest = entries.slice(WALL_PODIUM_SIZE, WALL_PODIUM_SIZE + wallRowCapacity);

        var html = '<section class="wall-screen" id="wallScreen">';
        html += '<h2 class="wall-title">Hall of Fame</h2>';

        if (entries.length === 0) {
            html += '<p class="wall-empty">The first score of the evening goes here.</p>';
        } else {
            html += wallPodiumHtml(podium);
            html += wallListHtml(rest);
        }

        // Always present, even while empty: the banner appearing must not
        // reflow the table underneath it every four seconds.
        html += '<div class="wall-banner-zone" id="wallBannerZone"></div>';
        html += '<span class="wall-stale" id="wallStaleDot"' + (wallStale ? '' : ' hidden')
            + ' title="Showing the last data received"></span>';
        html += '</section>';

        appContainer.innerHTML = html;
        measureWallCapacity();
    }

    /**
     * Work out how many rows actually fit, from what was just laid out.
     *
     * The list is a flex child with `min-height: 0; overflow: hidden`, so its
     * own height is decided by the space left over and NOT by how many rows
     * are inside it. That is what makes this safe to act on: measuring a box
     * that grew with its content would give a different answer after each
     * redraw and oscillate forever. The re-entry guard below is a belt to
     * that brace — a stylesheet edit should not be able to hang a wall.
     */
    var wallMeasuring = false;

    function measureWallCapacity() {
        if (wallMeasuring) return;

        var list = document.querySelector('.wall-list');
        if (!list) return;

        var row = list.querySelector('tbody tr');
        if (!row) return;

        var head = list.querySelector('thead');
        var headHeight = head ? head.getBoundingClientRect().height : 0;
        var available = list.clientHeight - headHeight;

        var capacity = rowsThatFit(available, row.getBoundingClientRect().height);
        if (capacity > 0 && capacity !== wallRowCapacity) {
            wallRowCapacity = capacity;
            wallMeasuring = true;
            try {
                renderWall();
            } finally {
                wallMeasuring = false;
            }
        }
    }

    /**
     * Show the queued banners one after another, about four seconds each.
     *
     * Serialised rather than overlapped: several players can finish between
     * two polls, and two names on top of each other would deny both of them
     * the moment.
     */
    function pumpWallBanners() {
        if (wallBannerTimer) return;

        var banner = nextBanner(wallQueue);
        if (!banner) return;

        var zone = document.getElementById('wallBannerZone');
        if (!zone) { releaseBanner(wallQueue); return; }

        zone.innerHTML = '<div class="wall-banner"><strong>'
            + escapeHtml(banner.name) + '</strong> just made the board — rank '
            + banner.rank + '</div>';

        wallBannerTimer = setTimeout(function () {
            wallBannerTimer = null;
            releaseBanner(wallQueue);
            var z = document.getElementById('wallBannerZone');
            if (z) z.replaceChildren();
            pumpWallBanners();
        }, WALL_BANNER_MS);
    }

    function wallCelebrate() {
        if (!boundConfetti) return;
        // boundConfetti was created with disableForReducedMotion, so this
        // already honours the viewer's preference without a second check.
        boundConfetti({ particleCount: 90, spread: 80, origin: { y: 0.35 } });
        setTimeout(function () {
            boundConfetti({ particleCount: 45, angle: 60, spread: 55, origin: { x: 0, y: 0.5 } });
            boundConfetti({ particleCount: 45, angle: 120, spread: 55, origin: { x: 1, y: 0.5 } });
        }, 250);
    }

    function applyWallArrivals(arrivals) {
        if (arrivals.highlightIds.length > 0) {
            wallHighlightIds = arrivals.highlightIds.slice();
            clearTimeout(wallHighlightTimer);
            wallHighlightTimer = setTimeout(function () {
                wallHighlightIds = [];
                renderWall();
            }, WALL_HIGHLIGHT_MS);
        }

        if (arrivals.banners.length > 0) {
            enqueueBanners(wallQueue, arrivals.banners);
        }

        if (arrivals.highlightIds.length > 0 || arrivals.banners.length > 0) {
            wallCelebrate();
        }
    }

    /**
     * One poll of /board/data.
     *
     * A plain GET, not the api() helper: that one POSTs with a CSRF token
     * bound to a session which expires in 24 minutes, and this page runs for
     * eight hours (see BoardController for the whole argument).
     */
    async function wallPoll() {
        var body = null;
        try {
            var resp = await fetch('/board/data?limit=' + WALL_FETCH_LIMIT, {
                cache: 'no-store',
                credentials: 'omit',
            });
            if (resp.ok) body = await resp.json();
        } catch {
            // Deliberately swallowed. Every failure mode here — offline, DNS
            // gone, a truncated body — means the same thing to the wall: no
            // new data this time. It is counted as a failure below, which is
            // what drives the backoff and the stale dot; there is nobody in
            // front of an unattended screen to show an error to.
            body = null;
        }

        if (body && Array.isArray(body.entries)) {
            wallFailures = 0;
        } else {
            wallFailures++;
        }

        var resolved = resolveDisplayData(wallData, body, wallFailures);
        var wasStale = wallStale;
        wallStale = resolved.stale;

        if (resolved.data !== wallData || wallStale !== wasStale) {
            wallData = resolved.data;
            renderWall();
        }

        if (body) {
            // Only the rows actually drawn count as "visible": an arrival that
            // landed below the fold gets a banner, not a highlight nobody can
            // see.
            var visible = WALL_PODIUM_SIZE + wallRowCapacity;
            var arrivals = diffArrivals(wallTracker, body, visible);
            if (!arrivals.firstLoad) {
                applyWallArrivals(arrivals);
            }
            pumpWallBanners();
        }

        // Scheduled from the end of the previous attempt rather than on a
        // fixed interval, so a slow response cannot pile requests on top of
        // each other on an evening when the server is already struggling.
        var delay = wallFailures === 0
            ? WALL_POLL_MS
            : backoffDelay(wallFailures, WALL_POLL_MS, WALL_MAX_BACKOFF_MS);
        // Not kept: ?mode=hof is a screen nothing navigates away from, so
        // there is no path that would ever cancel this. A handle held only to
        // be written is worse than none — it reads as if stopping were
        // possible.
        setTimeout(wallPoll, delay);
    }

    function startWall() {
        wallTracker = createArrivalTracker();
        wallQueue = createBannerQueue();
        renderWall();
        wallPoll();

        // Re-measure when the panel is rotated or the browser window changes;
        // the row count is a property of the viewport, never a constant.
        window.addEventListener('resize', function () {
            measureWallCapacity();
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
        var ghLink = document.getElementById('footerGithubLink');
        var ghSep  = document.getElementById('footerGithubSep');
        if (ghLink) ghLink.style.display = 'none';
        if (ghSep)  ghSep.style.display  = 'none';
    }

    function disableKioskMode() {
        kioskMode = false;
        document.removeEventListener('fullscreenchange', onFullscreenChange);
        document.removeEventListener('webkitfullscreenchange', onFullscreenChange);
        exitFullscreen();
        var ghLink = document.getElementById('footerGithubLink');
        var ghSep  = document.getElementById('footerGithubSep');
        if (ghLink) ghLink.style.display = '';
        if (ghSep)  ghSep.style.display  = '';
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

        // Mirror the exact same background as the main page (includes ?v= cache-bust hash)
        overlay.style.backgroundImage = getComputedStyle(document.body).backgroundImage;

        var actionWord = hasTouchScreen ? 'Touch' : 'Click';

        overlay.innerHTML = '<div class="screen-saver-inner">'
            + '<div id="ssCountdown" class="ss-countdown"></div>'
            + '<div class="ss-cta">' + actionWord + ' to play the<br>ISO 20022 Address Game</div>'
            + '<div id="ssFactDisplay" class="ss-fact"></div>'
            + '</div>';
        overlay.classList.add('visible');

        // Start countdown in screen saver
        (async function () {
            var data = await api('game/deadline', {});
            if (data?.deadline) {
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
    if (displayMode === 'hof') {
        startWall();
    } else {
        showScreen('game');
    }

})();
