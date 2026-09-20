<?php
declare(strict_types=1);

/**
 * SitemapMonitor – Content-Change-Detection für Sitemap-Seiten.
 *
 * Designprinzipien:
 * - Nutzt Social::normalizePageUrl() zur URL-Normalisierung (keine dritte
 *   Implementierung, siehe docs/url-normalization.md)
 * - Kein State ausserhalb der Klasse, nur statische Methoden
 * - Reines PHP (cURL, DOMDocument) – keine Composer-Abhängigkeiten
 * - Kanonische Spec: docs/content-change-detection.md
 */
class SitemapMonitor
{
    private const DEFAULT_SKIP_EXTENSIONS = [
        'pdf', 'jpg', 'jpeg', 'png', 'gif', 'svg', 'webp', 'ico',
        'zip', 'rar', '7z', 'doc', 'docx', 'xls', 'xlsx', 'ppt', 'pptx',
        'mp3', 'mp4', 'mov', 'avi', 'ics', 'css', 'js', 'xml', 'json',
    ];

    private const MAX_BODY_BYTES  = 5 * 1024 * 1024; // 5 MB
    private const DIFF_MAX_LINES  = 40;
    private const DIFF_MAX_CHARS  = 4000;

    // -------------------------------------------------------------------------
    // Sitemap laden
    // -------------------------------------------------------------------------

    /**
     * Lädt eine Sitemap (URL-Set oder Sitemap-Index, 1 Ebene tief rekursiv)
     * und gibt alle enthaltenen rohen <loc>-URLs zurück. Bei Fehlern: [].
     *
     * @return string[]
     */
    public static function fetchSitemapUrls(string $sitemapUrl, int $timeoutSec, string $userAgent): array
    {
        $xml = self::httpGet($sitemapUrl, $timeoutSec, $userAgent);
        if ($xml === null) {
            return [];
        }

        $locs = self::extractLocs($xml);
        if ($locs === null) {
            return [];
        }

        if ($locs['type'] === 'urlset') {
            return $locs['urls'];
        }

        // Sitemap-Index: jede Unter-Sitemap laden (eine Ebene tief)
        $all = [];
        foreach ($locs['urls'] as $subSitemapUrl) {
            $subXml = self::httpGet($subSitemapUrl, $timeoutSec, $userAgent);
            if ($subXml === null) {
                continue;
            }
            $subLocs = self::extractLocs($subXml);
            if ($subLocs !== null && $subLocs['type'] === 'urlset') {
                array_push($all, ...$subLocs['urls']);
            }
        }
        return $all;
    }

    /**
     * @return array{type: string, urls: string[]}|null
     */
    private static function extractLocs(string $xml): ?array
    {
        $prevErrors = libxml_use_internal_errors(true);
        $doc = new DOMDocument();
        $ok = $doc->loadXML($xml, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING);
        libxml_clear_errors();
        libxml_use_internal_errors($prevErrors);

        if (!$ok || $doc->documentElement === null) {
            return null;
        }

        $rootName = $doc->documentElement->localName;
        $urls = [];
        // getElementsByTagNameNS mit Wildcard-Namespace, da Sitemaps üblicherweise
        // einen Standard-Namespace (xmlns="http://www.sitemaps.org/schemas/sitemap/0.9")
        // deklarieren – getElementsByTagName('loc') ohne NS würde dann nichts finden.
        foreach ($doc->getElementsByTagNameNS('*', 'loc') as $locNode) {
            $loc = trim($locNode->textContent);
            if ($loc !== '') {
                $urls[] = $loc;
            }
        }

        if ($rootName === 'sitemapindex') {
            return ['type' => 'sitemapindex', 'urls' => $urls];
        }
        return ['type' => 'urlset', 'urls' => $urls];
    }

