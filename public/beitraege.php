<?php
declare(strict_types=1);

require_once __DIR__ . '/../src/Auth.php';
require_once __DIR__ . '/../src/Database.php';
require_once __DIR__ . '/../src/BeitragStats.php';

// Identisch zu index.php: CSP erlaubt Chart.js von jsdelivr. Ohne diesen Header
// blockiert der Browser die Charts.
header("Content-Security-Policy: default-src 'none'; script-src 'self' https://cdn.jsdelivr.net 'unsafe-inline'; style-src 'self' 'unsafe-inline'; img-src 'self' data:; connect-src 'none'; font-src 'none'; frame-src 'none'");
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');

Auth::requireAuth();

$pdo        = Database::get();
$config     = require __DIR__ . '/../config/config.php';
$selfDomain = $config['self_domain'] ?? '';

// Bewusst KEIN ?range=-Filter: V7 und Lebenszyklus beziehen sich auf das Alter
// des einzelnen Beitrags, nicht auf einen Kalenderzeitraum. Ein Zeitraumfilter
// würde für die halbe Seite gelten und für die andere nicht.
$sort = $_GET['sort'] ?? 'v7';
if (!in_array($sort, ['v7', 'views', 'recent', 'duration', 'likes', 'visitors'], true)) {
    $sort = 'v7';
}
$dir = ($_GET['dir'] ?? 'desc') === 'asc' ? 'asc' : 'desc';

$typeFilter = (string) ($_GET['type'] ?? 'all');
$termFilter = trim((string) ($_GET['q'] ?? ''));

// --- Daten ---
$all = BeitragStats::enrich(BeitragStats::overview($pdo), BeitragStats::likes($pdo));

$types = array_values(array_unique(array_column($all, 'c_type')));
sort($types);
if (!in_array($typeFilter, $types, true)) $typeFilter = 'all';

// Muster-Auswertungen laufen immer auf ALLEN Beiträgen – ein Filter in der
// Tabelle darf die Grundgesamtheit der Muster nicht verändern.
$weekday   = BeitragStats::weekdayPerformance($all);
$byType    = BeitragStats::byType($all);
$keywords  = BeitragStats::keywordStats($all);
$lifecycle = BeitragStats::lifecycle($pdo);
$origins   = BeitragStats::origins($pdo, $selfDomain);
$devices   = BeitragStats::devices($pdo);
$heat      = BeitragStats::hourWeekday($pdo);

// --- Tabelle: filtern und sortieren ---
$rows = $all;
if ($typeFilter !== 'all') {
    $rows = array_values(array_filter($rows, fn($r) => $r['c_type'] === $typeFilter));
}
if ($termFilter !== '') {
    $needle = mb_strtolower($termFilter, 'UTF-8');
    $rows = array_values(array_filter($rows, function ($r) use ($needle) {
        return str_contains(mb_strtolower($r['page_title'], 'UTF-8'), $needle);
    }));
}

usort($rows, function (array $a, array $b) use ($sort, $dir): int {
    // Beiträge ohne abgeschlossenes 7-Tage-Fenster sind beim V7-Vergleich nicht
    // vergleichbar und landen immer am Ende – in beiden Sortierrichtungen.
    if ($sort === 'v7' && $a['v7_complete'] !== $b['v7_complete']) {
        return $a['v7_complete'] ? -1 : 1;
    }
    $cmp = match ($sort) {
        'views'    => $a['views'] <=> $b['views'],
        'visitors' => $a['visitor_days'] <=> $b['visitor_days'],
        'recent'   => strcmp($a['first_at'], $b['first_at']),
        'duration' => ($a['avg_duration'] ?? -1) <=> ($b['avg_duration'] ?? -1),
        'likes'    => $a['likes'] <=> $b['likes'],
        default    => $a['v7'] <=> $b['v7'],
    };
    return $dir === 'asc' ? $cmp : -$cmp;
});

