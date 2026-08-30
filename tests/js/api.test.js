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

import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { createApi } from '../../public/assets/js/lib/api.js';

function jsonResponse(data) {
    return Promise.resolve({ json: () => Promise.resolve(data) });
}

describe('createApi', () => {
    let originalLocation;

    beforeEach(() => {
        originalLocation = window.location;
        // jsdom's window.location.href assignment triggers a "not implemented:
        // navigation" error unless replaced with a plain writable stub.
        delete window.location;
        window.location = { href: '' };
    });

    afterEach(() => {
        window.location = originalLocation;
        vi.restoreAllMocks();
    });

    it('POSTs JSON with the X-Action and X-CSRF-Token headers', async () => {
        global.fetch = vi.fn(() => jsonResponse({ success: true }));
        const api = createApi({ apiUrl: 'index.php', getCsrfToken: () => 'tok123' });

        await api('game/scenario', { foo: 'bar' });

        expect(fetch).toHaveBeenCalledWith('index.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-Action': 'game/scenario',
                'X-CSRF-Token': 'tok123',
            },
            body: JSON.stringify({ foo: 'bar' }),
        });
    });

    it('sends an empty object body when none is given', async () => {
        global.fetch = vi.fn(() => jsonResponse({}));
        const api = createApi({ apiUrl: 'index.php', getCsrfToken: () => 'tok' });

        await api('game/reset-session');

        expect(fetch).toHaveBeenCalledWith('index.php', expect.objectContaining({ body: '{}' }));
    });

    it('reads the CSRF token freshly on every call, not just at creation', async () => {
        global.fetch = vi.fn(() => jsonResponse({}));
        let token = 'first';
        const api = createApi({ apiUrl: 'index.php', getCsrfToken: () => token });

        await api('a', {});
        token = 'second';
        await api('b', {});

        expect(fetch.mock.calls[0][1].headers['X-CSRF-Token']).toBe('first');
        expect(fetch.mock.calls[1][1].headers['X-CSRF-Token']).toBe('second');
    });

    it('uses upload headers (no Content-Type/JSON body) when isUpload is true', async () => {
        global.fetch = vi.fn(() => jsonResponse({ success: true }));
        const api = createApi({ apiUrl: 'index.php', getCsrfToken: () => 'tok' });
        const formData = { fake: 'FormData' };

        await api('admin/upload', formData, true);

        expect(fetch).toHaveBeenCalledWith('index.php', {
            method: 'POST',
            headers: { 'X-Action': 'admin/upload', 'X-CSRF-Token': 'tok' },
            body: formData,
        });
    });

    it('returns the parsed response data on success', async () => {
        global.fetch = vi.fn(() => jsonResponse({ scenario: { id: 42 } }));
        const api = createApi({ apiUrl: 'index.php', getCsrfToken: () => 'tok' });

        const result = await api('game/scenario', {});
        expect(result).toEqual({ scenario: { id: 42 } });
    });

    it('returns null and logs when fetch rejects (network failure)', async () => {
        const consoleError = vi.spyOn(console, 'error').mockImplementation(() => {});
        global.fetch = vi.fn(() => Promise.reject(new Error('network down')));
        const api = createApi({ apiUrl: 'index.php', getCsrfToken: () => 'tok' });

        const result = await api('game/scenario', {});

        expect(result).toBeNull();
        expect(consoleError).toHaveBeenCalled();
    });

    it('returns null when the response body is not valid JSON', async () => {
        vi.spyOn(console, 'error').mockImplementation(() => {});
        global.fetch = vi.fn(() => Promise.resolve({ json: () => Promise.reject(new SyntaxError('bad json')) }));
        const api = createApi({ apiUrl: 'index.php', getCsrfToken: () => 'tok' });

        const result = await api('game/scenario', {});
        expect(result).toBeNull();
    });

    it('redirects to index.php and returns null when setup_required is set', async () => {
        global.fetch = vi.fn(() => jsonResponse({ setup_required: true }));
        const api = createApi({ apiUrl: 'index.php', getCsrfToken: () => 'tok' });

        const result = await api('game/scenario', {});

        expect(result).toBeNull();
        expect(window.location.href).toBe('index.php');
    });
});
