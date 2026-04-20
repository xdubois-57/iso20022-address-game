<?php
/**
 * ISO 20022 Address Structuring Game
 * Copyright (C) 2026 https://github.com/xdubois-57/iso20022-address-game
 *
 * Handles share page (OG tags), encrypted share tokens, and OG image generation.
 */

namespace App\Controllers;

use App\Models\Encryption;

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

        $w = 1200;
        $h = 630;
        $img = imagecreatetruecolor($w, $h);
        imagealphablending($img, true);
        imagesavealpha($img, true);

        // Swift palette
        $peppermint  = imagecolorallocate($img, 172, 249, 233); // #acf9e9
        $lightPepper = imagecolorallocate($img, 207, 251, 242); // #cffbf2 lighter
        $emerald     = imagecolorallocate($img, 1, 169, 144);   // #01a990
        $darkGreen   = imagecolorallocate($img, 51, 61, 62);    // #333d3e
        $white       = imagecolorallocate($img, 255, 255, 255);

        // Peppermint background
        imagefill($img, 0, 0, $peppermint);

        // Party balloons - colorful circles with strings
        // Place balloons ONLY in margins/corners to avoid text overlap
        // RANDOM positions (not seeded) - changes on each load
        $balloonColors = [
            [1, 169, 144],     // emerald
            [207, 251, 242],   // light peppermint
            [255, 193, 7],     // gold
            [255, 107, 107],   // coral
            [69, 183, 209],    // sky blue
        ];
        
        $balloons = []; // Track placed balloons to prevent overlap
        $attempts = 0;
        $maxAttempts = 100;
        
        for ($i = 0; $i < 12 && $attempts < $maxAttempts; $i++) {
            $attempts++;
            $r = mt_rand(25, 45);
            
            // Constrain balloons to edges/corners only (not center text area)
            $zone = $i % 4;
            if ($zone === 0) {
                // Left margin
                $cx = mt_rand(30, 120);
                $cy = mt_rand(50, $h - 50);
            } elseif ($zone === 1) {
                // Right margin
                $cx = mt_rand($w - 120, $w - 30);
                $cy = mt_rand(50, $h - 50);
            } elseif ($zone === 2) {
                // Top corners only (avoid title text in center)
                $cx = (mt_rand(0, 1) === 0) ? mt_rand(30, 200) : mt_rand($w - 200, $w - 30);
                $cy = mt_rand(30, 120);
            } else {
                // Bottom margin (left and right sides)
                $cx = mt_rand(150, $w - 150);
                $cy = mt_rand($h - 120, $h - 30);
            }
            
            // Check for overlap with existing balloons
            $overlap = false;
            foreach ($balloons as $b) {
                $dist = sqrt(pow($cx - $b['x'], 2) + pow($cy - $b['y'], 2));
                if ($dist < ($r + $b['r'] + 20)) { // 20px minimum spacing
                    $overlap = true;
                    $i--; // Retry this balloon
                    break;
                }
            }
            
            if (!$overlap) {
                $balloons[] = ['x' => $cx, 'y' => $cy, 'r' => $r];
                
                $col = $balloonColors[array_rand($balloonColors)];
                $balloonColor = imagecolorallocatealpha($img, $col[0], $col[1], $col[2], 30);
                
                // Balloon circle
                imagefilledellipse($img, $cx, $cy, $r * 2, $r * 2 + 5, $balloonColor);
                
                // String
                $stringColor = imagecolorallocatealpha($img, 51, 61, 62, 70);
                imageline($img, $cx, $cy + $r + 2, $cx + mt_rand(-10, 10), $cy + $r + mt_rand(30, 60), $stringColor);
            }
        }

        // Top emerald accent bar
        imagefilledrectangle($img, 0, 0, $w, 15, $emerald);

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
            $this->ttfCentered($img, 20, $fontRegular, 'Play now at ' . ($_SERVER['HTTP_HOST'] ?? ''), $w, 600, $darkGreen);
        } else {
            // GD built-in fonts fallback
            $this->gdCentered($img, 5, 'ISO 20022 Address Challenge', $w, 70, $darkGreen);
            $this->gdCentered($img, 4, $name, $w, 130, $darkGreen);
            imageline($img, 300, 165, $w - 300, 165, $emerald);
            $this->gdCentered($img, 5, $score . ' POINTS', $w, 300, $darkGreen);
            imageline($img, 300, 400, $w - 300, 400, $emerald);
            $this->gdCentered($img, 4, 'Can you beat this score?', $w, 460, $emerald);
            $this->gdCentered($img, 2, 'Play now at ' . ($_SERVER['HTTP_HOST'] ?? ''), $w, 550, $darkGreen);
        }

        header('Content-Type: image/png');
        header('Cache-Control: public, max-age=3600');
        imagepng($img, null, 6);
        imagedestroy($img);
    }

    /* --- Helpers --- */

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
        
        // Bundled fonts in public/assets/fonts (always accessible via DOCUMENT_ROOT)
        $docRoot = $_SERVER['DOCUMENT_ROOT'] ?? '';
        $bundledFont = $docRoot . '/assets/fonts/' . $fontFile;
        
        if ($bundledFont && is_file($bundledFont) && is_readable($bundledFont)) {
            return realpath($bundledFont) ?: $bundledFont;
        }
        
        // Fallback to system fonts if bundled fonts not found
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
        return $scheme . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost');
    }
}
