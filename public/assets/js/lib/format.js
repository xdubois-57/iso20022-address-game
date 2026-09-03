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

/**
 * Parse a timestamp the server wrote.
 *
 * MySQL hands `created_at` back as `YYYY-MM-DD HH:MM:SS` — a space, no zone
 * — and the admin deadline travels as `YYYY-MM-DDTHH:MM`. `new Date()` on
 * the first of those is implementation-defined: Chrome and Firefox read it
 * as local time, Safari returns Invalid Date, and the iPad kiosk runs
 * Safari — so every Hall of Fame row there showed "Invalid Date". Both
 * shapes are taken apart by hand here, in the local-time reading the other
 * browsers already use; anything else still goes to the native parser.
 *
 * @param {string} dateStr
 * @returns {Date} possibly invalid — check getTime() before using it
 */
export function parseServerDate(dateStr) {
    var m = /^(\d{4})-(\d{2})-(\d{2})[ T](\d{2}):(\d{2})(?::(\d{2}))?$/.exec(String(dateStr));
    if (!m) {
        return new Date(dateStr);
    }

    var month = Number(m[2]);
    var day = Number(m[3]);
    var hours = Number(m[4]);
    var minutes = Number(m[5]);
    var seconds = Number(m[6] || 0);
    if (hours > 23 || minutes > 59 || seconds > 59) {
        return new Date(NaN);
    }

    var d = new Date(Number(m[1]), month - 1, day, hours, minutes, seconds);
    // The Date constructor rolls an impossible day over into the next month
    // (31 February becomes 3 March) rather than refusing it. Only the date
    // fields are compared back: a time inside a DST gap legitimately shifts
    // by an hour, and that must not turn a real row's date into nothing.
    if (d.getMonth() !== month - 1 || d.getDate() !== day) {
        return new Date(NaN);
    }
    return d;
}

export function formatDate(dateStr) {
    if (!dateStr) return '';
    var d = parseServerDate(dateStr);
    // An unreadable date is better shown as nothing than as the literal
    // string "Invalid Date" in a table column.
    if (Number.isNaN(d.getTime())) return '';
    return d.toLocaleDateString('en-GB', { day: '2-digit', month: 'short', year: 'numeric' });
}

/** Two-digit zero padding for the countdown's hour/minute/second fields. */
function pad(n) {
    return n < 10 ? '0' + n : '' + n;
}

/**
 * The arithmetic half of the deadline countdown banner, split out from the
 * DOM-writing updateCountdown() in app.js so the maths (day/hour/minute/
 * second breakdown, the "expired" cutover) is unit-testable on its own.
 *
 * An invalid target — a deadline string the browser could not read — is
 * reported as such rather than turned into NaN in every field: a banner
 * on a wall must show nothing before it shows "NaNd:NaNh".
 *
 * @param {Date} targetDate
 * @param {Date} now
 * @returns {{expired: true} | {expired: false, invalid: true} | {expired: false, days: number, hours: string, minutes: string, seconds: string}}
 */
export function countdownParts(targetDate, now) {
    var diff = targetDate.getTime() - now.getTime();
    if (Number.isNaN(diff)) {
        return { expired: false, invalid: true };
    }
    if (diff <= 0) {
        return { expired: true };
    }

    var totalSeconds = Math.floor(diff / 1000);
    var days = Math.floor(totalSeconds / 86400);
    var hours = Math.floor((totalSeconds % 86400) / 3600);
    var minutes = Math.floor((totalSeconds % 3600) / 60);
    var seconds = totalSeconds % 60;

    return { expired: false, days: days, hours: pad(hours), minutes: pad(minutes), seconds: pad(seconds) };
}
