<?php

// src/Controllers/MatchController.php

require_once __DIR__ . '/../Database.php';

class MatchController
{
    /**
     * GET /api/matches/history
     *
     * История матчей (по игроку или просто последние матчи).
     * Сейчас это больше "про запас", фронт может этим и не пользоваться.
     */
    public function history(): void
    {
        header('Content-Type: application/json; charset=utf-8');

        $pdo = Database::getConnection();

        $playerId = isset($_GET['player_id']) ? (int)$_GET['player_id'] : null;
        $limit    = isset($_GET['limit']) ? max(1, min(100, (int)$_GET['limit'])) : 50;

        if ($playerId) {
            $stmt = $pdo->prepare(
                'SELECT m.id,
                        m.game_mode,
                        m.avg_mmr,
                        m.status,
                        m.created_at
                 FROM matches m
                 JOIN match_players mp ON mp.match_id = m.id
                 WHERE mp.player_id = :player_id
                 ORDER BY m.created_at DESC
                 LIMIT :limit'
            );
            $stmt->bindValue(':player_id', $playerId, PDO::PARAM_INT);
            $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
            $stmt->execute();
        } else {
            $stmt = $pdo->prepare(
                'SELECT id,
                        game_mode,
                        avg_mmr,
                        status,
                        created_at
                 FROM matches
                 ORDER BY created_at DESC
                 LIMIT :limit'
            );
            $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
            $stmt->execute();
        }

        $rows = $stmt->fetchAll();
        echo json_encode(['matches' => $rows], JSON_UNESCAPED_UNICODE);
    }

    /**
     * GET /api/matches/last?player_id=...
     *
     * Возвращает ПОСЛЕДНИЙ матч этого игрока с разбивкой по командам.
     * Это то, что использует фронт для отображения лобби.
     */
    public function lastForPlayer(): void
    {
        header('Content-Type: application/json; charset=utf-8');

        $playerId = isset($_GET['player_id']) ? (int)$_GET['player_id'] : 0;
        if ($playerId <= 0) {
            http_response_code(400);
            echo json_encode(
                ['status' => 'error', 'error' => 'player_id is required'],
                JSON_UNESCAPED_UNICODE
            );
            return;
        }

        $pdo = Database::getConnection();

        // 1. Находим последний матч, где участвовал этот игрок
        $stmt = $pdo->prepare(
            'SELECT m.id,
                    m.game_mode,
                    m.avg_mmr,
                    m.status,
                    m.created_at,
                    p.region
             FROM matches m
             JOIN match_players mp ON mp.match_id = m.id
             JOIN players p        ON p.id = mp.player_id
             WHERE mp.player_id = :player_id
             ORDER BY m.created_at DESC
             LIMIT 1'
        );
        $stmt->execute(['player_id' => $playerId]);
        $matchRow = $stmt->fetch();

        if (!$matchRow) {
            http_response_code(404);
            echo json_encode(
                ['status' => 'not_found', 'error' => 'match not found'],
                JSON_UNESCAPED_UNICODE
            );
            return;
        }

        $matchId = (int)$matchRow['id'];

        // 2. Забираем всех игроков этого матча
        $stmt = $pdo->prepare(
            'SELECT mp.team,
                    p.username,
                    p.mmr
             FROM match_players mp
             JOIN players p ON p.id = mp.player_id
             WHERE mp.match_id = :match_id
             ORDER BY mp.team, p.mmr DESC'
        );
        $stmt->execute(['match_id' => $matchId]);
        $rows = $stmt->fetchAll();

        // 3. Группируем по командам
        $teams = [];
        foreach ($rows as $row) {
            // team в БД CHAR(1), приводим к int 1/2
            $teamIndex = (int)$row['team'];

            if (!isset($teams[$teamIndex])) {
                $teams[$teamIndex] = [
                    'team_name' => $teamIndex === 1 ? 'Team A' : 'Team B',
                    'players'   => [],
                ];
            }

            $teams[$teamIndex]['players'][] = [
                'username' => $row['username'],
                'mmr'      => (int)$row['mmr'],
            ];
        }

        ksort($teams); 

        // 4. Собираем объект матча
        $matchData = [
            'match_id'   => $matchId,
            'game_mode'  => $matchRow['game_mode'],
            'region'     => $matchRow['region'],
            'avg_mmr'    => (int)$matchRow['avg_mmr'],
            'status_raw' => $matchRow['status'],     //
            'created_at' => $matchRow['created_at'],
            'teams'      => array_values($teams),    //
        ];

        //
        //
        //
        $response = $matchData;
        $response['status'] = 'matched';
        $response['match']  = $matchData;

        echo json_encode($response, JSON_UNESCAPED_UNICODE);
    }
}
