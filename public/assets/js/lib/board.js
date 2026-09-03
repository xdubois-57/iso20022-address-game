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
 * The Hall of Fame wall's decision-making, with no DOM in sight.
 *
 * Everything here answers one of two questions — "who is new since last
 * time?" and "when do we try again after a failure?" — and both are far
 * easier to get wrong than they look. Kept apart from the rendering in
 * app.js so they can be tested by asserting on return values rather than by
 * driving a screen, which is the only way the awkward cases (a reload, three
 * arrivals between two polls, a network that comes back) get covered at all.
 */

/** How many banners can queue up before the oldest are dropped. */
export const MAX_BANNER_QUEUE = 20;

/** Consecutive failures before the wall admits on screen that it is stale. */
export const STALE_AFTER_FAILURES = 3;

/**
 * Tracks which leaderboard ids the wall has already seen.
 *
 * The very first response fills the set and celebrates NOTHING. Without that
 * rule, every reload of an unattended screen — a Windows update at 2am, a
 * crashed tab, someone unplugging the monitor — would greet the empty room
 * with a wall of confetti for scores set two hours earlier, and the effect
 * would be worthless by the time anyone was there to see a real one.
 */
export function createArrivalTracker() {
    return { known: new Set(), primed: false };
}

/**
 * Fold a /board/data response into the tracker and say what to celebrate.
 *
 * @param {{known: Set<number>, primed: boolean}} tracker
 * @param {{entries?: Array, recent?: Array}} data       a /board/data body
 * @param {number} visibleCount  how many rows the screen is currently showing
 * @returns {{
 *   firstLoad: boolean,
 *   highlightIds: number[],
 *   banners: Array<{id: number, name: string, rank: number, score: number}>
 * }}
 *
 * Two kinds of arrival, and BOTH are celebrated:
 *
 *  - one that lands in the rows currently on screen gets its row highlighted,
 *    because the wall can simply point at it;
 *  - one that placed below the fold gets a banner naming it and its rank,
 *    because otherwise the players who most need the acknowledgement — the
 *    ones who did not make the visible top — would get none at all.
 *
 * `visibleCount` rather than entries.length: how many rows fit is a property
 * of the actual viewport height, recomputed on resize, and a 42-inch screen
 * may be 1080x1920 or 2160x3840.
 */
export function diffArrivals(tracker, data, visibleCount) {
    const entries = Array.isArray(data?.entries) ? data.entries : [];
    const recent = Array.isArray(data?.recent) ? data.recent : [];

    // Every id the response mentions, in either list. Built before any
    // decision, so the tracker ends up consistent whichever branch is taken.
    const seen = [];
    for (const row of entries.concat(recent)) {
        const id = Number(row?.id);
        if (Number.isFinite(id)) seen.push({ id, row });
    }

    if (!tracker.primed) {
        for (const { id } of seen) tracker.known.add(id);
        tracker.primed = true;
        return { firstLoad: true, highlightIds: [], banners: [] };
    }

    // The ids on screen right now, which decides highlight versus banner.
    const visibleIds = new Set(
        entries.slice(0, Math.max(0, visibleCount)).map((e) => Number(e.id))
    );

    const highlightIds = [];
    const banners = [];
    const celebrated = new Set();

    for (const { id, row } of seen) {
        if (tracker.known.has(id) || celebrated.has(id)) continue;
        celebrated.add(id);

        if (visibleIds.has(id)) {
            highlightIds.push(id);
        } else {
            banners.push({
                id,
                name: String(row.player_name == null ? '' : row.player_name),
                // The rank the SERVER computed. Deriving it from a position in
                // this array would be wrong for anyone outside the top slice,
                // which is precisely who these banners are about.
                rank: boardNumber(row.rank),
                score: boardNumber(row.game_score),
            });
        }
    }

    for (const { id } of seen) tracker.known.add(id);

    return { firstLoad: false, highlightIds, banners };
}

/**
 * A FIFO of banners shown one at a time, never overlapping.
 *
 * Several players can finish between two polls — the game takes minutes, the
 * poll is five seconds, and a queue at a busy stand delivers them in bursts.
 * Showing them simultaneously would stack unreadable text; dropping the
 * surplus would quietly deny somebody the one moment the wall exists to give
 * them. So they are queued and played in order.
 *
 * Bounded all the same: an unattended screen that somehow accumulated
 * thousands would spend the rest of the evening working through a backlog
 * nobody is watching, so the queue keeps the most recent MAX_BANNER_QUEUE.
 */
