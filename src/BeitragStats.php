<?php
declare(strict_types=1);

/**
 * BeitragStats – Auswertung der Clubdesk-Beiträge (/beitrag/<c>).
 *
 * REIN LESEND. Schreibt nie, ändert kein Schema und normalisiert keine URLs neu:
 * laut docs/url-normalization.md normalisieren Konsumenten nicht erneut, sie machen
 * höchstens reine Pfad-Extraktion. Hier wird deshalb ausschliesslich der bereits
 * beim Ingest normalisierte Pfad aus pageviews.url zerlegt.
 *
 * Methodik-Spec inkl. Begründung der Schwellwerte: docs/beitrags-analyse.md
 */
final class BeitragStats
{
    /** Clubdesk-Objekttypen, siehe docs/url-normalization.md Regel 4 */
    public const TYPE_LABELS = ['ND' => 'News', 'ED' => 'Termin', 'CD' => 'Kontakt'];

    /** Herkunftsklassen (Reihenfolge = Anzeigereihenfolge) */
    public const REF_LABELS = [
        'intern' => 'Interne Navigation',
        'suche'  => 'Suchmaschine',
        'social' => 'Social Media',
        'mail'   => 'E-Mail (Webmail)',
        'extern' => 'Andere Website',
        'direkt' => 'Direkt / unbekannt',
    ];

    /**
     * Schwellwerte. Zentral, weil die UI-Texte dieselben Konstanten
     * hineininterpolieren – so können Text und Verhalten nicht auseinanderlaufen.
     */
    public const MIN_GROUP             = 5;   // ab hier gilt eine Gruppe als Muster
    public const MIN_GROUP_SHOW        = 3;   // darunter: gar nicht anzeigen
    public const MIN_VIEWS_FOR_RATE    = 50;  // für Likes je 100 Aufrufe
    public const MIN_DURATION_SAMPLE   = 10;  // für Ø-Lesezeit je Beitrag
    public const MATURE_DAYS_V7        = 7;
    public const MATURE_DAYS_LIFECYCLE = 14;
    public const LIFECYCLE_DAYS        = 14;  // Tag 0..13
    public const KEYWORD_MIN_DOCS      = 3;
    public const KEYWORD_MIN_LEN       = 4;

    /**
     * Generische Titel, die einen Beitrag nicht benennen (Clubdesk-Overlay noch
     * nicht gerendert, Fehler-/Login-Seiten). Einzige Quelle – public/index.php
     * referenziert diese Konstante.
     */
    public const GENERIC_TITLES = [
        'Willkommen - Zurich Sailing',
        'Seite nicht gefunden.',
        'Bitte anmelden',
    ];

    /** Deutsche Stoppwörter + seitenspezifische Füllbegriffe für die Titel-Analyse. */
    private const STOPWORDS = [
        'aber','alle','allen','alles','also','auch','auf','aus','bei','beim','bis',
        'dann','dass','dem','den','der','des','dessen','die','dies','diese','diesem',
        'diesen','dieser','dieses','doch','dort','durch','eine','einem','einen',
        'einer','eines','etwa','euch','fuer','für','gegen','haben','hatte','hatten',
        'hier','hinter','ihre','ihrem','ihren','ihrer','immer','jede','jedem','jeden',
        'jeder','jedes','kann','koennen','können','mehr','mit','nach','neben','nicht',
        'noch','nur','oder','ohne','schon','sehr','sein','seine','seinem','seinen',
        'seiner','sich','sind','soll','sollen','über','ueber','unser','unsere',
        'unserem','unseren','unserer','unter','viel','vom','von','vor','waren',
        'wegen','weil','werden','wieder','wird','wurde','wurden','zum','zur',
        'zwischen',
        // haeufige Adjektiv-/Zeitformen, die nichts ueber das Thema sagen
        'neue','neuem','neuen','neuer','neues','erste','ersten','erster',
        'grosse','grossen','ganze','ganzen','viele','vielen','heute','morgen',
        'jahr','jahre','jahren','woche','wochen','monat','tage','tagen',
        // seitenspezifisch: cleanTitle() entfernt nur das Suffix, nicht Vorkommen
        // mitten im Titel
        'zurich','sailing','club','verein','news','neuigkeiten','aktuell',
        'aktuelles','info','infos','seite','beitrag',
    ];

