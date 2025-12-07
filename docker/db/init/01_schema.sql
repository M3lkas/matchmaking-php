USE matchmaking;

CREATE TABLE IF NOT EXISTS players (
  id INT AUTO_INCREMENT PRIMARY KEY,
  username VARCHAR(64) NOT NULL UNIQUE,
  password_hash VARCHAR(255) NOT NULL,
  mmr INT NOT NULL DEFAULT 1000,
  region VARCHAR(32) NOT NULL DEFAULT 'eu',
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS queue_tickets (
  id INT AUTO_INCREMENT PRIMARY KEY,
  player_id INT NOT NULL,
  game_mode VARCHAR(32) NOT NULL,
  status ENUM('in_queue','matched','cancelled') DEFAULT 'in_queue',
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_queue_player
    FOREIGN KEY (player_id) REFERENCES players(id)
    ON DELETE CASCADE
);

CREATE TABLE IF NOT EXISTS matches (
  id INT AUTO_INCREMENT PRIMARY KEY,
  game_mode VARCHAR(32) NOT NULL,
  avg_mmr INT,
  status ENUM('active','finished') DEFAULT 'active',
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS match_players (
  id INT AUTO_INCREMENT PRIMARY KEY,
  match_id INT NOT NULL,
  player_id INT NOT NULL,
  team CHAR(1),
  result ENUM('win','lose','draw') NULL,
  CONSTRAINT fk_matchplayer_match
    FOREIGN KEY (match_id) REFERENCES matches(id)
    ON DELETE CASCADE,
  CONSTRAINT fk_matchplayer_player
    FOREIGN KEY (player_id) REFERENCES players(id)
    ON DELETE CASCADE
);
