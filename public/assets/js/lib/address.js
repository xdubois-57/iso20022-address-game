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
 * Format an ISO 20022 address for display, using the bundled
 * @fragaria/address-formatter when available and a plain concatenation
 * fallback when it is not (see README's "outbound HTTPS" requirements note —
 * gameplay must stay correct even when styling/CDN scripts fail to load).
 */
export function formatAddressForDisplay(addressData) {
    if (!addressData) return '';

    if (typeof window === 'undefined' || typeof window.addressFormatter === 'undefined') {
        const lines = [];
        // Additional info first (like floor, suite)
        if (addressData.attention) lines.push(addressData.attention);
        // Street line: houseNumber + road (order depends on country; fallback uses number first)
        if (addressData.road || addressData.houseNumber) {
            lines.push(((addressData.houseNumber || '') + ' ' + (addressData.road || '')).trim());
        }
        // City + postcode line
        if (addressData.postcode || addressData.city) {
            lines.push(((addressData.postcode || '') + ' ' + (addressData.city || '')).trim());
        }
        // Country
        if (addressData.country) lines.push(addressData.country);
        return lines.join('\n');
    }

    // @fragaria/address-formatter v7: countryCode must be inside the address
    // object — it is NOT a format option. Pass all ISO 20022 components.
    // Field mapping:
    //   AdtlAdrInf -> attention (appears in all templates as first line)
    //   BldgNb -> houseNumber
    //   StrtNm -> road
    //   PstCd -> postcode
    //   TwnNm -> city
    //   Ctry -> countryCode (library looks up country name from this)
    const addr = {
        attention: addressData.attention || '',
        houseNumber: addressData.houseNumber || '',
        road: addressData.road || '',
        city: addressData.city || '',
        postcode: addressData.postcode || '',
        countryCode: (addressData.countryCode || '').toUpperCase(),
    };

    let lines = window.addressFormatter.format(addr, { output: 'array' });

    // Remove empty lines
    lines = lines.filter(function (l) { return l && l.trim() !== ''; });

    // Ensure country is shown - append it if not present in library output
    // The library sometimes omits country when components are sparse
    if (addressData.country && lines.length > 0) {
        const hasCountry = lines.some(function (line) {
            return line.toLowerCase().indexOf(addressData.country.toLowerCase()) !== -1;
        });
        if (!hasCountry) {
            lines.push(addressData.country);
        }
    }

    return lines.join('\n');
}

/** Whether a drop-slot id belongs to the free-form AdrLine group. */
export function isAdrLineSlot(slotId) {
    return slotId.indexOf('AdrLine') === 0;
}
