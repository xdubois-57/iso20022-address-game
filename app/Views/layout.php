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

// Load theme colors from DB (with graceful fallback to defaults)
if (!isset($layoutTheme)) {
    $layoutTheme = \App\Models\ThemeModel::defaults();
    $layoutDb = \App\Models\Database::getInstance();
    if ($layoutDb->isConnected() || $layoutDb->connect()) {
        $layoutPdo = $layoutDb->getPdo();
        if ($layoutPdo) {
            $layoutTheme = (new \App\Models\ThemeModel($layoutPdo))->get();
        }
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
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/@picocss/pico@2.1.1/css/pico.min.css" integrity="sha384-L1dWfspMTHU/ApYnFiMz2QID/PlP1xCW9visvBdbEkOLkSSWsP6ZJWhPw6apiXxU" crossorigin="anonymous">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/dropzone@5.9.3/dist/min/dropzone.min.css" integrity="sha384-hKRH7ZmTc4+t+iae668SDRfEsjc7HT3VrEMKuSwiDUK4pNQXd/v9BPVpIa0OLlp7" crossorigin="anonymous">
    <link rel="stylesheet" href="<?= assetUrl('assets/css/app.css') ?>">
    <?php
    // Compute a version hash for the background image:
    // includes theme colors + SVG asset mtime + controller mtime so any
    // code or asset change forces browsers to reload the image.
    $bgVersion = substr(md5(implode('', $layoutTheme)
        . filemtime(__DIR__ . '/../../public/assets/images/world_map.svg')
        . filemtime(__DIR__ . '/../Controllers/BackgroundController.php')
        . filemtime(__DIR__ . '/../Controllers/AppIconController.php')
        . filemtime(__DIR__ . '/../../public/assets/images/emoji-controller.png')
    ), 0, 8);
    $p = $layoutTheme['color_primary'];
    $ph = $layoutTheme['color_primary_hover'];
    $pl = $layoutTheme['color_primary_light'];
    $bg = $layoutTheme['color_bg'];
    $tx = $layoutTheme['color_text'];
    // Derive pico focus as rgba from primary (simplified)
    $pRgb = \App\Models\ThemeModel::hexToRgb($p) ?? [1, 169, 144];
    $picoFocus = 'rgba(' . $pRgb[0] . ',' . $pRgb[1] . ',' . $pRgb[2] . ',0.25)';
    ?>
    <style>
        :root {
            --game-peppermint: <?= htmlspecialchars($bg, ENT_QUOTES) ?>;
            --game-dark-green: <?= htmlspecialchars($tx, ENT_QUOTES) ?>;
            --game-emerald: <?= htmlspecialchars($p, ENT_QUOTES) ?>;
            --game-light-peppermint: <?= htmlspecialchars($pl, ENT_QUOTES) ?>;
            --game-neutral: #f8f8f8;
            --game-grey-green: <?= htmlspecialchars($tx, ENT_QUOTES) ?>;
            --game-white: #ffffff;
            --game-danger: #dc3545;
            --game-danger-bg: #fde8e8;
            --game-success: #28a745;
            --game-success-bg: #e8f8ef;
            --game-gold: #FFD700;
            --game-orange: #FFA500;
            --pico-primary: <?= htmlspecialchars($p, ENT_QUOTES) ?>;
            --pico-primary-background: <?= htmlspecialchars($p, ENT_QUOTES) ?>;
            --pico-primary-border: <?= htmlspecialchars($p, ENT_QUOTES) ?>;
            --pico-primary-underline: <?= htmlspecialchars($p, ENT_QUOTES) ?>;
            --pico-primary-hover: <?= htmlspecialchars($ph, ENT_QUOTES) ?>;
            --pico-primary-hover-background: <?= htmlspecialchars($ph, ENT_QUOTES) ?>;
            --pico-primary-hover-border: <?= htmlspecialchars($ph, ENT_QUOTES) ?>;
            --pico-primary-focus: <?= $picoFocus ?>;
            --pico-primary-inverse: #ffffff;
            --pico-form-element-focus-color: <?= $picoFocus ?>;
        }
        html, body {
            background-image: url('/bg?v=<?= $bgVersion ?>');
            background-size: cover;
            background-position: center;
            background-repeat: no-repeat;
            background-attachment: fixed;
        }
    </style>
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
    <meta name="apple-mobile-web-app-title" content="ISO 20022 Game">
    <link rel="apple-touch-icon" href="/app-icon?v=<?= $bgVersion ?>">
    <meta name="robots" content="index, follow">
    <meta name="description" content="Play the ISO 20022 Address Structuring Game - Learn and test your knowledge of international address formatting standards. Perfect for developers, bankers, and financial professionals.">
    <meta name="keywords" content="ISO 20022, address formatting, banking standards, financial messaging, SWIFT, game, quiz, learning, education">
    <meta name="author" content="ISO 20022 Address Game">
    <!-- OpenGraph Meta Tags for Social Media Sharing -->
    <meta property="og:title" content="ISO 20022 Address Challenge">
    <meta property="og:description" content="Master international address formatting standards. Test your skills, compete for high scores, and challenge your friends!">
    <meta property="og:image" content="<?= 'https://' . ($_SERVER['HTTP_HOST'] ?? 'localhost') ?>/share/home-image">
    <meta property="og:image:width" content="1200">
    <meta property="og:image:height" content="630">
    <meta property="og:image:type" content="image/png">
    <meta property="og:url" content="<?= 'https://' . ($_SERVER['HTTP_HOST'] ?? 'localhost') . $_SERVER['REQUEST_URI'] ?>">
    <meta property="og:type" content="website">
    <meta property="og:site_name" content="ISO 20022 Address Challenge">
    <!-- Twitter Card Meta Tags -->
    <meta name="twitter:card" content="summary_large_image">
    <meta name="twitter:title" content="ISO 20022 Address Challenge">
    <meta name="twitter:description" content="Master international address formatting standards. Test your skills, compete for high scores!">
    <meta name="twitter:image" content="<?= 'https://' . ($_SERVER['HTTP_HOST'] ?? 'localhost') ?>/share/home-image">
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
        <span class="footer-separator" id="footerGithubSep">&bull;</span>
        <a href="https://github.com/xdubois-57/iso20022-address-game" target="_blank" rel="noopener" class="footer-link" id="footerGithubLink">GitHub</a>
        <br/>
        <span class="footer-text"><?= htmlspecialchars($ver['tag'], ENT_QUOTES, 'UTF-8') ?> (<?= htmlspecialchars($ver['commit'], ENT_QUOTES, 'UTF-8') ?>)</span>
    </footer>

    <!-- Dedicated confetti canvas (iOS Safari fix: avoids position:fixed clipping) -->
    <canvas id="confettiCanvas" style="position:fixed;top:0;left:0;width:100%;height:100%;pointer-events:none;z-index:9999;"></canvas>

    <!-- Inactivity overlay -->
    <div id="inactivityOverlay" class="overlay hidden">
        <div class="overlay-content">
            <h2>Are you still there?</h2>
            <p>Session resets in <span id="countdownTimer">10</span> seconds</p>
            <button id="continueBtn" class="btn-primary">I'm still here!</button>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/dropzone@5.9.3/dist/min/dropzone.min.js" integrity="sha384-PwiT+fWTPpIySx6DrH1FKraKo+LvVpOClsjx0TSdMYTKi7BR1hR149f4VHLUUnfA" crossorigin="anonymous"></script>
    <script src="https://cdn.jsdelivr.net/npm/canvas-confetti@1.9.4/dist/confetti.browser.min.js" integrity="sha384-JSZXO0kKYHTylAsDYTb+7Kg2eUyalm19b8Pydcdf8sQ1cCKYZr9lLahoKT9+LFY5" crossorigin="anonymous"></script>
    <script src="https://unpkg.com/@fragaria/address-formatter@4.0.3/dist/address-formatter.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.9/dist/chart.umd.min.js" integrity="sha384-b0GXujLkk9eYYSmcSfoyZbfyElGAQnDyY0skCHSG6w3JgTMFnz11ggrTAr7seu9f" crossorigin="anonymous"></script>
    <script src="https://cdn.jsdelivr.net/npm/qrcode-generator@1.4.4/qrcode.min.js"></script>
    <script src="<?= assetUrl('assets/js/app.js') ?>"></script>
</body>
</html>
