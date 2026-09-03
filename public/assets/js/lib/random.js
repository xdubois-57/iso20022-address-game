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

/**
 * A uniformly distributed integer in [0, count), drawn from the platform
 * CSPRNG rather than Math.random().
 *
 * Which "Did you know?" fact appears first is not a secret, so Math.random()
 * was not a real weakness here. It was, however, one more finding a reviewer
 * has to dismiss by hand before reaching a real one, and crypto.getRandomValues
 * is available everywhere this app runs and costs nothing at this frequency.
 *
 * The rejection loop is what makes it uniform: taking `% count` of a raw
 * 32-bit draw favours the low indices whenever count does not divide 2^32.
 * Values landing in the final, short block are discarded and redrawn.
 *
 * @param {number} count exclusive upper bound
 * @returns {number} 0 when count is not a positive number
 */
export function randomIndex(count) {
    if (!Number.isFinite(count) || count <= 0) {
        return 0;
    }

    const bound = Math.floor(count);
    if (bound <= 1) {
        return 0;
    }

    const limit = Math.floor(0x100000000 / bound) * bound;
    const buf = new Uint32Array(1);
    let value;
    do {
        crypto.getRandomValues(buf);
        value = buf[0];
    } while (value >= limit);

    return value % bound;
}
