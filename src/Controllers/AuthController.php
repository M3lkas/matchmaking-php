<?php

require_once __DIR__ . '/../Models/Player.php';

class AuthController
{
    public function register(): void
    {
        $input = json_decode(file_get_contents('php://input'), true) ?? [];

        $username = trim($input['username'] ?? '');
        $password = trim($input['password'] ?? '');
        $region   = trim($input['region'] ?? 'eu');

        if ($username === '' || $password === '') {
            $this->jsonResponse(['error' => 'username and password are required'], 400);
            return;
        }

        if (Player::findByUsername($username) !== null) {
            $this->jsonResponse(['error' => 'username already taken'], 400);
            return;
        }

        $player = Player::create($username, $password, $region);

        $this->jsonResponse([
            'id'       => $player->id,
            'username' => $player->username,
            'mmr'      => $player->mmr,
            'region'   => $player->region,
        ], 201);
    }

    public function login(): void
    {
        $input = json_decode(file_get_contents('php://input'), true) ?? [];

        $username = trim($input['username'] ?? '');
        $password = trim($input['password'] ?? '');

        if ($username === '' || $password === '') {
            $this->jsonResponse(['error' => 'username and password are required'], 400);
            return;
        }

        $player = Player::findByUsername($username);
        if ($player === null || !$player->verifyPassword($password)) {
            $this->jsonResponse(['error' => 'invalid credentials'], 401);
            return;
        }

        $this->jsonResponse([
            'message'   => 'login successful',
            'player_id' => $player->id,
            'username'  => $player->username,
            'mmr'       => $player->mmr,
            'region'    => $player->region,
        ]);
    }

    private function jsonResponse(array $data, int $statusCode = 200): void
    {
        http_response_code($statusCode);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($data, JSON_UNESCAPED_UNICODE);
    }
}
