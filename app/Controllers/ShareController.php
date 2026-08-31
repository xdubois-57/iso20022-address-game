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
use App\Support\Input;

class ShareController
{
    /* =========================================================
       Share card composition
       ========================================================= */

    /** The card is 1200×630 — the size LinkedIn, Facebook and X all expect. */
    private const CARD_W = 1200;
    private const CARD_H = 630;

    /**
     * Balloon colours, tuned to the PMPG palette. The previous set was warm
     * and vivid to sit against the old teal; against #3d345f violet and
     * #8abed9 blue it read as clutter. These are the brand's own violets and
     * sunburst blues plus two warm accents, so the card stays festive without
     * fighting the logo it now carries.
     */
    private const BALLOON_PALETTE = [
        'rgba(93,79,140,0.80)',
        'rgba(240,180,60,0.80)',
        'rgba(58,124,165,0.80)',
        'rgba(214,110,120,0.80)',
        'rgba(44,38,70,0.80)',
        'rgba(126,178,209,0.80)',
    ];

    /** Fixed so the card for a given score is byte-identical on every render. */
    private const BALLOON_SEED = 42;
    private const BALLOON_COUNT = 12;

    /** Endorsement strip geometry. */
    private const ENDORSE_LOGO_W = 260;
    private const ENDORSE_LOGO_BOTTOM_MARGIN = 28;
    private const ENDORSE_LABEL_OFFSET = 205;

    /** Logo height, from the asset's own 1095×282 aspect ratio. */
    private static function endorseLogoHeight(): int
    {
        return (int) round(self::ENDORSE_LOGO_W * 282 / 1095);
    }

    private static function endorseLogoTop(int $h): int
    {
        return $h - self::ENDORSE_LOGO_BOTTOM_MARGIN - self::endorseLogoHeight();
    }

    /**
     * Rectangles no balloon may overlap: the centre text block, and the
     * endorsement strip at the foot of the card.
     *
     * The endorsement zone is the reason this became a list. There was one
     * hardcoded rectangle before, and dropping a logo onto the card without
     * extending the exclusion would have let a balloon land on the PMPG mark
     * on some seeds — the sort of defect that only shows up on somebody
     * else's LinkedIn feed.
     *
     * @return list<array{0:int,1:int,2:int,3:int}> [x1, y1, x2, y2]
     */
    private static function exclusionZones(int $w, int $h): array
    {
        $logoTop = self::endorseLogoTop($h);

        return [
            // Centre text: title, player name, score.
            [
                (int) (($w - 700) / 2),
                (int) (($h - 360) / 2),
                (int) (($w - 700) / 2) + 700,
                (int) (($h - 360) / 2) + 360,
            ],
            // "Supported by" + the lockup, with room around them.
            [
                (int) (($w - self::ENDORSE_LOGO_W) / 2) - 40,
                $logoTop - 60,
                (int) (($w + self::ENDORSE_LOGO_W) / 2) + 40,
                $h,
            ],
        ];
    }

    /**
     * Decide where the balloons go, without drawing anything.
     *
     * Extracted from the drawing code so the placement rules can be tested
     * across many seeds — the layout is deterministic per seed, so testing
     * one seed would only prove that one arrangement happens to be fine.
     * Being pure, it needs neither Imagick nor an HTTP request.
     *
     * The generator is a local xorshift32 rather than mt_srand(): seeding the
     * global RNG here would have made every later mt_rand()/shuffle() in the
     * request predictable.
     *
     * @param  list<array{0:int,1:int,2:int,3:int}> $zones
     * @return list<array{0:int,1:int,2:int,3:int,4:int}> [x, y, radius, tailDx, tailDy]
     */
    public static function planBalloons(
        int $seed,
        int $w,
        int $h,
        array $zones,
        int $count = self::BALLOON_COUNT
    ): array {
        $state = $seed;
        $rand = function (int $min, int $max) use (&$state): int {
            $state ^= ($state << 13) & 0xFFFFFFFF;
            $state ^= $state >> 17;
            $state ^= ($state << 5) & 0xFFFFFFFF;
            $state &= 0xFFFFFFFF;

            return $min + ($state % ($max - $min + 1));
        };

        $placed  = [];
        $maxIter = 200;

        while (count($placed) < $count && $maxIter-- > 0) {
            $r  = $rand(22, 40);
            $bx = $rand($r + 5, $w - $r - 5);
            $by = $rand($r + 5, $h - $r - 5);

            $blocked = false;
            foreach ($zones as [$zx1, $zy1, $zx2, $zy2]) {
                if ($bx + $r > $zx1 && $bx - $r < $zx2 && $by + $r > $zy1 && $by - $r < $zy2) {
                    $blocked = true;
                    break;
                }
            }
            if ($blocked) {
                continue;
            }

            foreach ($placed as [$px, $py, $pr]) {
                if (sqrt(($bx - $px) ** 2 + ($by - $py) ** 2) < ($r + $pr + 15)) {
                    $blocked = true;
                    break;
                }
            }
            if ($blocked) {
                continue;
            }

            // Tail offsets drawn here too, so the sequence of random draws —
            // and therefore the whole layout — stays identical to before.
            $placed[] = [$bx, $by, $r, $rand(-10, 10), $rand(25, 55)];
        }

        return $placed;
    }

