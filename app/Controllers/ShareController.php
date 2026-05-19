<?php
/**
 * ISO 20022 Address Structuring Game
 * Copyright (C) 2026 https://github.com/xdubois-57/iso20022-address-game
 *
 * Handles share page (OG tags), encrypted share tokens, and OG image generation.
 */

namespace App\Controllers;

use App\Controllers\BackgroundController;
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
        $shareScore = $data['s'];
        $shareName  = $data['n'];

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

        $score = (string) $data['s'];
        $name  = $data['n'];

        if (extension_loaded('imagick')) {
            $pngData = $this->buildShareImageImagick($score, $name);
        } else {
            $pngData = $this->buildShareImageGd($score, $name);
        }

        $this->outputPng($pngData);
    }

    /**
     * Build share image using Imagick: SVG world-map background + balloons + text.
     *
     * The background is produced by BackgroundController::buildThemedSvg(),
     * which is the single source of truth for the themed world map — the
     * exact same SVG served at /bg and rendered as the page background.
     */
    private function buildShareImageImagick(string $score, string $name): string
    {
        $theme    = $this->loadTheme();
        $bgHex    = $theme['color_bg']      ?: '#94e3fe';
        $fontBold = $this->findFont(true);

        $w = 1200;
        $h = 630;

        // ── 1. Rasterise the exact same themed SVG used by /bg ──────────────
        $svg = BackgroundController::buildThemedSvg($theme);

        $bgIm = new \Imagick();
        $bgIm->setBackgroundColor(new \ImagickPixel($bgHex));
        $bgIm->setResolution(96, 96);
        $bgIm->readImageBlob($svg);
        $bgIm->setImageFormat('png');
        $bgIm->resizeImage($w, $h, \Imagick::FILTER_LANCZOS, 1);

        $im = new \Imagick();
        $im->newImage($w, $h, new \ImagickPixel($bgHex));
        $im->compositeImage($bgIm, \Imagick::COMPOSITE_OVER, 0, 0);
        $bgIm->destroy();

        // ── 2. Balloons — warm/vivid palette that never clashes with green ──
        $balloonPalette = [
            'rgba(220,50,50,0.80)',
            'rgba(255,140,0,0.80)',
            'rgba(130,50,200,0.80)',
            'rgba(220,50,180,0.80)',
            'rgba(50,100,220,0.80)',
            'rgba(240,200,0,0.80)',
        ];
        // Keep balloons out of centre text zone
        $clearX1 = (int)(($w - 700) / 2);
        $clearX2 = $clearX1 + 700;
        $clearY1 = (int)(($h - 360) / 2);
        $clearY2 = $clearY1 + 360;

        mt_srand(42);
        $placed  = [];
        $maxIter = 200;
        for ($i = 0; $i < 12 && $maxIter > 0; $maxIter--) {
            $r  = mt_rand(22, 40);
            $bx = mt_rand($r + 5, $w - $r - 5);
            $by = mt_rand($r + 5, $h - $r - 5);
            if ($bx + $r > $clearX1 && $bx - $r < $clearX2
                && $by + $r > $clearY1 && $by - $r < $clearY2) {
                continue;
            }
            $ok = true;
            foreach ($placed as [$px, $py, $pr]) {
                if (sqrt(($bx - $px) ** 2 + ($by - $py) ** 2) < ($r + $pr + 15)) {
                    $ok = false;
                    break;
                }
            }
            if (!$ok) { continue; }
            $placed[] = [$bx, $by, $r];
            $i++;

            $col = $balloonPalette[($i - 1) % count($balloonPalette)];
            $bd  = new \ImagickDraw();
            $bd->setFillColor(new \ImagickPixel($col));
            $bd->setStrokeWidth(0);
            $bd->ellipse($bx, $by, $r, (int)($r * 1.15), 0, 360);
            $im->drawImage($bd);
            $bd->destroy();

            $sd = new \ImagickDraw();
            $sd->setStrokeColor(new \ImagickPixel('rgba(0,0,0,0.35)'));
            $sd->setStrokeWidth(1.5);
            $sd->line($bx, $by + $r + 2, $bx + mt_rand(-10, 10), $by + $r + mt_rand(25, 55));
            $im->drawImage($sd);
            $sd->destroy();
        }

        // ── 3. Text — large, centred, with drop-shadow for readability ──────
        $drawText = function (string $text, float $size, string $color, int $yOff) use ($im, $fontBold): void {
            // Shadow pass
            $ds = new \ImagickDraw();
            if ($fontBold) { $ds->setFont($fontBold); }
            $ds->setFontSize($size);
            $ds->setFillColor(new \ImagickPixel('rgba(0,0,0,0.55)'));
            $ds->setGravity(\Imagick::GRAVITY_CENTER);
            $im->annotateImage($ds, 3, $yOff + 3, 0, $text);
            $ds->destroy();
            // Foreground pass
            $df = new \ImagickDraw();
            if ($fontBold) { $df->setFont($fontBold); }
            $df->setFontSize($size);
            $df->setFillColor(new \ImagickPixel($color));
            $df->setGravity(\Imagick::GRAVITY_CENTER);
            $im->annotateImage($df, 0, $yOff, 0, $text);
            $df->destroy();
        };

        $drawText('ISO 20022 Address Game', 52, '#ffffff', -160);
        $drawText($name,                   110, '#ffffff',  -15);
        $drawText($score . ' pts',          80, '#FFD700',  130);

        $im->setImageFormat('png');
        $png = $im->getImageBlob();
        $im->destroy();
        return $png;
    }

    /**
     * GD fallback share image (no Imagick): plain bg + text.
     */
    private function buildShareImageGd(string $score, string $name): string
    {
        $theme    = $this->loadTheme();
        $bgRgb    = ThemeModel::hexToRgb($theme['color_bg'])      ?? [172, 249, 233];
        $emerRgb  = ThemeModel::hexToRgb($theme['color_primary']) ?? [1, 169, 144];
        $textRgb  = ThemeModel::hexToRgb($theme['color_text'])    ?? [51, 61, 62];

        $w = 1200; $h = 630;
        $img      = imagecreatetruecolor($w, $h);
        $bgColor  = imagecolorallocate($img, $bgRgb[0], $bgRgb[1], $bgRgb[2]);
        $emerald  = imagecolorallocate($img, $emerRgb[0], $emerRgb[1], $emerRgb[2]);
        $darkGreen = imagecolorallocate($img, $textRgb[0], $textRgb[1], $textRgb[2]);
        imagefill($img, 0, 0, $bgColor);
        imagefilledrectangle($img, 0, 0, $w, 12, $emerald);

        $fontBold    = $this->findFont(true);
        $fontRegular = $this->findFont(false);

        if ($fontBold && $fontRegular) {
            $this->ttfCentered($img, 42, $fontBold,    'ISO 20022 Address Game', $w, 130, $emerald);
            $this->ttfCentered($img, 56, $fontBold,    $name,                    $w, 320, $darkGreen);
            $this->ttfCentered($img, 52, $fontBold,    $score . ' pts',          $w, 450, $emerald);
        } else {
            $this->gdCentered($img, 5, 'ISO 20022 Address Game', $w, 100, $emerald);
            $this->gdCentered($img, 5, $name,                    $w, 280, $darkGreen);
            $this->gdCentered($img, 5, $score . ' pts',          $w, 400, $emerald);
        }

        ob_start();
        imagepng($img, null, 6);
        $png = ob_get_clean();
        imagedestroy($img);
        return $png;
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

    /**
     * Output a PNG binary blob with correct headers and optional gzip for browsers.
     */
    private function outputPng(string $pngData): void
    {
        header('Content-Type: image/png');
        header('Cache-Control: public, max-age=86400, immutable');
        header('Accept-Ranges: bytes');

        $userAgent = $_SERVER['HTTP_USER_AGENT'] ?? '';
        $crawlers  = ['linkedin', 'facebook', 'twitter', 'slack', 'discord'];
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
