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

use App\Models\GitHubWebhook;
use App\Models\SettingsModel;
use PHPUnit\Framework\TestCase;
use Tests\Support\UsesInMemoryDatabase;

/**
 * The one network call GitHubWebhook makes (resolving a release tag's commit
 * sha, or fetching the latest release/commit for a manual check) is injected
 * via its optional $httpGet constructor parameter — see GitHubWebhook's own
 * docblock. Every test here passes a fake so nothing hits the real network.
 */
class GitHubWebhookTest extends TestCase
{
    use UsesInMemoryDatabase;

    private SettingsModel $settings;

    protected function setUp(): void
    {
        $this->bootInMemoryDatabase();
        $this->settings = new SettingsModel($this->memoryPdo());
    }

    protected function tearDown(): void
    {
        $this->shutdownInMemoryDatabase();
    }

    private function webhook(?callable $httpGet = null): GitHubWebhook
    {
        return new GitHubWebhook($this->settings, $httpGet ?? fn () => null);
    }

    private function configure(string $channel, bool $enabled = true): void
    {
        $this->settings->setMany([
            'update_enabled' => $enabled ? '1' : '0',
            'update_channel' => $channel,
            'update_github_owner' => 'xdubois-57',
            'update_github_repo' => 'iso20022-address-game',
        ]);
    }

    // -----------------------------------------------------------------
    // verifySignature
    // -----------------------------------------------------------------

    public function testVerifySignatureAcceptsCorrectHmac(): void
    {
        $body = '{"zen":"ping"}';
        $secret = 'topsecret';
        $sig = 'sha256=' . hash_hmac('sha256', $body, $secret);

        $this->assertTrue($this->webhook()->verifySignature($body, $sig, $secret));
    }

    public function testVerifySignatureRejectsWrongSecret(): void
    {
        $body = '{"zen":"ping"}';
        $sig = 'sha256=' . hash_hmac('sha256', $body, 'the-real-secret');

        $this->assertFalse($this->webhook()->verifySignature($body, $sig, 'a-different-secret'));
    }

    public function testVerifySignatureRejectsTamperedBody(): void
    {
        $secret = 'topsecret';
        $sig = 'sha256=' . hash_hmac('sha256', '{"zen":"ping"}', $secret);

        $this->assertFalse($this->webhook()->verifySignature('{"zen":"tampered"}', $sig, $secret));
    }

    public function testVerifySignatureRejectsEmptySecretOrHeader(): void
    {
        $webhook = $this->webhook();
        $this->assertFalse($webhook->verifySignature('body', 'sha256=abc', ''));
        $this->assertFalse($webhook->verifySignature('body', '', 'secret'));
    }

    // -----------------------------------------------------------------
    // handleReleaseEvent
    // -----------------------------------------------------------------

    private function releasePayload(array $overrides = []): array
    {
        return array_replace_recursive([
            'action' => 'published',
            'release' => [
                'tag_name' => 'v1.2.3',
                'zipball_url' => 'https://api.github.com/repos/xdubois-57/iso20022-address-game/zipball/v1.2.3',
                'assets' => [],
            ],
            'repository' => ['full_name' => 'xdubois-57/iso20022-address-game'],
        ], $overrides);
    }

    public function testReleaseEventIgnoredWhenActionIsNotPublished(): void
    {
        $this->configure('release');
        $result = $this->webhook()->handleReleaseEvent($this->releasePayload(['action' => 'edited']));

        $this->assertSame(['status' => 'ignored', 'reason' => 'action_not_published'], $result);
        $this->assertNull($this->settings->get('update_last_event_result'));
    }

    public function testReleaseEventIgnoredWhenDisabled(): void
    {
        $this->configure('release', enabled: false);
        $result = $this->webhook()->handleReleaseEvent($this->releasePayload());

        $this->assertSame('ignored', $result['status']);
        $this->assertSame('auto_update_disabled', $result['reason']);
        $this->assertNull($this->settings->get('update_pending'));
    }

    public function testReleaseEventIgnoredWhenChannelIsMain(): void
    {
        $this->configure('main');
        $result = $this->webhook()->handleReleaseEvent($this->releasePayload());

        $this->assertSame('channel_not_release', $result['reason']);
    }

    public function testReleaseEventIgnoredOnRepositoryMismatch(): void
    {
        $this->configure('release');
        $result = $this->webhook()->handleReleaseEvent(
            $this->releasePayload(['repository' => ['full_name' => 'someone-else/other-repo']])
        );

        $this->assertSame('repository_mismatch', $result['reason']);
        $this->assertNull($this->settings->get('update_pending'));
    }

    public function testReleaseEventIgnoredWithoutTagName(): void
    {
        $this->configure('release');
        $result = $this->webhook()->handleReleaseEvent($this->releasePayload(['release' => ['tag_name' => '']]));

        $this->assertSame('invalid_payload', $result['reason']);
    }