    /**
     * Einfacher cURL-GET für Sitemap-XML-Dateien (keine Content-Type-Prüfung,
     * da Server hierfür application/xml, text/xml oder auch text/plain senden).
     * Gibt bei Fehlern null zurück statt eine Exception zu werfen – die
     * Sitemap ist nur eine von zwei URL-Quellen (siehe fallback_urls).
     */
    private static function httpGet(string $url, int $timeoutSec, string $userAgent): ?string
    {
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL            => $url,
            CURLOPT_HTTPGET        => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => $timeoutSec,
            CURLOPT_CONNECTTIMEOUT => $timeoutSec,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS      => 5,
            CURLOPT_USERAGENT      => $userAgent,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_PROTOCOLS      => CURLPROTO_HTTP | CURLPROTO_HTTPS,
            CURLOPT_HEADER         => false,
        ]);

        // CURLOPT_WRITEFUNCTION überschreibt CURLOPT_RETURNTRANSFER (curl_exec()
        // liefert dann nur noch bool zurück) – Body wird daher im Callback gesammelt.
        $body = '';
        curl_setopt($ch, CURLOPT_WRITEFUNCTION, function ($ch, string $chunk) use (&$body): int {
            $body .= $chunk;
            return strlen($body) > self::MAX_BODY_BYTES ? 0 : strlen($chunk);
        });

        $success    = curl_exec($ch);
        $httpStatus = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlErrno  = curl_errno($ch);
        // Kein curl_close(): seit PHP 8.0 wirkungslos, seit 8.5 deprecated.

        if ($curlErrno !== 0 || $success === false || $httpStatus < 200 || $httpStatus >= 300) {
            return null;
        }
        return $body;
    }

    // -------------------------------------------------------------------------
    // URL-Liste aufbauen (Sitemap + Fallback, normalisiert, gefiltert)
    // -------------------------------------------------------------------------

    /**
     * Führt Sitemap- und Fallback-URLs zusammen, normalisiert sie via
     * Social::normalizePageUrl(), dedupliziert per url_hash, wendet
     * Datei-Endungs- und Regex-Ausschlüsse an und kappt auf max_pages.
     *
     * @param string[] $sitemapUrls
     * @param string[] $fallbackUrls
     * @param array{exclude_patterns?: string[], max_pages?: int} $cfg
     * @return array<int, array{0: string, 1: string, 2: string}> Liste von [rawUrl, normalizedUrl, urlHash]
     */
    public static function buildUrlList(array $sitemapUrls, array $fallbackUrls, array $cfg): array
    {
        $excludePatterns = $cfg['exclude_patterns'] ?? [];
        $maxPages        = (int) ($cfg['max_pages'] ?? 300);

        $seen   = [];
        $result = [];

        foreach ([...$sitemapUrls, ...$fallbackUrls] as $rawUrl) {
            if (count($result) >= $maxPages) {
                break;
            }

            [$normalized, $host] = Social::normalizePageUrl($rawUrl);
            if ($host === '' || $normalized === '') {
                continue;
            }

            $ext = strtolower((string) pathinfo(parse_url($normalized, PHP_URL_PATH) ?: '', PATHINFO_EXTENSION));
            if ($ext !== '' && in_array($ext, self::DEFAULT_SKIP_EXTENSIONS, true)) {
                continue;
            }

            $excluded = false;
            foreach ($excludePatterns as $pattern) {
                if (@preg_match($pattern, $normalized) === 1) {
                    $excluded = true;
                    break;
                }
            }
            if ($excluded) {
                continue;
            }

            $urlHash = hash('sha256', strtolower($normalized));
            if (isset($seen[$urlHash])) {
                continue;
            }
            $seen[$urlHash] = true;

            // $rawUrl wird für den HTTP-Abruf benötigt (absolute URL), $normalized
            // ist die gespeicherte Form – identisch zum Format von pageviews.url
            // (siehe docs/url-normalization.md), also ohne Host/Schema.
            $result[] = [$rawUrl, $normalized, $urlHash];
        }

        return $result;
    }

    // -------------------------------------------------------------------------
    // Seite abrufen
    // -------------------------------------------------------------------------

    /**
     * @return array{html: ?string, http_status: ?int, error: ?string}
     */
    public static function fetchPage(string $url, int $timeoutSec, string $userAgent): array
    {
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL            => $url,
            CURLOPT_HTTPGET        => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => $timeoutSec,
            CURLOPT_CONNECTTIMEOUT => $timeoutSec,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS      => 5,
            CURLOPT_USERAGENT      => $userAgent,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_PROTOCOLS      => CURLPROTO_HTTP | CURLPROTO_HTTPS,
            CURLOPT_HEADER         => false,
        ]);

        // CURLOPT_WRITEFUNCTION überschreibt CURLOPT_RETURNTRANSFER (curl_exec()
        // liefert dann nur noch bool zurück) – Body wird daher im Callback gesammelt.
        $body = '';
        curl_setopt($ch, CURLOPT_WRITEFUNCTION, function ($ch, string $chunk) use (&$body): int {
            $body .= $chunk;
            return strlen($body) > self::MAX_BODY_BYTES ? 0 : strlen($chunk);
        });

        $success     = curl_exec($ch);
        $httpStatus  = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $contentType = (string) curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
        $curlErrno   = curl_errno($ch);
        $curlError   = curl_error($ch);
        // Kein curl_close(): seit PHP 8.0 wirkungslos, seit 8.5 deprecated –
        // das Handle wird beim Verlassen der Methode freigegeben.

        if ($curlErrno !== 0 || $success === false) {
            return ['html' => null, 'http_status' => $httpStatus ?: null, 'error' => mb_substr($curlError, 0, 255)];
        }
        if ($httpStatus < 200 || $httpStatus >= 300) {
            return ['html' => null, 'http_status' => $httpStatus, 'error' => "HTTP {$httpStatus}"];
        }
        if ($contentType !== '' && !str_contains($contentType, 'text/html')) {
            return ['html' => null, 'http_status' => $httpStatus, 'error' => 'unsupported_content_type'];
        }

        return ['html' => $body, 'http_status' => $httpStatus, 'error' => null];
    }

    // -------------------------------------------------------------------------
    // Content bereinigen & hashen
    // -------------------------------------------------------------------------

    /**
     * Entfernt Skripte/Styles, wandelt Block-Elemente in Zeilenumbrüche um
     * (damit die Zeilenstruktur für den Diff erhalten bleibt) und normalisiert
     * Whitespace pro Zeile.
     */
    public static function cleanContent(string $html): string
    {
        $html = (string) preg_replace('#<(script|style|noscript)\b[^>]*>.*?</\1>#is', ' ', $html);
        $html = (string) preg_replace('#<(br|/p|/li|/h[1-6]|/div|/tr|/section|/article)\b[^>]*>#i', "\n", $html);
        $text = strip_tags($html);
        $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');

        $lines = [];
        foreach (explode("\n", $text) as $line) {
            $line = trim((string) preg_replace('/\s+/u', ' ', $line));
            if ($line !== '') {
                $lines[] = $line;
            }
        }

        return implode("\n", $lines);
    }

    public static function hashContent(string $cleanText): string
    {
        return hash('sha256', $cleanText);
    }

    // -------------------------------------------------------------------------
    // Zeilen-Diff (einfacher LCS-basierter Algorithmus, keine Extension nötig)
    // -------------------------------------------------------------------------

    /**
     * @return array{added: int, removed: int, excerpt: string}
     */
    public static function diffLines(string $oldText, string $newText): array
    {
        $old = $oldText === '' ? [] : explode("\n", $oldText);
        $new = $newText === '' ? [] : explode("\n", $newText);

        $ops = self::lcsDiff($old, $new);

        $added = 0;
        $removed = 0;
        $excerptLines = [];
        $excerptChars = 0;

        foreach ($ops as [$op, $line]) {
            if ($op === '=') {
                continue;
            }
            if ($op === '+') {
                $added++;
            } else {
                $removed++;
            }
            if (count($excerptLines) < self::DIFF_MAX_LINES && $excerptChars < self::DIFF_MAX_CHARS) {
                $formatted = $op . ' ' . mb_substr($line, 0, 300);
                $excerptLines[] = $formatted;
                $excerptChars += strlen($formatted);
            }
        }

        return [
            'added'   => $added,
            'removed' => $removed,
            'excerpt' => mb_substr(implode("\n", $excerptLines), 0, self::DIFF_MAX_CHARS),
        ];
    }

    /**
     * Klassischer LCS-basierter Zeilen-Diff (dynamische Programmierung).
     * Für sehr grosse Seiten (viele tausend Zeilen) bewusst nicht optimiert –
     * ausreichend für typische Clubdesk-Seiteninhalte.
     *
     * @param string[] $old
     * @param string[] $new
     * @return array<int, array{0: string, 1: string}> Liste von [op, line], op ∈ {'=','+','-'}
     */
    private static function lcsDiff(array $old, array $new): array
    {
        $m = count($old);
        $n = count($new);

        $dp = array_fill(0, $m + 1, array_fill(0, $n + 1, 0));
        for ($i = $m - 1; $i >= 0; $i--) {
            for ($j = $n - 1; $j >= 0; $j--) {
                $dp[$i][$j] = $old[$i] === $new[$j]
                    ? $dp[$i + 1][$j + 1] + 1
                    : max($dp[$i + 1][$j], $dp[$i][$j + 1]);
            }
        }

        $ops = [];
        $i = 0;
        $j = 0;
        while ($i < $m && $j < $n) {
            if ($old[$i] === $new[$j]) {
                $ops[] = ['=', $old[$i]];
                $i++;
                $j++;
            } elseif ($dp[$i + 1][$j] >= $dp[$i][$j + 1]) {
                $ops[] = ['-', $old[$i]];
                $i++;
            } else {
                $ops[] = ['+', $new[$j]];
                $j++;
            }
        }
        while ($i < $m) {
            $ops[] = ['-', $old[$i]];
            $i++;
        }
        while ($j < $n) {
            $ops[] = ['+', $new[$j]];
            $j++;
        }

        return $ops;
    }

    // -------------------------------------------------------------------------
    // Persistenz
    // -------------------------------------------------------------------------

    /**
     * @param array{html: ?string, http_status: ?int, error: ?string} $fetchResult
     */
    public static function recordResult(PDO $pdo, string $checkTime, string $url, string $urlHash, array $fetchResult): void
    {
        $existingStmt = $pdo->prepare(
            'SELECT content_hash, content_text FROM tracked_pages WHERE url_hash = :hash LIMIT 1'
        );
        $existingStmt->execute([':hash' => $urlHash]);
        $existing = $existingStmt->fetch();

        if ($fetchResult['html'] === null) {
            self::recordFetchError($pdo, $checkTime, $url, $urlHash, $existing, $fetchResult);
            return;
        }

        $cleanText   = self::cleanContent($fetchResult['html']);
        $contentHash = self::hashContent($cleanText);

        if ($existing === false) {
            // Erste Erfassung: Baseline speichern, kein Change-Log-Eintrag.
            // Hinweis: PDO (ATTR_EMULATE_PREPARES=false) erlaubt keinen mehrfach
            // verwendeten benannten Platzhalter, daher :checked/:changed getrennt.
            $pdo->prepare(
                'INSERT INTO tracked_pages (url, url_hash, content_hash, content_text, last_checked_at, last_changed_at, last_http_status, in_sitemap)
                 VALUES (:url, :hash, :chash, :ctext, :checked, :changed, :status, 1)'
            )->execute([
                ':url'     => $url,
                ':hash'    => $urlHash,
                ':chash'   => $contentHash,
                ':ctext'   => $cleanText,
                ':checked' => $checkTime,
                ':changed' => $checkTime,
                ':status'  => $fetchResult['http_status'],
            ]);
            return;
        }

        if ($existing['content_hash'] === $contentHash) {
            $pdo->prepare(
                'UPDATE tracked_pages
                 SET last_checked_at = :checked, last_http_status = :status, consecutive_errors = 0, in_sitemap = 1
                 WHERE url_hash = :hash'
            )->execute([':checked' => $checkTime, ':status' => $fetchResult['http_status'], ':hash' => $urlHash]);
            return;
        }

        // Inhalt hat sich geändert
        $diff = self::diffLines((string) $existing['content_text'], $cleanText);

        $pdo->prepare(
            'INSERT INTO page_changes (url, url_hash, previous_hash, new_hash, lines_added, lines_removed, change_size, diff_excerpt, detected_at)
             VALUES (:url, :hash, :prev, :new, :added, :removed, :size, :excerpt, :detected)'
        )->execute([
            ':url'     => $url,
            ':hash'    => $urlHash,
            ':prev'    => $existing['content_hash'],
            ':new'     => $contentHash,
            ':added'   => $diff['added'],
            ':removed' => $diff['removed'],
            ':size'    => abs(mb_strlen($cleanText) - mb_strlen((string) $existing['content_text'])),
            ':excerpt' => $diff['excerpt'],
            ':detected'=> $checkTime,
        ]);

        $pdo->prepare(
            'UPDATE tracked_pages
             SET content_hash = :chash, content_text = :ctext, last_checked_at = :checked,
                 last_changed_at = :changed, last_http_status = :status, consecutive_errors = 0, in_sitemap = 1
             WHERE url_hash = :hash'
        )->execute([
            ':chash'   => $contentHash,
            ':ctext'   => $cleanText,
            ':checked' => $checkTime,
            ':changed' => $checkTime,
            ':status'  => $fetchResult['http_status'],
            ':hash'    => $urlHash,
        ]);
    }

    /**
     * @param array{html: ?string, http_status: ?int, error: ?string} $fetchResult
     */
    private static function recordFetchError(PDO $pdo, string $checkTime, string $url, string $urlHash, mixed $existing, array $fetchResult): void
    {
        if ($existing === false) {
            $pdo->prepare(
                'INSERT INTO tracked_pages (url, url_hash, last_checked_at, last_http_status, consecutive_errors, in_sitemap)
                 VALUES (:url, :hash, :checked, :status, 1, 1)'
            )->execute([
                ':url'     => $url,
                ':hash'    => $urlHash,
                ':checked' => $checkTime,
                ':status'  => $fetchResult['http_status'],
            ]);
            return;
        }

        $pdo->prepare(
            'UPDATE tracked_pages
             SET last_checked_at = :checked, last_http_status = :status,
                 consecutive_errors = consecutive_errors + 1, in_sitemap = 1
             WHERE url_hash = :hash'
        )->execute([':checked' => $checkTime, ':status' => $fetchResult['http_status'], ':hash' => $urlHash]);
    }

    /**
     * Markiert Seiten, die im aktuellen Lauf nicht mehr in der Sitemap/Fallback-Liste
     * auftauchen, als in_sitemap = 0. Historie in page_changes bleibt erhalten.
     *
     * @param string[] $seenUrlHashes
     */
    public static function markMissingFromSitemap(PDO $pdo, array $seenUrlHashes): void
    {
        if ($seenUrlHashes === []) {
            return;
        }
        $placeholders = implode(',', array_fill(0, count($seenUrlHashes), '?'));
        $pdo->prepare(
            "UPDATE tracked_pages SET in_sitemap = 0 WHERE in_sitemap = 1 AND url_hash NOT IN ({$placeholders})"
        )->execute($seenUrlHashes);
    }
}
