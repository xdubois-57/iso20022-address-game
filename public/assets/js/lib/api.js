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
 * Builds the api(action, body, isUpload) POST helper every screen in app.js
 * calls the backend through.
 *
 * A factory rather than a bare function so the CSRF token — read once from
 * the page's <meta name="csrf-token"> at call time via getCsrfToken(), not
 * captured at import time — and the endpoint are both injectable, which is
 * what lets tests/js/api.test.js exercise this with a fixed token and a
 * mocked fetch instead of a real DOM/network.
 *
 * @param {{ apiUrl: string, getCsrfToken: () => string }} config
 */
export function createApi(config) {
    var apiUrl = config.apiUrl;
    var getCsrfToken = config.getCsrfToken;

    return async function api(action, body, isUpload) {
        const opts = { method: 'POST' };
        if (isUpload) {
            opts.headers = { 'X-Action': action, 'X-CSRF-Token': getCsrfToken() };
            opts.body = body;
        } else {
            opts.headers = {
                'Content-Type': 'application/json',
                'X-Action': action,
                'X-CSRF-Token': getCsrfToken(),
            };
            opts.body = JSON.stringify(body || {});
        }
        // A 500, a proxy error page or a dropped connection all yield something
        // that is not JSON. resp.json() throws on those, and because every call
        // site only guards with `if (!data)` the rejection was unhandled and the
        // UI simply stopped. Fail as null instead, which callers already expect.
        var data;
        try {
            const resp = await fetch(apiUrl, opts);
            data = await resp.json();
        } catch (e) {
            console.error('API request failed:', action, e);
            return null;
        }

        if (data && data.setup_required) {
            // Database connection failed, redirect to setup page
            window.location.href = 'index.php';
            return null;
        }
        return data;
    };
}
