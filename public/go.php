<?php
declare(strict_types=1);

/**
 * Zählende Weiterleitung: /go/<slug>  (Rewrite in .htaccess)  bzw. /go.php?s=<slug>
 *
 * Zählt den Aufruf in short_link_clicks und leitet per 302 auf das Ziel weiter.
 * Kein Login, keine Cookies. HEAD-Requests werden weitergeleitet, aber nicht gezählt.
 * Spec: docs/links.md
 */

require_once __DIR__ . '/../src/Database.php';
require_once __DIR__ . '/../src/ShortLinks.php';

// Jeder Klick muss beim Server ankommen – sonst cacht der Browser die
// Weiterleitung und der zweite Klick wird nicht gezählt.
header('Cache-Control: no-store, no-cache, must-revalidate, private');
header('X-Robots-Tag: noindex, nofollow');

$slug = strtolower(trim((string) ($_GET['s'] ?? '')));

$notFound = static function (): never {
    http_response_code(404);
    header('Content-Type: text/html; charset=utf-8');
    echo '<!DOCTYPE html><html lang="de"><head><meta charset="UTF-8">'
       . '<meta name="viewport" content="width=device-width, initial-scale=1">'
       . '<title>Link nicht gefunden</title></head>'
       . '<body style="font-family:-apple-system,BlinkMacSystemFont,Segoe UI,Roboto,sans-serif;padding:2rem;color:#2D3748">'
       . '<h1 style="font-size:1.25rem">Link nicht gefunden</h1>'
       . '<p>Dieser Link existiert nicht oder ist nicht mehr aktiv.</p></body></html>';
    exit;
};

if (!ShortLinks::isValidSlug($slug)) {
    $notFound();
}

try {
    $config = require __DIR__ . '/../config/config.php';
    $pdo    = Database::get();
    $link   = ShortLinks::findActive($pdo, $slug);
} catch (Throwable $e) {
    http_response_code(503);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'Vorübergehend nicht erreichbar. Bitte später nochmals versuchen.';
    exit;
}

if ($link === null) {
    $notFound();
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'GET') {
    try {
        ShortLinks::recordClick($pdo, $link['id'], (string) ($config['salt'] ?? ''));
    } catch (Throwable $e) {
        // Zählen ist zweitrangig – die Weiterleitung muss trotzdem funktionieren.
    }
}

header('Location: ' . $link['target_url'], true, 302);
exit;
