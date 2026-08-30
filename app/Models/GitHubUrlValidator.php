<?php
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

namespace App\Models;

/**
 * Whether a URL is safe to download a code artifact from — an https URL whose
 * host is one of GitHub's.
 *
 * Updater unpacks a downloaded archive over the live install, so the URL it
 * downloads from is security-critical. The webhook payload carries that URL as
 * free-form JSON, and GitHub download URLs redirect across a small set of
 * GitHub-owned hosts (api.github.com -> codeload.github.com for a zipball,
 * github.com/.../releases/download -> objects.githubusercontent.com or
 * release-assets.githubusercontent.com for a release asset), so this must be
 * checked on the initial URL AND on every redirect hop Updater follows.
 *
 * This does not authenticate the artifact's *contents* — a compromise of the
 * configured GitHub repository, or the webhook secret plus push access to it,
 * still yields a trusted install. What this closes is pointing the updater at
 * an arbitrary host (http://attacker/evil.zip) and off-host redirects.
 */
final class GitHubUrlValidator
{
    /** Every host a legitimate GitHub release/zipball download resolves through. */
    private const ALLOWED_HOSTS = [
        'github.com',
        'api.github.com',
        'codeload.github.com',
        'objects.githubusercontent.com',
        'release-assets.githubusercontent.com',
    ];

    public static function isAllowed(string $url): bool
    {
        $scheme = parse_url($url, PHP_URL_SCHEME);
        $host = parse_url($url, PHP_URL_HOST);

        if ($scheme !== 'https' || !is_string($host) || $host === '') {
            return false;
        }

        return in_array(strtolower($host), self::ALLOWED_HOSTS, true);
    }
}
