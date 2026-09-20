<?php
/**
 * Optionaler Helfer: legt die Datenbank an und erstellt alle Tabellen.
 *
 * Standard-Weg für neue Installationen ist der Import von
 * `setup/schema.sql` via phpMyAdmin (siehe README Schritt 4).
 *
 * Dieses Skript ist eine SSH/CLI-Alternative, die zusätzlich die DB anlegt:
 *   php setup/install.php
 *
 * Läuft nur via CLI – ein Web-Aufruf wird abgewiesen (setup/ ist ohnehin
 * per .htaccess gesperrt und liegt ausserhalb des Document Roots).
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    die('Nur via CLI ausführbar: php setup/install.php');
}

$configFile = __DIR__ . '/../config/config.php';
if (!file_exists($configFile)) {
    die('config/config.php fehlt. Erstelle sie aus config/config.sample.php.');
}
$config = require $configFile;

try {
    $dsn = sprintf(
        'mysql:host=%s;charset=%s',
        $config['db']['host'],
        $config['db']['charset']
    );
    $pdo = new PDO($dsn, $config['db']['user'], $config['db']['pass'], [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    ]);

    $dbName = $config['db']['name'];
    $pdo->exec("CREATE DATABASE IF NOT EXISTS `{$dbName}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
    $pdo->exec("USE `{$dbName}`");

    $pdo->exec("
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
            INDEX idx_view_id    (view_id),
            INDEX idx_fingerprint (fingerprint),
            INDEX idx_created_at (created_at),
            INDEX idx_url        (url(255)),
            INDEX idx_host       (host),
            INDEX idx_country    (country),
            INDEX idx_is_cms     (is_cms)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS events (
            id          BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            fingerprint VARCHAR(64)  NOT NULL,
            event_type  VARCHAR(64)  NOT NULL,
            event_value VARCHAR(2048),
            url         VARCHAR(2048),
            created_at  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_event_type (event_type),
            INDEX idx_created_at (created_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");


    $pdo->exec("
        CREATE TABLE IF NOT EXISTS social_likes (
            url_hash   CHAR(64)      NOT NULL COMMENT 'SHA256 der normalisierten URL',
            url        VARCHAR(2048) NOT NULL,
            ip_hash    CHAR(64)      NOT NULL COMMENT 'SHA256(salt|IP)',
            liked_at   TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (url_hash, ip_hash),
            INDEX idx_url_hash (url_hash)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS social_stats (
            url_hash   CHAR(64)      NOT NULL PRIMARY KEY,
            url        VARCHAR(2048) NOT NULL,
            like_count BIGINT        NOT NULL DEFAULT 0,
            updated_at TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");

    $pdo->exec("
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
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");

    $pdo->exec("
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
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");

    $pdo->exec("
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
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");

    // Beitrags-Platzierung – wo auf der Website ein Beitrag eingebettet ist.
    // Gefüllt von cron/check_placements.php. Spec: docs/beitrags-analyse.md
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS beitrag_placements (
            id            BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            c_key         VARCHAR(32)   NOT NULL COMMENT 'Clubdesk-Schlüssel, z.B. ND1000043',
            block_id      VARCHAR(32)   NOT NULL COMMENT 'Clubdesk-Block (Parameter b) – die Kategorie',
            page_url      VARCHAR(2048) NOT NULL COMMENT 'Normalisierte Trägerseite, Format wie pageviews.url',
            block_label   VARCHAR(255)      NULL,
            published_at  DATE              NULL COMMENT 'Aus <time> der Kachel',
            author        VARCHAR(128)      NULL,
            source        ENUM('scrape','referrer') NOT NULL DEFAULT 'scrape',
            first_seen_at DATETIME      NOT NULL,
            last_seen_at  DATETIME      NOT NULL,
            UNIQUE KEY uniq_placement (c_key, block_id, page_url(180)),
            INDEX idx_c_key (c_key),
            INDEX idx_block (block_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");

    echo '<p style="color:green;font-family:monospace">✅ Tabellen erfolgreich erstellt (inkl. Social Widget, Uptime-Monitor, Content-Change-Detection, Beitrags-Platzierung).</p>';
    echo '<p style="font-family:monospace">⚠️ Lösche oder schütze jetzt diese Datei: <code>setup/install.php</code></p>';
} catch (PDOException $e) {
    echo '<p style="color:red;font-family:monospace">Fehler: ' . htmlspecialchars($e->getMessage()) . '</p>';
}

