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

    /**
     * The PMPG palette, sampled from the logo.
     *
     * Lower-case on purpose: save() lower-cases what it stores, so anything
     * here in mixed case would compare unequal to the same colour saved
     * through the admin panel.
     *
     * Contrast, checked rather than assumed: #3d345f on #8abed9 is ~5.7:1 and
     * on white ~12:1; white on #3d345f is ~12:1. All clear WCAG AA. Do not
     * change these without re-checking, since color_text and color_bg are
     * used together as body text on the page background.
     *
     * Two further copies of this palette must agree with it — `themeDefaults`
     * in public/assets/js/app.js and the `:root` block in
     * public/assets/css/app.css. ThemeDefaultsSyncTest fails if the JS one
     * drifts.
     */
    private const DEFAULTS = [
        'color_primary'       => '#3d345f',
        'color_primary_hover' => '#2c2646',
        'color_primary_light' => '#dceaf3',
        'color_bg'            => '#8abed9',
        'color_text'          => '#3d345f',
    ];

    private SettingsModel $settings;

    /**
     * The PDO handle is not kept: every read and write this class does goes
     * through SettingsModel, which owns the driver-specific upsert. Holding a
     * second handle to the same connection would only invite a query that
     * bypasses it.
     */
    public function __construct(PDO $pdo)
    {
        $this->settings = new SettingsModel($pdo);
    }

    /**
     * Return the current theme, falling back to defaults for any missing key.
     *
     * @return array<string,string>
     */
    public function get(): array
    {
        $rows = $this->settings->getMany(self::KEYS);

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
        $valid = [];
        foreach (self::KEYS as $key) {
            if (isset($colors[$key]) && $this->isValidHex($colors[$key])) {
                $valid[$key] = strtolower($colors[$key]);
            }
        }
        $this->settings->setMany($valid);
    }

    /**
     * Drop every stored theme colour, returning the theme that now applies.
     *
     * Deleting rather than writing DEFAULTS back is the whole point. get()
     * starts from DEFAULTS and only overrides the keys the database actually
     * holds, so an installation with no theme rows tracks the defaults exactly
     * as a fresh install does. Writing the five hex values back would instead
     * PIN the installation to today's palette: it would look identical
     * immediately and then silently fail to follow any future change of
     * defaults, which is the opposite of what an admin asking for "reset"
     * means.
     *
     * This is also the only migration path for an installation that saved a
     * theme under the previous teal palette — nothing migrates it
     * automatically, because overwriting colours an admin deliberately chose
     * is not ours to do.
     *
     * @return array<string,string> the theme in force afterwards, i.e. DEFAULTS
     */
    public function reset(): array
    {
        foreach (self::KEYS as $key) {
            $this->settings->delete($key);
        }

        return $this->get();
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