// --- Kennzahlen für die KPI-Zeile ---
$mature       = array_values(array_filter($all, fn($r) => $r['v7_complete']));
$medianViews  = BeitragStats::median(array_column($all, 'views'));
$medianV7     = $mature !== [] ? BeitragStats::median(array_column($mature, 'v7')) : 0.0;
$totalDurN    = array_sum(array_column($all, 'dur_n'));
$totalViews   = array_sum(array_column($all, 'views'));
$durWeighted  = 0.0;
foreach ($all as $r) {
    if ($r['avg_duration_raw'] !== null) $durWeighted += $r['avg_duration_raw'] * $r['dur_n'];
}
$avgDuration  = $totalDurN > 0 ? $durWeighted / $totalDurN : 0.0;
$coveragePct  = $totalViews > 0 ? $totalDurN / $totalViews * 100 : 0.0;
$firstEver    = $all !== [] ? min(array_column($all, 'first_at')) : null;

// Zeitzone der DB-Sitzung: HOUR(created_at) liefert die Stunde in dieser Zone.
// Wird in der Methodik-Karte ausgewiesen, damit ein Versatz sichtbar ist und
// die Aussage "abends wird gelesen" nicht still um zwei Stunden daneben liegt.
$dbTz = (string) $pdo->query("SELECT @@session.time_zone")->fetchColumn();

// --- Heatmap-Matrix (7 x 24) ---
$matrix  = array_fill(0, 7, array_fill(0, 24, 0));
$heatMax = 0;
foreach ($heat as $h) {
    $matrix[$h['dow']][$h['hr']] = $h['views'];
    if ($h['views'] > $heatMax) $heatMax = $h['views'];
}
$dayNames  = ['Mo', 'Di', 'Mi', 'Do', 'Fr', 'Sa', 'So'];
$peakLabel = '';
if ($heatMax > 0) {
    foreach ($heat as $h) {
        if ($h['views'] === $heatMax) {
            $peakLabel = $dayNames[$h['dow']] . ', ' . $h['hr'] . '–' . ($h['hr'] + 1) . ' Uhr';
            break;
        }
    }
}

// --- Chart-Daten ---
$wdChart = [
    'labels' => array_column($weekday, 'label'),
    // Wochentage unter der Mustergrenze werden zu null: ein Balken, der nur aus
    // Datenmangel klein ist, sieht sonst aus wie ein schlechter Tag.
    'data'   => array_map(fn($w) => $w['thin'] ? null : round($w['median_v7'], 1), $weekday),
];
$lcChart = [
    'labels' => array_map(fn($d) => 'Tag ' . $d, array_keys($lifecycle['share'])),
    'share'  => array_map(fn($v) => round($v, 1), $lifecycle['share']),
    'cum'    => array_map(fn($v) => round($v, 1), $lifecycle['cum']),
];

$refChart = [
    'labels' => array_column($origins, 'label'),
    'data'   => array_column($origins, 'views'),
    'colors' => ['#0A2342', '#2196F3', '#64B5F6', '#FF9800', '#718096', '#CBD5E0'],
];
$devLabels = ['desktop' => 'Desktop', 'mobile' => 'Mobil', 'tablet' => 'Tablet'];

/** Link auf dieselbe Seite mit geänderten Parametern. */
$link = function (array $overrides) use ($sort, $dir, $typeFilter, $termFilter): string {
    $p = array_merge(
        ['sort' => $sort, 'dir' => $dir, 'type' => $typeFilter, 'q' => $termFilter],
        $overrides
    );
    $p = array_filter($p, fn($v) => $v !== '' && $v !== 'all');
    return '/beitraege.php' . ($p ? '?' . http_build_query($p) : '');
};

/** Spaltenkopf als Sortierlink; erneuter Klick dreht die Richtung. */
$sortHead = function (string $key, string $label, string $title) use ($sort, $dir, $link): string {
    $nextDir = ($sort === $key && $dir === 'desc') ? 'asc' : 'desc';
    $arrow   = $sort === $key ? ($dir === 'desc' ? ' ▾' : ' ▴') : '';
    return '<a href="' . htmlspecialchars($link(['sort' => $key, 'dir' => $nextDir]))
         . '" title="' . htmlspecialchars($title) . '">'
         . htmlspecialchars($label) . $arrow . '</a>';
};
?>
<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>ZS Analytics – Beiträge</title>
    <link rel="stylesheet" href="/assets/style.css">
