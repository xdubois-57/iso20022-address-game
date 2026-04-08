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

namespace App\Controllers;

use App\Models\Database;
use App\Models\LeaderboardModel;

class LeaderboardController
{
    private LeaderboardModel $leaderboardModel;

    public function __construct()
    {
        $db = Database::getInstance();
        $pdo = $db->getPdo();
        $this->leaderboardModel = new LeaderboardModel($pdo);
    }

    /**
     * POST /api/leaderboard/top — Get the Hall of Fame.
     */
    public function getTop(): void
    {
        $entries = $this->leaderboardModel->getTopEntries(10);
        $this->jsonResponse(['entries' => $entries]);
    }

    /**
     * POST /api/leaderboard/submit — Submit a new score.
     */
    public function submit(): void
    {
        $input = $this->getJsonInput();
        $name = trim($input['player_name'] ?? '');
        $score = (int) ($input['score'] ?? 0);

        if ($name === '' || mb_strlen($name) > 50) {
            $this->jsonResponse(['error' => 'Player name must be 1-50 characters'], 400);
            return;
        }

        if ($score < 0 || $score > 100) {
            $this->jsonResponse(['error' => 'Invalid score'], 400);
            return;
        }

        // Sanitize name
        $name = htmlspecialchars($name, ENT_QUOTES, 'UTF-8');

        $id = $this->leaderboardModel->addEntry($name, $score);
        $this->jsonResponse([
            'success' => true,
            'entry_id' => $id,
        ]);
    }

    private function getJsonInput(): array
    {
        $raw = file_get_contents('php://input');
        return json_decode($raw, true) ?? [];
    }

    private function jsonResponse(array $data, int $code = 200): void
    {
        http_response_code($code);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($data);
    }
}
