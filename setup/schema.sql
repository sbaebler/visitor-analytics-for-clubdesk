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

-- Content-Change-Detection: aktueller Zustand pro überwachter Seite
-- (Vergleichs-Baseline für den nächsten Lauf; content_text hält den zuletzt
-- bereinigten Text, um beim nächsten Fund einen Diff-Ausschnitt zu bilden)
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

-- Content-Change-Detection: Log jeder tatsächlich erkannten Änderung
-- (Grundlage für spätere Notification-Mails und Traffic-Korrelations-Analysen)
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
-- Beitrags-Platzierung – wo auf der Website ein Beitrag eingebettet ist.
-- Gefüllt von cron/check_placements.php (src/PlacementMonitor.php), täglich.
-- Spec: docs/beitrags-analyse.md
CREATE TABLE IF NOT EXISTS beitrag_placements (
    id            BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    c_key         VARCHAR(32)   NOT NULL COMMENT 'Clubdesk-Schlüssel, z.B. ND1000043 – Join: pageviews.url = CONCAT(''/beitrag/'', c_key)',
    block_id      VARCHAR(32)   NOT NULL COMMENT 'Clubdesk-Block (Parameter b) – die eigentliche Kategorie',
    page_url      VARCHAR(2048) NOT NULL COMMENT 'Normalisierte Trägerseite, Format identisch zu pageviews.url',
    block_label   VARCHAR(255)      NULL COMMENT 'Automatisch aus der nächsten vorangehenden Überschrift',
    published_at  DATE              NULL COMMENT 'Aus <time> der Kachel – echtes Publikationsdatum',
    author        VARCHAR(128)      NULL,
    source        ENUM('scrape','referrer') NOT NULL DEFAULT 'scrape' COMMENT 'scrape = von der Website gelesen; referrer = aus Altdaten rekonstruiert',
    first_seen_at DATETIME      NOT NULL,
    last_seen_at  DATETIME      NOT NULL,
    UNIQUE KEY uniq_placement (c_key, block_id, page_url(180)),
    INDEX idx_c_key (c_key),
    INDEX idx_block (block_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
