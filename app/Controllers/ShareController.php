<?php
/**
 * ISO 20022 Address Structuring Game
 * Copyright (C) 2026 https://github.com/xdubois-57/iso20022-address-game
 *
 * Handles share page (OG tags) and dynamic OG image generation.
 */

namespace App\Controllers;

class ShareController
{
    /**
     * GET /share — Serve an HTML page with OpenGraph meta tags.
     * Social crawlers (Facebook, LinkedIn, WhatsApp) read the OG tags.
     * Real visitors are redirected to the home page via JS.
     */
    public function sharePage(): void
    {
        $score = (int) ($_GET['s'] ?? 0);
        $pct = (int) ($_GET['p'] ?? 0);
        $time = $this->sanitizeTime($_GET['t'] ?? '0:00');
        $name = $this->sanitizeName($_GET['n'] ?? 'A player');
        $perfect = (int) ($_GET['w'] ?? 0);
        $rounds = (int) ($_GET['r'] ?? 0);

        // Clamp values
        $score = max(0, min(10000, $score));
        $pct = max(0, min(100, $pct));
        $perfect = max(0, min(99, $perfect));
        $rounds = max(0, min(99, $rounds));

        // Build OG image URL
        $baseUrl = $this->getBaseUrl();
        $ogImageUrl = $baseUrl . '/share/image?' . http_build_query([
            's' => $score, 'p' => $pct, 't' => $time,
            'n' => $name, 'w' => $perfect, 'r' => $rounds,
        ]);

        $ogTitle = $name . ' scored ' . $score . ' pts on ISO 20022 Challenge!';
        $ogDescription = $pct . '% accuracy · ' . $time . ' · ' . $perfect . '/' . $rounds . ' perfect rounds. Can you beat this score? Play now!';

        require __DIR__ . '/../Views/share.php';
    }

    /**
     * GET /share/image — Generate a PNG share card using GD.
     */
    public function shareImage(): void
    {
        $score = max(0, min(10000, (int) ($_GET['s'] ?? 0)));
        $pct = max(0, min(100, (int) ($_GET['p'] ?? 0)));
        $time = $this->sanitizeTime($_GET['t'] ?? '0:00');
        $name = $this->sanitizeName($_GET['n'] ?? 'A player');
        $perfect = max(0, min(99, (int) ($_GET['w'] ?? 0)));
        $rounds = max(0, min(99, (int) ($_GET['r'] ?? 0)));

        $w = 1200;
        $h = 630;
        $img = imagecreatetruecolor($w, $h);

        // Swift palette colours
        $peppermint = imagecolorallocate($img, 172, 249, 233);      // #acf9e9
        $lightPeppermint = imagecolorallocate($img, 207, 251, 242); // #cffbf2
        $emerald = imagecolorallocate($img, 1, 169, 144);           // #01a990
        $darkGreen = imagecolorallocate($img, 51, 61, 62);          // #333d3e
        $greyGreen = imagecolorallocate($img, 105, 130, 135);       // #698287
        $white = imagecolorallocate($img, 255, 255, 255);           // #ffffff

        // Background: peppermint
        imagefill($img, 0, 0, $peppermint);

        // Top and bottom accent bars
        imagefilledrectangle($img, 0, 0, $w, 8, $emerald);
        imagefilledrectangle($img, 0, $h - 8, $w, $h, $emerald);

        // Decorative circles (subtle)
        for ($i = 0; $i < 25; $i++) {
            $cx = mt_rand(0, $w);
            $cy = mt_rand(0, $h);
            $cr = mt_rand(8, 30);
            $alpha = imagecolorallocatealpha($img, 1, 169, 144, mt_rand(100, 120)); // emerald, very transparent
            imagefilledellipse($img, $cx, $cy, $cr * 2, $cr * 2, $alpha);
        }

        // White card area
        $cardX = 80;
        $cardY = 60;
        $cardW = $w - 160;
        $cardH = $h - 120;
        imagefilledrectangle($img, $cardX, $cardY, $cardX + $cardW, $cardY + $cardH, $white);

        // Card border
        imagerectangle($img, $cardX, $cardY, $cardX + $cardW, $cardY + $cardH, $lightPeppermint);

        // Use built-in GD font sizes (1-5), or a TTF if available
        $fontBold = __DIR__ . '/../../public/assets/fonts/share-bold.ttf';
        $fontRegular = __DIR__ . '/../../public/assets/fonts/share-regular.ttf';
        $hasTTF = file_exists($fontBold) && file_exists($fontRegular);

        if ($hasTTF) {
            $this->renderWithTTF($img, $w, $h, $fontBold, $fontRegular, $score, $pct, $time, $name, $perfect, $rounds, $emerald, $darkGreen, $greyGreen);
        } else {
            $this->renderWithGDFonts($img, $w, $h, $score, $pct, $time, $name, $perfect, $rounds, $emerald, $darkGreen, $greyGreen);
        }

        // Cache for 1 hour
        header('Content-Type: image/png');
        header('Cache-Control: public, max-age=3600');
        imagepng($img);
        imagedestroy($img);
    }