    // -------------------------------------------------------------------------
    // Basisfilter
    // -------------------------------------------------------------------------

    /**
     * WHERE-Fragment für echte Beiträge.
     *
     * - `url LIKE '/beitrag/%'` nutzt idx_url(255) als Präfix-Range.
     * - '/beitrag/' ist exakt 9 Zeichen; Position 10 ist damit der erste
     *   Buchstabe des Clubdesk-Schlüssels.
     * - `COLLATE utf8mb4_bin` ist NICHT optional: die Tabelle ist
     *   utf8mb4_unicode_ci, ein Vergleich ohne dieses COLLATE wäre
     *   case-insensitiv und der Filter damit wirkungslos.
     *
     * Zweck: setup/migrate_beitrag.sql hat Altzeilen zu '/beitrag/b<block>'
     * umgeschrieben (kleines 'b' + Ziffern). Das sind zusammengefallene
     * News-Blöcke, KEINE echten Beiträge – sie würden jede Auswertung
     * verfälschen. Echte Clubdesk-Schlüssel beginnen gross (ND/ED/CD).
     *
     * Bewusst ein Buchstabenbereich statt einer festen Präfixliste: das Muster
     * in collect.php erlaubt 1–3 Buchstaben, ein künftiger Typ bliebe damit
     * automatisch erhalten.
     */
    private const BASE = "url LIKE '/beitrag/%'
                          AND is_cms = 0
                          AND SUBSTRING(url, 10, 1) COLLATE utf8mb4_bin BETWEEN 'A' AND 'Z'";

    /**
     * Abgeleitete Tabelle: jede Beitrags-Zeile plus die Erstsichtung ihres
     * Beitrags. MIN(...) OVER (PARTITION BY url) liefert das in EINEM Scan des
     * /beitrag/-Index-Ranges – keine korrelierte Subquery je Beitrag und kein
     * Self-Join über VARCHAR(2048). Window Functions gibt es ab MariaDB 10.2,
     * Cyon läuft auf 10.6.
     */
    private static function rows(): string
    {
        return "SELECT url, created_at, fingerprint, duration, device_type, page_title,
                       MIN(created_at) OVER (PARTITION BY url) AS first_at
                  FROM pageviews
                 WHERE " . self::BASE;
    }

    // -------------------------------------------------------------------------
    // Kernabfrage
    // -------------------------------------------------------------------------

