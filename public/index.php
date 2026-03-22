<?php
declare(strict_types=1);
ini_set('display_errors', '1');
error_reporting(E_ALL);

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
$pdo = Database::get();

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

$totalPageviews   = (int) qVal($pdo, 'SELECT COUNT(*) FROM pageviews WHERE created_at BETWEEN :s AND :e', $p);
$uniqueVisitors   = (int) qVal($pdo, 'SELECT COUNT(DISTINCT fingerprint) FROM pageviews WHERE created_at BETWEEN :s AND :e', $p);
$avgDuration      = (float) qVal($pdo, 'SELECT AVG(duration) FROM pageviews WHERE created_at BETWEEN :s AND :e AND duration > 0 AND duration < 3600', $p);
$outboundClicks   = (int) qVal($pdo, "SELECT COUNT(*) FROM events WHERE created_at BETWEEN :s AND :e AND event_type = 'outbound_link'", $p);

// Tägliche Übersicht für Chart
$dailyRows = q($pdo,
    'SELECT DATE(created_at) as d, COUNT(*) as pv, COUNT(DISTINCT fingerprint) as uv
     FROM pageviews WHERE created_at BETWEEN :s AND :e
     GROUP BY DATE(created_at) ORDER BY d ASC', $p);

// Top-Seiten (Pfad ohne Domain anzeigen)
$topPages = q($pdo,
    'SELECT url, page_title, COUNT(*) as views, COUNT(DISTINCT fingerprint) as visitors
     FROM pageviews WHERE created_at BETWEEN :s AND :e
     GROUP BY url, page_title ORDER BY views DESC LIMIT 15', $p);

// Top-Referrer (nur externe)
$topRefs = q($pdo,
    "SELECT referrer, COUNT(*) as cnt FROM pageviews
     WHERE created_at BETWEEN :s AND :e
     AND referrer != '' AND referrer IS NOT NULL
     AND referrer NOT LIKE '%zurich-sailing.ch%'
     GROUP BY referrer ORDER BY cnt DESC LIMIT 10", $p);

// Geräte
$devices = q($pdo,
    'SELECT device_type, COUNT(*) as cnt FROM pageviews
     WHERE created_at BETWEEN :s AND :e
     GROUP BY device_type ORDER BY cnt DESC', $p);

// Externe Links
$outboundLinks = q($pdo,
    "SELECT event_value, COUNT(*) as clicks FROM events
     WHERE created_at BETWEEN :s AND :e AND event_type = 'outbound_link'
     GROUP BY event_value ORDER BY clicks DESC LIMIT 10", $p);

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
                <span class="topbar-title">ZS Analytics</span>
                <span class="topbar-sub">zurich-sailing.ch</span>
            </div>
        </div>
        <nav class="range-nav">
            <?php foreach (['today' => 'Heute', '7d' => '7 Tage', '30d' => '30 Tage', 'month' => 'Monat', 'lastmonth' => 'Vormonat'] as $key => $lbl): ?>
                <a href="/?range=<?= $key ?>" class="range-btn <?= $range === $key ? 'active' : '' ?>">
                    <?= $lbl ?>
                </a>
            <?php endforeach; ?>
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
                <div class="kpi-label">Unique Visitors</div>
                <div class="kpi-value"><?= number_format($uniqueVisitors, 0, '.', "'") ?></div>
                <div class="kpi-sub">cookielos geschätzt</div>
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
                                <tr>
                                    <td>
                                        <span class="url-path" title="<?= htmlspecialchars($row['url']) ?>">
                                            <?= htmlspecialchars(shortUrl($row['url'])) ?>
                                        </span>
                                        <?php if (!empty($row['page_title'])): ?>
                                            <span class="url-title"><?= htmlspecialchars($row['page_title']) ?></span>
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
