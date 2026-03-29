<?php
declare(strict_types=1);

require_once __DIR__ . '/../src/Auth.php';
require_once __DIR__ . '/../src/Database.php';

// CSP erlaubt Chart.js von jsdelivr
header("Content-Security-Policy: default-src 'none'; script-src 'self' https://cdn.jsdelivr.net 'unsafe-inline'; style-src 'self' 'unsafe-inline'; img-src 'self' data:; connect-src 'none'; font-src 'none'; frame-src 'none'");
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');

Auth::requireAuth();

// --- Zeitraum auswählen ---
$range = $_GET['range'] ?? '30d';
$validRanges = ['today', '7d', '30d', 'month', 'lastmonth'];
if (!in_array($range, $validRanges, true)) $range = '30d';

$view = $_GET['view'] ?? 'real';
if (!in_array($view, ['real', 'cms'], true)) $view = 'real';
$isCmsFilter = ($view === 'cms') ? 1 : 0;

$tz = new DateTimeZone('Europe/Zurich');
$now = new DateTimeImmutable('now', $tz);

switch ($range) {
    case 'today':
        $start = $now->setTime(0, 0, 0);
        $end   = $now->setTime(23, 59, 59);
        $label = 'Heute';
        break;
    case '7d':
        $start = $now->modify('-6 days')->setTime(0, 0, 0);
        $end   = $now->setTime(23, 59, 59);
        $label = 'Letzte 7 Tage';
        break;
    case 'month':
        $start = $now->modify('first day of this month')->setTime(0, 0, 0);
        $end   = $now->setTime(23, 59, 59);
        $label = 'Dieser Monat';
        break;
    case 'lastmonth':
        $start = $now->modify('first day of last month')->setTime(0, 0, 0);
        $end   = $now->modify('last day of last month')->setTime(23, 59, 59);
        $label = 'Letzter Monat';
        break;
    default: // 30d
        $start = $now->modify('-29 days')->setTime(0, 0, 0);
        $end   = $now->setTime(23, 59, 59);
        $label = 'Letzte 30 Tage';
}

$startStr = $start->format('Y-m-d H:i:s');
$endStr   = $end->format('Y-m-d H:i:s');

// --- Queries ---
$pdo    = Database::get();
$config = require __DIR__ . '/../config/config.php';
$selfDomain = $config['self_domain'] ?? '';

function q(PDO $pdo, string $sql, array $params = []): array
{
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll();
}

function qVal(PDO $pdo, string $sql, array $params = []): mixed
{
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchColumn();
}

$p = [':s' => $startStr, ':e' => $endStr];

// Graceful Degradation: Filter nur anwenden wenn Spalte existiert
$hasCmsCol = (bool) $pdo->query("SHOW COLUMNS FROM pageviews LIKE 'is_cms'")->fetch();
$cmsCondition = $hasCmsCol ? ' AND is_cms = :cms' : '';
if ($hasCmsCol) $p[':cms'] = $isCmsFilter;

$totalPageviews   = (int) qVal($pdo, "SELECT COUNT(*) FROM pageviews WHERE created_at BETWEEN :s AND :e{$cmsCondition}", $p);
$uniqueVisitors   = (int) qVal($pdo, "SELECT COUNT(DISTINCT fingerprint) FROM pageviews WHERE created_at BETWEEN :s AND :e{$cmsCondition}", $p);
$avgDuration      = (float) qVal($pdo, "SELECT AVG(duration) FROM pageviews WHERE created_at BETWEEN :s AND :e AND duration > 0 AND duration < 3600{$cmsCondition}", $p);
$outboundClicks   = $view === 'cms' ? 0 : (int) qVal($pdo, "SELECT COUNT(*) FROM events WHERE created_at BETWEEN :s AND :e AND event_type = 'outbound_link'", [':s' => $startStr, ':e' => $endStr]);

