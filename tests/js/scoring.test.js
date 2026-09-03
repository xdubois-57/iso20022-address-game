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

import { describe, expect, it } from 'vitest';
import { computeGameScore } from '../../public/assets/js/lib/scoring.js';

describe('computeGameScore', () => {
    it('is zero for zero accuracy regardless of time', () => {
        expect(computeGameScore(0, 10)).toBe(0);
        expect(computeGameScore(0, 1)).toBe(0);
    });

    it('rewards perfect accuracy at a reasonable pace', () => {
        // 100% accuracy in 50s: accuracyScore=10000, timeMultiplier=1+500/50=11 -> 11000
        expect(computeGameScore(100, 50)).toBe(11000);
    });

    it('is strictly higher for a faster completion at equal accuracy', () => {
        const fast = computeGameScore(80, 10);
        const slow = computeGameScore(80, 60);
        expect(fast).toBeGreaterThan(slow);
    });

    it('scores accuracy quadratically, not linearly', () => {
        // At a fixed time, doubling accuracy should more than double the score.
        const half = computeGameScore(50, 30);
        const full = computeGameScore(100, 30);
        expect(full).toBeGreaterThan(half * 2);
    });

    it('clamps the effective time multiplier at seconds <= 1 (division guard)', () => {
        const atZero = computeGameScore(100, 0);
        const atOne = computeGameScore(100, 1);
        expect(atZero).toBe(atOne);
    });

    it('handles negative seconds the same as the Math.max(1, seconds) floor', () => {
        expect(computeGameScore(100, -5)).toBe(computeGameScore(100, 1));
    });

    it('returns an integer (rounded)', () => {
        const score = computeGameScore(73, 37);
        expect(Number.isInteger(score)).toBe(true);
    });
});
