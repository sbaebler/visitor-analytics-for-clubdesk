<?php
declare(strict_types=1);

require_once __DIR__ . '/Social.php';
require_once __DIR__ . '/SitemapMonitor.php';

/**
 * PlacementMonitor – erfasst, WO auf der Website ein Beitrag eingebettet ist.
 *
 * Hintergrund: normalizePageUrl() verwirft beim Ingest bewusst den Basispfad und
 * den Block-Parameter b, damit derselbe Beitrag über verschiedene Trägerseiten
 * als EINE Seite zählt (docs/url-normalization.md Regel 4). Die Platzierung ist
 * damit nicht in pageviews enthalten und wird hier von der Website nachgelesen.
 *
 * Clubdesk rendert die Beitragskacheln serverseitig ins HTML:
 *
 *   <div class="cd-tile-h-box"
 *        onclick="window.location.href='/?b=1002305&c=ND1000043&s=…'">
 *     <div class="cd-tile-h-main-heading">Titel</div>
 *     <div class="cd-tile-h-main-subheading"><time>15.09.2026</time>, Autor</div>
 *
 * Ein curl-Abruf genügt also – kein JavaScript nötig.
 *
 * Die Kategorie ist der BLOCK (b), nicht die Seite: derselbe Block kann auf
 * mehreren Seiten eingebettet sein (z. B. 1002383 auf /regional und
 * /regional/offen).
 *
 * Spec: docs/beitrags-analyse.md
 */
final class PlacementMonitor
{
    /** Obergrenze für entdeckte Seiten, falls die Config nichts vorgibt. */
    public const DEFAULT_MAX_PAGES = 60;

    /** Dateiendungen, die keine HTML-Seiten sind. */
    private const SKIP_EXTENSIONS = 'pdf|jpe?g|png|gif|svg|webp|ico|zip|docx?|xlsx?|pptx?|ics|css|js|mp4|mp3';

    // -------------------------------------------------------------------------
    // Seiten entdecken
    // -------------------------------------------------------------------------

    /**
     * Interne Seitenpfade aus dem Roh-HTML einer Seite (in der Praxis: Startseite).
     *
     * Die Website hat keine Sitemap (/sitemap.xml → 404), aber die Navigation
     * steht vollständig im HTML jeder Seite. Ergebnis sind normalisierte Pfade
     * im selben Format wie pageviews.url.
     *
     * @return list<string>
     */
    public static function discoverPages(string $html, string $baseUrl): array
    {
        $out = [];
        preg_match_all('/href="([^"]+)"/i', $html, $m);

        foreach ($m[1] as $href) {
            $href = html_entity_decode($href, ENT_QUOTES | ENT_HTML5, 'UTF-8');

            // Nur seiteninterne, absolute Pfade – keine Protokoll-relativen (//host),
            // keine Anker, keine Query-URLs (das sind Beitrags-/Listenlinks).
            if ($href === '' || $href[0] !== '/' || str_starts_with($href, '//')) continue;
            if (str_contains($href, '?') || str_contains($href, '#')) continue;
            if (preg_match('/\.(' . self::SKIP_EXTENSIONS . ')$/i', $href)) continue;

            // Clubdesk-Interna wie /clubdesk/w_zsv-sstr54/ überspringen
            if (str_starts_with($href, '/clubdesk/')) continue;

            [$normalized] = Social::normalizePageUrl(rtrim($baseUrl, '/') . $href);
            $out[$normalized] = true;
        }

        // Die Startseite selbst gehört immer dazu
        [$root] = Social::normalizePageUrl(rtrim($baseUrl, '/') . '/');
        $out[$root] = true;

        return array_keys($out);
    }

    // -------------------------------------------------------------------------
    // Kacheln auslesen
    // -------------------------------------------------------------------------

