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

/**
 * The shell is never cached, deliberately and explicitly.
 *
 * Every versioned asset URL on the page is minted HERE, so a cached copy of
 * this document keeps handing out the PREVIOUS `?v=` values — and a browser
 * holding a stale shell can never discover that the CSS or JS moved on. Cache
 * busting is only as fresh as the page that carries the stamps.
 *
 * PHP's session cache limiter happens to send these same headers today, which
 * is why this worked without saying so. That is incidental: it is a php.ini
 * setting a host can change (`session.cache_limiter=`), and losing it would
 * reintroduce exactly the stale-asset bug this file's versioning exists to
 * prevent. Saying it out loud costs nothing and does not depend on a session.
 */
if (!headers_sent()) {
    header('Cache-Control: no-store, no-cache, must-revalidate');
    header('Pragma: no-cache');
}

/**
 * The release stamp half of the cache-busting version, '' on a dev checkout.
 *
 * mtime alone is not enough on a real deployment. The site is uploaded by
 * FTP, and an FTP client set to preserve timestamps writes the new file with
 * the OLD mtime — so `?v={mtime}` produces the same URL it did before the
 * upload, and every browser that already has the file keeps its stale copy
 * indefinitely. That is not hypothetical: a stale app.css renders the page
 * with the PMPG logo at its natural 1095px, which is what a broken layout
 * after an update looks like.
 *
 * The commit written into config/version.php by release.sh changes on every
 * release regardless of what the transfer did to any mtime, so the two
 * together cover each other: a file edited without a release still busts on
 * mtime, and a release whose mtimes were preserved still busts on the commit.
 */
if (!function_exists('assetReleaseStamp')) {
    function assetReleaseStamp(): string {
        static $stamp = null;
        if ($stamp !== null) {
            return $stamp;
        }

        $stamp = '';
        $versionFile = __DIR__ . '/../../config/version.php';
        if (file_exists($versionFile)) {
            $info = include $versionFile;
            if (is_array($info) && !empty($info['commit'])) {
                // Into the URL's query string, so keep it to characters that
                // need no encoding rather than trusting the file's contents.
                $stamp = preg_replace('/[^A-Za-z0-9._-]/', '', (string) $info['commit']);
            }
        }

        return $stamp;
    }
}

// Cache busting helper: file modification timestamp plus the release stamp.
if (!function_exists('assetUrl')) {
    function assetUrl($path) {
        $fullPath = __DIR__ . '/../../public/' . $path;
        $mtime = file_exists($fullPath) ? filemtime($fullPath) : time();
        $release = assetReleaseStamp();

        return $path . '?v=' . $mtime . ($release === '' ? '' : '.' . $release);
    }
}

/**
 * The display mode public/index.php resolved from ?mode, or '' for the three
 * contexts that predate it (mobile, desktop, iPad kiosk).
 *
 * Defaulted here rather than assumed, because this file is included directly
 * by tests and could be included by a future entry point: an undefined
 * variable would emit a warning into the middle of the <body> tag.
 */
$layoutDisplayMode = isset($displayMode) && in_array($displayMode, ['hof', 'play'], true)
    ? $displayMode
    : '';

/**
 * Whether the interface offers sharing, resolved by public/index.php from the
 * `sharing_enabled` setting.
 *
 * Carried on <body> like the display mode, and — like it — the attribute is
 * ABSENT in the ordinary case. A default installation therefore renders the
 * byte-identical <body> tag it rendered before this setting existed, and
 * "no attribute" keeps meaning "behave as you always did".
 *
 * Defaulted to true when the variable is undefined, for the same reason
 * $layoutDisplayMode is defaulted: this file is included directly by tests,
 * and an undefined variable would emit a warning into the middle of the
 * <body> tag. Defaulting the OTHER way would also hide the share buttons in
 * every such context, which is a strange thing for a missing variable to do.
 */