// Tägliche Übersicht für Chart
$dailyRows = q($pdo,
    "SELECT DATE(created_at) as d, COUNT(*) as pv, COUNT(DISTINCT fingerprint) as uv
     FROM pageviews WHERE created_at BETWEEN :s AND :e{$cmsCondition}
     GROUP BY DATE(created_at) ORDER BY d ASC", $p);

// Top-Seiten (Pfad ohne Domain anzeigen)
$topPages = q($pdo,
    "SELECT url, page_title, COUNT(*) as views, COUNT(DISTINCT fingerprint) as visitors
     FROM pageviews WHERE created_at BETWEEN :s AND :e{$cmsCondition}
     GROUP BY url, page_title ORDER BY views DESC LIMIT 15", $p);

// Top-Referrer (nur externe)
$selfFilter = $selfDomain !== '' ? 'AND referrer NOT LIKE :self' : '';
$selfParams = $selfDomain !== '' ? [':self' => '%' . $selfDomain . '%'] : [];
$topRefs = q($pdo,
    "SELECT referrer, COUNT(*) as cnt FROM pageviews
     WHERE created_at BETWEEN :s AND :e
     AND referrer != '' AND referrer IS NOT NULL
     {$selfFilter}{$cmsCondition}
     GROUP BY referrer ORDER BY cnt DESC LIMIT 10",
    $p + $selfParams);

// Geräte
$devices = q($pdo,
    "SELECT device_type, COUNT(*) as cnt FROM pageviews
     WHERE created_at BETWEEN :s AND :e{$cmsCondition}
     GROUP BY device_type ORDER BY cnt DESC", $p);

// Top Länder
$topCountries = q($pdo,
    "SELECT country, COUNT(*) as views, COUNT(DISTINCT fingerprint) as visitors
     FROM pageviews WHERE created_at BETWEEN :s AND :e AND country IS NOT NULL{$cmsCondition}
     GROUP BY country ORDER BY visitors DESC LIMIT 10", $p);

// Externe Links (nur im Besucher-View)
$outboundLinks = $view === 'cms' ? [] : q($pdo,
    "SELECT event_value, COUNT(*) as clicks FROM events
     WHERE created_at BETWEEN :s AND :e AND event_type = 'outbound_link'
     GROUP BY event_value ORDER BY clicks DESC LIMIT 10", [':s' => $startStr, ':e' => $endStr]);

// CMS-Zähler für Info-Banner (nur im Besucher-View)
$cmsCount = 0;
if ($hasCmsCol && $view === 'real') {
    $cmsCount = (int) qVal($pdo,
        'SELECT COUNT(*) FROM pageviews WHERE created_at BETWEEN :s AND :e AND is_cms = 1',
        [':s' => $startStr, ':e' => $endStr]
    );
}

// --- Hilfsfunktionen ---
function formatDuration(float $secs): string
{
    if ($secs <= 0) return '–';
    $m = floor($secs / 60);
    $s = round($secs % 60);
    return $m > 0 ? "{$m}m {$s}s" : "{$s}s";
}

function shortUrl(string $url): string
{
    $path = parse_url($url, PHP_URL_PATH) ?? '/';
    $query = parse_url($url, PHP_URL_QUERY);
    $result = $path ?: '/';
    if ($query) $result .= '?' . $query;
    return $result;
}

function countryFlag(string $code): string
{
    // ISO 3166-1 alpha-2 → Regional Indicator Symbol (Flaggen-Emoji)
    $code   = strtoupper($code);
    $offset = 0x1F1E6 - ord('A');
    $flag   = '';
    foreach (str_split($code) as $char) {
        $cp    = ord($char) + $offset;
        $flag .= mb_convert_encoding('&#' . $cp . ';', 'UTF-8', 'HTML-ENTITIES');
    }
    return $flag;
}

function shortDomain(string $url): string
{
    $host = parse_url($url, PHP_URL_HOST) ?? $url;
    return preg_replace('/^www\./', '', $host);
}

// JSON für Chart
$chartLabels = array_column($dailyRows, 'd');
$chartPV     = array_column($dailyRows, 'pv');
$chartUV     = array_column($dailyRows, 'uv');

$deviceLabels = array_map(fn($r) => ucfirst($r['device_type']), $devices);
$deviceData   = array_column($devices, 'cnt');
?>
<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>ZS Analytics – Dashboard</title>
    <link rel="stylesheet" href="/assets/style.css">
</head>
<body>
    <header class="topbar">
        <div class="topbar-brand">
            <span class="topbar-icon">⛵</span>
            <div>
                <span class="topbar-title"><?= htmlspecialchars($config['site_name'] ?? 'Analytics') ?></span>
                <span class="topbar-sub"><?= htmlspecialchars($selfDomain) ?></span>
            </div>
        </div>
        <nav class="range-nav">
            <?php foreach (['today' => 'Heute', '7d' => '7 Tage', '30d' => '30 Tage', 'month' => 'Monat', 'lastmonth' => 'Vormonat'] as $key => $lbl): ?>
                <a href="/?range=<?= $key ?>&view=<?= $view ?>" class="range-btn <?= $range === $key ? 'active' : '' ?>">
                    <?= $lbl ?>
                </a>
            <?php endforeach; ?>
        </nav>
        <nav class="view-nav">
            <a href="/?range=<?= $range ?>&view=real" class="range-btn <?= $view === 'real' ? 'active' : '' ?>" title="Echte Website-Besucher – Zugriffe durch Redakteure sind ausgeblendet">Besucher</a>
            <a href="/?range=<?= $range ?>&view=cms"  class="range-btn <?= $view === 'cms'  ? 'active' : '' ?>" title="Zugriffe durch Redakteure im Clubdesk-Editor (Content-Management-System)">CMS</a>
        </nav>
        <a href="/logout.php" class="logout-btn">Abmelden</a>
    </header>

    <main class="content">

        <!-- KPI-Karten -->
        <div class="kpi-grid">
            <div class="kpi-card">
                <div class="kpi-label">Seitenaufrufe</div>
                <div class="kpi-value"><?= number_format($totalPageviews, 0, '.', "'") ?></div>
                <div class="kpi-sub"><?= $label ?></div>
            </div>
            <div class="kpi-card">
                <div class="kpi-label"><span class="hint" title="Anzahl unterschiedlicher Besucher im Zeitraum">Unique Visitors</span></div>
                <div class="kpi-value"><?= number_format($uniqueVisitors, 0, '.', "'") ?></div>
                <div class="kpi-sub"><span class="hint" title="Besucher werden ohne Cookies anhand eines anonymen Browser-Fingerprints erkannt – datenschutzkonform, aber eine Schätzung">cookielos geschätzt</span></div>
            </div>
            <div class="kpi-card">
                <div class="kpi-label">Ø Verweildauer</div>
                <div class="kpi-value"><?= formatDuration($avgDuration) ?></div>
                <div class="kpi-sub">aktive Zeit</div>
            </div>
            <div class="kpi-card">
                <div class="kpi-label">Externe Klicks</div>
                <div class="kpi-value"><?= number_format($outboundClicks, 0, '.', "'") ?></div>
                <div class="kpi-sub">Outbound Links</div>
            </div>
        </div>

        <?php if ($cmsCount > 0): ?>
        <div class="cms-hint">
            <?= number_format($cmsCount, 0, '.', "'") ?> <span class="hint" title="Seitenaufrufe durch Redakteure im Clubdesk-Editor – in der Besucher-Ansicht ausgeblendet">CMS-Aufrufe</span> im gleichen Zeitraum –
            <a href="/?range=<?= $range ?>&view=cms">anzeigen &rarr;</a>
        </div>
        <?php endif; ?>

        <!-- Verlauf-Chart -->
        <div class="card">
            <div class="card-header">
                <h2 class="card-title">Verlauf – <?= htmlspecialchars($label) ?></h2>
            </div>
            <div class="chart-wrap">
                <canvas id="lineChart"></canvas>
            </div>
        </div>

        <!-- Zwei Spalten -->
        <div class="two-col">
            <!-- Top Seiten -->
            <div class="card">
                <div class="card-header">
                    <h2 class="card-title">Häufigste Seiten</h2>
                </div>
                <table class="data-table">
                    <thead>
                        <tr><th>Seite</th><th>Aufrufe</th><th>Besucher</th></tr>
                    </thead>
                    <tbody>
                        <?php if (empty($topPages)): ?>
                            <tr><td colspan="3" class="empty">Noch keine Daten</td></tr>
                        <?php else: ?>
                            <?php foreach ($topPages as $row): ?>
                                <?php
                                    // Seitentitel bereinigen: "Titel – Zurich Sailing" → "Titel"
                                    $title = $row['page_title'] ?? '';
                                    $title = preg_replace('/\s*[-–|]\s*Zurich Sailing.*$/i', '', $title);
                                    $title = trim($title);
                                ?>
                                <tr>
                                    <td>
                                        <?php if ($title !== ''): ?>
                                            <span class="url-path" title="<?= htmlspecialchars($row['url']) ?>">
                                                <?= htmlspecialchars($title) ?>
                                            </span>
                                            <span class="url-title"><?= htmlspecialchars(shortUrl($row['url'])) ?></span>
                                        <?php else: ?>
                                            <span class="url-path" title="<?= htmlspecialchars($row['url']) ?>">
                                                <?= htmlspecialchars(shortUrl($row['url'])) ?>
                                            </span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="num"><?= number_format((int)$row['views'], 0, '.', "'") ?></td>
                                    <td class="num"><?= number_format((int)$row['visitors'], 0, '.', "'") ?></td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>

            <!-- Top Referrer -->
            <div class="card">
                <div class="card-header">
                    <h2 class="card-title">Externe Quellen</h2>
                </div>
                <table class="data-table">
                    <thead>
                        <tr><th>Quelle</th><th>Besuche</th></tr>
                    </thead>
                    <tbody>
                        <?php if (empty($topRefs)): ?>
                            <tr><td colspan="2" class="empty">Noch keine Daten</td></tr>
                        <?php else: ?>
                            <?php foreach ($topRefs as $row): ?>
                                <tr>
                                    <td>
                                        <span class="url-path" title="<?= htmlspecialchars($row['referrer']) ?>">
                                            <?= htmlspecialchars(shortDomain($row['referrer'])) ?>
                                        </span>
                                    </td>
                                    <td class="num"><?= number_format((int)$row['cnt'], 0, '.', "'") ?></td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- Zwei Spalten -->
        <div class="two-col">
            <!-- Geräte -->
            <div class="card">
                <div class="card-header">
                    <h2 class="card-title">Geräte</h2>
                </div>
                <div class="device-chart-wrap">
                    <canvas id="deviceChart"></canvas>
                </div>
                <div class="device-legend">
                    <?php
                    $total = array_sum($deviceData) ?: 1;
                    $deviceColors = ['#0A2342', '#2196F3', '#64B5F6'];
                    $i = 0;
                    foreach ($devices as $row):
                        $pct = round($row['cnt'] / $total * 100);
                    ?>
                        <div class="legend-item">
                            <span class="legend-dot" style="background:<?= $deviceColors[$i % 3] ?>"></span>
                            <span><?= ucfirst(htmlspecialchars($row['device_type'])) ?></span>
                            <span class="legend-pct"><?= $pct ?>%</span>
                        </div>
                    <?php $i++; endforeach; ?>
                </div>
            </div>

            <!-- Externe Links -->
            <div class="card">
                <div class="card-header">
                    <h2 class="card-title">Externe Links (Klicks)</h2>
                </div>
                <table class="data-table">
                    <thead>
                        <tr><th>URL</th><th>Klicks</th></tr>
                    </thead>
                    <tbody>
                        <?php if (empty($outboundLinks)): ?>
                            <tr><td colspan="2" class="empty">Noch keine Daten</td></tr>
                        <?php else: ?>
                            <?php foreach ($outboundLinks as $row): ?>
                                <tr>
                                    <td>
                                        <a href="<?= htmlspecialchars($row['event_value']) ?>"
                                           target="_blank" rel="noopener noreferrer"
                                           class="url-path">
                                            <?= htmlspecialchars(shortDomain($row['event_value'])) ?>
                                        </a>
                                        <span class="url-title" title="<?= htmlspecialchars($row['event_value']) ?>">
                                            <?= htmlspecialchars(mb_substr(shortUrl($row['event_value']), 0, 40)) ?>
                                        </span>
                                    </td>
                                    <td class="num"><?= number_format((int)$row['clicks'], 0, '.', "'") ?></td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <?php if (!empty($topCountries)): ?>
        <!-- Top Länder -->
        <div class="card">
            <div class="card-header">
                <h2 class="card-title">Herkunft der Besucher</h2>
            </div>
            <table class="data-table">
                <thead>
                    <tr><th>Land</th><th>Besucher</th><th>Aufrufe</th></tr>
                </thead>
                <tbody>
                    <?php foreach ($topCountries as $row): ?>
                        <tr>
                            <td>
                                <span style="font-size:1.2em;margin-right:.4em"><?= countryFlag($row['country']) ?></span>
                                <?= htmlspecialchars($row['country']) ?>
                            </td>
                            <td class="num"><?= number_format((int)$row['visitors'], 0, '.', "'") ?></td>
                            <td class="num"><?= number_format((int)$row['views'], 0, '.', "'") ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php endif; ?>

    </main>

    <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
    <script src="/assets/dashboard.js"></script>
    <script>
        ZSDash.init(
            <?= json_encode($chartLabels) ?>,
            <?= json_encode($chartPV) ?>,
            <?= json_encode($chartUV) ?>,
            <?= json_encode($deviceLabels) ?>,
            <?= json_encode($deviceData) ?>
        );
    </script>
</body>
</html>
