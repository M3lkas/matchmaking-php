<?php
// public/index.php

error_reporting(E_ALL);
ini_set('display_errors', '1');

require_once __DIR__ . '/../src/Controllers/AuthController.php';
require_once __DIR__ . '/../src/Controllers/QueueController.php';
require_once __DIR__ . '/../src/Controllers/MatchController.php';

$uri    = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$method = $_SERVER['REQUEST_METHOD'];

// ---------- API ПИНГ ----------
if (($uri === '/ping' || $uri === '/api/ping') && $method === 'GET') {
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode([
        'status'  => 'ok',
        'message' => 'matchmaking PHP server is alive',
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

// ---------- AUTH ----------
if ($uri === '/api/register' && $method === 'POST') {
    (new AuthController())->register();
    exit;
}

if ($uri === '/api/login' && $method === 'POST') {
    (new AuthController())->login();
    exit;
}

// ---------- QUEUE ----------
if ($uri === '/api/queue/join' && $method === 'POST') {
    (new QueueController())->join();
    exit;
}

if ($uri === '/api/queue/cancel' && $method === 'POST') {
    (new QueueController())->cancel();
    exit;
}

if ($uri === '/api/queue/status' && $method === 'GET') {
    (new QueueController())->status();
    exit;
}

// ---------- MATCHES ----------
if ($uri === '/api/matches/history' && $method === 'GET') {
    (new MatchController())->history();
    exit;
}

if ($uri === '/api/matches/last' && $method === 'GET') {
    (new MatchController())->lastForPlayer();
    exit;
}

// Если сюда дошли и это /api/... — отдаем 404 в JSON
if (strpos($uri, '/api/') === 0) {
    http_response_code(404);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['error' => 'Not found'], JSON_UNESCAPED_UNICODE);
    exit;
}

// ---------- ВСЁ ОСТАЛЬНОЕ: ОТДАЁМ ВЕБ-ИНТЕРФЕЙС ----------

header('Content-Type: text/html; charset=utf-8');
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <title>Matchmaking System</title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <!-- стили из public/style.css -->
    <link rel="stylesheet" href="style.css">
</head>
<body>
<div class="app-root">
    <!-- Верхняя панель -->
    <header class="topbar">
        <div class="topbar__brand">
            <span class="topbar__brand-main">MATCHMAKING</span>
            <span class="topbar__brand-sub">SYSTEM</span>
        </div>
        <div class="topbar__status">
            <span id="api-status" class="badge badge--checking">API: проверка...</span>
        </div>
    </header>

    <main class="layout">
        <!-- Левая колонка: форма и статус очереди -->
        <section class="card card--queue">
            <h2 class="card__title">Регистрация </h2>

            <!-- Убрали подсказочный текст -->

            <form id="queue-form" class="form">
                <div class="form__group">
                    <label for="username">Никнейм</label>
                    <input id="username" name="username" type="text" required placeholder="milan">
                </div>

                <div class="form__group">
                    <label for="password">Пароль</label>
                    <input id="password" name="password" type="password" required value="123456">
                </div>

                <div class="form__row">
                    <div class="form__group">
                        <label for="region">Регион</label>
                        <select id="region" name="region">
                            <option value="eu">EU</option>
                            <option value="na">NA</option>
                            <option value="asia">ASIA</option>
                        </select>
                    </div>

                    <div class="form__group">
                        <label for="game-mode">Режим</label>
                        <select id="game-mode" name="game_mode">
                            <option value="5v5">5v5</option>
                        </select>
                    </div>
                </div>

                <button type="submit" class="btn btn--primary" id="join-btn">
                    Встать в очередь
                </button>

                <!-- Подсказку про /api/register и /api/login убрали -->
            </form>

            <div class="queue-status">
                <h3>Текущий статус</h3>
                <div class="queue-status__row">
                    <span class="queue-status__label">Игрок:</span>
                    <span class="queue-status__value" id="current-player">–</span>
                </div>
                <div class="queue-status__row">
                    <span class="queue-status__label">Режим:</span>
                    <span class="queue-status__value" id="current-mode">–</span>
                </div>
                <div class="queue-status__row">
                    <span class="queue-status__label">Ticket ID:</span>
                    <span class="queue-status__value" id="ticket-id">–</span>
                </div>
                <div class="queue-status__row">
                    <span class="queue-status__label">Статус очереди:</span>
                    <span class="queue-status__badge" id="queue-state">не в очереди</span>
                </div>
            </div>

            <pre class="log" id="queue-log"></pre>
        </section>

        <!-- Правая колонка: лобби в стиле FACEIT -->
        <section class="card card--lobby">
            <header class="card__header card__header--lobby">
                <div>
                    <h2 class="card__title">Лобби матча</h2>
                    <p class="card__subtitle" id="lobby-subtitle">
                        Матч ещё не найден.
                    </p>
                </div>
                <div class="lobby-meta">
                    <span class="lobby-meta__item" id="lobby-mode">5v5</span>
                    <span class="lobby-meta__item" id="lobby-region">EU</span>
                    <span class="lobby-meta__item" id="lobby-mmr">MMR: –</span>
                </div>
            </header>

            <div class="lobby-score">
                <div class="lobby-score__team" id="team1-score">TEAM A</div>
                <div class="lobby-score__value" id="match-score">0 : 0</div>
                <div class="lobby-score__team lobby-score__team--right" id="team2-score">TEAM B</div>
            </div>

            <div class="lobby-teams" id="lobby-teams">
                <!-- Команды и игроки подставляются через JS -->
            </div>

            <footer class="lobby-footer">
                <button class="btn btn--ghost" type="button" id="reset-lobby-btn">
                    Очистить лобби
                </button>
            </footer>
        </section>
    </main>
</div>

<!-- фронтовая логика в public/app.js -->
<script src="app.js"></script>
</body>
</html>
