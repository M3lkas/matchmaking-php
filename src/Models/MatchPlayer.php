<?php
// src/Models/MatchPlayer.php

require_once __DIR__ . '/../Database.php';

class MatchPlayer
{
    public static function addPlayer(int $matchId, int $playerId, string $team): void
    {
        $pdo = Database::getConnection();
        $stmt = $pdo->prepare(
            'INSERT INTO match_players (match_id, player_id, team)
             VALUES (:match_id, :player_id, :team)'
        );
        $stmt->execute([
            'match_id'  => $matchId,
            'player_id' => $playerId,
            'team'      => $team,
        ]);
    }
}
