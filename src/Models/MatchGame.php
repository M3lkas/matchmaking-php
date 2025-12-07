<?php
// src/Models/MatchGame.php

require_once __DIR__ . '/../Database.php';

class MatchGame
{
    public int $id;
    public string $game_mode;
    public ?int $avg_mmr;
    public string $status;
    public string $created_at;

    public static function create(string $gameMode, int $avgMmr): self
    {
        $pdo = Database::getConnection();
        $stmt = $pdo->prepare(
            'INSERT INTO matches (game_mode, avg_mmr, status)
             VALUES (:game_mode, :avg_mmr, "active")'
        );
        $stmt->execute([
            'game_mode' => $gameMode,
            'avg_mmr'   => $avgMmr,
        ]);

        $id = (int)$pdo->lastInsertId();

        $match = new self();
        $match->id = $id;
        $match->game_mode = $gameMode;
        $match->avg_mmr = $avgMmr;
        $match->status = 'active';
        $match->created_at = date('Y-m-d H:i:s');

        return $match;
    }
}