    /**
     * Beitragskacheln aus dem Roh-HTML einer Seite.
     *
     * Bewusst das ROHE HTML: SitemapMonitor::cleanContent() ruft strip_tags()
     * und würde genau die onclick-Attribute entfernen, um die es hier geht.
     *
     * @return list<array{c_key:string,block_id:string,title:?string,published_at:?string,author:?string}>
     */
    public static function extractTiles(string $html): array
    {
        // Jede Kachel beginnt mit dem onclick-Sprung. Der Text bis zum nächsten
        // onclick (oder Dokumentende) enthält Titel, Datum und Autor dieser Kachel.
        $pattern = '/onclick="window\.location\.href=\'[^\']*?[?&]b=(\d+)&(?:amp;)?c=([^\'&]+)[^\']*\'"/i';
        preg_match_all($pattern, $html, $m, PREG_OFFSET_CAPTURE);

        $out  = [];
        $seen = [];
        $n    = count($m[0]);

        for ($i = 0; $i < $n; $i++) {
            $blockId = $m[1][$i][0];
            $cRaw    = html_entity_decode(urldecode($m[2][$i][0]), ENT_QUOTES | ENT_HTML5, 'UTF-8');

            // c kann eine Kette sein (NL,ND1000043) – der Beitrag ist das letzte
            // Glied mit Ziffern. Gleiche Regel wie in normalizePageUrl().
            $cKey = null;
            foreach (array_reverse(explode(',', $cRaw)) as $part) {
                $part = trim($part);
                if (preg_match('/^[A-Za-z]{1,3}\d+$/', $part)) { $cKey = $part; break; }
            }
            if ($cKey === null) continue;   // reiner Listenlink ("Weitere Einträge")

            $dedup = $blockId . '|' . $cKey;
            if (isset($seen[$dedup])) continue;
            $seen[$dedup] = true;

            $start   = $m[0][$i][1];
            $end     = ($i + 1 < $n) ? $m[0][$i + 1][1] : strlen($html);
            $segment = substr($html, $start, $end - $start);

            $out[] = [
                'c_key'        => $cKey,
                'block_id'     => $blockId,
                'title'        => self::firstMatch('/class="cd-tile-h-main-heading"[^>]*>([\s\S]{0,300}?)</i', $segment),
                'published_at' => self::parseDate(self::firstMatch('/<time[^>]*>([^<]{1,40})<\/time>/i', $segment)),
                'author'       => self::parseAuthor($segment),
            ];
        }

        return $out;
    }

    /**
     * "Weitere Einträge"-Links inklusive Signatur.
     *
     * Ohne den s=-Parameter antwortet die Vollliste mit 404; mit ihm liefert sie
     * den kompletten Block statt nur der neuesten Einträge (Block 1002305:
     * 11 statt 4). Das ist der Unterschied zwischen "aktuell sichtbar" und
     * "vollständig", und damit entscheidend für die Abdeckung älterer Beiträge.
     *
     * @return list<string> absolute URLs
     */
    public static function extractListLinks(string $html, string $baseUrl): array
    {
        preg_match_all('/href="([^"]*[?&]b=\d+&(?:amp;)?c=(?:NL|EL)(?:&(?:amp;)?s=[^"&]*)?[^"]*)"/i', $html, $m);

        $out = [];
        foreach ($m[1] as $href) {
            $href = html_entity_decode($href, ENT_QUOTES | ENT_HTML5, 'UTF-8');
            if ($href === '') continue;
            $abs = str_starts_with($href, 'http') ? $href : rtrim($baseUrl, '/') . '/' . ltrim($href, '/');
            $out[$abs] = true;
        }
        return array_keys($out);
    }

    /**
     * Bezeichnung eines Blocks: die nächste vorangehende Überschrift im HTML.
     *
     * Clubdesk vergibt den News-Listen selbst keinen Titel – die sichtbare
     * Beschriftung steht in einem eigenen Block darüber. Gegen die echte Website
     * geprüft: trifft alle neun vorhandenen Blöcke.
     */
    public static function labelForBlock(string $html, string $blockId): ?string
    {
        $pos = strpos($html, 'block_' . $blockId);
        if ($pos === false) return null;

        // Nur im Bereich davor suchen; das letzte Treffer-Heading ist das nächste.
        $before = substr($html, max(0, $pos - 5000), min($pos, 5000));
        if (!preg_match_all('/<h[1-3][^>]*>([\s\S]{0,200}?)<\/h[1-3]>/i', $before, $m)) return null;

        for ($i = count($m[1]) - 1; $i >= 0; $i--) {
            $label = self::plainText($m[1][$i]);
            if ($label !== '') return mb_substr($label, 0, 255);
        }
        return null;
    }

    // -------------------------------------------------------------------------
    // Persistenz
    // -------------------------------------------------------------------------

