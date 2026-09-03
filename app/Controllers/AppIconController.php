<?php
/**
 * ISO 20022 Address Structuring Game
 * Copyright (C) 2026 https://github.com/xdubois-57/iso20022-address-game
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Affero General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU Affero General Public License for more details.
 *
 * You should have received a copy of the GNU Affero General Public License
 * along with this program. If not, see <https://www.gnu.org/licenses/>.
 */

namespace App\Controllers;

use App\Models\Database;
use App\Models\ThemeModel;

/**
 * GET /app-icon — Serve a themed PNG apple-touch-icon (180×180).
 *
 * iOS requires PNG for apple-touch-icon; SVG is not supported.
 * The image is composed as:
 *   - Rounded-rect background in theme color_bg
 *   - A white disc
 *   - The PMPG sunburst (pmpg-mark.png) composited on the disc
 *   - "ISO 20022" label in theme color_text using the bundled bold font
 *
 * The white disc is not decoration. The sunburst's lower petals fade to near
 * white, and the default background is now #8abed9 — a mid blue — so placed
 * straight onto it the mark loses its bottom half and reads as a broken ring.
 * Rendered both ways at 180px and compared before choosing; the disc is what
 * keeps the mark legible at icon size, and it holds for any background colour
 * an admin might pick, which a tuned background would not.
 *
 * TWO rendering paths, Imagick and a GD fallback, and they must stay in step:
 * an installation without Imagick that kept rendering the old icon would be a
 * silent half-rebrand.
 *
 * Cache-busted via ?v= in layout.php whenever the theme changes.
 */
class AppIconController
{
    /** Rendered size of the icon, and of the mark and disc within it. */
    private const ICON_SIZE = 180;
    private const MARK_SIZE = 100;
    private const MARK_TOP  = 18;
    /** Disc diameter: the mark plus a small margin, so no petal touches the edge. */
    private const DISC_SIZE = 116;

    public function generate(): void
    {
        $theme    = $this->loadTheme();
        $bg       = $theme['color_bg']      ?: '#8abed9';
        $fg       = $theme['color_text']    ?: '#3d345f';
        $font     = __DIR__ . '/../../public/assets/fonts/LiberationSans-Bold.ttf';
        $markPng  = __DIR__ . '/../../public/assets/images/pmpg-mark.png';

        $size = self::ICON_SIZE;

        // ShareController guards this; this route did not, so on a host without
        // Imagick the apple-touch-icon was a fatal 500 rather than a missing
        // icon. Fall back to GD, which every PHP build here already relies on.
        if (!extension_loaded('imagick')) {
            $this->generateWithGd($size, $bg, $fg, $font, $markPng);
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

        // White disc, then the sunburst on top of it. See the class docblock
        // for why the disc is load-bearing rather than decorative.
        $centreX = (int) ($size / 2);
        $centreY = self::MARK_TOP + (int) (self::MARK_SIZE / 2);
        $radius  = (int) (self::DISC_SIZE / 2);

        $disc = new \ImagickDraw();
        $disc->setFillColor(new \ImagickPixel('white'));
        $disc->setStrokeWidth(0);
        // Imagick's circle() takes a centre and a point ON the perimeter.
        $disc->circle($centreX, $centreY, $centreX, $centreY - $radius);
        $im->drawImage($disc);
        $disc->destroy();

        if (file_exists($markPng)) {
            $mark = new \Imagick($markPng);
            $mark->scaleImage(self::MARK_SIZE, self::MARK_SIZE);
            $im->compositeImage(
                $mark,
                \Imagick::COMPOSITE_OVER,
                (int) (($size - self::MARK_SIZE) / 2),
                self::MARK_TOP
            );
            $mark->destroy();
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
     * Kept deliberately in step with the Imagick path above — same disc, same
     * mark, same geometry. An installation without Imagick that kept the old
     * icon would be a half-applied rebrand that nobody would notice.
     */
    private function generateWithGd(int $size, string $bg, string $fg, string $font, string $markPng): void
    {
        if (!function_exists('imagecreatetruecolor')) {
            // Neither imaging extension: a missing icon is not worth a 500.
            http_response_code(501);
            header('Content-Type: text/plain; charset=utf-8');
            echo 'Icon generation requires the imagick or gd extension.';
            return;
        }

        $bgRgb = ThemeModel::hexToRgb($bg) ?? [138, 190, 217];
        $fgRgb = ThemeModel::hexToRgb($fg) ?? [61, 52, 95];

        $img = imagecreatetruecolor($size, $size);
        imagealphablending($img, true);
        imagesavealpha($img, true);

        imagefill($img, 0, 0, imagecolorallocate($img, $bgRgb[0], $bgRgb[1], $bgRgb[2]));

        $centreX = (int) ($size / 2);
        $centreY = self::MARK_TOP + (int) (self::MARK_SIZE / 2);
        imagefilledellipse(
            $img,
            $centreX,
            $centreY,
            self::DISC_SIZE,
            self::DISC_SIZE,
            imagecolorallocate($img, 255, 255, 255)
        );

        if (is_file($markPng)) {
            $mark = @imagecreatefrompng($markPng);
            if ($mark !== false) {
                imagecopyresampled(
                    $img,
                    $mark,
                    (int) (($size - self::MARK_SIZE) / 2),
                    self::MARK_TOP,
                    0,
                    0,
                    self::MARK_SIZE,
                    self::MARK_SIZE,
                    imagesx($mark),
                    imagesy($mark)
                );
                imagedestroy($mark);
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

    /**
     * The five theme colours, falling back to the defaults when the database
     * is unreachable.
     *
     * @return array<string, string>
     */
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
