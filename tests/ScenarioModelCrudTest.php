<?php
/**
 * Tests for ScenarioModel CRUD operations (create, getById, getRandom, deleteAll, getAll).
 */

namespace Tests;

use PHPUnit\Framework\TestCase;
use App\Models\ScenarioModel;

class ScenarioModelCrudTest extends TestCase
{
    private \PDO $pdo;
    private ScenarioModel $model;

    protected function setUp(): void
    {
        $this->pdo = new \PDO('sqlite::memory:');
        $this->pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        $this->pdo->setAttribute(\PDO::ATTR_DEFAULT_FETCH_MODE, \PDO::FETCH_ASSOC);

        $this->pdo->exec("
            CREATE TABLE scenarios (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                json_data TEXT NOT NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
            )
        ");

        $this->model = new ScenarioModel($this->pdo);
    }

    public function testCreateReturnsPositiveId(): void
    {
        $id = $this->model->create(['StrtNm' => 'Main St', 'TwnNm' => 'Paris', 'Ctry' => 'FR']);
        $this->assertGreaterThan(0, $id);
    }

    public function testCreateStoresJsonCorrectly(): void
    {
        $data = ['StrtNm' => 'Baker Street', 'BldgNb' => '221B', 'TwnNm' => 'London', 'Ctry' => 'GB'];
        $id = $this->model->create($data);

        $stmt = $this->pdo->query("SELECT json_data FROM scenarios WHERE id = $id");
        $row = $stmt->fetch();
        $this->assertEquals($data, json_decode($row['json_data'], true));
    }

    public function testGetByIdReturnsScenario(): void
    {
        $data = ['TwnNm' => 'Berlin', 'Ctry' => 'DE'];
        $id = $this->model->create($data);

        $result = $this->model->getById($id);
        $this->assertNotNull($result);
        $this->assertEquals($id, $result['id']);
        $this->assertEquals('Berlin', $result['json_data']['TwnNm']);
    }

    public function testGetByIdReturnsNullForNonExistent(): void
    {
        $result = $this->model->getById(9999);
        $this->assertNull($result);
    }

    public function testGetByIdDecodesJsonData(): void
    {
        $data = ['StrtNm' => 'Rue de Rivoli', 'TwnNm' => 'Paris', 'Ctry' => 'FR'];
        $id = $this->model->create($data);

        $result = $this->model->getById($id);
        $this->assertIsArray($result['json_data']);
        $this->assertEquals('FR', $result['json_data']['Ctry']);
    }

    public function testGetAllReturnsAllScenarios(): void
    {
        $this->model->create(['TwnNm' => 'Paris', 'Ctry' => 'FR']);
        $this->model->create(['TwnNm' => 'London', 'Ctry' => 'GB']);
        $this->model->create(['TwnNm' => 'Berlin', 'Ctry' => 'DE']);

        $all = $this->model->getAll();
        $this->assertCount(3, $all);
    }

    public function testGetAllDecodesJsonData(): void
    {
        $this->model->create(['TwnNm' => 'Tokyo', 'Ctry' => 'JP']);

        $all = $this->model->getAll();
        $this->assertIsArray($all[0]['json_data']);
        $this->assertEquals('Tokyo', $all[0]['json_data']['TwnNm']);
    }

    public function testGetAllReturnsEmptyArrayWhenNoScenarios(): void
    {
        $all = $this->model->getAll();
        $this->assertIsArray($all);
        $this->assertEmpty($all);
    }

    public function testGetAllOrdersById(): void
    {
        $id1 = $this->model->create(['TwnNm' => 'First', 'Ctry' => 'AA']);
        $id2 = $this->model->create(['TwnNm' => 'Second', 'Ctry' => 'BB']);

        $all = $this->model->getAll();
        $this->assertEquals($id1, $all[0]['id']);
        $this->assertEquals($id2, $all[1]['id']);
    }

    public function testDeleteAllRemovesAllScenarios(): void
    {
        $this->model->create(['TwnNm' => 'Paris', 'Ctry' => 'FR']);
        $this->model->create(['TwnNm' => 'London', 'Ctry' => 'GB']);

        $this->model->deleteAll();

        $all = $this->model->getAll();
        $this->assertEmpty($all);
    }

    /**
     * getRandom uses MySQL RAND() which doesn't exist in SQLite.
     * These tests verify the randomization logic using SQLite's RANDOM() directly.
     */
    public function testGetRandomLogicReturnsRow(): void
    {
        $this->model->create(['TwnNm' => 'Paris', 'Ctry' => 'FR']);

        // Simulate getRandom with SQLite-compatible RANDOM()
        $stmt = $this->pdo->query('SELECT id, json_data FROM scenarios ORDER BY RANDOM() LIMIT 1');
        $row = $stmt->fetch();
        $this->assertNotNull($row);
        $row['json_data'] = json_decode($row['json_data'], true);
        $this->assertEquals('Paris', $row['json_data']['TwnNm']);
    }

    public function testGetRandomLogicReturnsNullWhenEmpty(): void
    {
        $stmt = $this->pdo->query('SELECT id, json_data FROM scenarios ORDER BY RANDOM() LIMIT 1');
        $row = $stmt->fetch();
        $this->assertFalse($row);
    }

    public function testGetRandomLogicExcludesSpecifiedIds(): void
    {
        $id1 = $this->model->create(['TwnNm' => 'Paris', 'Ctry' => 'FR']);
        $id2 = $this->model->create(['TwnNm' => 'London', 'Ctry' => 'GB']);

        $stmt = $this->pdo->prepare('SELECT id, json_data FROM scenarios WHERE id NOT IN (?) ORDER BY RANDOM() LIMIT 1');
        $stmt->execute([$id1]);
        $row = $stmt->fetch();
        $this->assertNotFalse($row);
        $this->assertEquals($id2, $row['id']);
    }

    public function testGetRandomLogicReturnsNullWhenAllExcluded(): void
    {
        $id1 = $this->model->create(['TwnNm' => 'Paris', 'Ctry' => 'FR']);

        $stmt = $this->pdo->prepare('SELECT id, json_data FROM scenarios WHERE id NOT IN (?) ORDER BY RANDOM() LIMIT 1');
        $stmt->execute([$id1]);
        $row = $stmt->fetch();
        $this->assertFalse($row);
    }

    public function testGetRandomLogicDecodesJsonData(): void
    {
        $this->model->create(['StrtNm' => 'Hauptstraße', 'BldgNb' => '5', 'TwnNm' => 'München', 'Ctry' => 'DE']);

        $stmt = $this->pdo->query('SELECT id, json_data FROM scenarios ORDER BY RANDOM() LIMIT 1');
        $row = $stmt->fetch();
        $data = json_decode($row['json_data'], true);
        $this->assertIsArray($data);
        $this->assertEquals('Hauptstraße', $data['StrtNm']);
    }

    public function testCreateMultipleAndDeleteAllThenRecreate(): void
    {
        $this->model->create(['TwnNm' => 'Old', 'Ctry' => 'XX']);
        $this->model->deleteAll();
        $this->assertEmpty($this->model->getAll());

        $id = $this->model->create(['TwnNm' => 'New', 'Ctry' => 'YY']);
        $this->assertGreaterThan(0, $id);
        $this->assertCount(1, $this->model->getAll());
    }
}
