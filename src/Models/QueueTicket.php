<?php

require_once __DIR__ . '/../Database.php';

class QueueTicket
{
    public int $id;
    public int $player_id;
    public string $game_mode;
    public string $status;      // in_queue | matched | cancelled
    public string $created_at;

    private static function fromRow(array $row): self
    {
        $t = new self();
        $t->id = (int) $row['id'];
        $t->player_id = (int) $row['player_id'];
        $t->game_mode = $row['game_mode'];
        $t->status = $row['status'];
        $t->created_at = $row['created_at'];

        return $t;
    }

    public static function create(int $playerId, string $gameMode): self
    {
        $pdo = Database::getConnection();

        $sql = 'INSERT INTO queue_tickets (player_id, game_mode, status) VALUES (:player_id, :game_mode, "in_queue")';
        $stmt = $pdo->prepare($sql);

        $stmt->execute([
            'player_id' => $playerId,
            'game_mode' => $gameMode,
        ]);

        $id = (int) $pdo->lastInsertId();

        $ticket = new self();
        $ticket->id = $id;
        $ticket->player_id = $playerId;
        $ticket->game_mode = $gameMode;
        $ticket->status = 'in_queue';
        $ticket->created_at = date('Y-m-d H:i:s');

        return $ticket;
    }

    public static function findById(int $id): ?self
    {
        $pdo = Database::getConnection();
        $sql = 'SELECT * FROM queue_tickets WHERE id = :id';
        $stmt = $pdo->prepare($sql);
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch();

        if (!$row) {
            return null;
        }

        return self::fromRow($row);
    }

    public static function findActiveForPlayer(int $playerId, string $gameMode): ?self
    {
        $pdo = Database::getConnection();
        $sql = 'SELECT * FROM queue_tickets
                WHERE player_id = :player_id
                  AND game_mode = :game_mode
                  AND status = "in_queue"
                ORDER BY created_at DESC
                LIMIT 1';
        $stmt = $pdo->prepare($sql);
        $stmt->execute([
            'player_id' => $playerId,
            'game_mode' => $gameMode,
        ]);
        $row = $stmt->fetch();

        if (!$row) {
            return null;
        }

        return self::fromRow($row);
    }

    public static function cancelActiveForPlayer(int $playerId, string $gameMode): bool
    {
        $pdo = Database::getConnection();
        $sql = 'UPDATE queue_tickets
                SET status = "cancelled"
                WHERE player_id = :player_id
                  AND game_mode = :game_mode
                  AND status = "in_queue"';
        $stmt = $pdo->prepare($sql);
        $stmt->execute([
            'player_id' => $playerId,
            'game_mode' => $gameMode,
        ]);

        return $stmt->rowCount() > 0;
    }

    public static function findLatestForPlayer(int $playerId, string $gameMode): ?self
    {
        $pdo = Database::getConnection();
        $sql = 'SELECT * FROM queue_tickets
                WHERE player_id = :player_id
                  AND game_mode = :game_mode
                ORDER BY created_at DESC
                LIMIT 1';
        $stmt = $pdo->prepare($sql);
        $stmt->execute([
            'player_id' => $playerId,
            'game_mode' => $gameMode,
        ]);
        $row = $stmt->fetch();

        if (!$row) {
            return null;
        }

        return self::fromRow($row);
    }

    public static function findWaitingTickets(string $gameMode, int $limit): array
    {
        $pdo = Database::getConnection();
        $sql = 'SELECT * FROM queue_tickets
                WHERE game_mode = :game_mode
                  AND status = "in_queue"
                ORDER BY created_at ASC
                LIMIT :limit';
        $stmt = $pdo->prepare($sql);
        $stmt->bindValue(':game_mode', $gameMode);
        $stmt->bindValue(':limit', $limit, \PDO::PARAM_INT);
        $stmt->execute();
        $rows = $stmt->fetchAll();

        $tickets = [];
        foreach ($rows as $row) {
            $tickets[] = self::fromRow($row);
        }

        return $tickets;
    }

    public static function markAsMatched(array $ticketIds): void
    {
        if (empty($ticketIds)) {
            return;
        }

        $pdo = Database::getConnection();
        $in  = implode(',', array_fill(0, count($ticketIds), '?'));

        $sql = "UPDATE queue_tickets SET status = 'matched' WHERE id IN ($in)";
        $stmt = $pdo->prepare($sql);
        $stmt->execute($ticketIds);
    }
}
