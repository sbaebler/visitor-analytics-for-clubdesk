-- ============================================================
-- Migration: Social Widget Tabellen
-- Für bestehende Installationen (install.php wurde bereits
-- ausgeführt und enthält diese Tabellen noch nicht).
--
-- Ausführen in phpMyAdmin oder via Terminal:
--   mysql -u USER -p DBNAME < setup/migrate_social.sql
-- ============================================================

-- Rohdata: Wer hat welche Seite geliked
CREATE TABLE IF NOT EXISTS social_likes (
    url_hash   CHAR(64)      NOT NULL COMMENT 'SHA256 der normalisierten URL',
    url        VARCHAR(2048) NOT NULL COMMENT 'Normalisierte URL (für Debugging)',
    ip_hash    CHAR(64)      NOT NULL COMMENT 'SHA256(salt|IP) – keine Roh-IP gespeichert',
    liked_at   TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (url_hash, ip_hash),
    INDEX idx_url_hash (url_hash)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Aggregat-Cache: Like-Zähler pro Seite (kein COUNT(*) bei jedem Request)
CREATE TABLE IF NOT EXISTS social_stats (
    url_hash   CHAR(64)      NOT NULL PRIMARY KEY,
    url        VARCHAR(2048) NOT NULL,
    like_count BIGINT        NOT NULL DEFAULT 0,
    updated_at TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