    /**
     * Eine Zeile je Beitrag mit allen Kernkennzahlen – die Basis, aus der
     * Wochentags-, Typ-, Keyword- und Engagement-Auswertung in PHP abgeleitet
     * werden (kein zweiter DB-Roundtrip für Daten, die schon da sind).
     *
     * Gruppiert nur nach url; Schlüssel und Typ werden in PHP zerlegt. Damit ist
     * das GROUP BY auch unter ONLY_FULL_GROUP_BY unstrittig.
     *
     * @return list<array<string,mixed>>
     */
    public static function overview(PDO $pdo): array
    {
        $generic = implode(',', array_map(
            static fn(string $t): string => $pdo->quote($t),
            self::GENERIC_TITLES
        ));

        $sql = "SELECT
                    x.url,
                    COALESCE(
                        MAX(CASE WHEN x.page_title <> '' AND x.page_title NOT IN ($generic)
                                 THEN x.page_title END),
                        MAX(x.page_title)
                    )                                                AS page_title,
                    MIN(x.first_at)                                  AS first_at,
                    MAX(x.created_at)                                AS last_at,
                    DATEDIFF(NOW(), MIN(x.first_at))                 AS age_days,
                    COUNT(*)                                         AS views,
                    /* fingerprint enthält das Datum (collect.php) und rotiert täglich.
                       Dieser Wert sind BESUCHERTAGE, keine Unique-Besucher – im UI
                       entsprechend benannt. */
                    COUNT(DISTINCT x.fingerprint)                    AS visitor_days,
                    SUM(CASE WHEN x.created_at < x.first_at + INTERVAL 1 DAY
                             THEN 1 ELSE 0 END)                      AS v1,
                    SUM(CASE WHEN x.created_at < x.first_at + INTERVAL "
                             . self::MATURE_DAYS_V7 . " DAY
                             THEN 1 ELSE 0 END)                      AS v7,
                    /* Lesezeit: Nenner ist die messbare Teilmenge, nicht alle Aufrufe */
                    AVG(CASE WHEN x.duration > 0 AND x.duration < 3600
                             THEN x.duration END)                    AS avg_duration,
                    SUM(CASE WHEN x.duration > 0  AND x.duration < 3600
                             THEN 1 ELSE 0 END)                      AS dur_n,
                    SUM(CASE WHEN x.duration >= 30 AND x.duration < 3600
                             THEN 1 ELSE 0 END)                      AS dur_30plus,
                    SUM(CASE WHEN x.duration > 0  AND x.duration < 10
                             THEN 1 ELSE 0 END)                      AS dur_short,
                    SUM(CASE WHEN x.device_type = 'mobile'
                             THEN 1 ELSE 0 END)                      AS mobile_views
                FROM (" . self::rows() . ") x
                GROUP BY x.url";

        $stmt = $pdo->query($sql);
        $rows = $stmt !== false ? $stmt->fetchAll() : [];

        $out = [];
        foreach ($rows as $r) {
            $cKey = substr((string) $r['url'], strlen('/beitrag/'));
            $age  = (int) $r['age_days'];

            $out[] = [
                'url'          => (string) $r['url'],
                'c_key'        => $cKey,
                'c_type'       => self::typeOf($cKey),
                'page_title'   => self::cleanTitle($r['page_title'] ?? null),
                'first_at'     => (string) $r['first_at'],
                'last_at'      => (string) $r['last_at'],
                'age_days'     => $age,
                'views'        => (int) $r['views'],
                'visitor_days' => (int) $r['visitor_days'],
                'v1'           => (int) $r['v1'],
                'v7'           => (int) $r['v7'],
                'v7_complete'  => $age >= self::MATURE_DAYS_V7,
                'lc_complete'  => $age >= self::MATURE_DAYS_LIFECYCLE,
                'avg_duration'     => $r['avg_duration'] !== null ? (float) $r['avg_duration'] : null,
                // ungedeckelt: fuer den seitenweiten Schnitt, wo alle Messungen zusammenkommen
                'avg_duration_raw' => $r['avg_duration'] !== null ? (float) $r['avg_duration'] : null,
                'dur_n'        => (int) $r['dur_n'],
                'dur_30plus'   => (int) $r['dur_30plus'],
                'dur_short'    => (int) $r['dur_short'],
                'mobile_views' => (int) $r['mobile_views'],
                'likes'        => 0,
            ];
        }
        return $out;
    }

    /**
     * Likes je Beitrag. Eigene Mini-Query statt LEFT JOIN: social_stats hat nur
     * PRIMARY KEY(url_hash) und keinen Index auf url – ein Join würde die Tabelle
     * je Beitragszeile scannen.
     *
     * Join-Schlüssel ist der rohe Pfad, nicht url_hash: Social::hashUrl() hasht
     * bewusst OHNE strtolower (src/Social.php), social_stats.url ist damit
     * byte-identisch zu pageviews.url. SitemapMonitor hasht dagegen MIT
     * strtolower – über url_hash zu joinen würde bei Grossbuchstaben fehlschlagen.
     *
     * @return array<string,int> url => like_count
     */
    public static function likes(PDO $pdo): array
    {
        if (!$pdo->query("SHOW TABLES LIKE 'social_stats'")->fetch()) {
            return [];
        }

        $stmt = $pdo->query(
            "SELECT url, like_count FROM social_stats
              WHERE url LIKE '/beitrag/%' AND like_count > 0"
        );
        $out = [];
        foreach ($stmt !== false ? $stmt->fetchAll() : [] as $r) {
            $out[(string) $r['url']] = (int) $r['like_count'];
        }
        return $out;
    }

