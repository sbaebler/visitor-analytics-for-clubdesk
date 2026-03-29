<?php
/**
 * Einmalig ausführen, um die Datenbanktabellen zu erstellen.
 * Danach diese Datei löschen oder mit Passwort schützen.
 *
 * Aufruf: https://stats.YOUR-DOMAIN.COM/setup/install.php?token=DEIN_TOKEN
 * (Token muss mit dem in config.php gesetzten INSTALL_TOKEN übereinstimmen)
 */

$configFile = __DIR__ . '/../config/config.php';
if (!file_exists($configFile)) {
    die('config/config.php fehlt. Erstelle sie aus config/config.sample.php.');
}
$config = require $configFile;

// Einfacher Schutz: Token in der URL
$expectedToken = $config['install_token'] ?? null;
if (!$expectedToken || ($_GET['token'] ?? '') !== $expectedToken) {
    http_response_code(403);
    die('Zugriff verweigert. Token fehlt oder falsch.');
}

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

    echo '<p style="color:green;font-family:monospace">✅ Tabellen erfolgreich erstellt.</p>';
    echo '<p style="font-family:monospace">⚠️ Lösche oder schütze jetzt diese Datei: <code>setup/install.php</code></p>';
} catch (PDOException $e) {
    echo '<p style="color:red;font-family:monospace">Fehler: ' . htmlspecialchars($e->getMessage()) . '</p>';
}
