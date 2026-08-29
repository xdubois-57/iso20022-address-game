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
 * Generates the image using Imagick:
 *   - Rounded-rect background in theme color_bg
 *   - Color emoji PNG (emoji-controller.png) composited on top
 *   - "ISO 20022" label in theme color_text using the bundled bold font
 *
 * Cache-busted via ?v= in layout.php whenever the theme changes.
 */
class AppIconController
{
    public function generate(): void
    {
        $theme    = $this->loadTheme();
        $bg       = $theme['color_bg']      ?: '#94e3fe';
        $fg       = $theme['color_text']    ?: '#00364a';
        $font     = __DIR__ . '/../../public/assets/fonts/LiberationSans-Bold.ttf';
        $emojiPng = __DIR__ . '/../../public/assets/images/emoji-controller.png';

        $size = 180;

        // ShareController guards this; this route did not, so on a host without
        // Imagick the apple-touch-icon was a fatal 500 rather than a missing
        // icon. Fall back to GD, which every PHP build here already relies on.
        if (!extension_loaded('imagick')) {
            $this->generateWithGd($size, $bg, $fg, $font, $emojiPng);
            return;
        }

        $im = new \Imagick();
        $im->newImage($size, $size, new \ImagickPixel('white'));

        // Rounded background
        $d = new \ImagickDraw();
        $d->setFillColor(new \ImagickPixel($bg));
        $d->setStrokeWidth(0);
        $d->roundRectangle(0, 0, $size - 1, $size - 1, 22, 22);
        $im->drawImage($d);

        // Composite the color emoji PNG centered in upper portion
        if (file_exists($emojiPng)) {
            $emoji = new \Imagick($emojiPng);
            $emoji->scaleImage(100, 100);
            $ex = (int)(($size - 100) / 2);  // horizontally centered
            $ey = 18;                          // top padding
            $im->compositeImage($emoji, \Imagick::COMPOSITE_OVER, $ex, $ey);
            $emoji->destroy();
        }

        // "ISO 20022" label
        $d2 = new \ImagickDraw();
        $d2->setFont($font);
        $d2->setFontSize(20);
        $d2->setFillColor(new \ImagickPixel($fg));
        $d2->setGravity(\Imagick::GRAVITY_CENTER);
        $im->annotateImage($d2, 0, 68, 0, 'ISO 20022');

        $im->setImageFormat('png');
        $png = $im->getImageBlob();
        $im->destroy();

        header('Content-Type: image/png');
        header('Cache-Control: public, max-age=31536000, immutable');
        echo $png;
    }

    /**
     * GD rendering of the same icon, for hosts without Imagick.
     *
     * GD cannot composite a colour emoji font, so the bundled emoji PNG is
     * scaled in the same way and the label drawn with the bundled TTF.
     */
    private function generateWithGd(int $size, string $bg, string $fg, string $font, string $emojiPng): void
    {
        if (!function_exists('imagecreatetruecolor')) {
            // Neither imaging extension: a missing icon is not worth a 500.
            http_response_code(501);
            header('Content-Type: text/plain; charset=utf-8');
            echo 'Icon generation requires the imagick or gd extension.';
            return;
        }

        $bgRgb = ThemeModel::hexToRgb($bg) ?? [148, 227, 254];
        $fgRgb = ThemeModel::hexToRgb($fg) ?? [0, 54, 74];

        $img = imagecreatetruecolor($size, $size);
        imagealphablending($img, true);
        imagesavealpha($img, true);

        imagefill($img, 0, 0, imagecolorallocate($img, $bgRgb[0], $bgRgb[1], $bgRgb[2]));

        if (is_file($emojiPng)) {
            $emoji = @imagecreatefrompng($emojiPng);
            if ($emoji !== false) {
                imagecopyresampled(
                    $img,
                    $emoji,
                    (int) (($size - 100) / 2),
                    18,
                    0,
                    0,
                    100,
                    100,
                    imagesx($emoji),
                    imagesy($emoji)
                );
                imagedestroy($emoji);
            }
        }

        $textColor = imagecolorallocate($img, $fgRgb[0], $fgRgb[1], $fgRgb[2]);
        if (is_file($font) && function_exists('imagettftext')) {
            $box   = imagettfbbox(20, 0, $font, 'ISO 20022');
            $textW = abs($box[2] - $box[0]);
            imagettftext($img, 20, 0, (int) (($size - $textW) / 2), 148, $textColor, $font, 'ISO 20022');
        } else {
            $textW = imagefontwidth(4) * strlen('ISO 20022');
            imagestring($img, 4, (int) (($size - $textW) / 2), 136, 'ISO 20022', $textColor);
        }

        ob_start();
        imagepng($img, null, 6);
        $png = ob_get_clean();
        imagedestroy($img);

        header('Content-Type: image/png');
        header('Cache-Control: public, max-age=31536000, immutable');
        echo $png;
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
