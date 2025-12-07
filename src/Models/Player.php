<?php
// src/Models/Player.php

require_once __DIR__ . '/../Database.php';

class Player
{
    public int $id;
    public string $username;
    public string $password_hash;
    public int $mmr;
    public string $region;
    public string $created_at;

    public static function findById(int $id): ?self
    {
        $pdo = Database::getConnection();
        $stmt = $pdo->prepare('SELECT * FROM players WHERE id = :id');
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch();

        if (!$row) {
            return null;
        }

        return self::fromRow($row);
    }

    public static function findByUsername(string $username): ?self
    {
        $pdo = Database::getConnection();
        $stmt = $pdo->prepare('SELECT * FROM players WHERE username = :username');
        $stmt->execute(['username' => $username]);
        $row = $stmt->fetch();

        if (!$row) {
            return null;
        }

        return self::fromRow($row);
    }

    public static function create(string $username, string $password, string $region = 'eu'): self
    {
        $pdo = Database::getConnection();

        $passwordHash = password_hash($password, PASSWORD_BCRYPT);

        $stmt = $pdo->prepare(
            'INSERT INTO players (username, password_hash, region)
             VALUES (:username, :password_hash, :region)'
        );

        $stmt->execute([
            'username'      => $username,
            'password_hash' => $passwordHash,
            'region'        => $region,
        ]);

        $id = (int)$pdo->lastInsertId();

        $player = new self();
        $player->id = $id;
        $player->username = $username;
        $player->password_hash = $passwordHash;
        $player->region = $region;
        $player->mmr = 1000;
        $player->created_at = date('Y-m-d H:i:s');

        return $player;
    }

    public function verifyPassword(string $password): bool
    {
        return password_verify($password, $this->password_hash);
    }

    private static function fromRow(array $row): self
    {
        $player = new self();
        $player->id = (int)$row['id'];
        $player->username = $row['username'];
        $player->password_hash = $row['password_hash'];
        $player->mmr = (int)$row['mmr'];
        $player->region = $row['region'];
        $player->created_at = $row['created_at'];

        return $player;
    }
}
