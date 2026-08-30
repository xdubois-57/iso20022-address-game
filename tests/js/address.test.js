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

import { afterEach, describe, expect, it } from 'vitest';
import { formatAddressForDisplay, isAdrLineSlot } from '../../public/assets/js/lib/address.js';

describe('formatAddressForDisplay — fallback path (no window.addressFormatter)', () => {
    afterEach(() => {
        delete window.addressFormatter;
    });

    it('returns an empty string for no data', () => {
        expect(formatAddressForDisplay(null)).toBe('');
        expect(formatAddressForDisplay(undefined)).toBe('');
    });

    it('assembles attention, street, postcode+city and country lines in order', () => {
        const result = formatAddressForDisplay({
            attention: 'Floor 10',
            houseNumber: '123',
            road: 'Main St',
            postcode: '10001',
            city: 'New York',
            country: 'United States',
        });

        expect(result).toBe('Floor 10\n123 Main St\n10001 New York\nUnited States');
    });

    it('omits lines for missing fields rather than leaving blank lines', () => {
        const result = formatAddressForDisplay({ road: 'Main St', city: 'Springfield' });
        expect(result).toBe('Main St\nSpringfield');
    });

    it('trims a lone houseNumber or road with no partner', () => {
        expect(formatAddressForDisplay({ houseNumber: '5' })).toBe('5');
        expect(formatAddressForDisplay({ road: 'Elm St' })).toBe('Elm St');
    });
});

describe('formatAddressForDisplay — library path (window.addressFormatter present)', () => {
    afterEach(() => {
        delete window.addressFormatter;
    });

    it('passes ISO 20022 fields mapped to the library and joins its array output', () => {
        let received = null;
        window.addressFormatter = {
            format(addr) {
                received = addr;
                return ['123 Main St', '10001 New York', 'United States'];
            },
        };

        const result = formatAddressForDisplay({
            attention: 'Floor 10',
            houseNumber: '123',
            road: 'Main St',
            postcode: '10001',
            city: 'New York',
            countryCode: 'us',
            country: 'United States',
        });

        expect(received).toEqual({
            attention: 'Floor 10',
            houseNumber: '123',
            road: 'Main St',
            city: 'New York',
            postcode: '10001',
            countryCode: 'US',
        });
        expect(result).toBe('123 Main St\n10001 New York\nUnited States');
    });

    it('filters out blank lines from the library output', () => {
        window.addressFormatter = { format: () => ['123 Main St', '', '   ', 'France'] };
        const result = formatAddressForDisplay({ country: 'France' });
        expect(result).toBe('123 Main St\nFrance');
    });

    it('appends the country when the library output omits it', () => {
        window.addressFormatter = { format: () => ['123 Main St'] };
        const result = formatAddressForDisplay({ country: 'Belgium' });
        expect(result).toBe('123 Main St\nBelgium');
    });

    it('does not duplicate the country when already present (case-insensitive)', () => {
        window.addressFormatter = { format: () => ['123 Main St', 'BELGIUM'] };
        const result = formatAddressForDisplay({ country: 'Belgium' });
        expect(result).toBe('123 Main St\nBELGIUM');
    });
});

describe('isAdrLineSlot', () => {
    it('is true for AdrLine-prefixed slot ids', () => {
        expect(isAdrLineSlot('AdrLine1')).toBe(true);
        expect(isAdrLineSlot('AdrLine')).toBe(true);
    });

    it('is false for any other slot id, including one that merely contains AdrLine', () => {
        expect(isAdrLineSlot('StrtNm')).toBe(false);
        expect(isAdrLineSlot('SomeAdrLine')).toBe(false);
        expect(isAdrLineSlot('')).toBe(false);
    });
});
