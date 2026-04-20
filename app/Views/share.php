<?php
/**
 * Share page — serves OpenGraph meta tags for social media previews.
 * Real visitors are redirected to the home page via JavaScript.
 *
 * Variables expected: $ogTitle, $ogDescription, $ogImageUrl, $baseUrl
 */
$homeUrl = $baseUrl . '/';
$shareUrl = $baseUrl . $_SERVER['REQUEST_URI'];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <!-- OpenGraph -->
    <meta property="og:type" content="website">
    <meta property="og:title" content="<?= htmlspecialchars($ogTitle, ENT_QUOTES, 'UTF-8') ?>">
    <meta property="og:url" content="<?= htmlspecialchars($shareUrl, ENT_QUOTES, 'UTF-8') ?>">
    <meta property="og:image" content="<?= htmlspecialchars($ogImageUrl, ENT_QUOTES, 'UTF-8') ?>">
    <meta property="og:image:secure_url" content="<?= htmlspecialchars($ogImageUrl, ENT_QUOTES, 'UTF-8') ?>">
    <meta property="og:image:type" content="image/png">
    <meta property="og:image:width" content="1200">
    <meta property="og:image:height" content="630">
    <meta property="og:image:alt" content="<?= htmlspecialchars($ogTitle, ENT_QUOTES, 'UTF-8') ?>">
    <meta property="og:description" content="<?= htmlspecialchars($ogDescription, ENT_QUOTES, 'UTF-8') ?>">

    <!-- Twitter Card -->
    <meta name="twitter:card" content="summary_large_image">
    <meta name="twitter:title" content="<?= htmlspecialchars($ogTitle, ENT_QUOTES, 'UTF-8') ?>">
    <meta name="twitter:image" content="<?= htmlspecialchars($ogImageUrl, ENT_QUOTES, 'UTF-8') ?>">
    <meta name="twitter:description" content="<?= htmlspecialchars($ogDescription, ENT_QUOTES, 'UTF-8') ?>">

    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($ogTitle, ENT_QUOTES, 'UTF-8') ?></title>

    <!-- Redirect real visitors to home page -->
    <meta http-equiv="refresh" content="0;url=<?= htmlspecialchars($homeUrl, ENT_QUOTES, 'UTF-8') ?>">
    <script>window.location.replace(<?= json_encode($homeUrl) ?>);</script>
</head>
<body>
    <p>Redirecting to <a href="<?= htmlspecialchars($homeUrl, ENT_QUOTES, 'UTF-8') ?>">ISO 20022 Address Game</a>...</p>
</body>
</html>