    public function testReleaseEventQueuesInstallAndRecordsDiagnostics(): void
    {
        $this->configure('release');
        $result = $this->webhook()->handleReleaseEvent($this->releasePayload());

        $this->assertSame(['status' => 'ok'], $result);

        $pending = json_decode($this->settings->get('update_pending'), true);
        $this->assertSame('release', $pending['source_type']);
        $this->assertSame('v1.2.3', $pending['version_to']);
        $this->assertSame(
            'https://api.github.com/repos/xdubois-57/iso20022-address-game/zipball/v1.2.3',
            $pending['download_url']
        );
        $this->assertNotEmpty($pending['commit']);

        $this->assertSame('ok', $this->settings->get('update_last_event_result'));
        $this->assertNotNull($this->settings->get('update_last_event_at'));
    }

    public function testReleaseEventPrefersZipAssetOverZipball(): void
    {
        $this->configure('release');
        $result = $this->webhook()->handleReleaseEvent($this->releasePayload([
            'release' => [
                'assets' => [
                    [
                        'name' => 'notes.txt',
                        'browser_download_url' => 'https://objects.githubusercontent.com/notes.txt',
                    ],
                    [
                        'name' => 'release-v1.2.3.zip',
                        'browser_download_url' => 'https://objects.githubusercontent.com/release-v1.2.3.zip',
                    ],
                ],
            ],
        ]));

        $this->assertSame('ok', $result['status']);
        $pending = json_decode($this->settings->get('update_pending'), true);
        $this->assertSame('https://objects.githubusercontent.com/release-v1.2.3.zip', $pending['download_url']);
    }

    public function testReleaseEventRefusesNonGitHubDownloadUrl(): void
    {
        $this->configure('release');
        $result = $this->webhook()->handleReleaseEvent(
            $this->releasePayload(['release' => ['zipball_url' => 'https://attacker.example/evil.zip']])
        );

        $this->assertSame('download_url_refused', $result['reason']);
        $this->assertNull($this->settings->get('update_pending'));
    }

    public function testReleaseEventUsesResolvedCommitFromInjectedHttpGet(): void
    {
        $this->configure('release');
        $webhook = $this->webhook(fn (string $url) => str_contains($url, '/commits/')
            ? ['sha' => 'abc1234567890']
            : null);

        $webhook->handleReleaseEvent($this->releasePayload());
        $pending = json_decode($this->settings->get('update_pending'), true);
        $this->assertSame('abc1234', $pending['commit']);
    }

    // -----------------------------------------------------------------
    // handlePushEvent
    // -----------------------------------------------------------------

    private function pushPayload(array $overrides = []): array
    {
        return array_replace_recursive([
            'ref' => 'refs/heads/main',
            'after' => str_repeat('a', 40),
            'repository' => ['full_name' => 'xdubois-57/iso20022-address-game'],
        ], $overrides);
    }

    public function testPushEventIgnoredOnOtherBranchAndNotRecorded(): void
    {
        $this->configure('main');
        $result = $this->webhook()->handlePushEvent($this->pushPayload(['ref' => 'refs/heads/feature-x']));

        $this->assertSame('branch_mismatch', $result['reason']);
        // Noise from every non-main push must never overwrite diagnostics.
        $this->assertNull($this->settings->get('update_last_event_result'));
    }

    public function testPushEventIgnoredWhenDisabled(): void
    {
        $this->configure('main', enabled: false);
        $result = $this->webhook()->handlePushEvent($this->pushPayload());

        $this->assertSame('auto_update_disabled', $result['reason']);
        // A push that DID land on main is worth recording even when ignored.
        $this->assertSame('ignored:auto_update_disabled', $this->settings->get('update_last_event_result'));
    }

    public function testPushEventIgnoredWhenChannelIsRelease(): void
    {
        $this->configure('release');
        $result = $this->webhook()->handlePushEvent($this->pushPayload());

        $this->assertSame('channel_not_main', $result['reason']);
    }

    public function testPushEventIgnoredOnRepositoryMismatch(): void
    {
        $this->configure('main');
        $result = $this->webhook()->handlePushEvent(
            $this->pushPayload(['repository' => ['full_name' => 'someone-else/other-repo']])
        );

        $this->assertSame('repository_mismatch', $result['reason']);
    }

    public function testPushEventIgnoresBranchDeletion(): void
    {
        $this->configure('main');
        $result = $this->webhook()->handlePushEvent($this->pushPayload(['after' => str_repeat('0', 40)]));

        $this->assertSame('invalid_payload', $result['reason']);
    }

