<?php
/**
 * ISO 20022 Address Structuring Game
 * Copyright (C) 2026 https://github.com/xdubois-57/iso20022-address-game
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program. If not, see <https://www.gnu.org/licenses/>.
 */

namespace App\Controllers;

use App\Models\Database;
use App\Models\ThemeModel;

/**
 * GET /app-icon — Serve a themed PNG apple-touch-icon (180×180).
 *
 * iOS requires PNG for apple-touch-icon; SVG is not supported.
 * Generates the image using GD with:
 *   - Rounded-rect background in color_bg
 *   - Simple game-controller shape in color_primary
 *   - "ISO 20022" label in color_text using the bundled bold font
 *
 * Cache-busted via ?v= in layout.php whenever the theme changes.
 */
class AppIconController
{
    public function generate(): void
    {
        $theme = $this->loadTheme();

        // Parse hex colors into RGB
        $bgRgb   = $this->hexToRgb($theme['color_bg'],      [148, 227, 254]);
        $fgRgb   = $this->hexToRgb($theme['color_text'],    [  0,  54,  74]);
        $iconRgb = $this->hexToRgb($theme['color_primary'], [  0,  54,  74]);

        $size = 180;
        $im   = imagecreatetruecolor($size, $size);
        imagealphablending($im, true);
        imagesavealpha($im, true);

        $bgColor   = imagecolorallocate($im, ...$bgRgb);
        $fgColor   = imagecolorallocate($im, ...$fgRgb);
        $iconColor = imagecolorallocate($im, ...$iconRgb);
        $transparent = imagecolorallocatealpha($im, 0, 0, 0, 127);

        // Fill transparent first, then draw rounded rect background
        imagefill($im, 0, 0, $transparent);
        $this->imageFilledRoundedRect($im, 0, 0, $size - 1, $size - 1, 22, $bgColor);

        // Draw a simple game controller shape centered in upper portion
        $this->drawController($im, $iconColor, $bgColor, 90, 82, 100);

        // Draw "ISO 20022" text centered near bottom
        $font     = __DIR__ . '/../../public/assets/fonts/LiberationSans-Bold.ttf';
        $fontSize = 19;
        $label    = 'ISO 20022';
        if (file_exists($font)) {
            $bbox = imagettfbbox($fontSize, 0, $font, $label);
            $tw   = $bbox[2] - $bbox[0];
            $tx   = (int)(($size - $tw) / 2);
            imagettftext($im, $fontSize, 0, $tx, 158, $fgColor, $font, $label);
        }

        ob_start();
        imagepng($im);
        $png = ob_get_clean();
        imagedestroy($im);

        header('Content-Type: image/png');
        header('Cache-Control: public, max-age=31536000, immutable');
        echo $png;
    }

    private function drawController(\GdImage $im, int $color, int $bg, int $cx, int $cy, int $w): void
    {
        $h  = (int)round($w * 0.55);
        $r  = (int)round($h * 0.42);  // body corner radius
        $x1 = $cx - (int)($w / 2);
        $y1 = $cy - (int)($h / 2);
        $x2 = $cx + (int)($w / 2);
        $y2 = $cy + (int)($h / 2);

        // Main body
        $this->imageFilledRoundedRect($im, $x1, $y1, $x2, $y2, $r, $color);

        // Left grip
        $gw = (int)round($w * 0.18);
        $gh = (int)round($h * 0.38);
        $gr = (int)round($gw * 0.45);
        $this->imageFilledRoundedRect($im, $x1 + 4, $y2 - $gh + 2, $x1 + 4 + $gw, (int)($y2 + $gh / 2), $gr, $color);

        // Right grip
        $this->imageFilledRoundedRect($im, $x2 - 4 - $gw, $y2 - $gh + 2, $x2 - 4, (int)($y2 + $gh / 2), $gr, $color);

        // D-pad (left side): horizontal + vertical bars
        $dp  = $cx - (int)round($w * 0.27);
        $dpy = $cy;
        $ds  = (int)round($w * 0.065);
        $dt  = (int)round($w * 0.022);
        imagefilledrectangle($im, $dp - $ds, $dpy - $dt, $dp + $ds, $dpy + $dt, $bg);
        imagefilledrectangle($im, $dp - $dt, $dpy - $ds, $dp + $dt, $dpy + $ds, $bg);

        // Buttons (right side): 2 small circles
        $bx = $cx + (int)round($w * 0.27);
        $by = $cy;
        $br = (int)round($w * 0.055);
        $gap = (int)round($w * 0.085);
        imagefilledellipse($im, (int)($bx - $gap / 2), $by, $br, $br, $bg);
        imagefilledellipse($im, (int)($bx + $gap / 2), $by, $br, $br, $bg);

        // Center select/start dots
        imagefilledellipse($im, $cx - (int)round($w * 0.07), $cy, (int)round($w * 0.04), (int)round($w * 0.04), $bg);
        imagefilledellipse($im, $cx + (int)round($w * 0.07), $cy, (int)round($w * 0.04), (int)round($w * 0.04), $bg);
    }

    private function imageFilledRoundedRect(\GdImage $im, int $x1, int $y1, int $x2, int $y2, int $r, int $color): void
    {
        // Fill center + edges
        imagefilledrectangle($im, $x1 + $r, $y1, $x2 - $r, $y2, $color);
        imagefilledrectangle($im, $x1, $y1 + $r, $x2, $y2 - $r, $color);
        // Four corners
        imagefilledellipse($im, $x1 + $r, $y1 + $r, $r * 2, $r * 2, $color);
        imagefilledellipse($im, $x2 - $r, $y1 + $r, $r * 2, $r * 2, $color);
        imagefilledellipse($im, $x1 + $r, $y2 - $r, $r * 2, $r * 2, $color);
        imagefilledellipse($im, $x2 - $r, $y2 - $r, $r * 2, $r * 2, $color);
    }

    private function hexToRgb(string $hex, array $default): array
    {
        $hex = ltrim($hex, '#');
        if (strlen($hex) !== 6) return $default;
        return [hexdec(substr($hex, 0, 2)), hexdec(substr($hex, 2, 2)), hexdec(substr($hex, 4, 2))];
    }

    private function loadTheme(): array
    {
        try {
            $db = Database::getInstance();
            if (!$db->isConnected() && !$db->connect()) {
                return ThemeModel::defaults();
            }
            $tm = new ThemeModel($db->getPdo());
            return $tm->get();
        } catch (\Throwable) {
            return ThemeModel::defaults();
        }
    }
}
