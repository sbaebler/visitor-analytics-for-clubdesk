<?php
declare(strict_types=1);

/**
 * Content-Change-Detection – läuft alle 30 Minuten via Cronjob.
 *
 * Prüft alle Seiten gemäss Sitemap (+ optionaler Fallback-Liste) auf
 * inhaltliche Änderungen und protokolliert Funde in `page_changes`.
 * Grundlage für spätere Notification-E-Mails/Analysen – siehe
 * docs/content-change-detection.md.
 *
 * Cron-Eintrag auf cyon.ch (cPanel):
 *   Intervall: * /30 * * * *  (alle 30 Minuten, Leerzeichen vor /30 entfernen)
 *   Befehl:    /usr/local/bin/php /home/USER/public_html/stats/cron/check_changes.php
 *
 * PHP-Pfad prüfen via cPanel → Terminal: which php
 * Log-Ausgabe optional: >> /home/USER/logs/check_changes.log 2>&1
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit;
}

require_once __DIR__ . '/../src/Database.php';
require_once __DIR__ . '/../src/Social.php';
require_once __DIR__ . '/../src/SitemapMonitor.php';

$config = require __DIR__ . '/../config/config.php';
$cfg    = $config['sitemap_monitor'] ?? [];

if (!($cfg['enabled'] ?? false)) {
    exit(0);
}

define('USER_AGENT', preg_replace('/\s+/', '', $config['site_name'] ?? 'Clubdesk-Analytics') . '-ChangeMonitor/1.0');

$checkTime = (new DateTimeImmutable('now', new DateTimeZone('Europe/Zurich')))->format('Y-m-d H:i:s');
$timeout   = (int) ($cfg['timeout'] ?? 10);

try {
    $pdo = Database::get();

    $sitemapUrl  = trim((string) ($cfg['sitemap_url'] ?? ''));
    $sitemapUrls = $sitemapUrl !== ''
        ? SitemapMonitor::fetchSitemapUrls($sitemapUrl, $timeout, USER_AGENT)
        : [];

    if ($sitemapUrl !== '' && $sitemapUrls === []) {
        fwrite(STDERR, '[' . date('Y-m-d H:i:s') . "] Content-Change-Monitor Warnung: Sitemap {$sitemapUrl} lieferte keine URLs (Abruf fehlgeschlagen oder leer).\n");
    }

    $urls = SitemapMonitor::buildUrlList($sitemapUrls, $cfg['fallback_urls'] ?? [], $cfg);

    $seenHashes = [];
    foreach ($urls as [$rawUrl, $normalizedUrl, $urlHash]) {
        $fetch = SitemapMonitor::fetchPage($rawUrl, $timeout, USER_AGENT);
        SitemapMonitor::recordResult($pdo, $checkTime, $normalizedUrl, $urlHash, $fetch);
        $seenHashes[] = $urlHash;

        $delayMs = (int) ($cfg['delay_ms'] ?? 0);
        if ($delayMs > 0) {
            usleep($delayMs * 1000);
        }
    }
    SitemapMonitor::markMissingFromSitemap($pdo, $seenHashes);

    // Tägliche Bereinigung: Einträge älter als retention_days löschen
    // (läuft nachts zwischen 04:00–04:59, zeitversetzt zum Uptime-Cleanup um 03:00)
    if ((int) date('H') === 4) {
        $days = max(1, (int) ($cfg['retention_days'] ?? 180));
        $pdo->exec("DELETE FROM page_changes WHERE detected_at < NOW() - INTERVAL {$days} DAY");
    }
} catch (Throwable $e) {
    fwrite(STDERR, '[' . date('Y-m-d H:i:s') . '] Content-Change-Monitor Fehler: ' . $e->getMessage() . PHP_EOL);
    exit(1);
}
