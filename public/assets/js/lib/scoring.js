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

/**
 * Round score from a completion percentage and elapsed time. Strong
 * accuracy weight (quadratic) plus an inverse time bonus (faster = higher).
 * No maximum cap, but scaled to a reasonable range.
 *
 * The server independently bounds whatever is submitted (score.php,
 * 0-100 pct / 0-3600s time) — see README's "Scoring is client-authoritative"
 * — but this is the actual curve players see, so it is unit-tested directly.
 */
export function computeGameScore(pct, seconds) {
    const accuracyScore = pct * pct; // 0-10000 for 0%-100%
    const timeMultiplier = 1 + (500 / Math.max(1, seconds)); // Inverse: faster = higher
    return Math.round(accuracyScore * timeMultiplier / 10);
}
