<?php
/**
 * Share page — serves OpenGraph meta tags for social media previews.
 * Real visitors are redirected to the home page via JavaScript.
 *
 * Variables expected: $ogTitle, $ogDescription, $ogImageUrl, $baseUrl
 */
$homeUrl = $baseUrl . '/';
// REQUEST_URI is attacker-supplied; go through the shared sanitiser rather than
// interpolating it into canonical/og:url directly.
$shareUrl = \App\Support\Url::currentUrl();
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= htmlspecialchars($ogTitle, ENT_QUOTES, 'UTF-8') ?></title>
    <link rel="canonical" href="<?= htmlspecialchars($shareUrl, ENT_QUOTES, 'UTF-8') ?>" />
    <meta property="og:title" content="<?= htmlspecialchars($ogTitle, ENT_QUOTES, 'UTF-8') ?>">
    <meta property="og:image" content="<?= htmlspecialchars($ogImageUrl, ENT_QUOTES, 'UTF-8') ?>">
    <meta property="og:image:width" content="1200">
    <meta property="og:image:height" content="630">
    <meta property="og:image:type" content="image/png">
    <meta property="og:url" content="<?= htmlspecialchars($shareUrl, ENT_QUOTES, 'UTF-8') ?>">
    <meta property="og:type" content="website">
    <meta property="og:description" content="<?= htmlspecialchars($ogDescription, ENT_QUOTES, 'UTF-8') ?>">
    <meta property="og:site_name" content="ISO 20022 Address Challenge — supported by the PMPG">
    <meta name="twitter:card" content="summary_large_image">
    <meta name="twitter:title" content="<?= htmlspecialchars($ogTitle, ENT_QUOTES, 'UTF-8') ?>">
    <meta name="twitter:image" content="<?= htmlspecialchars($ogImageUrl, ENT_QUOTES, 'UTF-8') ?>">
    <meta name="twitter:description" content="<?= htmlspecialchars($ogDescription, ENT_QUOTES, 'UTF-8') ?>">
    <script<?= \App\Support\Csp::nonceAttribute() ?>>setTimeout(function(){window.location.replace(<?= json_encode($homeUrl) ?>);},3000);</script>
</head>
<body>
    <p>Redirecting to <a href="<?= htmlspecialchars($homeUrl, ENT_QUOTES, 'UTF-8') ?>">ISO 20022 Address Game</a>...</p>
</body>
</html>
