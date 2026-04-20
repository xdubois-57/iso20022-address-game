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

        // Swift palette only - clean and minimal
        $peppermint  = imagecolorallocate($img, 172, 249, 233); // #acf9e9
        $emerald     = imagecolorallocate($img, 1, 169, 144);   // #01a990
        $darkGreen   = imagecolorallocate($img, 51, 61, 62);    // #333d3e
        $greyGreen   = imagecolorallocate($img, 105, 130, 135); // #698287

        // Solid peppermint background
        imagefill($img, 0, 0, $peppermint);

        // Top emerald accent bar
        imagefilledrectangle($img, 0, 0, $w, 12, $emerald);

        // Resolve fonts
        $fontBold = $this->findFont(true);
        $fontRegular = $this->findFont(false);

        if ($fontBold && $fontRegular) {
            // Title
            $this->ttfCentered($img, 36, $fontBold, 'ISO 20022 Address Challenge', $w, 95, $darkGreen);

            // Player name
            $this->ttfCentered($img, 22, $fontRegular, $name, $w, 155, $greyGreen);

            // Separator line
            $lineY = 190;
            imageline($img, 350, $lineY, $w - 350, $lineY, $greyGreen);

            // Giant score
            $this->ttfCentered($img, 130, $fontBold, (string) $score, $w, 360, $darkGreen);
            
            // "POINTS" label
            $this->ttfCentered($img, 26, $fontBold, 'POINTS', $w, 405, $emerald);

            // Separator line
            imageline($img, 350, 450, $w - 350, 450, $greyGreen);

            // Challenge CTA
            $this->ttfCentered($img, 30, $fontBold, 'Can you beat this score?', $w, 515, $emerald);

            // Footer
            $this->ttfCentered($img, 16, $fontRegular, 'Play now at ' . ($_SERVER['HTTP_HOST'] ?? ''), $w, 590, $greyGreen);
        } else {
            // GD built-in fonts fallback
            $this->gdCentered($img, 5, 'ISO 20022 Address Challenge', $w, 70, $darkGreen);
            $this->gdCentered($img, 4, $name, $w, 130, $greyGreen);
            imageline($img, 350, 165, $w - 350, 165, $greyGreen);
            $this->gdCentered($img, 5, $score . ' POINTS', $w, 300, $darkGreen);
            imageline($img, 350, 400, $w - 350, 400, $greyGreen);
            $this->gdCentered($img, 4, 'Can you beat this score?', $w, 460, $emerald);
            $this->gdCentered($img, 2, 'Play now at ' . ($_SERVER['HTTP_HOST'] ?? ''), $w, 550, $greyGreen);
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
