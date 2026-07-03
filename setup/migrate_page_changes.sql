-- ============================================================
-- Migration: Content-Change-Detection Tabellen
-- Für bestehende Installationen (install.php/schema.sql wurden
-- bereits ausgeführt und enthalten diese Tabellen noch nicht).
--
-- Ausführen in phpMyAdmin oder via Terminal:
--   mysql -u USER -p DBNAME < setup/migrate_page_changes.sql
-- ============================================================

-- Aktueller Zustand pro überwachter Seite (Vergleichs-Baseline)
CREATE TABLE IF NOT EXISTS tracked_pages (
    id                  BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    url                 VARCHAR(2048) NOT NULL COMMENT 'Normalisierte URL, siehe docs/url-normalization.md',
    url_hash            CHAR(64)      NOT NULL COMMENT 'SHA256 der normalisierten URL',
    content_hash        CHAR(64)      NULL COMMENT 'SHA256 des bereinigten Seiteninhalts (letzter bekannter Stand)',
    content_text        MEDIUMTEXT    NULL COMMENT 'Bereinigter Text, letzter bekannter Stand (für Diff-Ausschnitt)',
    last_checked_at     DATETIME      NULL,
    last_changed_at     DATETIME      NULL,
    last_http_status    SMALLINT UNSIGNED NULL,
    consecutive_errors  TINYINT UNSIGNED NOT NULL DEFAULT 0,
    in_sitemap          TINYINT(1) UNSIGNED NOT NULL DEFAULT 1
                         COMMENT '0 = aktuell nicht mehr in Sitemap/Fallback-Liste; Historie bleibt erhalten',
    created_at          TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at          TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_url_hash (url_hash),
    INDEX idx_last_changed_at (last_changed_at),
    INDEX idx_in_sitemap (in_sitemap)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Log jeder tatsächlich erkannten Änderung
CREATE TABLE IF NOT EXISTS page_changes (
    id             BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    url            VARCHAR(2048) NOT NULL COMMENT 'Normalisierte URL, identisch zu pageviews.url für spätere JOINs',
    url_hash       CHAR(64)      NOT NULL,
    previous_hash  CHAR(64)      NOT NULL,
    new_hash       CHAR(64)      NOT NULL,
    lines_added    SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    lines_removed  SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    change_size    INT UNSIGNED  NOT NULL DEFAULT 0 COMMENT 'Zeichen-Differenz zwischen altem und neuem bereinigtem Text',
    diff_excerpt   TEXT          NULL COMMENT 'Zeilen-Diff-Ausschnitt (+/- Präfix je Zeile), auf ca. 4000 Zeichen gekappt',
    detected_at    DATETIME      NOT NULL,
    notified_at    DATETIME      NULL COMMENT 'Anschlussstelle für künftiges Notification-Feature: NULL = noch nicht benachrichtigt',
    created_at     TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_url_hash (url_hash),
    INDEX idx_detected_at (detected_at),
    INDEX idx_notified_at (notified_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
