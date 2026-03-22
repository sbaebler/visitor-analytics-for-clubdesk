<?php
// Temporäre Diagnosedatei – nach dem Test löschen!
ini_set('display_errors', 1);
error_reporting(E_ALL);

echo '<pre>';

// 1. Config laden
$config = require __DIR__ . '/../config/config.php';
echo "✅ Config geladen\n";

// 2. DB-Verbindung
try {
    require_once __DIR__ . '/../src/Database.php';
    $pdo = Database::get();
    echo "✅ Datenbankverbindung OK\n";

    // 3. Tabellen prüfen
    $tables = $pdo->query("SHOW TABLES")->fetchAll(PDO::FETCH_COLUMN);
    echo "✅ Tabellen: " . implode(', ', $tables) . "\n";
} catch (Exception $e) {
    echo "❌ Fehler: " . $e->getMessage() . "\n";
}

echo '</pre>';
