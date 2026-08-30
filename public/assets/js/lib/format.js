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

/** Escapes a string for safe HTML text-node insertion, via the DOM itself. */
export function escapeHtml(str) {
    var div = document.createElement('div');
    div.textContent = str || '';
    return div.innerHTML;
}

/** Inverse of escapeHtml() — decodes HTML entities back to plain text. */
export function decodeHtml(str) {
    var ta = document.createElement('textarea');
    ta.innerHTML = str || '';
    return ta.value;
}

/** Strips `<a>` tags (keeping their text) — used in kiosk mode, where a fact's link would be dead weight. */
export function stripLinks(html) {
    return html.replace(/<a\b[^>]*>(.*?)<\/a>/gi, '$1');
}

export function formatDate(dateStr) {
    if (!dateStr) return '';
    var d = new Date(dateStr);
    return d.toLocaleDateString('en-GB', { day: '2-digit', month: 'short', year: 'numeric' });
}

/**
 * The arithmetic half of the deadline countdown banner, split out from the
 * DOM-writing updateCountdown() in app.js so the maths (day/hour/minute/
 * second breakdown, the "expired" cutover) is unit-testable on its own.
 *
 * @param {Date} targetDate
 * @param {Date} now
 * @returns {{expired: true} | {expired: false, days: number, hours: string, minutes: string, seconds: string}}
 */
export function countdownParts(targetDate, now) {
    var diff = targetDate.getTime() - now.getTime();
    if (diff <= 0) {
        return { expired: true };
    }

    var totalSeconds = Math.floor(diff / 1000);
    var days = Math.floor(totalSeconds / 86400);
    var hours = Math.floor((totalSeconds % 86400) / 3600);
    var minutes = Math.floor((totalSeconds % 3600) / 60);
    var seconds = totalSeconds % 60;

    function pad(n) { return n < 10 ? '0' + n : '' + n; }

    return { expired: false, days: days, hours: pad(hours), minutes: pad(minutes), seconds: pad(seconds) };
}