    /**
     * POST /api/share/token — Encrypt score data into an opaque share token.
     */
    public function generateToken(): void
    {
        $input = json_decode(file_get_contents('php://input'), true) ?? [];
        // Minimal payload: only score and name for shorter URLs
        $payload = json_encode([
            's' => max(0, min(10000, (int) ($input['score'] ?? 0))),
            // Input::string: sanitizeName()'s string parameter fataled on an
            // array — a 500 any visitor could trigger.
            'n' => $this->sanitizeName(Input::string($input['name'] ?? '')),
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
        $shareText = "\xF0\x9F\x8F\x86 " . $data['n'] . ' scored ' . $data['s']
            . ' pts on the ISO 20022 Address Challenge! Can you beat me?';
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
        $bgHex    = $theme['color_bg']      ?: '#8abed9';
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

        // ── 2. Balloons, placed clear of everything that must stay legible ──
        $balloons = self::planBalloons(self::BALLOON_SEED, $w, $h, self::exclusionZones($w, $h));

        foreach ($balloons as $i => [$bx, $by, $r, $tailDx, $tailDy]) {
            $col = self::BALLOON_PALETTE[$i % count(self::BALLOON_PALETTE)];
            $bd  = new \ImagickDraw();
            $bd->setFillColor(new \ImagickPixel($col));
            $bd->setStrokeWidth(0);
            $bd->ellipse($bx, $by, $r, (int)($r * 1.15), 0, 360);
            $im->drawImage($bd);
            $bd->destroy();

            $sd = new \ImagickDraw();
            $sd->setStrokeColor(new \ImagickPixel('rgba(0,0,0,0.35)'));
            $sd->setStrokeWidth(1.5);
            $sd->line($bx, $by + $r + 2, $bx + $tailDx, $by + $r + $tailDy);
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

        // ── 4. Endorsement strip at the foot of the card ────────────────────
        // This is the most public surface the branding has: a LinkedIn post
        // shows this image to people who will never open the game.
        $drawText('Supported by', 22, '#ffffff', self::ENDORSE_LABEL_OFFSET);

        $logoPath = __DIR__ . '/../../public/assets/images/pmpg-logo.png';
        if (is_file($logoPath)) {
            $logo = new \Imagick($logoPath);
            $logo->resizeImage(self::ENDORSE_LOGO_W, self::endorseLogoHeight(), \Imagick::FILTER_LANCZOS, 1);
            // White-on-transparent would vanish against the pale map, so the
            // lockup keeps its own colours and gets a light plate behind it.
            $plate = new \ImagickDraw();
            // Opaque, like the app icon's disc. A translucent plate looked
            // softer but the GD path cannot match it: GD composites the
            // rounded rectangle from overlapping shapes, and a semi-
            // transparent fill blends twice where they meet, leaving visible
            // seams at the corners. Both paths draw the same solid plate.
            $plate->setFillColor(new \ImagickPixel('#ffffff'));
            $plate->setStrokeWidth(0);
            $plate->roundRectangle(
                (int) (($w - self::ENDORSE_LOGO_W) / 2) - 18,
                self::endorseLogoTop($h) - 12,
                (int) (($w + self::ENDORSE_LOGO_W) / 2) + 18,
                self::endorseLogoTop($h) + self::endorseLogoHeight() + 12,
                10,
                10
            );
            $im->drawImage($plate);
            $plate->destroy();

            $im->compositeImage(
                $logo,
                \Imagick::COMPOSITE_OVER,
                (int) (($w - self::ENDORSE_LOGO_W) / 2),
                self::endorseLogoTop($h)
            );
            $logo->destroy();
        }

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

        $w = 1200;
        $h = 630;
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

        // The endorsement, on this path too. A host without Imagick posting
        // share cards with no PMPG logo would be exactly the sort of
        // half-applied rebrand the icon iteration guarded against.
        $this->drawEndorsementGd($img, $w, $h, $fontBold, $darkGreen);

        ob_start();
        imagepng($img, null, 6);
        $png = ob_get_clean();
        imagedestroy($img);
        return $png;
    }

    /**
     * Filled rounded rectangle. GD has no primitive for one, and a square
     * plate under the logo reads as a sticker rather than as part of the
     * card — Imagick's roundRectangle() is what this matches.
     *
     * @param \GdImage $img
     */
    private function roundedRectangleGd($img, int $x1, int $y1, int $x2, int $y2, int $radius, int $color): void
    {
        $d = $radius * 2;

        // Body: a tall rectangle and a wide one, which together leave only the
        // four corners to fill.
        imagefilledrectangle($img, $x1 + $radius, $y1, $x2 - $radius, $y2, $color);
        imagefilledrectangle($img, $x1, $y1 + $radius, $x2, $y2 - $radius, $color);

        imagefilledellipse($img, $x1 + $radius, $y1 + $radius, $d, $d, $color);
        imagefilledellipse($img, $x2 - $radius, $y1 + $radius, $d, $d, $color);
        imagefilledellipse($img, $x1 + $radius, $y2 - $radius, $d, $d, $color);
        imagefilledellipse($img, $x2 - $radius, $y2 - $radius, $d, $d, $color);
    }

    /**
     * Draw "Supported by" and the PMPG lockup at the foot of a GD canvas.
     *
     * Shared by the score card and the home card so the two cannot drift.
     *
     * @param \GdImage $img
     */
    private function drawEndorsementGd($img, int $w, int $h, ?string $fontBold, int $textColor): void
    {
        $logoW   = self::ENDORSE_LOGO_W;
        $logoH   = self::endorseLogoHeight();
        $logoTop = self::endorseLogoTop($h);
        $logoX   = (int) (($w - $logoW) / 2);

        // A light plate, matching the Imagick path: the lockup keeps its own
        // colours, and the sunburst's palest petals would otherwise be lost
        // against the background — the same reason the app icon has a disc.
        $plate = imagecolorallocate($img, 255, 255, 255);
        $this->roundedRectangleGd(
            $img,
            $logoX - 18,
            $logoTop - 12,
            $logoX + $logoW + 18,
            $logoTop + $logoH + 12,
            10,
            $plate
        );

        if ($fontBold) {
            $this->ttfCentered($img, 20, $fontBold, 'Supported by', $w, $logoTop - 26, $textColor);
        }

        $logoPath = __DIR__ . '/../../public/assets/images/pmpg-logo.png';
        if (!is_file($logoPath)) {
            return;
        }
        $logo = @imagecreatefrompng($logoPath);
        if ($logo === false) {
            return;
        }

        imagealphablending($img, true);
        imagecopyresampled($img, $logo, $logoX, $logoTop, 0, 0, $logoW, $logoH, imagesx($logo), imagesy($logo));
        imagedestroy($logo);
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

        $this->drawEndorsementGd($img, $w, $h, $fontBold, $darkGreen);

        // Render PNG to buffer
        ob_start();
        imagepng($img, null, 6);
        $pngData = ob_get_clean();
        imagedestroy($img);

        $this->outputPng($pngData);
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
                $cx = mt_rand(30, 120);
                $cy = mt_rand(50, $h - 50);
            } elseif ($zone === 1) {
                $cx = mt_rand($w - 120, $w - 30);
                $cy = mt_rand(50, $h - 50);
            } elseif ($zone === 2) {
                $cx = (mt_rand(0, 1) === 0) ? mt_rand(30, 200) : mt_rand($w - 200, $w - 30);
                $cy = mt_rand(30, 120);
            } else {
                $cx = mt_rand(150, $w - 150);
                $cy = mt_rand($h - 120, $h - 30);
            }
            // Zone 3 above scatters balloons along the bottom edge, which is
            // exactly where the endorsement strip now sits — so this canvas
            // needs the same exclusion the score card uses, or a balloon will
            // land on the PMPG logo. Unlike the score card this draw is not
            // seeded, so it would collide only sometimes: worse to debug, not
            // better.
            $overlap = false;
            foreach (self::exclusionZones($w, $h) as [$zx1, $zy1, $zx2, $zy2]) {
                if ($cx + $r > $zx1 && $cx - $r < $zx2 && $cy + $r > $zy1 && $cy - $r < $zy2) {
                    $overlap = true;
                    $i--;
                    break;
                }
            }
            foreach ($balloons as $b) {
                if ($overlap) {
                    break;
                }
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

        // Share tokens are always minted as GCM, so the legacy unauthenticated
        // CTR branch stays off: a forged token must not be able to select it.
        // A missing encryption key makes every token unreadable rather than
        // taking the page down — these routes are hit by social crawlers.
        try {
            $json = (new Encryption())->decrypt($base64);
        } catch (\RuntimeException $e) {
            error_log('SHARE: cannot decrypt token — ' . $e->getMessage());
            return null;
        }
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

    private function sanitizeName(string $raw): string
    {
        $name = trim(strip_tags($raw));
        if ($name === '' || mb_strlen($name) > 50) {
            return 'A player';
        }
        return $name;
    }

    private function getBaseUrl(): string
    {
        return \App\Support\Url::baseUrl();
    }
}
