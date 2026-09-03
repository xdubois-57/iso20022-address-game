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

import { afterEach, describe, expect, it, vi } from 'vitest';
import { randomIndex } from '../../public/assets/js/lib/random.js';

afterEach(() => { vi.restoreAllMocks(); });

describe('randomIndex', () => {
    it('returns 0 for counts that cannot yield an index', () => {
        for (const n of [0, 1, -5, NaN, Infinity, undefined]) {
            expect(randomIndex(n)).toBe(0);
        }
    });

    it('always returns an integer inside [0, count)', () => {
        for (let i = 0; i < 500; i++) {
            const v = randomIndex(10);
            expect(Number.isInteger(v)).toBe(true);
            expect(v).toBeGreaterThanOrEqual(0);
            expect(v).toBeLessThan(10);
        }
    });

    it('uses the CSPRNG, not Math.random', () => {
        const spy = vi.spyOn(crypto, 'getRandomValues');
        const mathSpy = vi.spyOn(Math, 'random');
        randomIndex(7);
        expect(spy).toHaveBeenCalled();
        expect(mathSpy).not.toHaveBeenCalled();
    });

    it('rejects draws in the biased tail and redraws', () => {
        // 2^32 is not divisible by 7, so the final short block must be
        // discarded rather than folded onto the low indices.
        const limit = Math.floor(0x100000000 / 7) * 7;
        let call = 0;
        vi.spyOn(crypto, 'getRandomValues').mockImplementation((buf) => {
            buf[0] = call++ === 0 ? limit : limit - 1; // first draw is in the tail
            return buf;
        });
        expect(randomIndex(7)).toBe((limit - 1) % 7);
        expect(call).toBe(2); // proves the first draw was rejected
    });

    it('covers the whole range given enough draws', () => {
        const seen = new Set();
        for (let i = 0; i < 2000; i++) seen.add(randomIndex(5));
        expect([...seen].sort()).toEqual([0, 1, 2, 3, 4]);
    });
});
