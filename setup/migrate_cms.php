<?php
/**
 * Migration: Fügt die Spalte `is_cms` (TINYINT 1) zur pageviews-Tabelle hinzu.
 * Einmalig ausführen, danach kann diese Datei gelöscht werden.
 *
 * Aufruf: https://stats.YOUR-DOMAIN.COM/setup/migrate_cms.php?token=DEIN_TOKEN
 */

$configFile = __DIR__ . '/../config/config.php';
if (!file_exists($configFile)) {
    die('config/config.php fehlt.');
}
$config = require $configFile;

$expectedToken = $config['install_token'] ?? null;
if (!$expectedToken || ($_GET['token'] ?? '') !== $expectedToken) {
    http_response_code(403);
    die('Zugriff verweigert. Token fehlt oder falsch.');
}

try {
    require_once __DIR__ . '/../src/Database.php';
    $pdo = Database::get();

    // Prüfen ob Spalte bereits existiert
    $stmt = $pdo->prepare("SHOW COLUMNS FROM pageviews LIKE 'is_cms'");
    $stmt->execute();
    if ($stmt->fetch()) {
        echo '<p style="font-family:monospace">ℹ️ Spalte <code>is_cms</code> existiert bereits – nichts zu tun.</p>';
        exit;
    }

    $pdo->exec("ALTER TABLE pageviews ADD COLUMN is_cms TINYINT(1) UNSIGNED NOT NULL DEFAULT 0 AFTER country");
    $pdo->exec("ALTER TABLE pageviews ADD INDEX idx_is_cms (is_cms)");

    echo '<p style="color:green;font-family:monospace">✅ Spalte <code>is_cms</code> erfolgreich hinzugefügt.</p>';
    echo '<p style="font-family:monospace">⚠️ Lösche jetzt diese Datei: <code>setup/migrate_cms.php</code></p>';
} catch (PDOException $e) {
    echo '<p style="color:red;font-family:monospace">Fehler: ' . htmlspecialchars($e->getMessage()) . '</p>';
}
