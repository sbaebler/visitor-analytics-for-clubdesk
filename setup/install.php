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

    echo '<p style="color:green;font-family:monospace">✅ Tabellen erfolgreich erstellt (inkl. Social Widget).</p>';
    echo '<p style="font-family:monospace">⚠️ Lösche oder schütze jetzt diese Datei: <code>setup/install.php</code></p>';
} catch (PDOException $e) {
    echo '<p style="color:red;font-family:monospace">Fehler: ' . htmlspecialchars($e->getMessage()) . '</p>';
}