    /**
     * Likes einmischen und alle abgeleiteten Raten berechnen.
     *
     * Jede Rate liefert null statt einer Zahl, wenn die Stichprobe zu klein ist.
     * Die UI zeigt dann "–", nicht "0 %": das ist der Unterschied zwischen
     * "gemessen und schlecht" und "nicht messbar".
     *
     * @param  list<array<string,mixed>> $rows
     * @param  array<string,int>         $likes
     * @return list<array<string,mixed>>
     */
    public static function enrich(array $rows, array $likes): array
    {
        foreach ($rows as &$r) {
            $r['likes'] = $likes[$r['url']] ?? 0;

            $durN = $r['dur_n'];
            $r['deep_read_pct'] = $durN >= self::MIN_DURATION_SAMPLE
                ? $r['dur_30plus'] / $durN * 100 : null;
            $r['bounce_pct'] = $durN >= self::MIN_DURATION_SAMPLE
                ? $r['dur_short'] / $durN * 100 : null;
            if ($durN < self::MIN_DURATION_SAMPLE) {
                $r['avg_duration'] = null;
            }

            $r['likes_per_100'] = $r['views'] >= self::MIN_VIEWS_FOR_RATE
                ? $r['likes'] / $r['views'] * 100 : null;
            $r['duration_coverage'] = $r['views'] > 0 ? $durN / $r['views'] * 100 : 0.0;
        }
        unset($r);
        return $rows;
    }

    // -------------------------------------------------------------------------
    // Muster (a): zeitlich
    // -------------------------------------------------------------------------

    /**
     * V7 gruppiert nach Wochentag der Erstsichtung. Reines PHP – first_at und v7
     * stehen bereits in overview().
     *
     * Nur reife Beiträge (v7_complete). Dünne Gruppen werden markiert, nicht
     * entfernt: die UI graut sie aus, statt sie stillschweigend zu verschweigen.
     *
     * @param  list<array<string,mixed>> $rows
     * @return list<array{dow:int,label:string,n:int,avg_v7:float,median_v7:float,thin:bool}>
     */
    public static function weekdayPerformance(array $rows): array
    {
        $labels  = [1 => 'Montag', 'Dienstag', 'Mittwoch', 'Donnerstag',
                         'Freitag', 'Samstag', 'Sonntag'];
        $buckets = array_fill_keys(range(1, 7), []);

        foreach ($rows as $r) {
            if (!$r['v7_complete']) continue;
            $dow = (int) (new DateTimeImmutable($r['first_at']))->format('N');
            $buckets[$dow][] = (float) $r['v7'];
        }

        $out = [];
        foreach ($buckets as $dow => $vals) {
            $n = count($vals);
            $out[] = [
                'dow'       => $dow,
                'label'     => $labels[$dow],
                'n'         => $n,
                'avg_v7'    => $n > 0 ? array_sum($vals) / $n : 0.0,
                'median_v7' => self::median($vals),
                'thin'      => $n < self::MIN_GROUP,
            ];
        }
        return $out;
    }

