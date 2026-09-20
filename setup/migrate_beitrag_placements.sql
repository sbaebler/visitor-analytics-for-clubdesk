-- ============================================================
-- Migration: Beitrags-Platzierung (welcher Beitrag steht wo auf der Website)
--
-- Für BESTEHENDE Installationen. Neue Installationen bekommen die Tabelle
-- bereits über setup/schema.sql.
--
-- Rein additiv: legt eine neue Tabelle an, ändert und löscht nichts.
-- Gefüllt wird sie von cron/check_placements.php – bis der Cron das erste Mal
-- läuft, zeigt das Dashboard alle Beiträge als "nicht zugeordnet".
--
-- Ausführen in phpMyAdmin oder via Terminal:
--   mysql -u USER -p DBNAME < setup/migrate_beitrag_placements.sql
--
-- Rückgängig:
--   DROP TABLE beitrag_placements;
-- ============================================================

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
