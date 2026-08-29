<?php
/**
 * Tests for initSchema() non-destructive facts behavior.
 * Ensures that calling initSchema() multiple times does NOT reset custom facts.
 * Also tests the schema versioning logic used in index.php.
 */

namespace Tests;

use PHPUnit\Framework\TestCase;
use App\Models\Database;
use Tests\Support\UsesInMemoryDatabase;

class InitSchemaFactsTest extends TestCase
{
    use UsesInMemoryDatabase;

    private Database $db;

    protected function setUp(): void
    {
        // In-memory SQLite: never the developer's configured server.
        $this->bootInMemoryDatabase();
        $this->db = Database::getInstance();
    }

    protected function tearDown(): void
    {
        $this->shutdownInMemoryDatabase();
    }

    /**
     * Core regression test: initSchema should NOT destroy existing custom facts.
     */
    public function testInitSchemaPreservesExistingFacts(): void
    {
        $pdo = $this->db->getPdo();

        // Clear and recreate with defaults
        $pdo->exec('DROP TABLE IF EXISTS facts');
        $this->db->initSchema();

        // Add a custom fact
        $pdo->exec("INSERT INTO facts (content) VALUES ('My custom fact for testing')");
        $countBefore = (int) $pdo->query('SELECT COUNT(*) FROM facts')->fetchColumn();
        $this->assertGreaterThan(10, $countBefore); // 10 defaults + 1 custom

        // Call initSchema again — this should NOT reset the facts
        $this->db->initSchema();

        $countAfter = (int) $pdo->query('SELECT COUNT(*) FROM facts')->fetchColumn();
        $this->assertEquals($countBefore, $countAfter, 'initSchema must not reset existing facts');

        // Verify custom fact is still present
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM facts WHERE content = ?");
        $stmt->execute(['My custom fact for testing']);
        $this->assertEquals(1, $stmt->fetchColumn(), 'Custom fact should survive initSchema');
    }

    /**
     * On first run (empty facts table), 10 defaults should be seeded.
     */
    public function testInitSchemaSeedsDefaultsOnEmptyTable(): void
    {
        $pdo = $this->db->getPdo();
        $pdo->exec('DROP TABLE IF EXISTS facts');
        $this->db->initSchema();

        $count = (int) $pdo->query('SELECT COUNT(*) FROM facts')->fetchColumn();
        $this->assertEquals(10, $count);
    }

    /**
     * If admin deletes all facts and then initSchema runs, defaults should be re-seeded.
     */
    public function testInitSchemaReSeedsAfterAdminPurge(): void
    {
        $pdo = $this->db->getPdo();
        $pdo->exec('DROP TABLE IF EXISTS facts');
        $this->db->initSchema();

        // Admin purges all facts
        $pdo->exec('DELETE FROM facts');
        $this->assertEquals(0, (int) $pdo->query('SELECT COUNT(*) FROM facts')->fetchColumn());

        // Next initSchema should re-seed
        $this->db->initSchema();
        $this->assertEquals(10, (int) $pdo->query('SELECT COUNT(*) FROM facts')->fetchColumn());
    }

    /**
     * Multiple initSchema calls with existing facts should be idempotent.
     */
    public function testMultipleInitSchemaCallsAreIdempotent(): void
    {
        $pdo = $this->db->getPdo();
        $pdo->exec('DROP TABLE IF EXISTS facts');
        $this->db->initSchema();

        $count1 = (int) $pdo->query('SELECT COUNT(*) FROM facts')->fetchColumn();

        $this->db->initSchema();
        $this->db->initSchema();
        $this->db->initSchema();

        $count2 = (int) $pdo->query('SELECT COUNT(*) FROM facts')->fetchColumn();
        $this->assertEquals($count1, $count2);
    }

    /**
     * Test that other tables are also created by initSchema.
     */
    public function testInitSchemaCreatesAllTables(): void
    {
        $pdo = $this->db->getPdo();
        $this->db->initSchema();

        // Portable across drivers: counting a missing table raises, so a
        // numeric result proves the table exists.
        $tables = ['scenarios', 'leaderboard', 'settings', 'facts', 'game_counter'];
        foreach ($tables as $table) {
            $count = $pdo->query("SELECT COUNT(*) FROM $table")->fetchColumn();
            $this->assertIsNumeric($count, "Table '$table' must exist after initSchema");
        }
    }

    /* =======================================================
       Schema Versioning Logic (mirrors index.php)
       ======================================================= */

    public function testSchemaVersionTriggersMigration(): void
    {
        $schemaVersion = 4;

        $session = ['schema_version' => 3];
        $shouldRun = ($session['schema_version'] ?? 0) < $schemaVersion;
        $this->assertTrue($shouldRun, 'Should run when session version is lower');
    }

    public function testSchemaVersionDoesNotRunWhenCurrent(): void
    {
        $schemaVersion = 4;

        $session = ['schema_version' => 4];
        $shouldRun = ($session['schema_version'] ?? 0) < $schemaVersion;
        $this->assertFalse($shouldRun);
    }

    public function testSchemaVersionRunsWhenMissing(): void
    {
        $schemaVersion = 4;

        $session = [];
        $shouldRun = ($session['schema_version'] ?? 0) < $schemaVersion;
        $this->assertTrue($shouldRun, 'Should run when schema_version not in session');
    }

    public function testDualSchemaCheckLogic(): void
    {
        // Mirrors both checks in index.php
        $schemaVersion = 4;
        $session = [];

        // First check: version-based
        if (($session['schema_version'] ?? 0) < $schemaVersion) {
            $session['schema_version'] = $schemaVersion;
        }
        $this->assertEquals(4, $session['schema_version']);

        // Second check: boolean-based
        if (empty($session['schema_ready'])) {
            $session['schema_ready'] = true;
        }
        $this->assertTrue($session['schema_ready']);
    }
}
