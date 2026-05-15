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

namespace App\Models;

use PDO;

/**
 * Manages the 5 theme color variables stored in the settings table.
 *
 * The palette is intentionally small so editing is easy:
 *   color_primary       — main brand color (buttons, chips, accents)
 *   color_primary_hover — darker shade of primary for hover states
 *   color_primary_light — very light tint for filled slots / highlights
 *   color_bg            — page background / image background
 *   color_text          — dark text / headings
 */
class ThemeModel
{
    private const KEYS = [
        'color_primary',
        'color_primary_hover',
        'color_primary_light',
        'color_bg',
        'color_text',
    ];

    private const DEFAULTS = [
        'color_primary'       => '#01a990',
        'color_primary_hover' => '#018a76',
        'color_primary_light' => '#cffbf2',
        'color_bg'            => '#acf9e9',
        'color_text'          => '#333d3e',
    ];

    private PDO $pdo;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    /**
     * Return the current theme, falling back to defaults for any missing key.
     *
     * @return array<string,string>
     */
    public function get(): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT setting_key, setting_value FROM settings WHERE setting_key IN ('
            . implode(',', array_fill(0, count(self::KEYS), '?'))
            . ')'
        );
        $stmt->execute(self::KEYS);

        $rows = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);
        $theme = self::DEFAULTS;
        foreach ($rows as $k => $v) {
            if (isset($theme[$k]) && $this->isValidHex($v)) {
                $theme[$k] = $v;
            }
        }
        return $theme;
    }

    /**
     * Persist a partial or full theme update. Only valid hex values are saved.
     *
     * @param array<string,string> $colors
     */
    public function save(array $colors): void
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO settings (setting_key, setting_value) VALUES (?, ?) '
            . 'ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)'
        );
        foreach (self::KEYS as $key) {
            if (isset($colors[$key]) && $this->isValidHex($colors[$key])) {
                $stmt->execute([$key, strtolower($colors[$key])]);
            }
        }
    }

    /**
     * Return default theme values (no DB read).
     *
     * @return array<string,string>
     */
    public static function defaults(): array
    {
        return self::DEFAULTS;
    }

    /**
     * Parse a hex color string into [r, g, b] integers, or null if invalid.
     *
     * @return array{int,int,int}|null
     */
    public static function hexToRgb(string $hex): ?array
    {
        $hex = ltrim($hex, '#');
        if (strlen($hex) === 3) {
            $hex = $hex[0] . $hex[0] . $hex[1] . $hex[1] . $hex[2] . $hex[2];
        }
        if (!preg_match('/^[0-9a-fA-F]{6}$/', $hex)) {
            return null;
        }
        return [
            hexdec(substr($hex, 0, 2)),
            hexdec(substr($hex, 2, 2)),
            hexdec(substr($hex, 4, 2)),
        ];
    }

    private function isValidHex(string $value): bool
    {
        return (bool) preg_match('/^#[0-9a-fA-F]{3}([0-9a-fA-F]{3})?$/', $value);
    }
}
