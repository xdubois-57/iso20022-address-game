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

use App\Models\Database;
use App\Models\SettingsModel;
use PDO;
use PHPUnit\Framework\TestCase;

/**
 * The automatic-update feature is gone: deployment is the deploy script's
 * job alone.
 *
 * Two things are worth a test rather than a release note. The first is the
 * migration: an install that ran the feature holds a live HMAC credential in
 * `settings`, and deleting the code that used it does not delete the secret.
 * The second is that the code really is gone — a removal is only finished
 * when nothing can reach the classes any more, and a stray `require` or a
 * resurrected route is exactly the kind of thing a later refactor
 * reintroduces by accident.
 */
class RemovedAutoUpdateTest extends TestCase
{
    private ?PDO $pdo = null;

    protected function tearDown(): void
    {
        $this->pdo = null;
        Database::getInstance()->setPdo(null);
        Database::resetInstance();
    }

    /**
     * Boots a schema with the settings a pre-removal install would carry,
     * seeded BEFORE initSchema() runs so the migration is what removes them
     * rather than the test never having written them.
     */
    private function bootPreUpgradeInstall(): PDO
    {
        Database::resetInstance();

        $pdo = new PDO('sqlite::memory:');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        $pdo->exec(
            'CREATE TABLE settings (
                setting_key TEXT PRIMARY KEY,
                setting_value TEXT,
                updated_at TEXT
            )'
        );
        foreach ([
            'update_enabled' => '1',
            'update_channel' => 'release',
            'update_webhook_secret' => str_repeat('a', 64),
            'update_github_owner' => 'xdubois-57',
            'update_github_repo' => 'iso20022-address-game',
            'update_pending' => '{"queued_at":1756600000,"version":"v1.2.3"}',
            'update_last_install_status' => 'completed',
            'update_dependencies_changed' => '1',
            // A neighbour that must survive: the migration matches on a
            // prefix, so anything it over-matched would be silently lost.
            'unstructured_deadline' => '2026-11-14T18:00',
        ] as $key => $value) {
            $pdo->prepare('INSERT INTO settings (setting_key, setting_value) VALUES (?, ?)')
                ->execute([$key, $value]);
        }

        $db = Database::getInstance();
        $db->setPdo($pdo);
        $db->initSchema();

        return $this->pdo = $pdo;
    }

    public function testUpgradingPurgesEveryAutomaticUpdateSetting(): void
    {
        $pdo = $this->bootPreUpgradeInstall();

        $left = $pdo->query("SELECT setting_key FROM settings WHERE setting_key LIKE 'update%'")
            ->fetchAll(PDO::FETCH_COLUMN);

        $this->assertSame([], $left, 'no automatic-update setting may survive the upgrade');
    }

    public function testTheWebhookSecretIsGoneSpecifically(): void
    {
        $this->bootPreUpgradeInstall();

        $settings = new SettingsModel($this->pdo);

        $this->assertNull(
            $settings->get('update_webhook_secret'),
            'the webhook secret is a live credential — it must not outlive the feature'
        );
    }

    public function testUnrelatedSettingsSurviveTheMigration(): void
    {
        $this->bootPreUpgradeInstall();

        $this->assertSame(
            '2026-11-14T18:00',
            (new SettingsModel($this->pdo))->get('unstructured_deadline'),
            'the prefix DELETE must not reach beyond the removed feature'
        );
    }

    public function testTheMigrationIsANoOpOnAnInstallThatNeverEnabledIt(): void
    {
        Database::resetInstance();
        $pdo = new PDO('sqlite::memory:');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

        $db = Database::getInstance();
        $db->setPdo($pdo);
        $db->initSchema();
        $db->initSchema(); // idempotent: a second request must not fail

        $this->pdo = $pdo;
        $this->assertSame(
            [],
            $pdo->query("SELECT setting_key FROM settings WHERE setting_key LIKE 'update%'")
                ->fetchAll(PDO::FETCH_COLUMN)
        );
    }

    /**
     * @dataProvider removedClassProvider
     */
    public function testTheAutoUpdateClassesAreGone(string $class): void
    {
        $this->assertFalse(
            class_exists($class),
            "{$class} must no longer exist — the updater was removed, not disabled"
        );
    }

    public static function removedClassProvider(): array
    {
        return array_map(fn ($c) => [$c], [
            'App\Models\Updater',
            'App\Models\GitHubWebhook',
            'App\Models\GitHubUrlValidator',
            'App\Controllers\WebhookController',
        ]);
    }

    /**
     * @dataProvider removedMethodProvider
     */
    public function testTheAdminPanelHasNoUpdateEndpoints(string $method): void
    {
        $this->assertFalse(
            method_exists('App\Controllers\AdminController', $method),
            "AdminController::{$method}() must be gone with the rest of the feature"
        );
    }

    public static function removedMethodProvider(): array
    {
        return array_map(fn ($m) => [$m], [
            'getUpdateSettings', 'saveUpdateSettings', 'generateWebhookSecret', 'installUpdateNow',
        ]);
    }

    public function testTheFrontControllerRoutesNothingToTheUpdater(): void
    {
        $front = (string) file_get_contents(__DIR__ . '/../public/index.php');

        foreach (['webhook/github', 'Updater', 'update-settings', 'install-update-now'] as $needle) {
            $this->assertStringNotContainsString(
                $needle,
                $front,
                "public/index.php must not mention {$needle}"
            );
        }
    }
}
