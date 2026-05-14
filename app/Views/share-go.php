<?php
/**
 * Share trigger page — opens native share dialog on mobile devices.
 * When scanned via QR code in kiosk mode, triggers navigator.share().
 *
 * Variables expected: $shareUrl, $shareTitle, $shareText
 */
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Share Result - ISO 20022 Address Game</title>
    <style>
        body { font-family: -apple-system, system-ui, sans-serif; text-align: center; padding: 2rem; background: #acf9e9; color: #333d3e; }
        .card { max-width: 400px; margin: 2rem auto; background: #fff; border-radius: 12px; padding: 2rem; box-shadow: 0 4px 20px rgba(0,0,0,0.1); }
        h1 { font-size: 1.5rem; margin-bottom: 1rem; }
        .btn { display: inline-block; background: #01a990; color: #fff; padding: 1rem 2rem; border-radius: 8px; text-decoration: none; font-size: 1.1rem; border: none; cursor: pointer; margin: 0.5rem; }
        .btn:hover { background: #018a76; }
        .btn-secondary { background: #333d3e; }
        .link-display { word-break: break-all; font-size: 0.85rem; color: #666; margin: 1rem 0; padding: 0.5rem; background: #f0f0f0; border-radius: 4px; }
        .status { margin-top: 1rem; font-size: 0.9rem; color: #01a990; }
    </style>
</head>
<body>
    <div class="card">
        <h1>Share Your Score!</h1>
        <p id="statusText">Opening share dialog...</p>
        <div id="fallback" style="display:none;">
            <p>Challenge 5 friends on social media!</p>
            <button class="btn" id="shareBtn">Share Result</button>
            <button class="btn btn-secondary" id="copyBtn">Copy Link</button>
            <p class="status" id="copyStatus"></p>
        </div>
        <p style="margin-top:2rem;"><a href="/" style="color:#01a990;">Play the game</a></p>
    </div>
    <script>
        var shareUrl = <?= json_encode($shareUrl) ?>;
        var shareTitle = <?= json_encode($shareTitle) ?>;
        var shareText = <?= json_encode($shareText) ?>;

        function showFallback() {
            document.getElementById('statusText').textContent = 'Share your result:';
            document.getElementById('fallback').style.display = 'block';
            // Don't show the URL - just the buttons
        }

        function tryShare() {
            if (navigator.share) {
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
                showFallback();
            }
        }

        // Auto-trigger share on page load
        tryShare();

        // Manual share button
        document.getElementById('shareBtn').addEventListener('click', function() {
            tryShare();
        });

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
