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
 * GET /app-icon — Serve a themed SVG apple-touch-icon.
 *
 * Generates a 180×180 SVG with:
 *   - Background filled with theme color_bg
 *   - A game controller emoji centered in the upper half
 *   - "ISO 20022" text in theme color_text in the lower half
 *
 * Cache-busted via ?v= in layout.php whenever the theme changes.
 */
class AppIconController
{
    public function generate(): void
    {
        $theme = $this->loadTheme();
        $bg    = htmlspecialchars($theme['color_bg'],   ENT_QUOTES);
        $text  = htmlspecialchars($theme['color_text'], ENT_QUOTES);

        $svg = <<<SVG
<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 180 180" width="180" height="180">
  <rect width="180" height="180" rx="22" fill="{$bg}"/>
  <text x="90" y="78" font-size="80" text-anchor="middle" dominant-baseline="middle" font-family="Apple Color Emoji, Segoe UI Emoji, Noto Color Emoji, sans-serif">🎮</text>
  <text x="90" y="150" font-size="22" font-weight="700" text-anchor="middle" dominant-baseline="middle" font-family="-apple-system, BlinkMacSystemFont, Helvetica Neue, Arial, sans-serif" fill="{$text}">ISO 20022</text>
</svg>
SVG;

        header('Content-Type: image/svg+xml');
        header('Cache-Control: public, max-age=31536000, immutable');
        echo $svg;
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
