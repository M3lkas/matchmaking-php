<?php
/**
 * Простой интеграционный тест API матчмейкинга.
 *
 * Варианты запуска:
 *
 * 1) С хоста (когда API доступен на 8080):
 *      APP_BASE_URL=http://localhost:8080 php tests/api_test.php
 *
 * 2) Внутри Docker-контейнера app:
 *      docker compose exec app php /var/www/html/tests/api_test.php
 *    В этом случае по умолчанию используется http://localhost (порт 80 внутри контейнера),
 *    переменную APP_BASE_URL можно не указывать.
 */

// Отключаем предупреждения типа E_DEPRECATED, чтобы PHP 8.5 не засорял вывод.
error_reporting(E_ALL & ~E_DEPRECATED & ~E_USER_DEPRECATED);

// Базовый URL API: берём из переменной окружения APP_BASE_URL,
// а если её нет — используем http://localhost (для запуска внутри контейнера).
$BASE_URL = getenv('APP_BASE_URL') ?: 'http://localhost';

function api_request(string $method, string $path, ?array $data = null): array
{
    global $BASE_URL;

    $ch  = curl_init();
    $url = $BASE_URL . $path;

    $headers = ['Accept: application/json'];

    $options = [
        CURLOPT_URL            => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CUSTOMREQUEST  => $method,
        CURLOPT_HTTPHEADER     => $headers,
        CURLOPT_HEADER         => false,
    ];

    if ($data !== null) {
        $json = json_encode($data, JSON_UNESCAPED_UNICODE);
        $headers[] = 'Content-Type: application/json';
        $options[CURLOPT_HTTPHEADER] = $headers;
        $options[CURLOPT_POSTFIELDS] = $json;
    }

    curl_setopt_array($ch, $options);

    $body   = curl_exec($ch);
    $errno  = curl_errno($ch);
    $error  = curl_error($ch);
    $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);

    // Начиная с PHP 8.0 curl_close() ничего не делает,
    // а в 8.5 помечен как deprecated, поэтому не вызываем.
    // curl_close($ch);

    $json = null;
    if ($body !== false && $body !== null) {
        $decoded = json_decode($body, true);
        if (json_last_error() === JSON_ERROR_NONE) {
            $json = $decoded;
        }
    }

    return [
        'status' => $status,
        'body'   => $body,
        'json'   => $json,
        'errno'  => $errno,
        'error'  => $error,
    ];
}

function assert_true(bool $cond, string $message): void
{
    if ($cond) {
        echo "[OK]   $message\n";
    } else {
        echo "[FAIL] $message\n";
    }
}

// ---- ТЕСТОВЫЙ СЦЕНАРИЙ ----

echo "=== Matchmaking API integration test ===\n\n";

// 1. Регистрируем игроков
$usernames = ['milan', 'p2', 'p3', 'p4', 'p5', 'p6', 'p7', 'p8', 'p9', 'p10'];

echo "1) Register players:\n";

foreach ($usernames as $username) {
    $resp = api_request('POST', '/api/register', [
        'username' => $username,
        'password' => '123456',
        'region'   => 'eu',
    ]);

    // 200 / 201 / 400 (username already taken) — всё ок для нас
    echo "   - $username: HTTP {$resp['status']} {$resp['body']}\n";
}

echo "\n2) Login players and get player_id:\n";

$playerIds = [];

foreach ($usernames as $username) {
    $resp = api_request('POST', '/api/login', [
        'username' => $username,
        'password' => '123456',
    ]);

    $ok = ($resp['status'] === 200 && isset($resp['json']['player_id']));
    assert_true($ok, "login for $username returns player_id");

    if ($ok) {
        $playerIds[$username] = (int)$resp['json']['player_id'];
        echo "      $username -> player_id = {$playerIds[$username]}\n";
    } else {
        echo "      ERROR response: {$resp['body']}\n";
    }
}

if (count($playerIds) !== count($usernames)) {
    echo "\n[ABORT] Not all players logged in successfully, stopping test.\n";
    exit(1);
}

echo "\n3) Join queue 5v5 for all 10 players:\n";

$ticketIds    = [];
$lastStatuses = [];

foreach ($usernames as $username) {
    $pid = $playerIds[$username];

    $resp = api_request('POST', '/api/queue/join', [
        'player_id' => $pid,
        'game_mode' => '5v5',
    ]);

    $ok = ($resp['status'] === 200 && isset($resp['json']['ticket_id']));
    assert_true($ok, "queue/join for $username returned ticket_id");

    if ($ok) {
        $ticketId = (int)$resp['json']['ticket_id'];
        $status   = $resp['json']['status'] ?? 'unknown';

        $ticketIds[$username]    = $ticketId;
        $lastStatuses[$username] = $status;

        echo "      $username -> ticket_id = $ticketId, status = $status\n";
    } else {
        echo "      ERROR response: {$resp['body']}\n";
    }
}

// 4. Проверяем актуальные статусы через /api/queue/status
echo "\n4) Check final queue status for each player via /api/queue/status:\n";

$matchedCount = 0;

foreach ($usernames as $username) {
    $pid = $playerIds[$username];

    $path = '/api/queue/status?player_id=' . urlencode((string)$pid) . '&game_mode=5v5';
    $resp = api_request('GET', $path);

    $ok = ($resp['status'] === 200 && isset($resp['json']['status']));
    assert_true($ok, "queue/status for $username returns status");

    if ($ok) {
        $status = $resp['json']['status'];
        echo "      $username -> status = $status\n";
        if ($status === 'matched') {
            $matchedCount++;
        }
    } else {
        echo "      ERROR response: {$resp['body']}\n";
    }
}

// Ожидаем, что все 10 игроков уже должны быть заматчены
echo "\n5) Summary:\n";
assert_true($matchedCount === 10, "all 10 players have status = 'matched' (got $matchedCount)");

echo "\n=== Test finished ===\n";
