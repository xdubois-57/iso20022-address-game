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
 * GET /bg — Serve the world map SVG with theme colors injected.
 *
 * Reads the pre-built Simplified_World_Map.svg, replaces its two
 * hardcoded colors with the current theme colors, and outputs SVG.
 * Cache-busted via ?v= in layout.php whenever theme changes.
 */
class BackgroundController
{
    public function generate(): void
    {
        $theme = $this->loadTheme();

        $oceanColor = $theme['color_bg'];
        $landColor  = $theme['color_primary_light'];
        $lineColor  = $theme['color_primary'];

        $svgPath = __DIR__ . '/../../public/assets/images/world_map.svg';
        $svg = file_get_contents($svgPath);

        // The SVG uses exactly two hardcoded colors (case-insensitive):
        //   #9BD5C1 — land fill
        //   #0477BE — ocean stroke / lines
        // We replace them with theme colors.
        // Also inject a background rect for the ocean color.
        $svg = str_ireplace('#9BD5C1', $landColor, $svg);
        $svg = str_ireplace('#0477BE', $lineColor, $svg);

        // Inject ocean background rect right after the opening <svg ...> tag.
        $oceanRect = '<rect width="100%" height="100%" fill="' . htmlspecialchars($oceanColor, ENT_QUOTES) . '"/>';
        $svg = preg_replace('/(<svg[^>]*>)/', '$1' . $oceanRect, $svg, 1);

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
