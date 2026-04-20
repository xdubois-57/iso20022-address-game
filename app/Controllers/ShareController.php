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
        $payload = json_encode([
            's' => max(0, min(10000, (int) ($input['score'] ?? 0))),
            'p' => max(0, min(100, (int) ($input['pct'] ?? 0))),
            't' => $this->sanitizeTime($input['time'] ?? '0:00'),
            'n' => $this->sanitizeName($input['name'] ?? ''),
            'w' => max(0, min(99, (int) ($input['perfect'] ?? 0))),
            'r' => max(0, min(99, (int) ($input['rounds'] ?? 0))),
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

        $ogTitle = $data['n'] . ' scored ' . $data['s'] . ' pts on the ISO 20022 Challenge!';
        $ogDescription = $data['p'] . '% accuracy · ' . $data['t'] . ' · ' . $data['w'] . '/' . $data['r'] . ' perfect rounds. Can you beat this score? Play now!';

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
        $pct = $data['p'];
        $time = $data['t'];
        $name = $data['n'];
        $perfect = $data['w'];
        $rounds = $data['r'];

        $w = 1200;
        $h = 630;
        $img = imagecreatetruecolor($w, $h);
        imagesavealpha($img, true);
        imagealphablending($img, true);

        // Swift palette
        $peppermint     = imagecolorallocate($img, 172, 249, 233); // #acf9e9
        $lightPepper    = imagecolorallocate($img, 207, 251, 242); // #cffbf2
        $emerald        = imagecolorallocate($img, 1, 169, 144);   // #01a990
        $darkGreen      = imagecolorallocate($img, 51, 61, 62);    // #333d3e
        $greyGreen      = imagecolorallocate($img, 105, 130, 135); // #698287
        $white          = imagecolorallocate($img, 255, 255, 255);
        $gold           = imagecolorallocate($img, 255, 193, 7);   // party gold

        // --- Background: peppermint ---
        imagefill($img, 0, 0, $peppermint);

        // --- Scattered confetti rectangles and circles for party feel ---
        $confettiPalette = [
            [255, 193, 7],   // gold
            [1, 169, 144],   // emerald
            [51, 61, 62],    // dark green
            [105, 130, 135], // grey green
            [255, 107, 107], // coral
            [69, 183, 209],  // sky blue
            [187, 143, 206], // lavender
            [255, 160, 122], // light salmon
        ];
        mt_srand(crc32($name . $score)); // Deterministic for caching
        for ($i = 0; $i < 50; $i++) {
            $c = $confettiPalette[$i % count($confettiPalette)];
            $alpha = imagecolorallocatealpha($img, $c[0], $c[1], $c[2], mt_rand(80, 105));
            $cx = mt_rand(0, $w);
            $cy = mt_rand(0, $h);
            if ($i % 3 === 0) {
                // Circle
                $r = mt_rand(6, 20);
                imagefilledellipse($img, $cx, $cy, $r * 2, $r * 2, $alpha);
            } elseif ($i % 3 === 1) {
                // Small rotated rectangle (diamond shape)
                $sz = mt_rand(5, 14);
                imagefilledpolygon($img, [
                    $cx, $cy - $sz,
                    $cx + $sz, $cy,
                    $cx, $cy + $sz,
                    $cx - $sz, $cy,
                ], $alpha);
            } else {
                // Star-like burst
                $sz = mt_rand(4, 10);
                imageline($img, $cx - $sz, $cy, $cx + $sz, $cy, $alpha);
                imageline($img, $cx, $cy - $sz, $cx, $cy + $sz, $alpha);
            }
        }

        // --- White rounded card ---
        $cardX = 60;
        $cardY = 40;
        $cardW = $w - 120;
        $cardH = $h - 80;
        imagefilledrectangle($img, $cardX, $cardY, $cardX + $cardW, $cardY + $cardH, $white);

        // Card shadow (subtle)
        $shadow = imagecolorallocatealpha($img, 51, 61, 62, 115);
        imagerectangle($img, $cardX + 1, $cardY + 1, $cardX + $cardW + 1, $cardY + $cardH + 1, $shadow);
        imagerectangle($img, $cardX + 2, $cardY + 2, $cardX + $cardW + 2, $cardY + $cardH + 2, $shadow);

        // Emerald top accent bar on card
        imagefilledrectangle($img, $cardX, $cardY, $cardX + $cardW, $cardY + 6, $emerald);

        // --- Resolve font ---
        $fontBold = $this->findFont(true);
        $fontRegular = $this->findFont(false);

        if ($fontBold && $fontRegular) {
            // Trophy
            $this->ttfCentered($img, 38, $fontBold, 'ISO 20022 Address Challenge', $w, $cardY + 62, $emerald);

            // Player name
            $this->ttfCentered($img, 22, $fontRegular, $name, $w, $cardY + 100, $greyGreen);

            // Separator line
            imageline($img, $w / 2 - 100, $cardY + 120, $w / 2 + 100, $cardY + 120, $lightPepper);

            // Big score
            $this->ttfCentered($img, 100, $fontBold, (string) $score, $w, $cardY + 270, $darkGreen);
            $this->ttfCentered($img, 24, $fontBold, 'POINTS', $w, $cardY + 305, $emerald);

            // Stats
            $stats = $pct . '% accuracy  ·  ' . $time . '  ·  ' . $perfect . '/' . $rounds . ' perfect';
            $this->ttfCentered($img, 19, $fontRegular, $stats, $w, $cardY + 365, $greyGreen);

            // Challenge CTA
            $this->ttfCentered($img, 26, $fontBold, 'Can you beat this score?', $w, $cardY + 420, $emerald);

            // Footer
            $this->ttfCentered($img, 15, $fontRegular, 'Play now at ' . ($_SERVER['HTTP_HOST'] ?? ''), $w, $cardY + $cardH - 20, $greyGreen);
        } else {
            // GD built-in font fallback
            $this->gdCentered($img, 5, 'ISO 20022 Address Challenge', $w, $cardY + 40, $emerald);
            $this->gdCentered($img, 4, $name, $w, $cardY + 75, $greyGreen);
            $this->gdCentered($img, 5, $score . ' POINTS', $w, $cardY + 200, $darkGreen);
            $this->gdCentered($img, 3, $pct . '% accuracy | ' . $time . ' | ' . $perfect . '/' . $rounds . ' perfect', $w, $cardY + 290, $greyGreen);
            $this->gdCentered($img, 4, 'Can you beat this score?', $w, $cardY + 340, $emerald);
            $this->gdCentered($img, 2, 'Play now at ' . ($_SERVER['HTTP_HOST'] ?? ''), $w, $cardY + $cardH - 30, $greyGreen);
        }

        // --- Gold trophy circles in corners of card ---
        $trophyAlpha = imagecolorallocatealpha($img, 255, 193, 7, 90);
        imagefilledellipse($img, $cardX + 50, $cardY + 50, 40, 40, $trophyAlpha);
        imagefilledellipse($img, $cardX + $cardW - 50, $cardY + 50, 40, 40, $trophyAlpha);

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

        // Clamp
        $data['s'] = max(0, min(10000, (int) $data['s']));
        $data['p'] = max(0, min(100, (int) ($data['p'] ?? 0)));
        $data['t'] = $this->sanitizeTime($data['t'] ?? '0:00');
        $data['n'] = $this->sanitizeName($data['n'] ?? '');
        $data['w'] = max(0, min(99, (int) ($data['w'] ?? 0)));
        $data['r'] = max(0, min(99, (int) ($data['r'] ?? 0)));
        return $data;
    }

    private function findFont(bool $bold): ?string
    {
        $candidates = $bold
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
        foreach ($candidates as $path) {
            if (file_exists($path)) {
                return $path;
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
