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

// Cache busting helper: appends file modification timestamp to URL
if (!function_exists('assetUrl')) {
    function assetUrl($path) {
        $fullPath = __DIR__ . '/../../public/' . $path;
        $mtime = file_exists($fullPath) ? filemtime($fullPath) : time();
        return $path . '?v=' . $mtime;
    }
}

// Version info helper: reads from config/version.php or falls back to git
if (!function_exists('getVersionInfo')) {
    function getVersionInfo(): array {
        $versionFile = __DIR__ . '/../../config/version.php';
        if (file_exists($versionFile)) {
            $info = include $versionFile;
            if (is_array($info) && !empty($info['tag']) && !empty($info['commit'])) {
                return $info;
            }
        }
        // Fallback: read from git directly (dev environment)
        $rootDir = __DIR__ . '/../../';
        $tag = trim(shell_exec("cd " . escapeshellarg($rootDir) . " && git tag -l 'v*' --sort=-v:refname 2>/dev/null | head -1") ?? '');
        $commit = trim(shell_exec("cd " . escapeshellarg($rootDir) . " && git rev-parse --short HEAD 2>/dev/null") ?? '');
        return ['tag' => $tag ?: 'dev', 'commit' => $commit ?: 'unknown'];
    }
}
?>
<!DOCTYPE html>
<html lang="en" data-theme="light">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, user-scalable=no">
    <title>ISO 20022 Address Game</title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/@picocss/pico@2.2.2/css/pico.min.css" integrity="sha384-L2RaVCHS9h6hXcb75D7/007oHi3zJENE2zKMlMUn91AH3p8x1SPCeDZmRB7yS6cl" crossorigin="anonymous">
    <link rel="stylesheet" href="https://unpkg.com/dropzone@5.9.3/dist/min/dropzone.min.css" integrity="sha384-hKRH7ZmTc4+t+iae668SDRfEsjc7HT3VrEMKuSwiDUK4pNQXd/v9BPVpIa0OLlp7" crossorigin="anonymous">
    <link rel="stylesheet" href="<?= assetUrl('assets/css/app.css') ?>">
    <meta name="robots" content="noindex, nofollow">
    <meta name="csrf-token" content="<?= htmlspecialchars($_SESSION['csrf_token'] ?? '', ENT_QUOTES, 'UTF-8') ?>">
</head>
<body>
    <header class="game-header">
        <div class="header-content">
            <h1 class="logo">ISO 20022 Address Game</h1>
            <button class="hamburger" id="hamburgerBtn" aria-label="Menu" aria-expanded="false">
                <span></span><span></span><span></span>
            </button>
            <nav class="header-nav" id="headerNav">
                <button class="nav-btn" data-screen="game" aria-label="Play">Play</button>
                <button class="nav-btn" data-screen="leaderboard" aria-label="Hall of Fame">Hall of Fame</button>
                <button class="nav-btn" data-screen="admin" aria-label="Admin">Admin</button>
                <button class="nav-btn stop-btn" id="stopBtn" aria-label="Stop">Stop</button>
            </nav>
        </div>
    </header>

    <main class="game-main" id="appContainer">
        <!-- Dynamic SPA content loaded here -->
    </main>

    <?php $ver = getVersionInfo(); ?>
    <footer class="game-footer">
        <span class="footer-text">For entertainment only</span>
        <span class="footer-separator">&bull;</span>
        <a href="#" data-screen="privacy" class="footer-link">Privacy</a>
        <span class="footer-separator">&bull;</span>
        <a href="https://github.com/xdubois-57/iso20022-address-game" target="_blank" rel="noopener" class="footer-link">GitHub</a>
        <br/>
        <span class="footer-text"><?= htmlspecialchars($ver['tag'], ENT_QUOTES, 'UTF-8') ?> (<?= htmlspecialchars($ver['commit'], ENT_QUOTES, 'UTF-8') ?>)</span>
    </footer>

    <!-- Inactivity overlay -->
    <div id="inactivityOverlay" class="overlay hidden">
        <div class="overlay-content">
            <h2>Are you still there?</h2>
            <p>Session resets in <span id="countdownTimer">10</span> seconds</p>
            <button id="continueBtn" class="btn-primary">I'm still here!</button>
        </div>
    </div>

    <script src="https://unpkg.com/dropzone@5.9.3/dist/min/dropzone.min.js" integrity="sha384-PwiT+fWTPpIySx6DrH1FKraKo+LvVpOClsjx0TSdMYTKi7BR1hR149f4VHLUUnfA" crossorigin="anonymous"></script>
    <script src="https://cdn.jsdelivr.net/npm/canvas-confetti@1.9.3/dist/confetti.browser.min.js" integrity="sha384-Rv68Y7adOjMMJc1/xFMcdNvXre/HF51to4GZjBALmXr7ABnVl5V4UajJwBu7zbhN" crossorigin="anonymous"></script>
    <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.9/dist/chart.umd.min.js" integrity="sha384-b0GXujLkk9eYYSmcSfoyZbfyElGAQnDyY0skCHSG6w3JgTMFnz11ggrTAr7seu9f" crossorigin="anonymous"></script>
    <script src="<?= assetUrl('assets/js/app.js') ?>"></script>
</body>
</html>
