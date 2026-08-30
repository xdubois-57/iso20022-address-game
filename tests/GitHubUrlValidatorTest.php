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

namespace Tests;

use App\Models\GitHubUrlValidator;
use PHPUnit\Framework\TestCase;

class GitHubUrlValidatorTest extends TestCase
{
    /**
     * @dataProvider allowedUrlProvider
     */
    public function testAllowsGitHubHosts(string $url): void
    {
        $this->assertTrue(GitHubUrlValidator::isAllowed($url));
    }

    public static function allowedUrlProvider(): array
    {
        return [
            'github.com' => [
                'https://github.com/xdubois-57/iso20022-address-game/releases/download/v1.0.0/release.zip',
            ],
            'api.github.com' => ['https://api.github.com/repos/xdubois-57/iso20022-address-game/zipball/main'],
            'codeload.github.com' => [
                'https://codeload.github.com/xdubois-57/iso20022-address-game/zip/refs/heads/main',
            ],
            'objects.githubusercontent.com' => ['https://objects.githubusercontent.com/some/path'],
            'release-assets.githubusercontent.com' => ['https://release-assets.githubusercontent.com/some/path'],
            'uppercase host' => ['https://GITHUB.COM/owner/repo/zipball/main'],
        ];
    }

    /**
     * @dataProvider refusedUrlProvider
     */
    public function testRefusesNonGitHubUrls(string $url): void
    {
        $this->assertFalse(GitHubUrlValidator::isAllowed($url));
    }

    public static function refusedUrlProvider(): array
    {
        return [
            'plain http' => ['http://github.com/owner/repo/zipball/main'],
            'attacker host' => ['https://attacker.example/evil.zip'],
            'lookalike host' => ['https://github.com.attacker.example/evil.zip'],
            'lookalike subdomain' => ['https://not-github.com/evil.zip'],
            'ftp scheme' => ['ftp://github.com/owner/repo/zipball/main'],
            'no scheme' => ['github.com/owner/repo/zipball/main'],
            'empty string' => [''],
            'javascript scheme' => ['javascript://github.com/%0aalert(1)'],
        ];
    }
}