</head>
<body>
    <header class="topbar">
        <div class="topbar-brand">
            <span class="topbar-icon">⛵</span>
            <div>
                <span class="topbar-title"><?= htmlspecialchars($config['site_name'] ?? 'Analytics') ?></span>
                <span class="topbar-sub">Beiträge</span>
            </div>
        </div>
        <nav class="view-nav">
            <a href="/" class="range-btn">Übersicht</a>
            <a href="/beitraege.php" class="range-btn active">Beiträge</a>
        </nav>
        <a href="/logout.php" class="logout-btn">Abmelden</a>
    </header>

    <main class="content">

        <?php if (empty($all)): ?>
            <div class="card">
                <div class="card-header"><h2 class="card-title">Beiträge</h2></div>
                <p class="card-desc">
                    Noch keine Beitrags-Aufrufe erfasst. Beiträge werden als eigene Seite
                    <code>/beitrag/&lt;schlüssel&gt;</code> gezählt, sobald jemand sie öffnet.
                </p>
            </div>
        <?php else: ?>

        <div class="kpi-grid">
            <div class="kpi-card">
                <span class="kpi-label">Beiträge erfasst</span>
                <span class="kpi-value"><?= BeitragStats::nf((float) count($all)) ?></span>
                <span class="kpi-sub">
                    <?= $firstEver ? 'seit ' . htmlspecialchars(date('j.n.Y', strtotime($firstEver))) : '' ?>
                </span>
            </div>
            <div class="kpi-card">
                <span class="kpi-label">Median Aufrufe</span>
                <span class="kpi-value"><?= BeitragStats::nf($medianViews) ?></span>
                <span class="kpi-sub">je Beitrag, gesamte Laufzeit</span>
            </div>
            <div class="kpi-card">
                <span class="kpi-label">Median erste 7 Tage</span>
                <span class="kpi-value"><?= BeitragStats::nf($medianV7) ?></span>
                <span class="kpi-sub"><?= BeitragStats::nf((float) count($mature)) ?> vergleichbare Beiträge</span>
            </div>
            <div class="kpi-card">
                <span class="kpi-label">Ø aktive Lesezeit</span>
                <span class="kpi-value"><?= htmlspecialchars(BeitragStats::formatDuration($avgDuration)) ?></span>
                <span class="kpi-sub">bei <?= BeitragStats::nf($coveragePct) ?> % der Aufrufe gemessen</span>
            </div>
        </div>

        <div class="cms-hint">
            <strong>Datenbasis:</strong> Beitrags-Aufrufe werden erst seit dem 3. Juli 2026 als
            eigene Seite erfasst – frühere Aufrufe fehlen vollständig. „Erstsichtung“ ist der
            erste gemessene Aufruf, <strong>nicht</strong> das Veröffentlichungsdatum.
        </div>

        <?php if (count($mature) < 20): ?>
            <div class="uptime-correlation-hint">
                Noch wenig Vergleichsmaterial: erst <?= BeitragStats::nf((float) count($mature)) ?>
                Beiträge haben ihre ersten 7 Tage hinter sich. Die Muster unten sind als
                Einzelbeobachtungen zu lesen, nicht als Trend.
            </div>
        <?php endif; ?>

        <!-- ================= Beitragsliste ================= -->
        <div class="card" id="beitrag-card">
            <div class="card-header card-header-tabs">
                <h2 class="card-title">Alle Beiträge</h2>
                <?php if (count($types) > 1 || $termFilter !== ''): ?>
                <div class="card-tabs">
                    <?php if ($termFilter !== ''): ?>
                        <a class="tab-btn active" href="<?= htmlspecialchars($link(['q' => ''])) ?>">
                            „<?= htmlspecialchars($termFilter) ?>“ ✕
                        </a>
                    <?php else: ?>
                        <a class="tab-btn <?= $typeFilter === 'all' ? 'active' : '' ?>"
                           href="<?= htmlspecialchars($link(['type' => 'all'])) ?>">Alle</a>
                        <?php foreach ($types as $t): ?>
                            <a class="tab-btn <?= $typeFilter === $t ? 'active' : '' ?>"
                               href="<?= htmlspecialchars($link(['type' => $t])) ?>">
                                <?= htmlspecialchars(BeitragStats::typeLabel($t)) ?>
                            </a>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
                <?php endif; ?>
            </div>
            <p class="card-desc">
                Spaltenköpfe sind sortierbar. <strong>Erste 7 Tage</strong> vergleicht alle Beiträge
                im gleichen Zeitfenster – <strong>Aufrufe</strong> summiert die gesamte Laufzeit und
                bevorzugt damit ältere Beiträge.
            </p>
            <table class="data-table">
                <thead>
                    <tr>
                        <th>Beitrag</th>
                        <th><?= $sortHead('recent', 'Erstsichtung', 'Nach erstem gemessenem Aufruf sortieren') ?></th>
                        <th><?= $sortHead('v7', 'Erste 7 Tage', 'Aufrufe in den ersten 7 Tagen – fairer Vergleich') ?></th>
                        <th><?= $sortHead('views', 'Aufrufe', 'Aufrufe über die gesamte Laufzeit') ?></th>
                        <th><?= $sortHead('visitors', 'Besuchertage', 'Besucherkennung wechselt täglich – siehe Methodik') ?></th>
                        <th><?= $sortHead('duration', 'Ø Zeit', 'Aktive Lesezeit, ab 10 Messungen') ?></th>
                        <th><?= $sortHead('likes', '♥', 'Likes aus dem Social-Widget') ?></th>
                    </tr>
                </thead>
                <tbody>
                <?php if (empty($rows)): ?>
                    <tr><td colspan="7" class="empty">Keine Beiträge für diesen Filter</td></tr>
                <?php else: ?>
                    <?php foreach (array_slice($rows, 0, 100) as $r): ?>
                        <?php $href = '/?range=30d&view=real&url=' . urlencode($r['url']) . '&scope=exact'; ?>
                        <tr>
                            <td>
                                <a class="page-link" href="<?= htmlspecialchars($href) ?>"
                                   title="Verlauf dieses Beitrags im Dashboard – <?= htmlspecialchars($r['url']) ?>">
                                    <span class="url-path">
                                        <?= htmlspecialchars($r['page_title'] !== '' ? $r['page_title'] : $r['c_key']) ?>
                                    </span>
                                    <span class="url-title">
                                        <?= htmlspecialchars(BeitragStats::typeLabel($r['c_type'])) ?> ·
                                        <?= htmlspecialchars($r['url']) ?>
                                    </span>
                                </a>
                            </td>
                            <td>
                                <span class="url-path"><?= htmlspecialchars(date('j.n.Y', strtotime($r['first_at']))) ?></span>
                                <span class="url-title">vor <?= BeitragStats::nf((float) $r['age_days']) ?> Tagen</span>
                            </td>
                            <td class="num">
                                <?php if ($r['v7_complete']): ?>
                                    <?= BeitragStats::nf((float) $r['v7']) ?>
                                <?php else: ?>
                                    <span class="hint" title="Erst <?= (int) $r['age_days'] ?> Tage alt – das 7-Tage-Fenster läuft noch">läuft</span>
                                <?php endif; ?>
                            </td>
                            <td class="num"><?= BeitragStats::nf((float) $r['views']) ?></td>
                            <td class="num"><?= BeitragStats::nf((float) $r['visitor_days']) ?></td>
                            <td class="num">
                                <?php if ($r['avg_duration'] !== null): ?>
                                    <?= htmlspecialchars(BeitragStats::formatDuration($r['avg_duration'])) ?>
                                <?php else: ?>
                                    <span class="hint" title="Nur <?= (int) $r['dur_n'] ?> Messungen – zu wenig für einen Mittelwert">–</span>
                                <?php endif; ?>
                            </td>
                            <td class="num"><?= $r['likes'] > 0 ? BeitragStats::nf((float) $r['likes']) : '–' ?></td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
                </tbody>
            </table>
            <?php if (count($rows) > 100): ?>
                <p class="card-desc">Zeigt 100 von <?= BeitragStats::nf((float) count($rows)) ?> Beiträgen.</p>
            <?php endif; ?>
        </div>

        <!-- ================= Lebenszyklus ================= -->
        <?php if ($lifecycle['n_mature'] >= BeitragStats::MIN_GROUP): ?>
        <div class="card">
            <div class="card-header"><h2 class="card-title">Lebenszyklus eines Beitrags</h2></div>
            <p class="card-desc">
                <?php if ($lifecycle['half_life'] !== null): ?>
                    <strong>Halbwertszeit: <?= $lifecycle['half_life'] === 0 ? 'unter einem Tag' : BeitragStats::nf((float) $lifecycle['half_life'] + 1) . ' Tage' ?>.</strong>
                    So lange dauert es, bis die Hälfte der Aufrufe eines Beitrags erreicht ist.
                <?php endif; ?>
                Basis: <?= BeitragStats::nf((float) $lifecycle['n_mature']) ?> Beiträge, die mindestens
                <?= BeitragStats::MATURE_DAYS_LIFECYCLE ?> Tage alt sind. Gemittelt wird je Beitrag,
                damit ein einzelner grosser Beitrag die Kurve nicht allein bestimmt.
            </p>
            <div class="chart-wrap"><canvas id="lifecycleChart"></canvas></div>
        </div>
        <?php endif; ?>

        <div class="two-col">
            <!-- ================= Wochentag ================= -->
            <div class="card">
                <div class="card-header"><h2 class="card-title">Wochentag der Veröffentlichung</h2></div>
                <p class="card-desc">
                    Median der Aufrufe in den ersten 7 Tagen, gruppiert nach dem Wochentag der
                    Erstsichtung. Tage mit weniger als <?= BeitragStats::MIN_GROUP ?> Beiträgen
                    haben keinen Balken – zu wenig Daten für eine Aussage.
                </p>
                <div class="chart-wrap"><canvas id="weekdayChart"></canvas></div>
                <table class="data-table">
                    <thead><tr><th>Tag</th><th>Beiträge</th><th>Median</th><th>Ø</th></tr></thead>
                    <tbody>
                    <?php foreach ($weekday as $w): ?>
                        <?php if ($w['n'] === 0) continue; ?>
                        <tr<?= $w['thin'] ? ' class="row-thin"' : '' ?>>
                            <td><span class="url-path"><?= htmlspecialchars($w['label']) ?></span></td>
                            <td class="num"><?= BeitragStats::nf((float) $w['n']) ?></td>
                            <td class="num">
                                <?php if ($w['thin']): ?>
                                    <span class="hint" title="Erst <?= $w['n'] ?> Beiträge an diesem Tag – keine belastbare Aussage">–</span>
                                <?php else: ?>
                                    <?= BeitragStats::nf($w['median_v7'], 1) ?>
                                <?php endif; ?>
                            </td>
                            <td class="num"><?= $w['thin'] ? '–' : BeitragStats::nf($w['avg_v7'], 1) ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

            <!-- ================= Typ ================= -->
            <div class="card">
                <div class="card-header"><h2 class="card-title">Nach Beitragstyp</h2></div>
                <p class="card-desc">
                    Der Typ kommt direkt aus dem Clubdesk-Schlüssel (ND/ED/CD) – keine Schätzung,
                    im Gegensatz zur Themenauswertung weiter unten.
                </p>
                <table class="data-table">
                    <thead><tr><th>Typ</th><th>Beiträge</th><th>Aufrufe</th><th>Median 7 T.</th><th>Ø Zeit</th></tr></thead>
                    <tbody>
                    <?php if (empty($byType)): ?>
                        <tr><td colspan="5" class="empty">Noch keine Daten</td></tr>
                    <?php endif; ?>
                    <?php foreach ($byType as $t): ?>
                        <tr<?= $t['thin'] ? ' class="row-thin"' : '' ?>>
                            <td><span class="url-path"><?= htmlspecialchars($t['label']) ?></span></td>
                            <td class="num"><?= BeitragStats::nf((float) $t['n']) ?></td>
                            <td class="num"><?= BeitragStats::nf((float) $t['views']) ?></td>
                            <td class="num">
                                <?php if ($t['thin'] || $t['median_v7'] === null): ?>
                                    <span class="hint" title="Erst <?= $t['n_mature'] ?> vergleichbare Beiträge dieses Typs">–</span>
                                <?php else: ?>
                                    <?= BeitragStats::nf($t['median_v7'], 1) ?>
                                <?php endif; ?>
                            </td>
                            <td class="num">
                                <?= $t['avg_duration'] !== null
                                    ? htmlspecialchars(BeitragStats::formatDuration($t['avg_duration']))
                                    : '–' ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- ================= Lesezeitpunkt ================= -->
        <?php if ($heatMax > 0): ?>
        <div class="card">
            <div class="card-header"><h2 class="card-title">Wann Beiträge gelesen werden</h2></div>
            <p class="card-desc">
                Aufrufe nach Wochentag und Stunde<?= $peakLabel !== '' ? ', am meisten ' . htmlspecialchars($peakLabel) : '' ?>.
                Je dunkler, desto mehr Aufrufe – Spitzenwert <?= BeitragStats::nf((float) $heatMax) ?>.
            </p>
            <div class="heatmap-wrap">
                <div class="heatmap">
                    <div class="heat-corner"></div>
                    <?php for ($h = 0; $h < 24; $h++): ?>
                        <div class="heat-hour"><?= $h % 3 === 0 ? $h : '' ?></div>
                    <?php endfor; ?>
                    <?php foreach ($dayNames as $d => $name): ?>
                        <div class="heat-day"><?= $name ?></div>
                        <?php for ($h = 0; $h < 24; $h++): ?>
                            <?php $v = $matrix[$d][$h]; ?>
                            <div class="heat-cell" style="--i: <?= $heatMax > 0 ? round($v / $heatMax, 3) : 0 ?>"
                                 title="<?= $name ?> <?= $h ?>–<?= $h + 1 ?> Uhr: <?= BeitragStats::nf((float) $v) ?> Aufrufe"></div>
                        <?php endfor; ?>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>
        <?php endif; ?>

        <!-- ================= Themen ================= -->
        <div class="card">
            <div class="card-header"><h2 class="card-title">Themen in den Titeln</h2></div>
            <p class="card-desc">
                Begriffe aus den Beitragstiteln, Stoppwörter entfernt. <strong>Heuristisch</strong> –
                ein Titel beschreibt nicht immer das Thema. Ab <?= BeitragStats::MIN_GROUP ?> Beiträgen
                je Begriff aussagekräftig, darunter grau. Klick filtert die Liste oben.
            </p>
            <table class="data-table">
                <thead><tr><th>Begriff</th><th>Beiträge</th><th>Median 7 T.</th><th>ggü. Median aller</th></tr></thead>
                <tbody>
                <?php if (empty($keywords)): ?>
                    <tr><td colspan="4" class="empty">Noch zu wenig Titel für eine Themenauswertung</td></tr>
                <?php endif; ?>
                <?php foreach (array_slice($keywords, 0, 20) as $k): ?>
                    <tr<?= $k['thin'] ? ' class="row-thin"' : '' ?>>
                        <td>
                            <a class="page-link" href="<?= htmlspecialchars($link(['q' => $k['term'], 'type' => 'all'])) ?>">
                                <span class="url-path"><?= htmlspecialchars($k['term']) ?></span>
                            </a>
                        </td>
                        <td class="num"><?= BeitragStats::nf((float) $k['n']) ?></td>
                        <td class="num"><?= BeitragStats::nf($k['median_v7'], 1) ?></td>
                        <td class="num">
                            <?php if ($k['lift'] === null): ?>–<?php else: ?>
                                <?= ($k['lift'] >= 0 ? '+' : '') . BeitragStats::nf($k['lift'], 0) ?> %
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <div class="two-col">
            <!-- ================= Herkunft ================= -->
            <div class="card">
                <div class="card-header"><h2 class="card-title">Herkunft der Leser</h2></div>
                <p class="card-desc">
                    „Interne Navigation“ heisst: über die Website gefunden. Klicks aus Apple Mail
                    oder der Outlook-App kommen ohne Herkunft an und zählen als „Direkt“ –
                    die E-Mail-Zahl ist eine Untergrenze.
                </p>
                <div class="device-chart-wrap"><canvas id="refChart"></canvas></div>
                <div class="device-legend">
                    <?php foreach ($origins as $i => $o): ?>
                        <div class="legend-item">
                            <span class="legend-dot" style="background: <?= $refChart['colors'][$i % 6] ?>"></span>
                            <span><?= htmlspecialchars($o['label']) ?></span>
                            <span class="legend-pct"><?= BeitragStats::nf($o['pct']) ?> %</span>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>

            <!-- ================= Geräte ================= -->
            <div class="card">
                <div class="card-header"><h2 class="card-title">Geräte</h2></div>
                <p class="card-desc">Alle Beitrags-Aufrufe, mit der jeweils gemessenen Lesezeit.</p>
                <table class="data-table">
                    <thead><tr><th>Gerät</th><th>Aufrufe</th><th>Anteil</th><th>Ø Zeit</th></tr></thead>
                    <tbody>
                    <?php $devTotal = array_sum(array_column($devices, 'views')); ?>
                    <?php if (empty($devices)): ?>
                        <tr><td colspan="4" class="empty">Noch keine Daten</td></tr>
                    <?php endif; ?>
                    <?php foreach ($devices as $d): ?>
                        <tr>
                            <td><span class="url-path"><?= htmlspecialchars($devLabels[$d['device_type']] ?? $d['device_type']) ?></span></td>
                            <td class="num"><?= BeitragStats::nf((float) $d['views']) ?></td>
                            <td class="num"><?= $devTotal > 0 ? BeitragStats::nf($d['views'] / $devTotal * 100) : '0' ?> %</td>
                            <td class="num">
                                <?= $d['avg_duration'] !== null
                                    ? htmlspecialchars(BeitragStats::formatDuration($d['avg_duration']))
                                    : '–' ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- ================= Grenzen ================= -->
        <div class="card">
            <div class="card-header"><h2 class="card-title">Was diese Zahlen nicht sagen</h2></div>
            <p class="card-desc">
                <strong>Kein Veröffentlichungsdatum.</strong> Clubdesk liefert keines, und im Tracker
                kommt keines an. Alters-, Wochentags- und Lebenszyklus-Auswertung benutzen stattdessen
                die Erstsichtung – den ersten gemessenen Aufruf. Bei einem Beitrag, den am
                Publikationstag niemand öffnet, liegt sie zu spät.
            </p>
            <p class="card-desc">
                <strong>Kein Inventar aller Beiträge.</strong> Ein Beitrag ohne einen einzigen Aufruf
                existiert in diesen Daten gar nicht. „Wenig Aufrufe“ heisst hier immer: wenig im
                Vergleich zu den anderen gemessenen Beiträgen.
            </p>
            <p class="card-desc">
                <strong>Keine Wiederkehrer.</strong> Die anonyme Besucherkennung wechselt täglich –
                das ist Absicht (kein Cookie, keine gespeicherte IP). „Besuchertage“ zählt denselben
                Menschen an drei Tagen dreimal. Ob jemand zurückkommt, ist aus diesen Daten
                grundsätzlich nicht beantwortbar.
            </p>
            <p class="card-desc">
                <strong>Keine Klicks aus Beiträgen heraus.</strong> Ausgehende Link-Klicks werden ohne
                Beitragsschlüssel gespeichert und der Trägerseite zugerechnet. Eine Zuordnung zum
                einzelnen Beitrag ist nicht möglich – deshalb fehlt diese Kennzahl hier ganz, statt
                geschätzt zu werden.
            </p>
            <p class="card-desc">
                <strong>Lesezeit ist eine Stichprobe.</strong> Sie wird per Nachmeldung gemessen und
                fehlt bei <?= BeitragStats::nf(100 - $coveragePct) ?> % der Aufrufe (Tab geschlossen,
                Verbindung weg). Je Beitrag erst ab <?= BeitragStats::MIN_DURATION_SAMPLE ?> Messungen
                ausgewiesen, sonst steht „–“.
            </p>
            <p class="card-desc">
                <strong>Muster brauchen Menge.</strong> Eine Gruppe gilt erst ab
                <?= BeitragStats::MIN_GROUP ?> Beiträgen als Muster; darunter steht „–“ und die Zeile
                ist grau. Begriffe unter <?= BeitragStats::KEYWORD_MIN_DOCS ?> Beiträgen erscheinen gar
                nicht. Verglichen wird mit dem Median, nicht dem Durchschnitt – ein einzelner viraler
                Beitrag soll das Bild nicht kippen.
            </p>
            <p class="card-desc">
                <strong>Stundenangaben</strong> beziehen sich auf die Zeitzone der Datenbank
                (<code><?= htmlspecialchars($dbTz) ?></code>).
            </p>
        </div>

        <?php endif; ?>
    </main>

    <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
    <script src="/assets/dashboard.js"></script>
    <script>
        ZSDash.initBeitraege({
            weekday:   <?= json_encode($wdChart, JSON_UNESCAPED_UNICODE) ?>,
            lifecycle: <?= json_encode($lcChart, JSON_UNESCAPED_UNICODE) ?>,
            referrer:  <?= json_encode($refChart, JSON_UNESCAPED_UNICODE) ?>
        });
    </script>
</body>
</html>
