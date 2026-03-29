-- Migration: Tabelle uptime_checks erstellen
-- Ausführen via phpMyAdmin in der Datenbank vabavoco_stats

CREATE TABLE IF NOT EXISTS uptime_checks (
  id               BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  check_time       DATETIME NOT NULL,
  target_name      VARCHAR(64) NOT NULL,
  target_url       VARCHAR(2048) NOT NULL,
  request_method   VARCHAR(4) NOT NULL DEFAULT 'HEAD',
  http_status      SMALLINT UNSIGNED NULL,
  response_time_ms INT UNSIGNED NULL,
  success          TINYINT(1) UNSIGNED NOT NULL DEFAULT 0,
  error_type       VARCHAR(32) NULL,
  error_message    VARCHAR(512) NULL,
  redirect_count   TINYINT UNSIGNED NULL DEFAULT 0,
  final_url        VARCHAR(2048) NULL,
  resolved_ip      VARCHAR(45) NULL,
  created_at       TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_check_time  (check_time),
  INDEX idx_target_name (target_name),
  INDEX idx_success     (success)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
