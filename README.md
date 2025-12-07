# Система матчмейкинга 5v5 (PHP + MySQL + Docker)

Курсовой проект: серверная часть системы матчмейкинга для командных игр формата **5 vs 5**.

Проект реализует полный цикл:
- регистрация и авторизация игроков;
- хранение рейтинга (MMR) и региона;
- постановка игроков в очередь;
- подбор матча 5v5 с балансировкой по MMR;
- формирование двух команд и запись матча в базу;
- отображение лобби матча во фронтенде (веб-интерфейс).

---

## 1. Стек технологий

**Backend:**
- PHP 8.2 (Apache)
- PDO + MySQL (БД `matchmaking`)
- Чистый PHP без фреймворков (MVC-структура: Controllers / Models / Services)

**Database:**
- MySQL 8
- 4 основные таблицы:
  - `players` — игроки и их MMR;
  - `queue_tickets` — заявки в очереди;
  - `matches` — созданные матчи;
  - `match_players` — связь матчей и игроков (кто в какой команде).

**Frontend:**
- Чистый JavaScript (без фреймворков)
- HTML + CSS (верстка в стиле FACEIT-лобби)
- Взаимодействие с backend через REST API (`fetch`).

**Инфраструктура:**
- Docker + docker compose
- Дополнительно: phpMyAdmin для просмотра БД.

---

## 2. Структура проекта

Основные директории:

- `public/`
  - `index.php` — единая точка входа (роутер для API + отдача фронта).
  - `app.js` — логика фронтенда (регистрация, очередь, лобби).
  - `style.css` — стили.

- `src/`
  - `Database.php` — подключение к MySQL через PDO.
  - `Controllers/`
    - `AuthController.php` — регистрация и логин.
    - `QueueController.php` — работа с очередью.
    - `MatchController.php` — работа с матчами (история, последний матч).
  - `Models/`
    - `Player.php` — модель игрока.
    - `QueueTicket.php` — модель тикета очереди.
  - `Services/`
    - `MatchmakingService.php` — основной алгоритм подбора матча.

- `tests/`
  - `api_test.php` — интеграционный тест API (регистрация, очередь, матч).

- `docker-compose.yml` — описание сервисов (app, db, phpmyadmin).
- `Dockerfile` — образ PHP + Apache.

---

## 3. База данных

В базе `matchmaking` используются таблицы:

- **players**
  - `id` (PK, AUTO_INCREMENT)
  - `username` (UNIQUE)
  - `password_hash`
  - `mmr` (INT, по умолчанию 1000)
  - `region` (например, `eu`, `na`, `asia`)
  - `created_at`

- **queue_tickets**
  - `id` (PK)
  - `player_id` (FK → players.id)
  - `game_mode` (например, `5v5`)
  - `status` (`in_queue`, `matched`, `cancelled`)
  - `created_at`

- **matches**
  - `id` (PK)
  - `game_mode`
  - `avg_mmr` — средний рейтинг всех игроков матча
  - `status` (`active`, `finished`)
  - `created_at`

- **match_players**
  - `id` (PK)
  - `match_id` (FK → matches.id)
  - `player_id` (FK → players.id)
  - `team` — номер команды (1 или 2)
  - `result` — `win` / `lose` / `draw` (на будущее)

---

## 4. Алгоритм матчмейкинга

Алгоритм реализован в классе `MatchmakingService`:

1. Берём из `queue_tickets` до **10 игроков** со статусом `in_queue` для нужного режима (`5v5`).
2. По их `player_id` забираем MMR из таблицы `players`.
3. Сортируем игроков по MMR от сильных к слабым.
4. Разбрасываем по двум командам (**Team 1** и **Team 2**) так, чтобы:
   - в каждой команде было по 5 игроков;
   - суммарный MMR команд был как можно ближе (баланс).
5. Создаём запись в таблице `matches` (средний MMR матча).
6. В таблицу `match_players` записываем всех игроков матча с полем `team = 1` или `2`.
7. Все использованные `queue_tickets` переводим в статус `matched`.

Таким образом, матч подбирается **с учётом рейтинга игроков**, а не просто “первые 10 из очереди”.

---

## 5. Запуск проекта через Docker

### Требования

- Установлены **Docker** и **docker compose**.

### Шаги

1. Клонировать репозиторий:

   ```bash
   git clone https://github.com/ТВОЙ_ЛОГИН/matchmaking-php.git
   cd matchmaking-php
