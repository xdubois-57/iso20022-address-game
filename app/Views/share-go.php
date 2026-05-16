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
        body { font-family: -apple-system, system-ui, sans-serif; text-align: center; padding: 2rem; background: <?= $sgBg ?>; color: <?= $sgText ?>; }
        .card { max-width: 400px; margin: 2rem auto; background: #fff; border-radius: 12px; padding: 2rem; box-shadow: 0 4px 20px rgba(0,0,0,0.1); }
        h1 { font-size: 1.5rem; margin-bottom: 1rem; }
        .btn { display: inline-flex; align-items: center; justify-content: center; gap: 0.5rem; background: <?= $sgPrimary ?>; color: #fff; padding: 1rem 2rem; border-radius: 8px; text-decoration: none; font-size: 1.1rem; border: none; cursor: pointer; margin: 0.5rem; }
        .btn:hover { background: <?= $sgHover ?>; }
        .btn-secondary { background: <?= $sgText ?>; }
        /* LinkedIn brand button styling */
        .btn-linkedin { background: #0a66c2; }
        .btn-linkedin:hover { background: #004182; }
        .btn-linkedin svg { width: 20px; height: 20px; fill: currentColor; }
        .link-display { word-break: break-all; font-size: 0.85rem; color: #666; margin: 1rem 0; padding: 0.5rem; background: #f0f0f0; border-radius: 4px; }
        .status { margin-top: 1rem; font-size: 0.9rem; color: <?= $sgPrimary ?>; }
    </style>
</head>
<body>
    <div class="card">
        <h1>Share Your Score!</h1>
        <p id="statusText">Opening share dialog...</p>
        <div id="fallback" style="display:none;">
            <p>Challenge 5 friends on social media!</p>
            <!-- Desktop: LinkedIn share button -->
            <a class="btn btn-linkedin" id="linkedinBtn" href="#" target="_blank" rel="noopener">
                <svg viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg"><path d="M20.447 20.452h-3.554v-5.569c0-1.328-.027-3.037-1.852-3.037-1.853 0-2.136 1.445-2.136 2.939v5.667H9.351V9h3.414v1.561h.046c.477-.9 1.637-1.85 3.37-1.85 3.601 0 4.267 2.37 4.267 5.455v6.286zM5.337 7.433c-1.144 0-2.063-.926-2.063-2.065 0-1.138.92-2.063 2.063-2.063 1.14 0 2.064.925 2.064 2.063 0 1.139-.925 2.065-2.064 2.065zm1.782 13.019H3.555V9h3.564v11.452zM22.225 0H1.771C.792 0 0 .774 0 1.729v20.542C0 23.227.792 24 1.771 24h20.451C23.2 24 24 23.227 24 22.271V1.729C24 .774 23.2 0 22.222 0h.003z"/></svg>
                Share on LinkedIn
            </a>
            <button class="btn btn-secondary" id="copyBtn">Copy Link</button>
            <p class="status" id="copyStatus"></p>
        </div>
        <p style="margin-top:2rem;"><a href="/" style="color:<?= $sgPrimary ?>;">Play the game</a></p>
    </div>
    <script>
        var shareUrl = <?= json_encode($shareUrl) ?>;
        var shareTitle = <?= json_encode($shareTitle) ?>;
        var shareText = <?= json_encode($shareText) ?>;

        // Detect mobile device (has native share capability)
        function isMobile() {
            return navigator.share !== undefined ||
                   /Android|webOS|iPhone|iPad|iPod|BlackBerry|IEMobile|Opera Mini/i.test(navigator.userAgent) ||
                   (window.innerWidth <= 768);
        }

        function showFallback() {
            document.getElementById('statusText').textContent = '';
            document.getElementById('fallback').style.display = 'block';

            // Set up LinkedIn share URL for desktop
            // LinkedIn sharing URL format: https://www.linkedin.com/sharing/share-offsite/?url={encoded_url}
            var linkedinUrl = 'https://www.linkedin.com/sharing/share-offsite/?url=' + encodeURIComponent(shareUrl);
            document.getElementById('linkedinBtn').href = linkedinUrl;
        }

        function tryShare() {
            // On mobile: try native share first
            if (isMobile() && navigator.share) {
                navigator.share({
                    title: shareTitle,
                    text: shareText,
                    url: shareUrl
                }).then(function() {
                    document.getElementById('statusText').textContent = 'Shared successfully!';
                }).catch(function() {
                    showFallback();
                });
            } else {
                // On desktop: show LinkedIn share button immediately
                showFallback();
            }
        }

        // Auto-trigger on page load
        tryShare();

        // Copy button
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
