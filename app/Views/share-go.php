<?php
/**
 * Share trigger page — opens native share dialog on mobile devices.
 * When scanned via QR code in kiosk mode, triggers navigator.share().
 *
 * Variables expected: $shareUrl, $shareTitle, $shareText
 */
use App\Models\Database;
use App\Models\ThemeModel;

$shareGoTheme = ThemeModel::defaults();
$shareGoDb = Database::getInstance();
if ($shareGoDb->isConnected() || $shareGoDb->connect()) {
    $shareGoPdo = $shareGoDb->getPdo();
    if ($shareGoPdo) {
        $shareGoTheme = (new ThemeModel($shareGoPdo))->get();
    }
}
$sgPrimary = htmlspecialchars($shareGoTheme['color_primary'], ENT_QUOTES);
$sgHover   = htmlspecialchars($shareGoTheme['color_primary_hover'], ENT_QUOTES);
$sgBg      = htmlspecialchars($shareGoTheme['color_bg'], ENT_QUOTES);
$sgText    = htmlspecialchars($shareGoTheme['color_text'], ENT_QUOTES);
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Share Result - ISO 20022 Address Game</title>
    <style>
        html, body {
            margin: 0; padding: 0;
            font-family: 'Arial Nova', Arial, Helvetica, sans-serif;
            background-image: url('/bg');
            background-position: center center;
            background-size: cover;
            background-repeat: no-repeat;
            background-attachment: fixed;
            color: <?= $sgText ?>;
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 2rem;
            box-sizing: border-box;
        }
        .card {
            max-width: 420px;
            width: 100%;
            background: #fff;
            border-radius: 16px;
            padding: 2.5rem 2rem;
            box-shadow: 0 8px 32px rgba(0,0,0,0.12);
            text-align: center;
        }
        .card h1 { font-size: 2rem; color: <?= $sgText ?>; margin: 0 0 0.25rem; }
        .score-value {
            font-size: 4rem;
            font-weight: 800;
            color: <?= $sgPrimary ?>;
            line-height: 1;
            margin: 0.5rem 0 0.25rem;
        }
        .score-label { font-size: 1rem; color: <?= $sgText ?>; margin: 0 0 1.5rem; opacity: 0.75; }
        .status-text { min-height: 1.5rem; font-size: 0.9rem; color: <?= $sgPrimary ?>; margin-bottom: 0.5rem; }
        .actions {
            display: flex;
            flex-direction: column;
            gap: 0.5rem;
            align-items: stretch;
        }
        .btn {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 0.5rem;
            width: 100%;
            box-sizing: border-box;
            background: <?= $sgPrimary ?>;
            color: #fff;
            padding: 0.75rem 1.5rem;
            border-radius: 8px;
            text-decoration: none;
            font-size: 1rem;
            font-weight: 600;
            border: none;
            cursor: pointer;
            transition: background 0.2s;
        }
        .btn:hover { background: <?= $sgHover ?>; }
        /* btn-share matches game over page Challenge a Friend button */
        .btn-share {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 0.5rem;
            width: 100%;
            box-sizing: border-box;
            background: linear-gradient(135deg, #ffd700, #ff8c00);
            color: #00364a;
            padding: 0.75rem 1.5rem;
            border-radius: 8px;
            text-decoration: none;
            font-size: 1rem;
            font-weight: 700;
            border: none;
            cursor: pointer;
            box-shadow: 0 2px 8px rgba(255, 140, 0, 0.3);
            transition: transform 0.15s, box-shadow 0.15s;
        }
        .btn-share:hover {
            transform: translateY(-1px);
            box-shadow: 0 4px 16px rgba(255, 140, 0, 0.4);
        }
        .btn-linkedin { background: #0a66c2; }
        .btn-linkedin:hover { background: #004182; }
        .btn-linkedin svg { width: 18px; height: 18px; fill: currentColor; flex-shrink: 0; }
        .share-row {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 0.5rem;
            width: 100%;
        }
        @media (max-width: 360px) { .share-row { grid-template-columns: 1fr; } }
        .copy-status { font-size: 0.85rem; color: <?= $sgPrimary ?>; min-height: 1.2rem; margin-top: 0.25rem; }
    </style>
</head>
<body>
    <div class="card">
        <h1>🎉 Game Over!</h1>
        <div class="score-value"><?= (int)$shareScore ?></div>
        <p class="score-label">points scored by <?= htmlspecialchars($shareName, ENT_QUOTES, 'UTF-8') ?></p>

        <p class="status-text" id="statusText">Opening share dialog...</p>

        <div class="actions" id="actions" style="display:none;">
            <!-- Desktop only: LinkedIn + Copy Link side by side -->
            <div class="share-row" id="desktopShareRow" style="display:none;">
                <a class="btn btn-linkedin" id="linkedinBtn" href="#" target="_blank" rel="noopener">
                    <svg viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg"><path d="M20.447 20.452h-3.554v-5.569c0-1.328-.027-3.037-1.852-3.037-1.853 0-2.136 1.445-2.136 2.939v5.667H9.351V9h3.414v1.561h.046c.477-.9 1.637-1.85 3.37-1.85 3.601 0 4.267 2.37 4.267 5.455v6.286zM5.337 7.433c-1.144 0-2.063-.926-2.063-2.065 0-1.138.92-2.063 2.063-2.063 1.14 0 2.064.925 2.064 2.063 0 1.139-.925 2.065-2.064 2.065zm1.782 13.019H3.555V9h3.564v11.452zM22.225 0H1.771C.792 0 0 .774 0 1.729v20.542C0 23.227.792 24 1.771 24h20.451C23.2 24 24 23.227 24 22.271V1.729C24 .774 23.2 0 22.222 0h.003z"/></svg>
                    Share on LinkedIn
                </a>
                <button class="btn btn-linkedin" id="copyBtn">📋 Copy Link</button>
            </div>
            <!-- Mobile: native share button matching game over page style -->
            <button class="btn-share" id="mobileShareBtn" style="display:none;">📤 Challenge a Friend</button>
            <a class="btn" href="/">🎮 Play the Game</a>
        </div>
        <p class="copy-status" id="copyStatus"></p>
    </div>
    <script>
        var shareUrl = <?= json_encode($shareUrl) ?>;
        var shareTitle = <?= json_encode($shareTitle) ?>;
        var shareText = <?= json_encode($shareText) ?>;

        function isMobileDevice() {
            return /Android|webOS|iPhone|iPad|iPod|BlackBerry|IEMobile|Opera Mini/i.test(navigator.userAgent) ||
                   ('ontouchstart' in window) ||
                   (window.innerWidth <= 768);
        }

        function showActions() {
            document.getElementById('statusText').textContent = '';
            document.getElementById('actions').style.display = 'flex';

            if (!isMobileDevice()) {
                // Desktop: show LinkedIn + Copy Link side by side
                var linkedinUrl = 'https://www.linkedin.com/sharing/share-offsite/?url=' + encodeURIComponent(shareUrl);
                document.getElementById('linkedinBtn').href = linkedinUrl;
                document.getElementById('desktopShareRow').style.display = 'grid';
            } else if (navigator.share) {
                // Mobile with native share: show share button
                document.getElementById('mobileShareBtn').style.display = 'inline-flex';
            }
            // Mobile without native share: only shows "Play the Game"
        }

        function tryShare() {
            if (isMobileDevice() && navigator.share) {
                navigator.share({
                    title: shareTitle,
                    text: shareText,
                    url: shareUrl
                }).then(function() {
                    document.getElementById('statusText').textContent = 'Shared successfully!';
                    showActions();
                }).catch(function() {
                    showActions();
                });
            } else {
                showActions();
            }
        }

        tryShare();

        // Mobile share button click handler
        document.getElementById('mobileShareBtn').addEventListener('click', function() {
            if (navigator.share) {
                navigator.share({
                    title: shareTitle,
                    text: shareText,
                    url: shareUrl
                }).then(function() {
                    document.getElementById('statusText').textContent = 'Shared successfully!';
                }).catch(function() {
                    // user cancelled - keep actions visible
                });
            }
        });

        document.getElementById('copyBtn').addEventListener('click', function() {
            if (navigator.clipboard && navigator.clipboard.writeText) {
                navigator.clipboard.writeText(shareUrl).then(function() {
                    document.getElementById('copyStatus').textContent = 'Link copied!';
                });
            } else {
                var ta = document.createElement('textarea');
                ta.value = shareUrl;
                ta.style.position = 'fixed';
                ta.style.left = '-9999px';
                document.body.appendChild(ta);
                ta.select();
                try { document.execCommand('copy'); document.getElementById('copyStatus').textContent = 'Link copied!'; } catch(e) {}
                ta.remove();
            }
        });
    </script>
</body>
</html>
