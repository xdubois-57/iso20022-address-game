<?php
/**
 * ISO 20022 Address Structuring Game
 * Copyright (C) 2026 https://github.com/xdubois-57/iso20022-address-game
 *
 * Handles share page (OG tags), encrypted share tokens, and OG image generation.
 */

namespace App\Controllers;

use App\Models\Database;
use App\Models\Encryption;
use App\Models\ThemeModel;

class ShareController
{
    /**
     * POST /api/share/token — Encrypt score data into an opaque share token.
     */
    public function generateToken(): void
    {
        $input = json_decode(file_get_contents('php://input'), true) ?? [];
        // Minimal payload: only score and name for shorter URLs
        $payload = json_encode([
            's' => max(0, min(10000, (int) ($input['score'] ?? 0))),
            'n' => $this->sanitizeName($input['name'] ?? ''),
        ]);

        $enc = new Encryption();
        $token = $enc->encrypt($payload);
        // Make it URL-safe
        $urlToken = rtrim(strtr($token, '+/', '-_'), '=');

        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['token' => $urlToken]);
    }

    /**
     * GET /share/go?d=<token> — Trigger native share on mobile (scanned from QR code in kiosk mode).
     */
    public function shareGoPage(): void
    {
        $data = $this->decryptToken($_GET['d'] ?? '');
        if (!$data) {
            header('Location: /');
            exit;
        }

        $baseUrl = $this->getBaseUrl();
        $rawToken = $_GET['d'];
        $shareUrl = $baseUrl . '/share?d=' . urlencode($rawToken);
        $shareTitle = "\xF0\x9F\x8F\x86 " . $data['n'] . ' scored ' . $data['s'] . ' pts!';
        $shareText = "\xF0\x9F\x8F\x86 " . $data['n'] . ' scored ' . $data['s'] . ' pts on the ISO 20022 Address Challenge! Can you beat me?';

        require __DIR__ . '/../Views/share-go.php';
    }

    /**
     * GET /share?d=<token> — Serve HTML with OpenGraph meta tags.
     */
    public function sharePage(): void
    {
        $data = $this->decryptToken($_GET['d'] ?? '');
        if (!$data) {
            header('Location: /');
            exit;
        }

        $baseUrl = $this->getBaseUrl();
        $rawToken = $_GET['d'];
        $ogImageUrl = $baseUrl . '/share/image?d=' . urlencode($rawToken);

        $ogTitle = $data['n'] . ' scored ' . $data['s'] . ' points on the ISO 20022 Challenge!';
        $ogDescription = 'Think you can beat ' . $data['s'] . ' points? Play the ISO 20022 Address Challenge now!';

        // Cache headers for social media crawlers
        header('Content-Type: text/html; charset=utf-8');
        header('Cache-Control: public, max-age=3600');

        require __DIR__ . '/../Views/share.php';
    }

    /**
     * GET /share/image?d=<token> — Generate a 1200×630 PNG share card.
     */
    public function shareImage(): void
    {
        $data = $this->decryptToken($_GET['d'] ?? '');
        if (!$data) {
            http_response_code(400);
            exit;
        }

        $score = $data['s'];
        $name = $data['n'];

        [$img, $w, $h, $emerald, $darkGreen] = $this->buildImageCanvas();

        // Resolve fonts
        $fontBold = $this->findFont(true);
        $fontRegular = $this->findFont(false);

        if ($fontBold && $fontRegular) {
            // Title - dark green for contrast
            $this->ttfCentered($img, 48, $fontBold, 'ISO 20022 Address Challenge', $w, 100, $darkGreen);

            // Player name
            $this->ttfCentered($img, 28, $fontRegular, $name, $w, 165, $darkGreen);

            // Separator line
            $lineY = 205;
            imageline($img, 300, $lineY, $w - 300, $lineY, $emerald);
            imageline($img, 300, $lineY + 1, $w - 300, $lineY + 1, $emerald);

            // HUGE score in dark green
            $this->ttfCentered($img, 150, $fontBold, (string) $score, $w, 385, $darkGreen);

            // "POINTS" label in emerald
            $this->ttfCentered($img, 32, $fontBold, 'POINTS', $w, 435, $emerald);

            // Separator line
            imageline($img, 300, 480, $w - 300, 480, $emerald);
            imageline($img, 300, 481, $w - 300, 481, $emerald);

            // Challenge CTA in emerald
            $this->ttfCentered($img, 36, $fontBold, 'Can you beat this score?', $w, 545, $emerald);

            // Footer in dark green
            $safeHost = $this->getSafeHost();
            $this->ttfCentered($img, 20, $fontRegular, 'Play now at ' . $safeHost, $w, 600, $darkGreen);
        } else {
            // GD built-in fonts fallback
            $safeHost = $this->getSafeHost();
            $this->gdCentered($img, 5, 'ISO 20022 Address Challenge', $w, 70, $darkGreen);
            $this->gdCentered($img, 4, $name, $w, 130, $darkGreen);
            imageline($img, 300, 165, $w - 300, 165, $emerald);
            $this->gdCentered($img, 5, $score . ' POINTS', $w, 300, $darkGreen);
            imageline($img, 300, 400, $w - 300, 400, $emerald);
            $this->gdCentered($img, 4, 'Can you beat this score?', $w, 460, $emerald);
            $this->gdCentered($img, 2, 'Play now at ' . $safeHost, $w, 550, $darkGreen);
        }

        // Render PNG to buffer
        ob_start();
        imagepng($img, null, 6);
        $pngData = ob_get_clean();
        imagedestroy($img);

        header('Content-Type: image/png');
        header('Cache-Control: public, max-age=86400, immutable');
        header('Accept-Ranges: bytes');

        // Detect social media crawlers - serve uncompressed for compatibility
        $userAgent = $_SERVER['HTTP_USER_AGENT'] ?? '';
        $crawlers = ['linkedin', 'facebook', 'twitter', 'slack', 'discord'];
        $isCrawler = false;
        foreach ($crawlers as $crawler) {
            if (stripos($userAgent, $crawler) !== false) {
                $isCrawler = true;
                break;
            }
        }

        $acceptEncoding = $_SERVER['HTTP_ACCEPT_ENCODING'] ?? '';
        if (!$isCrawler && strpos($acceptEncoding, 'gzip') !== false && function_exists('gzencode')) {
            $compressed = gzencode($pngData, 6);
            header('Content-Encoding: gzip');
            header('Content-Length: ' . strlen($compressed));
            echo $compressed;
        } else {
            header('Content-Length: ' . strlen($pngData));
            echo $pngData;
        }
    }

    /**
     * GET /share/home-image — Generate a 1200×630 PNG share card for the home page.
     */
    public function homeShareImage(): void
    {
        [$img, $w, $h, $emerald, $darkGreen] = $this->buildImageCanvas();

        // Resolve fonts
        $fontBold = $this->findFont(true);
        $fontRegular = $this->findFont(false);

        if ($fontBold && $fontRegular) {
            // Main title
            $this->ttfCentered($img, 64, $fontBold, 'ISO 20022 Address Game', $w, 180, $darkGreen);

            // Separator line
            $lineY = 250;
            imageline($img, 300, $lineY, $w - 300, $lineY, $emerald);
            imageline($img, 300, $lineY + 1, $w - 300, $lineY + 1, $emerald);

            // Simple features
            $this->ttfCentered($img, 32, $fontRegular, 'Learn - Compete - Challenge', $w, 330, $darkGreen);

            // Separator line
            imageline($img, 300, 390, $w - 300, 390, $emerald);
            imageline($img, 300, 391, $w - 300, 391, $emerald);

            // Call-to-action in emerald
            $this->ttfCentered($img, 40, $fontBold, 'Play Now!', $w, 470, $emerald);
        } else {
            // GD built-in fonts fallback
            $this->gdCentered($img, 5, 'ISO 20022 Address Game', $w, 120, $darkGreen);
            imageline($img, 300, 180, $w - 300, 180, $emerald);
            $this->gdCentered($img, 4, 'Learn - Compete - Challenge', $w, 250, $darkGreen);
            imageline($img, 300, 310, $w - 300, 310, $emerald);
            $this->gdCentered($img, 5, 'Play Now!', $w, 390, $emerald);
        }

        // Render PNG to buffer
        ob_start();
        imagepng($img, null, 6);
        $pngData = ob_get_clean();
        imagedestroy($img);

        header('Content-Type: image/png');
        header('Cache-Control: public, max-age=86400, immutable');
        header('Accept-Ranges: bytes');

        // Detect social media crawlers - serve uncompressed for compatibility
        $userAgent = $_SERVER['HTTP_USER_AGENT'] ?? '';
        $crawlers = ['linkedin', 'facebook', 'twitter', 'slack', 'discord'];
        $isCrawler = false;
        foreach ($crawlers as $crawler) {
            if (stripos($userAgent, $crawler) !== false) {
                $isCrawler = true;
                break;
            }
        }

        $acceptEncoding = $_SERVER['HTTP_ACCEPT_ENCODING'] ?? '';
        if (!$isCrawler && strpos($acceptEncoding, 'gzip') !== false && function_exists('gzencode')) {
            $compressed = gzencode($pngData, 6);
            header('Content-Encoding: gzip');
            header('Content-Length: ' . strlen($compressed));
            echo $compressed;
        } else {
            header('Content-Length: ' . strlen($pngData));
            echo $pngData;
        }
    }

    /* --- Helpers --- */

    /**
     * Build a themed 1200×630 image canvas with background and decorative balloons.
     * Returns [$img, $w, $h, $emeraldColor, $darkGreenColor].
     */
    private function buildImageCanvas(): array
    {
        $theme = $this->loadTheme();

        $bgRgb      = ThemeModel::hexToRgb($theme['color_bg'])           ?? [172, 249, 233];
        $lightRgb   = ThemeModel::hexToRgb($theme['color_primary_light']) ?? [207, 251, 242];
        $emeraldRgb = ThemeModel::hexToRgb($theme['color_primary'])       ?? [1, 169, 144];
        $textRgb    = ThemeModel::hexToRgb($theme['color_text'])          ?? [51, 61, 62];

        $w = 1200;
        $h = 630;
        $img = imagecreatetruecolor($w, $h);
        imagealphablending($img, true);
        imagesavealpha($img, true);

        $bgColor    = imagecolorallocate($img, $bgRgb[0], $bgRgb[1], $bgRgb[2]);
        $emerald    = imagecolorallocate($img, $emeraldRgb[0], $emeraldRgb[1], $emeraldRgb[2]);
        $darkGreen  = imagecolorallocate($img, $textRgb[0], $textRgb[1], $textRgb[2]);

        imagefill($img, 0, 0, $bgColor);

        // Decorative balloons in margins
        $balloonColors = [
            $emeraldRgb,
            $lightRgb,
            [255, 193, 7],
            [255, 107, 107],
            [69, 183, 209],
        ];

        $balloons = [];
        $attempts = 0;
        for ($i = 0; $i < 12 && $attempts < 100; $i++) {
            $attempts++;
            $r    = mt_rand(25, 45);
            $zone = $i % 4;
            if ($zone === 0) {
                $cx = mt_rand(30, 120);        $cy = mt_rand(50, $h - 50);
            } elseif ($zone === 1) {
                $cx = mt_rand($w - 120, $w - 30); $cy = mt_rand(50, $h - 50);
            } elseif ($zone === 2) {
                $cx = (mt_rand(0, 1) === 0) ? mt_rand(30, 200) : mt_rand($w - 200, $w - 30);
                $cy = mt_rand(30, 120);
            } else {
                $cx = mt_rand(150, $w - 150);  $cy = mt_rand($h - 120, $h - 30);
            }
            $overlap = false;
            foreach ($balloons as $b) {
                if (sqrt(pow($cx - $b['x'], 2) + pow($cy - $b['y'], 2)) < ($r + $b['r'] + 20)) {
                    $overlap = true;
                    $i--;
                    break;
                }
            }
            if (!$overlap) {
                $balloons[] = ['x' => $cx, 'y' => $cy, 'r' => $r];
                $col          = $balloonColors[array_rand($balloonColors)];
                $balloonColor = imagecolorallocatealpha($img, $col[0], $col[1], $col[2], 30);
                $stringColor  = imagecolorallocatealpha($img, $textRgb[0], $textRgb[1], $textRgb[2], 70);
                imagefilledellipse($img, $cx, $cy, $r * 2, $r * 2 + 5, $balloonColor);
                imageline($img, $cx, $cy + $r + 2, $cx + mt_rand(-10, 10), $cy + $r + mt_rand(30, 60), $stringColor);
            }
        }

        // Top accent bar
        imagefilledrectangle($img, 0, 0, $w, 15, $emerald);

        return [$img, $w, $h, $emerald, $darkGreen];
    }

    /**
     * Load theme colors from DB if available, otherwise fall back to defaults.
     *
     * @return array<string,string>
     */
    private function loadTheme(): array
    {
        $db = Database::getInstance();
        if ($db->isConnected() || $db->connect()) {
            $pdo = $db->getPdo();
            if ($pdo) {
                return (new ThemeModel($pdo))->get();
            }
        }
        return ThemeModel::defaults();
    }

    private function decryptToken(string $urlToken): ?array
    {
        if ($urlToken === '') {
            return null;
        }
        // Reverse URL-safe base64
        $base64 = strtr($urlToken, '-_', '+/');
        $pad = strlen($base64) % 4;
        if ($pad) {
            $base64 .= str_repeat('=', 4 - $pad);
        }

        $enc = new Encryption();
        $json = $enc->decrypt($base64);
        if ($json === false) {
            return null;
        }
        $data = json_decode($json, true);
        if (!is_array($data) || !isset($data['s'])) {
            return null;
        }

        // Clamp and validate
        $data['s'] = max(0, min(10000, (int) ($data['s'] ?? 0)));
        $data['n'] = $this->sanitizeName($data['n'] ?? '');
        return $data;
    }

    private function findFont(bool $bold): ?string
    {
        $fontFile = $bold ? 'LiberationSans-Bold.ttf' : 'LiberationSans-Regular.ttf';
        
        // Try multiple paths to find bundled fonts
        $docRoot = $_SERVER['DOCUMENT_ROOT'] ?? '';
        $scriptFilename = $_SERVER['SCRIPT_FILENAME'] ?? '';
        
        $candidates = [
            // Path 1: DOCUMENT_ROOT/assets/fonts
            $docRoot . '/assets/fonts/' . $fontFile,
            // Path 2: Same directory as index.php (SCRIPT_FILENAME)
            dirname($scriptFilename) . '/assets/fonts/' . $fontFile,
            // Path 3: Relative to this controller file
            __DIR__ . '/../../public/assets/fonts/' . $fontFile,
        ];
        
        foreach ($candidates as $path) {
            if ($path && is_file($path) && is_readable($path)) {
                return realpath($path) ?: $path;
            }
        }
        
        // Font not found in bundled paths — fall through to system fonts
        
        // Fallback to system fonts
        $systemFonts = $bold
            ? [
                '/System/Library/Fonts/Supplemental/Arial Bold.ttf',
                '/usr/share/fonts/truetype/liberation/LiberationSans-Bold.ttf',
                '/usr/share/fonts/liberation-sans/LiberationSans-Bold.ttf',
                '/usr/share/fonts/truetype/dejavu/DejaVuSans-Bold.ttf',
            ]
            : [
                '/System/Library/Fonts/Supplemental/Arial.ttf',
                '/usr/share/fonts/truetype/liberation/LiberationSans-Regular.ttf',
                '/usr/share/fonts/liberation-sans/LiberationSans-Regular.ttf',
                '/usr/share/fonts/truetype/dejavu/DejaVuSans.ttf',
            ];
        
        foreach ($systemFonts as $path) {
            if (is_file($path) && is_readable($path)) {
                return realpath($path) ?: $path;
            }
        }
        
        return null;
    }

    private function ttfCentered($img, float $size, string $font, string $text, int $imgW, int $y, $color): void
    {
        $box = imagettfbbox($size, 0, $font, $text);
        $textW = abs($box[2] - $box[0]);
        $x = (int) (($imgW - $textW) / 2);
        imagettftext($img, $size, 0, $x, $y, $color, $font, $text);
    }

    private function gdCentered($img, int $font, string $text, int $imgW, int $y, $color): void
    {
        $textW = imagefontwidth($font) * strlen($text);
        $x = (int) (($imgW - $textW) / 2);
        imagestring($img, $font, $x, $y, $text, $color);
    }

    private function getSafeHost(): string
    {
        $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
        if (!preg_match('/^[a-zA-Z0-9.\-]+(:\d{1,5})?$/', $host)) {
            return 'localhost';
        }
        return $host;
    }

    private function sanitizeName(string $raw): string
    {
        $name = trim(strip_tags($raw));
        if ($name === '' || mb_strlen($name) > 50) {
            return 'A player';
        }
        return $name;
    }

    private function sanitizeTime(string $raw): string
    {
        if (preg_match('/^\d{1,3}:\d{2}$/', $raw)) {
            return $raw;
        }
        return '0:00';
    }

    private function getBaseUrl(): string
    {
        $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
        $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
        // Validate host header to prevent host injection attacks
        if (!preg_match('/^[a-zA-Z0-9.\-]+(:\d{1,5})?$/', $host)) {
            $host = 'localhost';
        }
        return $scheme . '://' . $host;
    }
}
