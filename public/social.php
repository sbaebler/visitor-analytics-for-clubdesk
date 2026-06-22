<?php
declare(strict_types=1);

/**
 * Social API – öffentlicher Endpunkt für das Widget.
 *
 * Endpunkte:
 *   GET  ?action=stats&url=...   → Zähler lesen (views + likes + user_liked)
 *   POST ?action=like  body: url=...  → Like togglen
 *
 * Kein Login nötig. CORS auf allowed_origins beschränkt.
 * Nutzt Social::class und Database::class aus src/.
 */

require_once __DIR__ . '/../src/Database.php';
require_once __DIR__ . '/../src/Social.php';

$config = require __DIR__ . '/../config/config.php';

// --- X-Frame-Options überschreiben: widget.php darf eingebettet werden ---
// (Das globale .htaccess setzt DENY; hier überschreiben wir nur für diesen Endpunkt)
header_remove('X-Frame-Options');
header('X-Frame-Options: ALLOWALL');

// --- CORS ---
Social::setCorsHeaders($config);

// --- Input ---
$action = $_GET['action'] ?? '';
$url    = match ($_SERVER['REQUEST_METHOD']) {
    'GET'  => $_GET['url']  ?? '',
    'POST' => $_POST['url'] ?? '',
    default => '',
};

if (empty($url) || !filter_var($url, FILTER_VALIDATE_URL)) {
    Social::jsonResponse(['error' => 'Ungültige oder fehlende URL'], 400);
}

$salt    = $config['salt'] ?? '';
$urlHash = Social::hashUrl($url);
$ipHash  = Social::hashIp($salt);

// --- Routing ---
try {
    $pdo = Database::get();

    switch ($action) {

        case 'stats':
            $stats = Social::getStats($pdo, $urlHash, $ipHash);
            Social::jsonResponse($stats);

        case 'like':
            if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
                Social::jsonResponse(['error' => 'POST required'], 405);
            }
            [$normalizedUrl] = Social::normalizePageUrl($url);
            $liked = Social::toggleLike($pdo, $urlHash, $normalizedUrl, $ipHash);
            $stats = Social::getStats($pdo, $urlHash, $ipHash);
            Social::jsonResponse([
                'liked'     => $liked,
                'likes'     => $stats['likes'],
                'likes_fmt' => $stats['likes_fmt'],
            ]);

        default:
            Social::jsonResponse(['error' => 'Unbekannte Aktion'], 400);
    }

} catch (PDOException $e) {
    // Kein Stack-Trace nach aussen
    Social::jsonResponse(['error' => 'Datenbankfehler'], 500);
} catch (\JsonException $e) {
    Social::jsonResponse(['error' => 'JSON-Fehler'], 500);
}