    private function renderWithGDFonts($img, int $w, int $h, int $score, int $pct, string $time, string $name, int $perfect, int $rounds, $emerald, $darkGreen, $greyGreen): void
    {
        // Title
        $title = 'ISO 20022 Address Challenge';
        $this->gdCenteredText($img, 5, $title, $w, 100, $emerald);

        // Player name
        $this->gdCenteredText($img, 4, $name, $w, 150, $greyGreen);

        // Score (large — repeat the number with spacing for visual weight)
        $scoreStr = (string) $score . ' pts';
        $this->gdCenteredText($img, 5, $scoreStr, $w, 280, $darkGreen);

        // Stats
        $stats = $pct . '% accuracy  |  ' . $time . '  |  ' . $perfect . '/' . $rounds . ' perfect';
        $this->gdCenteredText($img, 3, $stats, $w, 370, $greyGreen);

        // Challenge
        $this->gdCenteredText($img, 4, 'Can you beat this score?', $w, 440, $emerald);

        // Footer
        $this->gdCenteredText($img, 2, 'Play now at ' . ($_SERVER['HTTP_HOST'] ?? ''), $w, 530, $greyGreen);
    }

    private function renderWithTTF($img, int $w, int $h, string $fontBold, string $fontRegular, int $score, int $pct, string $time, string $name, int $perfect, int $rounds, $emerald, $darkGreen, $greyGreen): void
    {
        // Title
        $this->ttfCenteredText($img, 36, $fontBold, 'ISO 20022 Address Challenge', $w, 120, $emerald);

        // Trophy emoji placeholder + Player name
        $this->ttfCenteredText($img, 22, $fontRegular, $name, $w, 170, $greyGreen);

        // Score
        $this->ttfCenteredText($img, 90, $fontBold, (string) $score, $w, 330, $darkGreen);
        $this->ttfCenteredText($img, 24, $fontRegular, 'points', $w, 370, $emerald);

        // Stats
        $stats = $pct . '% accuracy  ·  ' . $time . '  ·  ' . $perfect . '/' . $rounds . ' perfect';
        $this->ttfCenteredText($img, 20, $fontRegular, $stats, $w, 430, $greyGreen);

        // Challenge
        $this->ttfCenteredText($img, 26, $fontBold, 'Can you beat this score?', $w, 490, $emerald);

        // Footer
        $this->ttfCenteredText($img, 16, $fontRegular, 'Play now at ' . ($_SERVER['HTTP_HOST'] ?? ''), $w, 560, $greyGreen);
    }

    private function gdCenteredText($img, int $font, string $text, int $imgW, int $y, $color): void
    {
        $textW = imagefontwidth($font) * strlen($text);
        $x = (int) (($imgW - $textW) / 2);
        imagestring($img, $font, $x, $y, $text, $color);
    }

    private function ttfCenteredText($img, float $size, string $font, string $text, int $imgW, int $y, $color): void
    {
        $box = imagettfbbox($size, 0, $font, $text);
        $textW = abs($box[2] - $box[0]);
        $x = (int) (($imgW - $textW) / 2);
        imagettftext($img, $size, 0, $x, $y, $color, $font, $text);
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
