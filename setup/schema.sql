-- ============================================================
-- Clubdesk Analytics – komplettes Datenbank-Schema
--
-- Standard-Setup für neue Installationen. Erstellt alle Tabellen
-- in einer bereits angelegten Datenbank (siehe README Schritt 2).
--
-- Import:
--   phpMyAdmin → Datenbank auswählen → "Importieren" → diese Datei
--   oder Terminal:  mysql -u USER -p DBNAME < setup/schema.sql
--
-- Alle CREATE TABLE sind idempotent (IF NOT EXISTS) und dürfen
-- gefahrlos erneut importiert werden.
-- ============================================================

-- Rohdaten Tracker: jede Seitenansicht
CREATE TABLE IF NOT EXISTS pageviews (
    id          BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    view_id     VARCHAR(36)  NOT NULL,
    fingerprint VARCHAR(64)  NOT NULL,
    url         VARCHAR(2048) NOT NULL,
    host        VARCHAR(253) DEFAULT NULL,
    page_title  VARCHAR(512),
    referrer    VARCHAR(2048),
    device_type ENUM('desktop','mobile','tablet') NOT NULL DEFAULT 'desktop',
    screen_width SMALLINT UNSIGNED,
    lang        VARCHAR(32),
    country     VARCHAR(2) DEFAULT NULL,
    is_cms      TINYINT(1) UNSIGNED NOT NULL DEFAULT 0,
    duration    INT UNSIGNED DEFAULT NULL,
    created_at  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_view_id     (view_id),
    INDEX idx_fingerprint (fingerprint),
    INDEX idx_created_at  (created_at),
    INDEX idx_url         (url(255)),
    INDEX idx_host        (host),
    INDEX idx_country     (country),
    INDEX idx_is_cms      (is_cms)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Outbound-Link-Klicks und weitere Events
CREATE TABLE IF NOT EXISTS events (
    id          BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    fingerprint VARCHAR(64)  NOT NULL,
    event_type  VARCHAR(64)  NOT NULL,
    event_value VARCHAR(2048),
    url         VARCHAR(2048),
    created_at  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_event_type (event_type),
    INDEX idx_created_at (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Social Widget – Rohdaten: Wer hat welche Seite geliked
CREATE TABLE IF NOT EXISTS social_likes (
    url_hash   CHAR(64)      NOT NULL COMMENT 'SHA256 der normalisierten URL',
    url        VARCHAR(2048) NOT NULL COMMENT 'Normalisierte URL (für Debugging)',
    ip_hash    CHAR(64)      NOT NULL COMMENT 'SHA256(salt|IP) – keine Roh-IP gespeichert',
    liked_at   TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (url_hash, ip_hash),
    INDEX idx_url_hash (url_hash)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Social Widget – Aggregat-Cache: Like-Zähler pro Seite
CREATE TABLE IF NOT EXISTS social_stats (
    url_hash   CHAR(64)      NOT NULL PRIMARY KEY,
    url        VARCHAR(2048) NOT NULL,
    like_count BIGINT        NOT NULL DEFAULT 0,
    updated_at TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Uptime-Monitor – Resultate der Cron-Checks
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
