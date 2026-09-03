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
import { countdownParts, decodeHtml, escapeHtml, formatDate, parseServerDate, stripLinks } from '../../public/assets/js/lib/format.js';

describe('escapeHtml', () => {
    it('escapes HTML-significant characters', () => {
        expect(escapeHtml('<script>alert(1)</script>')).toBe('&lt;script&gt;alert(1)&lt;/script&gt;');
    });

    it('returns an empty string for null/undefined', () => {
        expect(escapeHtml(null)).toBe('');
        expect(escapeHtml(undefined)).toBe('');
    });

    it('leaves plain text untouched', () => {
        expect(escapeHtml('Main St')).toBe('Main St');
    });
});

describe('decodeHtml', () => {
    it('is the inverse of escapeHtml for entities', () => {
        expect(decodeHtml('&lt;b&gt;Bold&lt;/b&gt;')).toBe('<b>Bold</b>');
    });

    it('returns an empty string for null/undefined', () => {
        expect(decodeHtml(null)).toBe('');
        expect(decodeHtml(undefined)).toBe('');
    });
});

describe('stripLinks', () => {
    it('removes anchor tags but keeps their text', () => {
        expect(stripLinks('See <a href="https://example.com">this page</a> for more.'))
            .toBe('See this page for more.');
    });

    it('strips multiple links', () => {
        expect(stripLinks('<a href="#">One</a> and <a href="#">Two</a>')).toBe('One and Two');
    });

    it('leaves text with no links untouched', () => {
        expect(stripLinks('No links here.')).toBe('No links here.');
    });
});

describe('formatDate', () => {
    it('formats an ISO date string as "DD Mon YYYY"', () => {
        expect(formatDate('2026-03-05T10:00:00Z')).toBe('05 Mar 2026');
    });

    it('returns an empty string for a falsy input', () => {
        expect(formatDate('')).toBe('');
        expect(formatDate(null)).toBe('');
    });

    it('formats the space-separated timestamp MySQL writes', () => {
        // Safari's Date() cannot read this shape; the Hall of Fame on the
        // iPad kiosk showed "Invalid Date" in every row.
        expect(formatDate('2026-03-05 10:00:00')).toBe('05 Mar 2026');
    });

    it('shows nothing rather than "Invalid Date" for an unreadable value', () => {
        expect(formatDate('not a date')).toBe('');
    });
});

describe('parseServerDate', () => {
    it('reads the MySQL shape as local time, field by field', () => {
        const d = parseServerDate('2026-03-05 10:07:09');
        expect(d.getFullYear()).toBe(2026);
        expect(d.getMonth()).toBe(2);
        expect(d.getDate()).toBe(5);
        expect(d.getHours()).toBe(10);
        expect(d.getMinutes()).toBe(7);
        expect(d.getSeconds()).toBe(9);
    });

    it('reads the admin deadline shape, which has no seconds', () => {
        const d = parseServerDate('2027-11-28T00:00');
        expect(d.getFullYear()).toBe(2027);
        expect(d.getMonth()).toBe(10);
        expect(d.getDate()).toBe(28);
        expect(d.getSeconds()).toBe(0);
    });

    it('hands anything else to the native parser', () => {
        expect(parseServerDate('2026-03-05T10:00:00Z').toISOString()).toBe('2026-03-05T10:00:00.000Z');
        expect(Number.isNaN(parseServerDate('2027-02-31T25:99').getTime())).toBe(true);
    });
});

describe('countdownParts', () => {
    it('reports expired once the target has passed', () => {
        const now = new Date('2026-01-01T00:00:00Z');
        const target = new Date('2025-12-31T23:59:59Z');
        expect(countdownParts(target, now)).toEqual({ expired: true });
    });

    it('reports an unreadable target as invalid rather than as NaN fields', () => {
        const now = new Date('2026-01-01T00:00:00Z');
        expect(countdownParts(new Date('not a date'), now)).toEqual({ expired: false, invalid: true });
    });

    it('reports expired exactly at the target instant', () => {
        const now = new Date('2026-01-01T00:00:00Z');
        expect(countdownParts(now, now)).toEqual({ expired: true });
    });

    it('breaks down a future date into days/hours/minutes/seconds, zero-padded', () => {
        const now = new Date('2026-01-01T00:00:00Z');
        // 2 days, 3 hours, 4 minutes, 5 seconds ahead.
        const target = new Date(now.getTime() + ((2 * 86400) + (3 * 3600) + (4 * 60) + 5) * 1000);

        expect(countdownParts(target, now)).toEqual({
            expired: false,
            days: 2,
            hours: '03',
            minutes: '04',
            seconds: '05',
        });
    });

    it('does not zero-pad days but does pad hours/minutes/seconds under 10', () => {
        const now = new Date('2026-01-01T00:00:00Z');
        const target = new Date(now.getTime() + (12 * 86400 + 5 * 3600 + 9 * 60 + 1) * 1000);

        const parts = countdownParts(target, now);
        expect(parts.days).toBe(12);
        expect(parts.hours).toBe('05');
        expect(parts.minutes).toBe('09');
        expect(parts.seconds).toBe('01');
    });
});