    public function testPushEventQueuesBranchInstall(): void
    {
        $this->configure('main');
        $sha = 'abcdef0123456789abcdef0123456789abcdef01';
        $result = $this->webhook()->handlePushEvent($this->pushPayload(['after' => $sha]));

        $this->assertSame(['status' => 'ok'], $result);
        $pending = json_decode($this->settings->get('update_pending'), true);
        $this->assertSame('branch', $pending['source_type']);
        $this->assertSame('dev-abcdef0', $pending['version_to']);
        $this->assertSame('abcdef0', $pending['commit']);
        $this->assertSame(
            "https://api.github.com/repos/xdubois-57/iso20022-address-game/zipball/{$sha}",
            $pending['download_url']
        );
    }

    // -----------------------------------------------------------------
    // checkAndQueueLatest (manual "Install now")
    // -----------------------------------------------------------------

    public function testCheckAndQueueLatestIgnoredWhenRepositoryNotConfigured(): void
    {
        $this->settings->setMany(['update_channel' => 'release']);
        $result = $this->webhook()->checkAndQueueLatest();

        $this->assertSame('repository_not_configured', $result['reason']);
    }

    public function testCheckAndQueueLatestReleaseUsesInjectedHttpGet(): void
    {
        $this->configure('release');
        $webhook = $this->webhook(fn (string $url) => match (true) {
            str_contains($url, '/releases/latest') => [
                'tag_name' => 'v2.0.0',
                'zipball_url' => 'https://api.github.com/repos/xdubois-57/iso20022-address-game/zipball/v2.0.0',
            ],
            str_contains($url, '/commits/') => ['sha' => str_repeat('b', 40)],
            default => null,
        });

        $result = $webhook->checkAndQueueLatest();
        $this->assertSame(['status' => 'ok'], $result);

        $pending = json_decode($this->settings->get('update_pending'), true);
        $this->assertSame('release', $pending['source_type']);
        $this->assertSame('v2.0.0', $pending['version_to']);
    }

    public function testCheckAndQueueLatestMainCommitUsesInjectedHttpGet(): void
    {
        $this->configure('main');
        $sha = str_repeat('c', 40);
        $webhook = $this->webhook(fn (string $url) => str_contains($url, '/commits/main') ? ['sha' => $sha] : null);

        $result = $webhook->checkAndQueueLatest();
        $this->assertSame(['status' => 'ok'], $result);

        $pending = json_decode($this->settings->get('update_pending'), true);
        $this->assertSame('branch', $pending['source_type']);
        $this->assertSame('dev-ccccccc', $pending['version_to']);
    }

    public function testCheckAndQueueLatestReleaseReportsWhenGitHubHasNone(): void
    {
        $this->configure('release');
        $result = $this->webhook(fn () => null)->checkAndQueueLatest();

        $this->assertSame('no_release_found', $result['reason']);
    }

    public function testCheckAndQueueLatestReleaseRefusesAnOffGitHubAsset(): void
    {
        $this->configure('release');
        $webhook = $this->webhook(fn (string $url) => str_contains($url, '/releases/latest')
            ? ['tag_name' => 'v3.0.0', 'zipball_url' => 'https://attacker.example/evil.zip']
            : null);

        $this->assertSame('no_download_url', $webhook->checkAndQueueLatest()['reason']);
        $this->assertNull($this->settings->get('update_pending'));
    }

    public function testAPingIsNotTreatedAsAnInstallTrigger(): void
    {
        // The controller answers ping itself; neither handler should ever see
        // a payload without an action or a ref and queue something from it.
        $this->configure('release');
        $this->assertSame('action_not_published', $this->webhook()->handleReleaseEvent(['zen' => 'x'])['reason']);
        $this->assertNull($this->settings->get('update_pending'));
    }

    public function testAReleaseWithoutAnyDownloadableArtifactIsIgnored(): void
    {
        $this->configure('release');
        $result = $this->webhook()->handleReleaseEvent($this->releasePayload([
            'release' => ['zipball_url' => '', 'assets' => []],
        ]));

        $this->assertSame('no_download_url', $result['reason']);
    }

    public function testANonZipReleaseAssetFallsBackToTheSourceZipball(): void
    {
        $this->configure('release');
        $this->webhook()->handleReleaseEvent($this->releasePayload([
            'release' => ['assets' => [
                ['name' => 'checksums.txt', 'browser_download_url' => 'https://objects.githubusercontent.com/c.txt'],
            ]],
        ]));

        $pending = json_decode($this->settings->get('update_pending'), true);
        $this->assertStringContainsString('zipball', $pending['download_url']);
    }

    public function testCheckAndQueueLatestMainReportsNoCommitFound(): void
    {
        $this->configure('main');
        $result = $this->webhook(fn () => null)->checkAndQueueLatest();

        $this->assertSame('no_commit_found', $result['reason']);
    }
}
