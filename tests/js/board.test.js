// ISO 20022 Address Structuring Game
// Copyright (C) 2026 https://github.com/xdubois-57/iso20022-address-game
//
// This program is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// This program is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with this program. If not, see <https://www.gnu.org/licenses/>.
//
// The wall's decision-making, asserted on return values with no screen in
// sight. These are the cases that are awkward to reach by driving a browser
// and easy to get wrong: a reload, three players finishing between two polls,
// and a network that drops and comes back.

import { describe, expect, it } from 'vitest';
import {
    MAX_BANNER_QUEUE,
    STALE_AFTER_FAILURES,
    backoffDelay,
    createArrivalTracker,
    createBannerQueue,
    diffArrivals,
    enqueueBanners,
    nextBanner,
    releaseBanner,
    resolveDisplayData,
    rowsThatFit,
} from '../../public/assets/js/lib/board.js';

/** A /board/data-shaped row. */
function entry(id, rank, name = `Player ${id}`, score = 900 - rank) {
    return {
        id,
        rank,
        player_name: name,
        game_score: score,
        time_seconds: 60,
        created_at: '2026-05-01 20:00:00',
    };
}

function body(entries, recent = []) {
    return { entries, recent, window_hours: 24, total_count: entries.length };
}

describe('diffArrivals', () => {
    it('celebrates nothing at all on the first response', () => {
        const tracker = createArrivalTracker();

        const result = diffArrivals(
            tracker,
            body([entry(1, 1), entry(2, 2), entry(3, 3)], [entry(3, 3)]),
            10
        );

        // The rule that makes an unattended screen survivable: a reload at 2am
        // must not greet an empty room with confetti for scores set two hours
        // ago, which would leave the effect worthless when a real one lands.
        expect(result.firstLoad).toBe(true);
        expect(result.highlightIds).toEqual([]);
        expect(result.banners).toEqual([]);
    });

    it('highlights an arrival that lands in the visible rows', () => {
        const tracker = createArrivalTracker();
        diffArrivals(tracker, body([entry(1, 1), entry(2, 2)]), 10);

        const result = diffArrivals(
            tracker,
            body([entry(1, 1), entry(9, 2), entry(2, 3)], [entry(9, 2)]),
            10
        );

        expect(result.firstLoad).toBe(false);
        expect(result.highlightIds).toEqual([9]);
        expect(result.banners).toEqual([]);
    });

    it('banners an arrival that placed below the visible rows', () => {
        const tracker = createArrivalTracker();
        diffArrivals(tracker, body([entry(1, 1), entry(2, 2), entry(3, 3)]), 3);

        const result = diffArrivals(
            tracker,
            body(
                [entry(1, 1), entry(2, 2), entry(3, 3)],
                [entry(47, 47, 'Rafael C.', 512)]
            ),
            3
        );

        expect(result.highlightIds).toEqual([]);
        expect(result.banners).toEqual([
            { id: 47, name: 'Rafael C.', rank: 47, score: 512 },
        ]);
    });

    it('uses the rank the server gave, not a position in the array', () => {
        const tracker = createArrivalTracker();
        diffArrivals(tracker, body([entry(1, 1)]), 1);

        // Second in the `recent` array, but 63rd on the board. A page counting
        // positions would put "rank 2" on the wall in front of the player.
        const result = diffArrivals(
            tracker,
            body([entry(1, 1)], [entry(80, 12, 'Ingrid S.'), entry(81, 63, 'Kwame A.')]),
            1
        );

        expect(result.banners.map((b) => b.rank)).toEqual([12, 63]);
    });

    it('produces one banner per arrival when three land between two polls', () => {
        const tracker = createArrivalTracker();
        diffArrivals(tracker, body([entry(1, 1)]), 1);

        const result = diffArrivals(
            tracker,
            body([entry(1, 1)], [entry(10, 20), entry(11, 21), entry(12, 22)]),
            1
        );

        expect(result.banners).toHaveLength(3);
        expect(result.banners.map((b) => b.id)).toEqual([10, 11, 12]);
    });

    it('never celebrates the same entry twice', () => {
        const tracker = createArrivalTracker();
        diffArrivals(tracker, body([entry(1, 1)]), 5);

        const first = diffArrivals(tracker, body([entry(1, 1), entry(2, 2)], [entry(2, 2)]), 5);
        expect(first.highlightIds).toEqual([2]);

        // Same response again — a poll that returned unchanged data.
        const second = diffArrivals(tracker, body([entry(1, 1), entry(2, 2)], [entry(2, 2)]), 5);
        expect(second.highlightIds).toEqual([]);
        expect(second.banners).toEqual([]);
    });

    it('counts an entry appearing in both lists once', () => {
        const tracker = createArrivalTracker();
        diffArrivals(tracker, body([entry(1, 1)]), 5);

        // A new top-5 finisher is in `entries` AND in `recent`; it must not be
        // both highlighted and bannered.
        const result = diffArrivals(tracker, body([entry(1, 1), entry(7, 2)], [entry(7, 2)]), 5);

        expect(result.highlightIds).toEqual([7]);
        expect(result.banners).toEqual([]);
    });

    it('banners an entry that is in the board but scrolled past the fold', () => {
        const tracker = createArrivalTracker();
        diffArrivals(tracker, body([entry(1, 1), entry(2, 2), entry(3, 3)]), 2);

        // Only two rows fit. The new entry is fourth, so it is in `entries`
        // but not on screen — a highlight nobody can see is not a celebration.
        const result = diffArrivals(
            tracker,
            body([entry(1, 1), entry(2, 2), entry(3, 3), entry(4, 4)], []),
            2
        );

        expect(result.highlightIds).toEqual([]);
        expect(result.banners.map((b) => b.id)).toEqual([4]);
    });

    it('survives a malformed or empty response without throwing', () => {
        const tracker = createArrivalTracker();

        expect(diffArrivals(tracker, {}, 10).firstLoad).toBe(true);
        expect(diffArrivals(tracker, null, 10).banners).toEqual([]);
        expect(diffArrivals(tracker, { entries: 'nope' }, 10).banners).toEqual([]);
    });
});