$layoutSharingEnabled = !isset($sharingEnabled) || (bool) $sharingEnabled;

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
        $inRepo = 'cd ' . escapeshellarg($rootDir) . ' && ';
        $tagCmd = $inRepo . "git tag -l 'v*' --sort=-v:refname 2>/dev/null | head -1";
        $commitCmd = $inRepo . 'git rev-parse --short HEAD 2>/dev/null';

        $tag = trim(shell_exec($tagCmd) ?? '');
        $commit = trim(shell_exec($commitCmd) ?? '');
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
    <link rel="stylesheet"
          href="https://cdn.jsdelivr.net/npm/@picocss/pico@2.1.1/css/pico.min.css"
          integrity="sha384-L1dWfspMTHU/ApYnFiMz2QID/PlP1xCW9visvBdbEkOLkSSWsP6ZJWhPw6apiXxU"
          crossorigin="anonymous">
    <link rel="stylesheet"
          href="https://cdn.jsdelivr.net/npm/dropzone@5.9.3/dist/min/dropzone.min.css"
          integrity="sha384-hKRH7ZmTc4+t+iae668SDRfEsjc7HT3VrEMKuSwiDUK4pNQXd/v9BPVpIa0OLlp7"
          crossorigin="anonymous">
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
    <meta name="mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
    <meta name="apple-mobile-web-app-title" content="ISO 20022 Game">
    <link rel="apple-touch-icon" href="/app-icon?v=<?= $bgVersion ?>">
    <meta name="robots" content="index, follow">
    <!-- Kept on one line, like the og/twitter descriptions below: wrapping a
         content="..." attribute puts the literal newline and its indentation
         inside the value a crawler reads and a search result displays. -->
    <meta name="description"
          content="Play the ISO 20022 Address Structuring Game - Learn and test your knowledge of
                   international address formatting standards. Perfect for developers, bankers,
                   and financial professionals.">
    <meta name="keywords"
          content="ISO 20022, address formatting, banking standards, financial messaging,
                   SWIFT, game, quiz, learning, education">
    <meta name="author" content="ISO 20022 Address Game">
    <!-- OpenGraph Meta Tags for Social Media Sharing.
         Titles stay short: LinkedIn truncates around 70 characters and the
         PMPG mention is worth nothing if it lands past the ellipsis, so it
         rides in og:site_name and the description rather than the title. -->
    <meta property="og:title" content="ISO 20022 Address Challenge">
    <!-- Kept on one line: wrapping a content="..." attribute puts the literal
         newline and indentation inside the value the crawler reads. -->
    <meta property="og:description"
          content="Master international address formatting standards. Supported by the
                   Payments Market Practice Group. Test your skills and challenge your friends!">
    <meta property="og:image" content="<?= \App\Support\Url::absoluteHtml('/share/home-image') ?>">
    <meta property="og:image:width" content="1200">
    <meta property="og:image:height" content="630">
    <meta property="og:image:type" content="image/png">
    <meta property="og:url" content="<?= \App\Support\Url::currentUrlHtml() ?>">
    <meta property="og:type" content="website">
    <meta property="og:site_name" content="ISO 20022 Address Challenge — supported by the PMPG">
    <!-- Twitter Card Meta Tags -->
    <meta name="twitter:card" content="summary_large_image">
    <meta name="twitter:title" content="ISO 20022 Address Challenge">
    <meta name="twitter:description"
          content="Master international address formatting standards.
                   Supported by the Payments Market Practice Group.">
    <meta name="twitter:image" content="<?= \App\Support\Url::absoluteHtml('/share/home-image') ?>">
    <meta name="csrf-token" content="<?= htmlspecialchars($_SESSION['csrf_token'] ?? '', ENT_QUOTES, 'UTF-8') ?>">
    <?php
    // app.js builds this one URL itself, so it cannot use assetUrl(). It used
    // to carry a hand-written '?v=1' — a version that, being a literal, could
    // never change: replacing the logo file would have left every browser
    // showing the old one forever.
    ?>
    <meta name="pmpg-logo-url" content="<?= htmlspecialchars(assetUrl('assets/images/pmpg-logo.png'), ENT_QUOTES, 'UTF-8') ?>">
    <?php
    // app.js is versioned by the <script> tag below, but its `import`s are
    // bare relative paths that carry no version at all — so editing
    // lib/format.js changed nothing any browser could see, and the stale copy
    // was served until the cache aged out on its own. An import map is how
    // that is fixed without a build step: the specifier app.js writes stays
    // relative, and the browser fetches the versioned URL named here.
    //
    // Must precede every module script. A browser too old to understand
    // import maps ignores it and loads the unversioned paths — exactly what
    // it did before, so this can only help.
    // Keys must be URL-LIKE ('./...'), not bare: a key without a leading
    // './', '../' or '/' is a bare specifier, and a bare specifier only ever
    // matches an import written exactly that way. app.js writes
    // `import ... from './lib/format.js'`, which the browser resolves to a
    // URL — so the map has to be keyed by that same URL, or it silently
    // matches nothing and the unversioned file is fetched. Document-relative
    // rather than root-absolute ('/assets/...') so an install served from a
    // subdirectory resolves exactly as the <script src> below already does.
    $moduleVersions = [];
    foreach (['scoring', 'address', 'format', 'sanitize', 'random', 'api', 'board'] as $lib) {
        $moduleVersions['./assets/js/lib/' . $lib . '.js'] = './' . assetUrl('assets/js/lib/' . $lib . '.js');
    }
    ?>
    <script type="importmap">
        <?= json_encode(['imports' => $moduleVersions], JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT) ?>
    </script>
