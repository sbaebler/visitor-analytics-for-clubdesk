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

// --- Uptime-Monitoring (graceful degradation) ---
$hasUptimeTable  = false;
$uptimeTargets   = $config['uptime_targets'] ?? [];
$mainTargets     = array_values(array_filter($uptimeTargets, fn($t) => !($t['reference'] ?? false)));
$mainTargetNames = array_column($mainTargets, 'name');

$uptimeStatus      = [];
$uptimeStats       = [];
$uptimeErrors      = [];
$uptimeChartLabels = [];
$uptimeChartData   = [];
$uptimeCorrelation = false;

try {
    $hasUptimeTable = (bool) $pdo->query("SHOW TABLES LIKE 'uptime_checks'")->fetch();
} catch (PDOException $e) {}

if ($hasUptimeTable && !empty($uptimeTargets)) {
    // Aktueller Status je Target (letzter Eintrag pro Target)
    $statusRows = q($pdo,
        "SELECT uc.target_name, uc.success, uc.http_status, uc.response_time_ms,
                uc.check_time, uc.error_type, uc.error_message
         FROM uptime_checks uc
         INNER JOIN (
             SELECT target_name, MAX(check_time) AS max_time
             FROM uptime_checks GROUP BY target_name
         ) latest ON uc.target_name = latest.target_name AND uc.check_time = latest.max_time"
    );
    foreach ($statusRows as $row) {
        $uptimeStatus[$row['target_name']] = $row;
    }

    if (!empty($mainTargetNames)) {
        $ph = implode(',', array_fill(0, count($mainTargetNames), '?'));

        // Uptime-Statistik (24h / 7d / 30d)
        $statsRows = q($pdo,
            "SELECT target_name,
                    SUM(CASE WHEN check_time >= NOW() - INTERVAL 1 DAY THEN 1 ELSE 0 END)                 AS total_24h,
                    SUM(CASE WHEN check_time >= NOW() - INTERVAL 1 DAY AND success = 1 THEN 1 ELSE 0 END) AS ok_24h,
                    SUM(CASE WHEN check_time >= NOW() - INTERVAL 7 DAY THEN 1 ELSE 0 END)                 AS total_7d,
                    SUM(CASE WHEN check_time >= NOW() - INTERVAL 7 DAY AND success = 1 THEN 1 ELSE 0 END) AS ok_7d,
                    COUNT(*)                                                                                AS total_30d,
                    SUM(success)                                                                            AS ok_30d
             FROM uptime_checks
             WHERE target_name IN ({$ph})
               AND check_time >= NOW() - INTERVAL 30 DAY
             GROUP BY target_name",
            $mainTargetNames
        );
        foreach ($statsRows as $row) {
            $n = $row['target_name'];
            $uptimeStats[$n]['24h'] = $row['total_24h'] > 0 ? round($row['ok_24h'] / $row['total_24h'] * 100, 1) : null;
            $uptimeStats[$n]['7d']  = $row['total_7d']  > 0 ? round($row['ok_7d']  / $row['total_7d']  * 100, 1) : null;
            $uptimeStats[$n]['30d'] = $row['total_30d'] > 0 ? round($row['ok_30d'] / $row['total_30d'] * 100, 1) : null;
        }

        // Korrelation: gleichzeitige Ausfälle mehrerer Hauptziele (letzten 30 Tage)
        if (count($mainTargetNames) >= 2) {
            $uptimeCorrelation = (bool) qVal($pdo,
                "SELECT COUNT(*) FROM (
                    SELECT check_time FROM uptime_checks
                    WHERE target_name IN ({$ph}) AND success = 0
                      AND check_time >= NOW() - INTERVAL 30 DAY
                    GROUP BY check_time HAVING COUNT(DISTINCT target_name) >= 2
                ) sub",
                $mainTargetNames
            );
        }

        // Antwortzeit-Chart: stündliche Durchschnitte, letzte 24h
        $chartRows = q($pdo,
            "SELECT target_name,
                    DATE_FORMAT(check_time, '%Y-%m-%d %H') AS hour_key,
                    DATE_FORMAT(check_time, '%H:00')        AS hour_label,
                    ROUND(AVG(response_time_ms))             AS avg_ms
             FROM uptime_checks
             WHERE target_name IN ({$ph})
               AND check_time >= NOW() - INTERVAL 24 HOUR
               AND success = 1 AND response_time_ms IS NOT NULL
             GROUP BY target_name, DATE_FORMAT(check_time, '%Y-%m-%d %H')
             ORDER BY MIN(check_time) ASC",
            $mainTargetNames
        );
        $rawChart = [];
        $hourMap  = [];
        foreach ($chartRows as $row) {
            $rawChart[$row['target_name']][$row['hour_key']] = (int)$row['avg_ms'];
            $hourMap[$row['hour_key']] = $row['hour_label'];
        }
        ksort($hourMap);
        $sortedKeys        = array_keys($hourMap);
        $uptimeChartLabels = array_values($hourMap);
        foreach ($mainTargets as $t) {
            $uptimeChartData[] = array_map(fn($k) => $rawChart[$t['name']][$k] ?? null, $sortedKeys);
        }
    }

    // Letzte 10 Fehler
    $uptimeErrors = q($pdo,
        "SELECT check_time, target_name, error_type, http_status, error_message
         FROM uptime_checks WHERE success = 0
         ORDER BY check_time DESC LIMIT 10"
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

function uptimeLabel(array $t): string
{
    return $t['label'] ?? preg_replace('#^https?://#', '', $t['url']);
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

        <?php if ($hasUptimeTable && !empty($uptimeTargets)): ?>
        <!-- Verfügbarkeit -->
        <div class="card">
            <div class="card-header">
                <h2 class="card-title">Verfügbarkeit</h2>
            </div>

            <!-- Aktueller Status -->
            <div class="uptime-status-grid">
                <?php foreach ($uptimeTargets as $t): ?>
                <?php
                    $s = $uptimeStatus[$t['name']] ?? null;
                    $timeAgo = '';
                    if ($s) {
                        $diff = $now->getTimestamp() - (new DateTimeImmutable($s['check_time'], $tz))->getTimestamp();
                        if ($diff < 120)      $timeAgo = 'vor ' . $diff . ' Sek.';
                        elseif ($diff < 3600) $timeAgo = 'vor ' . floor($diff / 60) . ' Min.';
                        else                  $timeAgo = 'vor ' . floor($diff / 3600) . ' Std.';
                    }
                ?>
                <div class="uptime-status-card">
                    <div class="uptime-target-name"><?= htmlspecialchars(uptimeLabel($t)) ?></div>
                    <?php if ($s === null): ?>
                        <div class="uptime-badge uptime-unknown">Keine Daten</div>
                    <?php elseif ($s['success']): ?>
                        <div class="uptime-badge uptime-up">Erreichbar</div>
                    <?php else: ?>
                        <div class="uptime-badge uptime-down">Gestört</div>
                    <?php endif; ?>
                    <?php if ($s): ?>
                    <div class="uptime-meta">
                        <?php if ($s['response_time_ms'] !== null): ?>
                            <?= (int)$s['response_time_ms'] ?> ms<?php if ($s['http_status']): ?> · HTTP <?= (int)$s['http_status'] ?><?php endif; ?>
                        <?php elseif ($s['error_type']): ?>
                            <?= htmlspecialchars($s['error_type']) ?>
                        <?php endif; ?>
                    </div>
                    <div class="uptime-time"><?= htmlspecialchars($timeAgo) ?></div>
                    <?php endif; ?>
                </div>
                <?php endforeach; ?>
            </div>

            <!-- Uptime-Statistik (nur Hauptziele) -->
            <table class="data-table">
                <thead>
                    <tr><th>Ziel</th><th>24 Std.</th><th>7 Tage</th><th>30 Tage</th></tr>
                </thead>
                <tbody>
                    <?php foreach ($mainTargets as $t): ?>
                    <tr>
                        <td><?= htmlspecialchars(uptimeLabel($t)) ?></td>
                        <?php foreach (['24h', '7d', '30d'] as $period): ?>
                            <?php $pct = $uptimeStats[$t['name']][$period] ?? null; ?>
                            <td class="num <?= $pct === null ? '' : ($pct >= 99 ? 'uptime-ok' : ($pct >= 90 ? 'uptime-warn' : 'uptime-err')) ?>">
                                <?= $pct !== null ? number_format($pct, 1) . '%' : '–' ?>
                            </td>
                        <?php endforeach; ?>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>

            <?php if ($uptimeCorrelation): ?>
            <div class="uptime-correlation-hint">
                Gemeinsame Ausfälle erkannt: mehrere überwachte Ziele waren in den letzten 30 Tagen gleichzeitig nicht erreichbar.
            </div>
            <?php endif; ?>
        </div>

        <!-- Antwortzeiten-Chart -->
        <div class="card">
            <div class="card-header">
                <h2 class="card-title">Antwortzeiten – letzte 24 Stunden</h2>
            </div>
            <?php if (count($uptimeChartLabels) >= 2): ?>
            <div class="chart-wrap">
                <canvas id="uptimeChart"></canvas>
            </div>
            <?php else: ?>
            <p class="uptime-no-data">Noch zu wenig Daten – wird nach einigen Messungen angezeigt.</p>
            <?php endif; ?>
        </div>

        <!-- Letzte Fehler -->
        <?php if (!empty($uptimeErrors)): ?>
        <div class="card">
            <div class="card-header">
                <h2 class="card-title">Letzte Fehler</h2>
            </div>
            <table class="data-table">
                <thead>
                    <tr><th>Zeitpunkt</th><th>Ziel</th><th>Fehler</th><th>HTTP</th></tr>
                </thead>
                <tbody>
                    <?php foreach ($uptimeErrors as $err): ?>
                    <tr>
                        <td><?= htmlspecialchars($err['check_time']) ?></td>
                        <?php $errLabel = uptimeLabel(array_values(array_filter($uptimeTargets, fn($t) => $t['name'] === $err['target_name']))[0] ?? ['url' => $err['target_name']]); ?>
                        <td><?= htmlspecialchars($errLabel) ?></td>
                        <td><?= htmlspecialchars($err['error_type'] ?? $err['error_message'] ?? '–') ?></td>
                        <td class="num"><?= $err['http_status'] ? (int)$err['http_status'] : '–' ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php endif; ?>

        <?php endif; // hasUptimeTable ?>

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
        <?php if ($hasUptimeTable && count($uptimeChartLabels) >= 2): ?>
        ZSDash.initUptime(
            <?= json_encode($uptimeChartLabels) ?>,
            <?= json_encode($uptimeChartData) ?>,
            <?= json_encode(array_map('uptimeLabel', $mainTargets)) ?>
        );
        <?php endif; ?>
    </script>
</body>
</html>