describe('the banner queue', () => {
    it('hands out one banner at a time, in order', () => {
        const queue = createBannerQueue();
        enqueueBanners(queue, [
            { id: 1, name: 'A', rank: 10, score: 1 },
            { id: 2, name: 'B', rank: 11, score: 2 },
        ]);

        expect(nextBanner(queue).name).toBe('A');
        // Nothing else until the first has had its time on screen: two names
        // on top of each other deny both players the moment.
        expect(nextBanner(queue)).toBeNull();

        releaseBanner(queue);
        expect(nextBanner(queue).name).toBe('B');

        releaseBanner(queue);
        expect(nextBanner(queue)).toBeNull();
    });

    it('loses nobody when arrivals come in bursts', () => {
        const queue = createBannerQueue();
        enqueueBanners(queue, [{ id: 1, name: 'A', rank: 1, score: 1 }]);
        enqueueBanners(queue, [
            { id: 2, name: 'B', rank: 2, score: 2 },
            { id: 3, name: 'C', rank: 3, score: 3 },
        ]);

        const shown = [];
        for (let i = 0; i < 3; i++) {
            shown.push(nextBanner(queue).name);
            releaseBanner(queue);
        }

        expect(shown).toEqual(['A', 'B', 'C']);
    });

    it('is bounded, keeping the most recent arrivals', () => {
        const queue = createBannerQueue(3);
        enqueueBanners(queue, [1, 2, 3, 4, 5].map((id) => ({ id, name: `P${id}`, rank: id, score: id })));

        const shown = [];
        let banner = nextBanner(queue);
        while (banner) {
            shown.push(banner.id);
            releaseBanner(queue);
            banner = nextBanner(queue);
        }

        expect(shown).toEqual([3, 4, 5]);
        expect(MAX_BANNER_QUEUE).toBeGreaterThan(0);
    });
});

describe('resolveDisplayData', () => {
    it('keeps the previous board on screen when a request fails', () => {
        const previous = body([entry(1, 1)]);

        const result = resolveDisplayData(previous, null, 1);

        // The single most important behaviour on this screen. A board frozen
        // two minutes ago beats a blank one in front of fifty people.
        expect(result.data).toBe(previous);
    });

    it('stays quiet about a single blip', () => {
        expect(resolveDisplayData(body([]), null, 1).stale).toBe(false);
        expect(resolveDisplayData(body([]), null, 2).stale).toBe(false);
    });

    it('admits staleness after three failures in a row', () => {
        expect(resolveDisplayData(body([]), null, STALE_AFTER_FAILURES).stale).toBe(true);
        expect(resolveDisplayData(body([]), null, 9).stale).toBe(true);
    });

    it('clears the stale flag the moment data comes back', () => {
        const fresh = body([entry(1, 1)]);

        const result = resolveDisplayData(body([]), fresh, 0);

        expect(result.data).toBe(fresh);
        expect(result.stale).toBe(false);
    });

    it('treats a response without an entries array as a failure', () => {
        const previous = body([entry(1, 1)]);

        // A proxy error page that happens to be valid JSON, or a 503 body.
        expect(resolveDisplayData(previous, { error: 'Database unavailable' }, 1).data)
            .toBe(previous);
    });
});

describe('backoffDelay', () => {
    it('starts at the normal poll interval and doubles', () => {
        expect(backoffDelay(1, 5000, 30000)).toBe(5000);
        expect(backoffDelay(2, 5000, 30000)).toBe(10000);
        expect(backoffDelay(3, 5000, 30000)).toBe(20000);
    });

    it('never grows past the cap', () => {
        // The cap matters more than the growth: a wall whose venue Wi-Fi drops
        // for ten minutes has to come back within seconds of its return, not
        // after a backoff that has climbed into the minutes.
        expect(backoffDelay(4, 5000, 30000)).toBe(30000);
        expect(backoffDelay(50, 5000, 30000)).toBe(30000);
    });
});

describe('rowsThatFit', () => {
    it('divides the space by the measured row height', () => {
        expect(rowsThatFit(1000, 100)).toBe(10);
        expect(rowsThatFit(1050, 100)).toBe(10);
    });

    it('answers zero rather than NaN when nothing has been measured yet', () => {
        expect(rowsThatFit(0, 40)).toBe(0);
        expect(rowsThatFit(400, 0)).toBe(0);
        expect(rowsThatFit(-10, 40)).toBe(0);
    });

    it('scales with the panel, which is the point', () => {
        // The same row height on a 1080x1920 panel and on a 2160x3840 one.
        expect(rowsThatFit(1400, 70)).toBe(20);
        expect(rowsThatFit(2800, 70)).toBe(40);
    });
});
