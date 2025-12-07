<?php

require_once __DIR__ . '/../Database.php';
require_once __DIR__ . '/../Models/QueueTicket.php';
require_once __DIR__ . '/../Services/MatchmakingService.php';

class QueueController
{
    /**
     * POST /api/queue/join
     *
     * Ставит игрока в очередь и сразу возвращает АКТУАЛЬНОЕ состояние тикета
     * после возможного запуска матчмейкера.
     *
     * ВАЖНО: игрок может иметь много старых тикетов со статусом "matched",
     * но одновременно только ОДИН тикет "in_queue".
     */
    public function join(): void
    {
        header('Content-Type: application/json; charset=utf-8');

        $input = json_decode(file_get_contents('php://input'), true);
        if (!$input || !isset($input['player_id']) || !isset($input['game_mode'])) {
            http_response_code(400);
            echo json_encode(['error' => 'player_id and game_mode are required'], JSON_UNESCAPED_UNICODE);
            return;
        }

        $playerId = (int)$input['player_id'];
        $gameMode = (string)$input['game_mode'];

        $pdo = Database::getConnection();

        // 1. Ищем существующий тикет ТОЛЬКО со статусом "in_queue"
        $stmt = $pdo->prepare(
            'SELECT id, player_id, game_mode, status, created_at
             FROM queue_tickets
             WHERE player_id = :player_id
               AND game_mode = :game_mode
               AND status = "in_queue"
             ORDER BY created_at DESC
             LIMIT 1'
        );
        $stmt->execute([
            'player_id' => $playerId,
            'game_mode' => $gameMode,
        ]);
        $existing = $stmt->fetch();

        if ($existing) {
            // Игрок уже СЕЙЧАС стоит в очереди → не создаём дубликат
            $ticketId = (int)$existing['id'];
        } else {
            // 2. Создаём НОВЫЙ тикет со статусом in_queue
            $stmt = $pdo->prepare(
                'INSERT INTO queue_tickets (player_id, game_mode, status)
                 VALUES (:player_id, :game_mode, "in_queue")'
            );
            $stmt->execute([
                'player_id' => $playerId,
                'game_mode' => $gameMode,
            ]);
            $ticketId = (int)$pdo->lastInsertId();
        }

        // 3. Запускаем матчмейкер для этого режима
        MatchmakingService::runForGameMode($gameMode);

        // 4. Перечитываем тикет из БД — здесь уже может быть status = "matched"
        $stmt = $pdo->prepare(
            'SELECT id, player_id, game_mode, status, created_at
             FROM queue_tickets
             WHERE id = :id'
        );
        $stmt->execute(['id' => $ticketId]);
        $ticket = $stmt->fetch();

        if (!$ticket) {
            http_response_code(500);
            echo json_encode(['error' => 'queue ticket not found after matchmaking'], JSON_UNESCAPED_UNICODE);
            return;
        }

        echo json_encode([
            'ticket_id'  => (int)$ticket['id'],
            'player_id'  => (int)$ticket['player_id'],
            'game_mode'  => $ticket['game_mode'],
            'status'     => $ticket['status'],   // in_queue или matched
            'created_at' => $ticket['created_at'],
        ], JSON_UNESCAPED_UNICODE);
    }

    /**
     * GET /api/queue/status?player_id=&game_mode=
     *
     * Возвращает последнее состояние тикета игрока в данном режиме.
     * Клиент может периодически опрашивать этот эндпоинт.
     */
    public function status(): void
    {
        header('Content-Type: application/json; charset=utf-8');

        $playerId = isset($_GET['player_id']) ? (int)$_GET['player_id'] : null;
        $gameMode = isset($_GET['game_mode']) ? (string)$_GET['game_mode'] : null;

        if (!$playerId || !$gameMode) {
            http_response_code(400);
            echo json_encode(['error' => 'player_id and game_mode are required'], JSON_UNESCAPED_UNICODE);
            return;
        }

        $pdo = Database::getConnection();
        $stmt = $pdo->prepare(
            'SELECT id, player_id, game_mode, status, created_at
             FROM queue_tickets
             WHERE player_id = :player_id
               AND game_mode = :game_mode
             ORDER BY created_at DESC
             LIMIT 1'
        );
        $stmt->execute([
            'player_id' => $playerId,
            'game_mode' => $gameMode,
        ]);
        $ticket = $stmt->fetch();

        if (!$ticket) {
            http_response_code(404);
            echo json_encode(['error' => 'ticket not found'], JSON_UNESCAPED_UNICODE);
            return;
        }

        echo json_encode([
            'ticket_id'  => (int)$ticket['id'],
            'player_id'  => (int)$ticket['player_id'],
            'game_mode'  => $ticket['game_mode'],
            'status'     => $ticket['status'],
            'created_at' => $ticket['created_at'],
        ], JSON_UNESCAPED_UNICODE);
    }

    /**
     * POST /api/queue/cancel
     *
     * Выход из очереди (ставим status = "cancelled" для всех in_queue тикетов игрока).
     */
    public function cancel(): void
    {
        header('Content-Type: application/json; charset=utf-8');

        $input = json_decode(file_get_contents('php://input'), true);
        if (!$input || !isset($input['player_id']) || !isset($input['game_mode'])) {
            http_response_code(400);
            echo json_encode(['error' => 'player_id and game_mode are required'], JSON_UNESCAPED_UNICODE);
            return;
        }

        $playerId = (int)$input['player_id'];
        $gameMode = (string)$input['game_mode'];

        $pdo = Database::getConnection();
        $stmt = $pdo->prepare(
            'UPDATE queue_tickets
             SET status = "cancelled"
             WHERE player_id = :player_id
               AND game_mode = :game_mode
               AND status = "in_queue"'
        );
        $stmt->execute([
            'player_id' => $playerId,
            'game_mode' => $gameMode,
        ]);

        echo json_encode(['message' => 'left queue'], JSON_UNESCAPED_UNICODE);
    }
}
