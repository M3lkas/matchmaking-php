<?php

require_once __DIR__ . '/../Database.php';
require_once __DIR__ . '/../Models/QueueTicket.php';
require_once __DIR__ . '/../Models/Player.php';

class MatchmakingService
{
    // 5 на 5: 5 игроков в каждой команде → всего 10 игроков на матч
    private const PLAYERS_PER_TEAM  = 5;
    private const TEAMS_PER_MATCH   = 2;
    private const PLAYERS_PER_MATCH = self::PLAYERS_PER_TEAM * self::TEAMS_PER_MATCH; // 10

    public static function runForGameMode(string $gameMode): void
    {
        $pdo = Database::getConnection();

        // Берём до 10 игроков в очереди для этого режима
        $tickets = QueueTicket::findWaitingTickets($gameMode, self::PLAYERS_PER_MATCH);

        // Если игроков меньше 10 — матч не собираем
        if (count($tickets) < self::PLAYERS_PER_MATCH) {
            return;
        }

        // Получаем их MMR
        $playerIds = array_map(fn($t) => $t->player_id, $tickets);

        $in = implode(',', array_fill(0, count($playerIds), '?'));
        $stmt = $pdo->prepare("SELECT id, mmr FROM players WHERE id IN ($in)");
        $stmt->execute($playerIds);
        $players = $stmt->fetchAll();

        if (count($players) !== count($playerIds)) {
            // На всякий случай защита от несостыковок
            return;
        }

        // Карта player_id -> mmr
        $mmrByPlayerId = [];
        $avgMmr = 0;
        foreach ($players as $p) {
            $mmr = (int) $p['mmr'];
            $mmrByPlayerId[(int) $p['id']] = $mmr;
            $avgMmr += $mmr;
        }
        $avgMmr = (int) round($avgMmr / max(1, count($players)));

        // Собираем пул: тикет + его MMR
        $pool = [];
        foreach ($tickets as $ticket) {
            $pid = (int) $ticket->player_id;
            $pool[] = [
                'ticket' => $ticket,
                'mmr'    => $mmrByPlayerId[$pid] ?? 1000,
            ];
        }

        // Сортируем игроков по MMR от сильных к слабым
        usort($pool, function ($a, $b) {
            return $b['mmr'] <=> $a['mmr'];
        });

        // Балансируем 2 команды по суммарному MMR
        $team1 = [];
        $team2 = [];
        $sum1  = 0;
        $sum2  = 0;

        foreach ($pool as $item) {
            $ticket = $item['ticket'];
            $mmr    = $item['mmr'];

            // Если в одной команде уже 5 игроков – кидаем в другую
            if (count($team1) >= self::PLAYERS_PER_TEAM) {
                $team2[] = $ticket;
                $sum2 += $mmr;
                continue;
            }
            if (count($team2) >= self::PLAYERS_PER_TEAM) {
                $team1[] = $ticket;
                $sum1 += $mmr;
                continue;
            }

            // Иначе кладём в команду с меньшей суммой MMR
            if ($sum1 <= $sum2) {
                $team1[] = $ticket;
                $sum1 += $mmr;
            } else {
                $team2[] = $ticket;
                $sum2 += $mmr;
            }
        }

        // Создаём матч (статус по умолчанию проставит БД)
        $stmt = $pdo->prepare(
            'INSERT INTO matches (game_mode, avg_mmr)
             VALUES (:game_mode, :avg_mmr)'
        );
        $stmt->execute([
            'game_mode' => $gameMode,
            'avg_mmr'   => $avgMmr,
        ]);

        $matchId = (int) $pdo->lastInsertId();

        // Подготовка вставки игроков в match_players
        $stmtMp = $pdo->prepare(
            'INSERT INTO match_players (match_id, player_id, team)
             VALUES (:match_id, :player_id, :team)'
        );

        $ticketIds = [];

        // Команда 1
        foreach ($team1 as $ticket) {
            $stmtMp->execute([
                'match_id'  => $matchId,
                'player_id' => $ticket->player_id,
                'team'      => 1,
            ]);
            $ticketIds[] = $ticket->id;
        }

        // Команда 2
        foreach ($team2 as $ticket) {
            $stmtMp->execute([
                'match_id'  => $matchId,
                'player_id' => $ticket->player_id,
                'team'      => 2,
            ]);
            $ticketIds[] = $ticket->id;
        }

        // Обновляем статус тикетов в очереди на matched
        QueueTicket::markAsMatched($ticketIds);
    }
}