</head>
<body<?= $layoutDisplayMode === '' ? '' : ' data-mode="' . htmlspecialchars($layoutDisplayMode, ENT_QUOTES, 'UTF-8') . '"' ?><?= $layoutSharingEnabled ? '' : ' data-sharing="off"' ?>>
    <header class="game-header">
        <div class="header-content">
            <h1 class="logo">ISO 20022 Address Game</h1>
            <?php if ($layoutDisplayMode === '') { ?>
            <button class="hamburger" id="hamburgerBtn" aria-label="Menu" aria-expanded="false">
                <span></span><span></span><span></span>
            </button>
            <nav class="header-nav" id="headerNav">
                <button class="nav-btn" data-screen="game" aria-label="Play">Play</button>
                <button class="nav-btn" data-screen="leaderboard" aria-label="Hall of Fame">Hall of Fame</button>
                <button class="nav-btn" data-screen="admin" aria-label="Admin">Admin</button>
                <button class="nav-btn stop-btn" id="stopBtn" aria-label="Stop">Stop</button>
            </nav>
            <?php } ?>
        </div>
    </header>

    <main class="game-main" id="appContainer">
        <!-- Dynamic SPA content loaded here -->
    </main>

    <?php $ver = getVersionInfo(); ?>
    <footer class="game-footer">
        <span class="footer-text">For entertainment only</span>
        <?php
        /**
         * The wall drops Privacy and GitHub; the play station keeps them.
         *
         * On a wall nobody touches, both links are dead weight that only ever
         * gets tapped by accident — and GitHub would navigate a kiosk-locked
         * browser somewhere it cannot come back from. A player standing at the
         * play station, by contrast, must still be able to reach Privacy.
         */
        if ($layoutDisplayMode !== 'hof') { ?>
        <span class="footer-separator">&bull;</span>
        <a href="#" data-screen="privacy" class="footer-link">Privacy</a>
        <span class="footer-separator" id="footerGithubSep">&bull;</span>
        <a href="https://github.com/xdubois-57/iso20022-address-game"
           target="_blank" rel="noopener"
           class="footer-link" id="footerGithubLink">GitHub</a>
        <?php } ?>
        <!--
            The endorsement rides in the layout rather than in any single
            screen, so it holds on every page — not just the welcome card.
            Deliberately quiet: the card's logo already carries the message,
            and this footer is busy. assetUrl() gives it the same mtime
            cache-busting as the rest of the layout's assets.

            No <br> before it: this div is block-level and starts its own row.

            Not a link, for the same reason the card's logo is not: a kiosk in
            Guided Access cannot come back from an outbound navigation.
        -->
        <div class="footer-endorsement">
            <img src="<?= assetUrl('assets/images/pmpg-logo.png') ?>"
                 alt="Payments Market Practice Group" width="1095" height="282">
        </div>
        <span class="footer-text"><?= htmlspecialchars($ver['tag'], ENT_QUOTES, 'UTF-8') ?>
            (<?= htmlspecialchars($ver['commit'], ENT_QUOTES, 'UTF-8') ?>)</span>
    </footer>

    <!-- Dedicated confetti canvas (iOS Safari fix: avoids position:fixed clipping) -->
    <canvas id="confettiCanvas"
            style="position:fixed;top:0;left:0;width:100%;height:100%;
                   pointer-events:none;z-index:9999;"></canvas>

    <!-- Inactivity overlay -->
    <div id="inactivityOverlay" class="overlay hidden">
        <div class="overlay-content">
            <h2>Are you still there?</h2>
            <p>Session resets in <span id="countdownTimer">10</span> seconds</p>
            <button id="continueBtn" class="btn-primary">I'm still here!</button>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/dropzone@5.9.3/dist/min/dropzone.min.js"
            integrity="sha384-PwiT+fWTPpIySx6DrH1FKraKo+LvVpOClsjx0TSdMYTKi7BR1hR149f4VHLUUnfA"
            crossorigin="anonymous"></script>
    <script src="https://cdn.jsdelivr.net/npm/canvas-confetti@1.9.4/dist/confetti.browser.min.js"
            integrity="sha384-JSZXO0kKYHTylAsDYTb+7Kg2eUyalm19b8Pydcdf8sQ1cCKYZr9lLahoKT9+LFY5"
            crossorigin="anonymous"></script>
    <!-- Served locally: hybrid-mode grading depends on this formatter, and a
         kiosk on a restricted network would otherwise silently fall back to a
         single hardcoded layout for every country. -->
    <script src="<?= assetUrl('assets/js/vendor/address-formatter.js') ?>"></script>
    <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.9/dist/chart.umd.min.js"
            integrity="sha384-b0GXujLkk9eYYSmcSfoyZbfyElGAQnDyY0skCHSG6w3JgTMFnz11ggrTAr7seu9f"
            crossorigin="anonymous"></script>
    <script src="https://cdn.jsdelivr.net/npm/qrcode-generator@1.4.4/qrcode.min.js"
            integrity="sha384-lQXOAyZwHXE55JFyrOMB7nY2Wv+m5ZWNtJcHrd1rceRQXAYNLak8ukN5TjBTcIwz"
            crossorigin="anonymous"></script>
    <!-- type="module" lets app.js import public/assets/js/lib/*.js (extracted
         for tests/js/*.test.js — see tests/js) with no build step: module
         scripts are natively deferred, so this still runs after every
         classic script above has set its global (addressFormatter, confetti,
         Chart, qrcode). -->
    <script type="module" src="<?= assetUrl('assets/js/app.js') ?>"></script>
</body>
</html>