    /**
     * Eine Platzierung festhalten. Zweiter Lauf erzeugt kein Duplikat, sondern
     * schreibt last_seen_at fort (UNIQUE uniq_placement).
     *
     * Hinweis: PDO läuft hier mit ATTR_EMULATE_PREPARES=false – ein benannter
     * Platzhalter darf nur EINMAL vorkommen, deshalb die :*_upd-Duplikate.
     */
    public static function record(
        PDO $pdo,
        array $tile,
        string $pageUrl,
        ?string $blockLabel,
        string $runAt,
        string $source = 'scrape'
    ): void {
        $stmt = $pdo->prepare(
            "INSERT INTO beitrag_placements
                 (c_key, block_id, page_url, block_label, published_at, author,
                  source, first_seen_at, last_seen_at)
             VALUES (:c, :b, :p, :label, :pub, :author, :src, :run, :run2)
             ON DUPLICATE KEY UPDATE
                 last_seen_at = :run3,
                 block_label  = COALESCE(:label2, block_label),
                 published_at = COALESCE(:pub2, published_at),
                 author       = COALESCE(:author2, author),
                 -- Ein Scrape bestätigt die Zeile und hebt sie von 'referrer'
                 -- auf 'scrape'; umgekehrt stuft eine Rekonstruktion aus
                 -- Altdaten eine gescrapte Zeile nie zurück.
                 source       = IF(:src2 = 'scrape', 'scrape', source)"
        );
        $stmt->execute([
            ':c'       => $tile['c_key'],
            ':b'       => $tile['block_id'],
            ':p'       => $pageUrl,
            ':label'   => $blockLabel,
            ':label2'  => $blockLabel,
            ':pub'     => $tile['published_at'] ?? null,
            ':pub2'    => $tile['published_at'] ?? null,
            ':author'  => $tile['author'] ?? null,
            ':author2' => $tile['author'] ?? null,
            ':src'     => $source,
            ':src2'    => $source,
            ':run'     => $runAt,
            ':run2'    => $runAt,
            ':run3'    => $runAt,
        ]);
    }

    /**
     * Platzierungen aus Altdaten rekonstruieren.
     *
     * Bis zum Fix in tracker.js meldeten client-seitig geöffnete Beiträge ihre
     * eigene rohe URL als Referrer – inklusive Trägerpfad und Block. Für
     * Beiträge, die inzwischen aus allen Listen gefallen sind, ist das die
     * einzige erhaltene Quelle. Rein lesend gegenüber pageviews.
     *
     * Trägt source='referrer' ein und überschreibt keine gescrapte Zeile.
     *
     * @return int Anzahl neu zugeordneter Platzierungen
     */
    public static function backfillFromReferrer(PDO $pdo, string $runAt): int
    {
        $rows = $pdo->query(
            "SELECT DISTINCT referrer
               FROM pageviews
              WHERE url LIKE '/beitrag/%'
                AND is_cms = 0
                AND referrer LIKE '%b=%'
                AND referrer LIKE '%c=%'"
        )->fetchAll();

        $n = 0;
        foreach ($rows as $row) {
            $ref = (string) $row['referrer'];
            if (!preg_match('/[?&]b=(\d+)/', $ref, $mb)) continue;
            if (!preg_match('/[?&]c=([^&]+)/', $ref, $mc)) continue;

            $cKey = null;
            foreach (array_reverse(explode(',', urldecode($mc[1]))) as $part) {
                $part = trim($part);
                if (preg_match('/^[A-Za-z]{1,3}\d+$/', $part)) { $cKey = $part; break; }
            }
            if ($cKey === null) continue;

            // Trägerpfad: der Referrer ohne Query, über dieselbe Normalisierung
            // wie alles andere – keine dritte Implementierung.
            [$pagePath] = Social::normalizePageUrl(strtok($ref, '?') ?: '/');

            self::record(
                $pdo,
                ['c_key' => $cKey, 'block_id' => $mb[1], 'published_at' => null, 'author' => null],
                $pagePath,
                null,
                $runAt,
                'referrer'
            );
            $n++;
        }
        return $n;
    }

    // -------------------------------------------------------------------------
    // Helfer
    // -------------------------------------------------------------------------

    private static function firstMatch(string $pattern, string $subject): ?string
    {
        if (!preg_match($pattern, $subject, $m)) return null;
        $text = self::plainText($m[1]);
        return $text !== '' ? mb_substr($text, 0, 500) : null;
    }

    /** Tags raus, Entities auflösen, Whitespace normalisieren. */
    private static function plainText(string $html): string
    {
        $text = html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        // &nbsp; wird zu U+00A0 – das ist kein \s in der Standard-Klasse
        $text = str_replace("\xC2\xA0", ' ', $text);
        return trim((string) preg_replace('/\s+/u', ' ', $text));
    }

    /** "15.09.2026" → "2026-09-15"; alles andere → null. */
    private static function parseDate(?string $raw): ?string
    {
        if ($raw === null) return null;
        if (!preg_match('/(\d{1,2})\.(\d{1,2})\.(\d{4})/', $raw, $m)) return null;
        return sprintf('%04d-%02d-%02d', (int) $m[3], (int) $m[2], (int) $m[1]);
    }

    /** Autor steht hinter dem Datum: "<time>15.09.2026</time>, Frey Rolf". */
    private static function parseAuthor(string $segment): ?string
    {
        if (!preg_match('/<\/time>\s*,?\s*([^<]{1,120})</i', $segment, $m)) return null;
        $author = self::plainText($m[1]);
        $author = trim($author, " ,\t\n");
        return $author !== '' ? mb_substr($author, 0, 128) : null;
    }
}