export function createBannerQueue(limit) {
    const cap = typeof limit === 'number' && limit > 0 ? limit : MAX_BANNER_QUEUE;
    return { pending: [], showing: null, cap };
}

export function enqueueBanners(queue, banners) {
    for (const banner of banners) {
        queue.pending.push(banner);
    }
    if (queue.pending.length > queue.cap) {
        queue.pending.splice(0, queue.pending.length - queue.cap);
    }
    return queue;
}

/**
 * Take the next banner, or null when one is already showing or none is left.
 * Call releaseBanner() when its time on screen is up.
 */
export function nextBanner(queue) {
    if (queue.showing || queue.pending.length === 0) return null;
    queue.showing = queue.pending.shift();
    return queue.showing;
}

export function releaseBanner(queue) {
    queue.showing = null;
    return queue;
}

/**
 * How long to wait before retrying, in milliseconds.
 *
 * Exponential from the normal poll interval, capped — the cap matters more
 * than the growth. An unattended wall whose venue Wi-Fi drops for ten minutes
 * must come back on its own within seconds of the network returning, so the
 * backoff must never grow into minutes; and it must not hammer a server that
 * is down either.
 *
 * @param {number} failures  consecutive failures so far (1 for the first)
 * @param {number} baseMs    the healthy polling interval
 * @param {number} maxMs     the ceiling
 */
export function backoffDelay(failures, baseMs, maxMs) {
    const n = Math.max(1, Math.floor(failures));
    return Math.min(maxMs, baseMs * Math.pow(2, n - 1));
}

/**
 * What the wall should display, given a new response and what it had before.
 *
 * The single most important rule in this file: a FAILED request never clears
 * the screen. A wall frozen on data from two minutes ago is worth infinitely
 * more than a blank one, or an error page, in front of fifty people. The
 * staleness is admitted only by a small dot in a corner, and only after
 * several failures in a row — a single blip is not worth reporting.
 *
 * @param {Record<string, any>|null} previous  the last body that was good, or null
 * @param {Record<string, any>|null} incoming  the body just received, or null on failure
 * @param {number} failures       consecutive failures including this one
 */
export function resolveDisplayData(previous, incoming, failures) {
    if (incoming && Array.isArray(incoming.entries)) {
        return { data: incoming, stale: false };
    }

    return {
        data: previous,
        stale: failures >= STALE_AFTER_FAILURES,
    };
}

/**
 * How many rows below the podium fit in the space left over.
 *
 * Never a fixed number: a 42-inch portrait panel can be 1080x1920 or
 * 2160x3840, and hard-coding a row count would leave either a gaping hole or
 * a list running off the bottom edge. Recomputed on resize.
 *
 * @param {number} availableHeight  pixels left for the list
 * @param {number} rowHeight        measured height of one row
 */
export function rowsThatFit(availableHeight, rowHeight) {
    // Spelled out rather than as `!(x > 0)`: the negated form also catches
    // NaN, which is the case that matters here — an unmeasured element gives
    // NaN, and a NaN row count would render as garbage. `<=` alone would let
    // it through, so finiteness is checked explicitly instead.
    if (!Number.isFinite(availableHeight) || !Number.isFinite(rowHeight)) return 0;
    if (rowHeight <= 0 || availableHeight <= 0) return 0;
    return Math.max(0, Math.floor(availableHeight / rowHeight));
}

/**
 * A number from a /board/data row, safe to concatenate into markup.
 *
 * The wall builds its HTML by string concatenation and escapes the one field
 * that is obviously prose — the player name. The numbers looked exempt: the
 * server casts every one of them to `(int)` before it emits them. But the
 * wall trusts a *response*, not a database, and an unattended screen that
 * polls the same URL all evening is exactly the wrong place to assume the
 * thing on the other end is still the thing that was deployed. A field that
 * arrives as a string lands in innerHTML as markup.
 *
 * So the numbers are made numbers here, at the boundary where they enter the
 * page, rather than trusted to have been numbers already. Anything that is
 * not a finite number becomes 0 — a wrong score on screen for one row beats
 * a script tag on a wall nobody is watching.
 */
export function boardNumber(value) {
    const n = Number(value);
    return Number.isFinite(n) ? n : 0;
}
