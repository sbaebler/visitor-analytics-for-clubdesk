-- ============================================================
-- Migration: Zählende Weiterleitungs-Links (/go/<slug>)
-- Für bestehende Installationen. Neue Installationen bekommen
-- die Tabellen über setup/schema.sql bzw. setup/install.php.
--
-- Ausführen in phpMyAdmin (Datenbank auswählen → Import) oder:
--   mysql -u USER -p DBNAME < setup/migrate_links.sql
--
-- Spec: docs/links.md
-- ============================================================

-- Ein Eintrag pro Weiterleitungs-Link
CREATE TABLE IF NOT EXISTS short_links (
    id          INT UNSIGNED  AUTO_INCREMENT PRIMARY KEY,
    slug        VARCHAR(64)   NOT NULL COMMENT 'Teil nach /go/, nur a-z 0-9 und Bindestrich',
    target_url  VARCHAR(2048) NOT NULL COMMENT 'Ziel der Weiterleitung, z.B. Clubdesk-fileservlet-Link',
    label       VARCHAR(255)  NOT NULL DEFAULT '' COMMENT 'Bezeichnung für das Dashboard',
    is_active   TINYINT(1) UNSIGNED NOT NULL DEFAULT 1 COMMENT '0 = Link liefert 404, Klick-Historie bleibt',
    created_at  TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at  TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_slug (slug)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Ein Eintrag pro Aufruf. Bots/Link-Vorschauen werden mitgeschrieben
-- (is_bot = 1), aber in allen Zählern ausgeblendet.
CREATE TABLE IF NOT EXISTS short_link_clicks (
    id            BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    link_id       INT UNSIGNED  NOT NULL,
    ip_hash       CHAR(64)      NOT NULL COMMENT 'SHA256(salt|IP) – keine Roh-IP gespeichert',
    referrer_host VARCHAR(253)  NULL COMMENT 'Nur der Host des Referrers, kein Pfad',
    device_type   ENUM('desktop','mobile','tablet') NOT NULL DEFAULT 'desktop',
    is_bot        TINYINT(1) UNSIGNED NOT NULL DEFAULT 0 COMMENT '1 = Crawler oder Link-Vorschau (WhatsApp, iMessage, Outlook ...)',
    clicked_at    TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_link_time (link_id, clicked_at),
    INDEX idx_clicked_at (clicked_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