    /**
     * Beitrags-Aufrufe nach Wochentag und Stunde (max. 168 Zeilen).
     *
     * Bewusst roh: die Tageszeit-Buckets entstehen in PHP, damit die Grenzen
     * nicht in SQL einbetoniert sind.
     *
     * WEEKDAY() liefert 0 = Montag … 6 = Sonntag.
     *
     * @return list<array{dow:int,hr:int,views:int}>
     */
    public static function hourWeekday(PDO $pdo): array
    {
        $stmt = $pdo->query(
            "SELECT WEEKDAY(created_at) AS dow, HOUR(created_at) AS hr, COUNT(*) AS views
               FROM pageviews
              WHERE " . self::BASE . "
              GROUP BY WEEKDAY(created_at), HOUR(created_at)
              ORDER BY dow, hr"
        );
        $out = [];
        foreach ($stmt !== false ? $stmt->fetchAll() : [] as $r) {
            $out[] = ['dow' => (int) $r['dow'], 'hr' => (int) $r['hr'], 'views' => (int) $r['views']];
        }
        return $out;
    }

    /**
     * Lebenszyklus: durchschnittlicher Anteil der Aufrufe je Tag 0..13 seit
     * Erstsichtung, plus Halbwertszeit.
     *
     * Zwei Fallstricke bestimmen die Bauweise:
     *
     * 1. Makro- statt Mikro-Mittel: erst je Beitrag den Anteil bilden, dann über
     *    die Beiträge mitteln. Sonst definiert ein einzelner Beitrag mit 2000
     *    Aufrufen die Kurve praktisch allein.
     * 2. Fehlende Tage: hat ein Beitrag an Tag 3 null Aufrufe, existiert keine
     *    Zeile. AVG() würde dann nur über die vorhandenen Zeilen mitteln und den
     *    Anteil systematisch ZU HOCH schätzen. Deshalb SUM(anteil) und Division
     *    durch die FESTE Zahl reifer Beiträge.
     *
     * @return array{n_mature:int, share:list<float>, cum:list<float>, half_life:?int}
     */
    public static function lifecycle(PDO $pdo): array
    {
        $mature = self::MATURE_DAYS_LIFECYCLE;
        $maxDay = self::LIFECYCLE_DAYS - 1;

        $nMature = (int) $pdo->query(
            "SELECT COUNT(*) FROM (
                 SELECT url FROM pageviews
                  WHERE " . self::BASE . "
                  GROUP BY url
                 HAVING MIN(created_at) < NOW() - INTERVAL {$mature} DAY
             ) t"
        )->fetchColumn();

        $empty = ['n_mature' => $nMature, 'share' => [], 'cum' => [], 'half_life' => null];
        if ($nMature === 0) return $empty;

        $stmt = $pdo->query(
            "SELECT s.day_offset, SUM(s.share) AS share_sum
               FROM (
                     SELECT g.day_offset,
                            g.views / SUM(g.views) OVER (PARTITION BY g.url) AS share
                       FROM (
                             SELECT x.url,
                                    DATEDIFF(x.created_at, x.first_at) AS day_offset,
                                    COUNT(*)                           AS views
                               FROM (" . self::rows() . ") x
                              WHERE x.first_at < NOW() - INTERVAL {$mature} DAY
                                AND DATEDIFF(x.created_at, x.first_at) BETWEEN 0 AND {$maxDay}
                              GROUP BY x.url, DATEDIFF(x.created_at, x.first_at)
                           ) g
                   ) s
              GROUP BY s.day_offset
              ORDER BY s.day_offset"
        );

        $sums = array_fill(0, self::LIFECYCLE_DAYS, 0.0);
        foreach ($stmt !== false ? $stmt->fetchAll() : [] as $r) {
            $d = (int) $r['day_offset'];
            if ($d >= 0 && $d < self::LIFECYCLE_DAYS) {
                $sums[$d] = (float) $r['share_sum'];
            }
        }

        $share = $cum = [];
        $run = 0.0;
        $halfLife = null;
        foreach ($sums as $d => $sum) {
            $pct   = $sum / $nMature * 100;
            $run  += $pct;
            $share[] = $pct;
            $cum[]   = $run;
            if ($halfLife === null && $run >= 50.0) $halfLife = $d;
        }

