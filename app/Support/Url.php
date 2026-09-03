<?php
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

namespace App\Support;

/**
 * Absolute URLs built from request data that a client controls.
 *
 * Host and Request-URI both come from the caller. ShareController validated the
 * Host header before using it, but layout.php interpolated both straight into
 * og:url, og:image and twitter:image with no validation and no escaping — while
 * the docs claimed host-header validation was applied throughout. Both now go
 * through here.
 */
class Url
{
    /** Hostname, optionally with a port. Rejects anything that could break out. */
    private const SAFE_HOST = '/^[a-zA-Z0-9.\-]+(:\d{1,5})?$/';

    /**
     * The request's Host header, or 'localhost' when it is missing or malformed.
     */
    public static function safeHost(): string
    {
        $host = $_SERVER['HTTP_HOST'] ?? 'localhost';

        return preg_match(self::SAFE_HOST, $host) ? $host : 'localhost';
    }

    /**
     * Scheme and host for this request, e.g. "https://example.org".
     */
    public static function baseUrl(): string
    {
        $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';

        return $scheme . '://' . self::safeHost();
    }

    /**
     * Absolute URL for the current request.
     *
     * The path and query are taken from REQUEST_URI, which is attacker-supplied,
     * so only characters legal in a URL path or query survive.
     */
    public static function currentUrl(): string
    {
        $uri = $_SERVER['REQUEST_URI'] ?? '/';

        // Drop anything outside the unreserved / sub-delim / path set, which
        // removes quotes and angle brackets that would otherwise escape an
        // HTML attribute.
        $uri = preg_replace('#[^A-Za-z0-9\-._~:/?\#\[\]@!$&\'()*+,;=%]#', '', $uri) ?? '/';

        if ($uri === '' || $uri[0] !== '/') {
            $uri = '/' . $uri;
        }

        return self::baseUrl() . $uri;
    }

    /**
     * HTML-attribute-safe version of currentUrl().
     */
    public static function currentUrlHtml(): string
    {
        return htmlspecialchars(self::currentUrl(), ENT_QUOTES, 'UTF-8');
    }

    /**
     * HTML-attribute-safe absolute URL for a path on this host.
     */
    public static function absoluteHtml(string $path): string
    {
        if ($path === '' || $path[0] !== '/') {
            $path = '/' . $path;
        }

        return htmlspecialchars(self::baseUrl() . $path, ENT_QUOTES, 'UTF-8');
    }
}
