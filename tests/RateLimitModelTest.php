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

use App\Models\RateLimitModel;
use PHPUnit\Framework\TestCase;
use Tests\Support\UsesInMemoryDatabase;

class RateLimitModelTest extends TestCase
{
    use UsesInMemoryDatabase;

    private RateLimitModel $limiter;

    protected function setUp(): void
    {
        $this->limiter = new RateLimitModel($this->bootInMemoryDatabase());
    }

    protected function tearDown(): void
    {
        $this->shutdownInMemoryDatabase();
    }

    public function testFreshBucketIsNotLimited(): void
    {
        $this->assertSame(0, $this->limiter->retryAfter('admin_login:fresh'));
    }

    public function testFailuresBelowThresholdDoNotLock(): void
    {
        $bucket = 'admin_login:below';

        for ($i = 0; $i < 4; $i++) {
            $this->assertSame(0, $this->limiter->recordFailure($bucket, 5, 300));
        }
        $this->assertSame(0, $this->limiter->retryAfter($bucket));
    }

    public function testReachingThresholdLocksTheBucket(): void
    {
        $bucket = 'admin_login:threshold';

        for ($i = 0; $i < 4; $i++) {
            $this->limiter->recordFailure($bucket, 5, 300);
        }
        $this->assertSame(300, $this->limiter->recordFailure($bucket, 5, 300));
        $this->assertGreaterThan(0, $this->limiter->retryAfter($bucket));
    }

    public function testClearResetsTheBucket(): void
    {
        $bucket = 'admin_login:cleared';

        for ($i = 0; $i < 5; $i++) {
            $this->limiter->recordFailure($bucket, 5, 300);
        }
        $this->assertGreaterThan(0, $this->limiter->retryAfter($bucket));

        $this->limiter->clear($bucket);
        $this->assertSame(0, $this->limiter->retryAfter($bucket));
    }

    public function testBucketsAreIndependent(): void
    {
        for ($i = 0; $i < 5; $i++) {
            $this->limiter->recordFailure('admin_login:a', 5, 300);
        }

        $this->assertGreaterThan(0, $this->limiter->retryAfter('admin_login:a'));
        $this->assertSame(0, $this->limiter->retryAfter('admin_login:b'));
    }

    /**
     * The whole point of moving off $_SESSION: the counter must not reset
     * because the caller threw its cookie away.
     */
    public function testCounterSurvivesAcrossInstances(): void
    {
        $bucket = RateLimitModel::bucketFor('admin_login', '203.0.113.7');

        for ($i = 0; $i < 5; $i++) {
            $this->limiter->recordFailure($bucket, 5, 300);
        }

        $freshInstance = new RateLimitModel($this->memoryPdo());
        $this->assertGreaterThan(
            0,
            $freshInstance->retryAfter($bucket),
            'A new session must not reset an address-keyed limiter'
        );
    }

    public function testBucketDependsOnAddress(): void
    {
        $a = RateLimitModel::bucketFor('admin_login', '198.51.100.1');
        $b = RateLimitModel::bucketFor('admin_login', '198.51.100.2');

        $this->assertNotEquals($a, $b);
    }

    public function testBucketDependsOnScope(): void
    {
        $login = RateLimitModel::bucketFor('admin_login', '198.51.100.1');
        $event = RateLimitModel::bucketFor('leaderboard_submit', '198.51.100.1');

        $this->assertNotEquals($login, $event, 'One limiter must not lock another');
    }

    public function testBucketDoesNotContainTheAddress(): void
    {
        // GDPR: the address is hashed, never stored.
        $bucket = RateLimitModel::bucketFor('admin_login', '203.0.113.42');
        $this->assertStringNotContainsString('203.0.113.42', $bucket);
    }

    public function testExpiredLockRestartsTheCount(): void
    {
        $bucket = 'admin_login:expired';

        // Lock, then rewind the stored expiry into the past.
        for ($i = 0; $i < 5; $i++) {
            $this->limiter->recordFailure($bucket, 5, 300);
        }
        $this->memoryPdo()
            ->prepare('UPDATE rate_limits SET locked_until = ? WHERE bucket = ?')
            ->execute([time() - 1, $bucket]);

        $this->assertSame(0, $this->limiter->retryAfter($bucket));
        $this->assertSame(
            0,
            $this->limiter->recordFailure($bucket, 5, 300),
            'A served lockout must not leave the caller one attempt from re-locking'
        );
    }

    public function testPurgeExpiredRemovesStaleRows(): void
    {
        $this->limiter->recordFailure('admin_login:stale', 5, 300);
        $this->memoryPdo()->exec('UPDATE rate_limits SET updated_at = 0, locked_until = 0');

        $this->assertSame(1, $this->limiter->purgeExpired());
    }
}