        return ['n_mature' => $nMature, 'share' => $share, 'cum' => $cum, 'half_life' => $halfLife];
    }

    // -------------------------------------------------------------------------
    // Muster (b): Typ und Themen
    // -------------------------------------------------------------------------

    /**
     * Gruppierung nach Clubdesk-Objekttyp. Die einzige Themen-Gruppierung im
     * System, die keine Heuristik ist – sie kommt direkt aus dem Schlüssel.
     *
     * @param  list<array<string,mixed>> $rows
     * @return list<array<string,mixed>>
     */
    public static function byType(array $rows): array
    {
        $g = [];
        foreach ($rows as $r) {
            $g[$r['c_type']][] = $r;
        }

        $out = [];
        foreach ($g as $type => $items) {
            $mature = array_values(array_filter($items, static fn($r) => $r['v7_complete']));
            $v7     = array_map(static fn($r) => (float) $r['v7'], $mature);
            $durs   = array_values(array_filter(
                array_map(static fn($r) => $r['avg_duration'], $items),
                static fn($d) => $d !== null
            ));

            $out[] = [
                'type'         => $type,
                'label'        => self::typeLabel($type),
                'n'            => count($items),
                'n_mature'     => count($mature),
                'views'        => array_sum(array_map(static fn($r) => $r['views'], $items)),
                'avg_v7'       => $v7 !== [] ? array_sum($v7) / count($v7) : null,
                'median_v7'    => $v7 !== [] ? self::median($v7) : null,
                'avg_duration' => $durs !== [] ? array_sum($durs) / count($durs) : null,
                'thin'         => count($mature) < self::MIN_GROUP,
            ];
        }

        usort($out, static fn($a, $b) => $b['views'] <=> $a['views']);
        return $out;
    }

    /**
     * Tokenisiert einen Beitragstitel zu Suchbegriffen.
     *
     * \p{L} statt [a-z]: Umlaute und Akzente bleiben ein Wort statt zu zerfallen.
     * Pro Titel dedupliziert – ein Titel, der "Regatta" zweimal nennt, zählt einmal.
     *
     * @return array<string,string> Gruppierungsschlüssel => Anzeigeform
     */
    public static function extractKeywords(string $title): array
    {
        $t = mb_strtolower(self::cleanTitle($title), 'UTF-8');
        $t = (string) preg_replace('/[^\p{L}\p{N}]+/u', ' ', $t);
        $tokens = preg_split('/\s+/u', trim($t), -1, PREG_SPLIT_NO_EMPTY) ?: [];

        $out = [];
        foreach ($tokens as $tok) {
            if (mb_strlen($tok, 'UTF-8') < self::KEYWORD_MIN_LEN) continue;
            if (ctype_digit($tok)) continue;
            $fold = self::foldUmlauts($tok);
            if (in_array($fold, self::STOPWORDS, true)) continue;
            if (in_array($tok, self::STOPWORDS, true)) continue;
            $out[$fold] = $tok;
        }
        return $out;
    }

    /**
     * Begriffe aus den Beitragstiteln mit ihrer Performance.
     *
     * Baseline ist der MEDIAN V7 aller reifen Beiträge, nicht der Durchschnitt:
     * ein einzelner viral gegangener Beitrag würde den Vergleichswert sonst
     * nach oben ziehen und jeden Begriff schlecht aussehen lassen.
     *
     * @param  list<array<string,mixed>> $rows
     * @return list<array<string,mixed>>
     */
    public static function keywordStats(array $rows): array
    {
        $mature = array_values(array_filter($rows, static fn($r) => $r['v7_complete']));
        if ($mature === []) return [];

        $baseline = self::median(array_map(static fn($r) => (float) $r['v7'], $mature));

        $terms = [];
        foreach ($mature as $r) {
            if ($r['page_title'] === '') continue;
            foreach (self::extractKeywords($r['page_title']) as $fold => $display) {
                $terms[$fold]['display'][] = $display;
                $terms[$fold]['v7'][]      = (float) $r['v7'];
                $terms[$fold]['views'][]   = (int) $r['views'];
            }
        }

        $out = [];
        foreach ($terms as $fold => $data) {
            $n = count($data['v7']);
            if ($n < self::KEYWORD_MIN_DOCS) continue;

            $counts = array_count_values($data['display']);
            arsort($counts);
            $median = self::median($data['v7']);

            $out[] = [
                'term'        => (string) array_key_first($counts),
                'n'           => $n,
                'avg_v7'      => array_sum($data['v7']) / $n,
                'median_v7'   => $median,
                'total_views' => array_sum($data['views']),
                // Vergleich Median gegen Median: die Tabelle zeigt den Median,
                // ein Lift aus dem Mittelwert wuerde daneben widerspruechlich wirken.
                'lift'        => $baseline > 0 ? ($median / $baseline - 1) * 100 : null,
                'thin'        => $n < self::MIN_GROUP,
            ];
        }

        usort($out, static fn($a, $b) => $b['median_v7'] <=> $a['median_v7']);
        return $out;
    }

    // -------------------------------------------------------------------------
    // Muster (d): Herkunft und Geräte
    // -------------------------------------------------------------------------

    /**
     * Herkunft der Beitrags-Aufrufe, klassifiziert.
     *
     * Aggregiert auf den Referrer-HOST statt die volle URL: jede
     * Google-Ergebnisseite hat eine eigene URL, das Resultset würde sonst
     * tausende Zeilen umfassen. Die Klassifikation selbst bleibt in PHP –
     * testbar, an einer Stelle, kein SQL-CASE-Monster.
     *
     * @return list<array{class:string,label:string,views:int,pct:float}>
     */
    public static function origins(PDO $pdo, string $selfDomain): array
    {
        $hostExpr = "LOWER(SUBSTRING_INDEX(
                         SUBSTRING_INDEX(REGEXP_REPLACE(COALESCE(referrer, ''), '^https?://', ''), '/', 1),
                         ':', 1))";

        $stmt = $pdo->query(
            "SELECT {$hostExpr} AS ref_host, COUNT(*) AS views
               FROM pageviews
              WHERE " . self::BASE . "
              GROUP BY {$hostExpr}"
        );

        $classes = array_fill_keys(array_keys(self::REF_LABELS), 0);
        $total   = 0;
        foreach ($stmt !== false ? $stmt->fetchAll() : [] as $r) {
            $class = self::classifyReferrerHost((string) $r['ref_host'], $selfDomain);
            $classes[$class] += (int) $r['views'];
            $total += (int) $r['views'];
        }

        $out = [];
        foreach (self::REF_LABELS as $class => $label) {
            if ($classes[$class] === 0) continue;
            $out[] = [
                'class' => $class,
                'label' => $label,
                'views' => $classes[$class],
                'pct'   => $total > 0 ? $classes[$class] / $total * 100 : 0.0,
            ];
        }

        usort($out, static fn($a, $b) => $b['views'] <=> $a['views']);
        return $out;
    }

    /**
     * Ordnet einen Referrer-Host einer Herkunftsklasse zu.
     * Leerer Host = kein Referrer = 'direkt'.
     *
     * Wichtig für die Interpretation: Klicks aus Desktop-Mailprogrammen
     * (Apple Mail, Outlook-App) kommen OHNE Referrer an und landen zwangsläufig
     * in 'direkt'. Nur Webmail ist als 'mail' erkennbar – die E-Mail-Zahl ist
     * damit eine Untergrenze, keine Messung.
     */
    public static function classifyReferrerHost(string $host, string $selfDomain): string
    {
        $host = (string) preg_replace('/^www\./', '', strtolower(trim($host)));
        if ($host === '') return 'direkt';

        $selfDomain = strtolower(trim($selfDomain));
        if ($selfDomain !== ''
            && ($host === $selfDomain || str_ends_with($host, '.' . $selfDomain))) {
            return 'intern';
        }
        if ($host === 'clubdesk.com' || str_ends_with($host, '.clubdesk.com')) {
            return 'intern';
        }

        foreach (['google.', 'bing.', 'duckduckgo.', 'ecosia.', 'yahoo.',
                  'startpage.', 'qwant.', 'search.brave.', 'yandex.'] as $needle) {
            if (str_starts_with($host, $needle)) return 'suche';
        }

        if (in_array($host, [
                'facebook.com', 'm.facebook.com', 'l.facebook.com',
                'instagram.com', 'l.instagram.com', 't.co', 'twitter.com',
                'x.com', 'linkedin.com', 'lnkd.in', 'youtube.com',
                'reddit.com', 'threads.net',
            ], true)
            || str_contains($host, 'whatsapp')) {
            return 'social';
        }

        if (str_starts_with($host, 'mail.') || str_starts_with($host, 'webmail.')
            || in_array($host, ['outlook.live.com', 'outlook.office.com',
                                'outlook.office365.com'], true)) {
            return 'mail';
        }

        return 'extern';
    }

    /**
     * Geräteverteilung über alle Beitrags-Aufrufe.
     *
     * @return list<array{device_type:string,views:int,avg_duration:?float}>
     */
    public static function devices(PDO $pdo): array
    {
        $stmt = $pdo->query(
            "SELECT device_type,
                    COUNT(*) AS views,
                    AVG(CASE WHEN duration > 0 AND duration < 3600 THEN duration END) AS avg_duration
               FROM pageviews
              WHERE " . self::BASE . "
              GROUP BY device_type
              ORDER BY views DESC"
        );
        $out = [];
        foreach ($stmt !== false ? $stmt->fetchAll() : [] as $r) {
            $out[] = [
                'device_type'  => (string) $r['device_type'],
                'views'        => (int) $r['views'],
                'avg_duration' => $r['avg_duration'] !== null ? (float) $r['avg_duration'] : null,
            ];
        }
        return $out;
    }

    // -------------------------------------------------------------------------
    // Helfer
    // -------------------------------------------------------------------------

    /** Typpräfix aus dem Clubdesk-Schlüssel, z. B. 'ND1000030' → 'ND'. */
    public static function typeOf(string $cKey): string
    {
        return preg_match('/^([A-Za-z]{1,3})/', $cKey, $m) ? $m[1] : '?';
    }

    public static function typeLabel(string $type): string
    {
        return self::TYPE_LABELS[$type] ?? $type;
    }

    /** "Titel – Zurich Sailing" → "Titel". Einzige Implementierung im Projekt. */
    public static function cleanTitle(?string $title): string
    {
        return trim((string) preg_replace('/\s*[-–|]\s*Zurich Sailing.*$/iu', '', $title ?? ''));
    }

    /** Sekunden lesbar machen. Einzige Implementierung im Projekt. */
    public static function formatDuration(float $secs): string
    {
        if ($secs <= 0) return '–';
        $m = floor($secs / 60);
        $s = round(fmod($secs, 60));
        return $m > 0 ? "{$m}m {$s}s" : "{$s}s";
    }

    /** Schweizer Zahlenformat: 1'234. Eine Stelle fuer Mittelwerte/Prozente. */
    public static function nf(float $n, int $dec = 0): string
    {
        return number_format($n, $dec, '.', "'");
    }

    /** Median – robuster als der Durchschnitt, wenn ein Beitrag viral ging. */
    public static function median(array $values): float
    {
        $values = array_values(array_map('floatval', $values));
        $n = count($values);
        if ($n === 0) return 0.0;
        sort($values);
        $mid = intdiv($n, 2);
        return $n % 2 === 1 ? $values[$mid] : ($values[$mid - 1] + $values[$mid]) / 2;
    }

    /** Nur fürs Gruppieren: ä→ae usw. Angezeigt wird die häufigste Originalform. */
    private static function foldUmlauts(string $s): string
    {
        return strtr($s, ['ä' => 'ae', 'ö' => 'oe', 'ü' => 'ue', 'ß' => 'ss',
                          'à' => 'a', 'á' => 'a', 'è' => 'e', 'é' => 'e', 'ê' => 'e']);
    }
}
