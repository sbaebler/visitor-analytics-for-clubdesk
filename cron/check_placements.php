<?php
declare(strict_types=1);

/**
 * Beitrags-Platzierung – läuft einmal täglich via Cronjob.
 *
 * Liest von der Website ab, in welchem Clubdesk-Block und auf welcher Seite ein
 * Beitrag eingebettet ist, und füllt damit `beitrag_placements`. Diese Angabe
 * steckt nicht in `pageviews`: normalizePageUrl() verwirft Basispfad und Block
 * bewusst (docs/url-normalization.md Regel 4).
 *
 * Nebenbei werden Publikationsdatum und Autor aus der Kachel übernommen – beides
 * gibt es sonst nirgends im System.
 *
 * Täglich genügt: Platzierungen ändern sich nur, wenn jemand die Website umbaut.
 *
 * Cron-Eintrag auf cyon.ch (cPanel):
 *   Minute 20, Stunde 4, Tag/Monat/Wochentag jeweils *   (täglich 04:20)
 *   Befehl: php -f /home/USER/public_html/stats/cron/check_placements.php
 *
 * Bei Erfolg gibt das Skript nichts aus – sonst würde cPanel jeden Lauf als
 * E-Mail verschicken. Für den Lauf von Hand `-v` anhängen, dann kommt eine
 * Zusammenfassung. Fehler und ein Lauf ganz ohne gefundene Kacheln gehen auf
 * STDERR und beenden mit Exitcode 1 – cPanel meldet das dann.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit;
}

require_once __DIR__ . '/../src/Database.php';
require_once __DIR__ . '/../src/Social.php';
require_once __DIR__ . '/../src/SitemapMonitor.php';
require_once __DIR__ . '/../src/PlacementMonitor.php';

$config = require __DIR__ . '/../config/config.php';
$cfg    = $config['beitrag_placements'] ?? [];

if (!($cfg['enabled'] ?? false)) {
    exit(0);
}

define('USER_AGENT', preg_replace('/\s+/', '', $config['site_name'] ?? 'Clubdesk-Analytics') . '-PlacementMonitor/1.0');

$runAt    = (new DateTimeImmutable('now', new DateTimeZone('Europe/Zurich')))->format('Y-m-d H:i:s');
$timeout  = (int) ($cfg['timeout'] ?? 10);
$delayMs  = (int) ($cfg['delay_ms'] ?? 200);
$maxPages = (int) ($cfg['max_pages'] ?? PlacementMonitor::DEFAULT_MAX_PAGES);
$startUrl = rtrim(trim((string) ($cfg['start_url'] ?? '')), '/');

if ($startUrl === '') {
    fwrite(STDERR, '[' . date('Y-m-d H:i:s') . "] Platzierungs-Monitor Fehler: start_url ist nicht konfiguriert.\n");
    exit(1);
}

try {
    $pdo = Database::get();

    // Die Website hat keine Sitemap – die Seitenliste kommt aus der Navigation
    // der Startseite, die in jedem Roh-HTML vollständig enthalten ist.
    $rootFetch = SitemapMonitor::fetchPage($startUrl . '/', $timeout, USER_AGENT);
    if ($rootFetch['html'] === null) {
        fwrite(STDERR, '[' . date('Y-m-d H:i:s') . "] Platzierungs-Monitor Fehler: Startseite {$startUrl}/ nicht abrufbar ({$rootFetch['error']}).\n");
        exit(1);
    }

    $pages = PlacementMonitor::discoverPages($rootFetch['html'], $startUrl);
    if (count($pages) > $maxPages) {
        $pages = array_slice($pages, 0, $maxPages);
        fwrite(STDERR, '[' . date('Y-m-d H:i:s') . "] Platzierungs-Monitor Warnung: max_pages={$maxPages} erreicht, Liste gekürzt.\n");
    }

    $placements = 0;
    $seenLists  = [];

    foreach ($pages as $pageUrl) {
        // Die Startseite liegt bereits vor – kein zweiter Abruf.
        $html = $pageUrl === '/'
            ? $rootFetch['html']
            : (SitemapMonitor::fetchPage($startUrl . $pageUrl, $timeout, USER_AGENT)['html'] ?? null);

        if ($pageUrl !== '/' && $delayMs > 0) {
            usleep($delayMs * 1000);
        }
        if ($html === null) {
            continue;
        }

        foreach (PlacementMonitor::extractTiles($html) as $tile) {
            PlacementMonitor::record(
                $pdo,
                $tile,
                $pageUrl,
                PlacementMonitor::labelForBlock($html, $tile['block_id']),
                $runAt
            );
            $placements++;
        }

        // Vollisten ("Weitere Einträge") liefern den ganzen Block statt nur der
        // neuesten Einträge – ohne sie fehlen ältere Beiträge komplett.
        if (!($cfg['follow_lists'] ?? true)) {
            continue;
        }

        foreach (PlacementMonitor::extractListLinks($html, $startUrl) as $listUrl) {
            if (isset($seenLists[$listUrl])) continue;
            $seenLists[$listUrl] = true;

            $listHtml = SitemapMonitor::fetchPage($listUrl, $timeout, USER_AGENT)['html'] ?? null;
            if ($delayMs > 0) usleep($delayMs * 1000);
            if ($listHtml === null) continue;

            foreach (PlacementMonitor::extractTiles($listHtml) as $tile) {
                PlacementMonitor::record(
                    $pdo,
                    $tile,
                    $pageUrl,   // die Trägerseite, nicht die Listen-URL
                    PlacementMonitor::labelForBlock($html, $tile['block_id']),
                    $runAt
                );
                $placements++;
            }
        }
    }

    // Altdaten-Rückführung: bis zum Referrer-Fix in tracker.js meldeten
    // client-seitig geöffnete Beiträge ihre eigene rohe URL als Referrer –
    // inklusive Trägerpfad und Block. Für Beiträge, die aus allen Listen
    // gefallen sind, ist das die einzige erhaltene Quelle.
    $backfilled = PlacementMonitor::backfillFromReferrer($pdo, $runAt);

    // Bei Erfolg still – wie check_uptime.php und check_changes.php. Jede Ausgabe
    // würde cPanel täglich als E-Mail verschicken. Mit -v für den Lauf von Hand.
    if (in_array('-v', $argv ?? [], true)) {
        fwrite(STDOUT, '[' . date('Y-m-d H:i:s') . "] Platzierungs-Monitor: {$placements} Platzierungen aus "
            . count($pages) . " Seiten, {$backfilled} aus Altdaten rekonstruiert.\n");
    }

    // Null Platzierungen heisst: Website umgebaut, Abruf blockiert oder das
    // Kachel-Markup hat sich geändert. Das ist meldenswert, sonst veraltet die
    // Zuordnung still vor sich hin.
    if ($placements === 0) {
        fwrite(STDERR, '[' . date('Y-m-d H:i:s') . '] Platzierungs-Monitor Warnung: keine Beitragskacheln gefunden ('
            . count($pages) . " Seiten geprüft). Markup oder Erreichbarkeit prüfen.\n");
        exit(1);
    }
} catch (Throwable $e) {
    fwrite(STDERR, '[' . date('Y-m-d H:i:s') . '] Platzierungs-Monitor Fehler: ' . $e->getMessage() . PHP_EOL);
    exit(1);
}
