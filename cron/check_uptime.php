<?php
declare(strict_types=1);

/**
 * Uptime-Monitor – läuft alle 15 Minuten via Cronjob.
 *
 * Cron-Eintrag auf cyon.ch (cPanel):
 *   Intervall: * /15 * * * *  (alle 15 Minuten, Leerzeichen vor /15 entfernen)
 *   Befehl:    /usr/local/bin/php /home/vabavoco/public_html/stats/cron/check_uptime.php
 *
 * PHP-Pfad prüfen via cPanel → Terminal: which php
 * Log-Ausgabe optional: >> /home/vabavoco/logs/uptime.log 2>&1
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit;
}

require_once __DIR__ . '/../src/Database.php';

// --- Konfiguration ---
$targets = [
    ['name' => 'zurich-sailing', 'url' => 'https://zurich-sailing.ch'],
    ['name' => 'clubdesk',       'url' => 'https://app.clubdesk.com'],
    ['name' => 'reference',      'url' => 'https://google.com'],
];

const TIMEOUT      = 10;
const MAX_REDIRECTS = 5;
const USER_AGENT   = 'ZurichSailing-Monitor/1.0';

// --- Checks ausführen ---
$checkTime = (new DateTimeImmutable('now', new DateTimeZone('Europe/Zurich')))->format('Y-m-d H:i:s');

try {
    $pdo = Database::get();

    foreach ($targets as $target) {
        $result = checkTarget($target['url']);
        saveResult($pdo, $checkTime, $target['name'], $target['url'], $result);
    }

    // Tägliche Bereinigung: Einträge älter als 90 Tage löschen (läuft nachts zwischen 03:00–03:59)
    if ((int)date('H') === 3) {
        $pdo->exec("DELETE FROM uptime_checks WHERE created_at < NOW() - INTERVAL 90 DAY");
    }
} catch (Throwable $e) {
    fwrite(STDERR, '[' . date('Y-m-d H:i:s') . '] Uptime-Monitor Fehler: ' . $e->getMessage() . PHP_EOL);
    exit(1);
}

// --- Funktionen ---

function checkTarget(string $url): array
{
    $host = parse_url($url, PHP_URL_HOST) ?: '';
    $resolved = $host ? gethostbyname($host) : null;
    // gethostbyname gibt den Hostnamen zurück wenn DNS fehlschlägt
    $resolvedIp = ($resolved !== $host) ? $resolved : null;

    // HEAD versuchen, bei HTTP 405 auf GET ausweichen
    $result = performRequest($url, 'HEAD');
    if ($result['error_type'] === 'method_not_allowed') {
        $result = performRequest($url, 'GET');
    }

    $result['resolved_ip'] = $resolvedIp;
    return $result;
}

function performRequest(string $url, string $method): array
{
    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL            => $url,
        CURLOPT_CUSTOMREQUEST  => $method,
        CURLOPT_NOBODY         => ($method === 'HEAD'),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => TIMEOUT,
        CURLOPT_CONNECTTIMEOUT => TIMEOUT,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS      => MAX_REDIRECTS,
        CURLOPT_USERAGENT      => USER_AGENT,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
        CURLOPT_HEADER         => false,
    ]);

    $startMs = (int)(microtime(true) * 1000);
    curl_exec($ch);
    $responseMs = (int)(microtime(true) * 1000) - $startMs;

    $httpStatus    = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $finalUrl      = curl_getinfo($ch, CURLINFO_EFFECTIVE_URL) ?: null;
    $redirectCount = (int)curl_getinfo($ch, CURLINFO_REDIRECT_COUNT);
    $curlErrno     = curl_errno($ch);
    $curlError     = curl_error($ch);
    curl_close($ch);

    if ($httpStatus === 405 && $method === 'HEAD') {
        return ['error_type' => 'method_not_allowed', 'method' => $method];
    }

    if ($curlErrno !== 0) {
        return [
            'method'           => $method,
            'http_status'      => null,
            'response_time_ms' => $responseMs,
            'success'          => false,
            'error_type'       => mapCurlError($curlErrno),
            'error_message'    => mb_substr($curlError, 0, 512),
            'redirect_count'   => $redirectCount,
            'final_url'        => null,
            'resolved_ip'      => null,
        ];
    }

    $success = $httpStatus >= 200 && $httpStatus <= 399;
    return [
        'method'           => $method,
        'http_status'      => $httpStatus ?: null,
        'response_time_ms' => $responseMs,
        'success'          => $success,
        'error_type'       => (!$success && $httpStatus >= 400) ? 'http_error' : null,
        'error_message'    => (!$success && $httpStatus >= 400) ? "HTTP {$httpStatus}" : null,
        'redirect_count'   => $redirectCount,
        'final_url'        => ($finalUrl && $finalUrl !== $url) ? mb_substr($finalUrl, 0, 2048) : null,
        'resolved_ip'      => null,
    ];
}

function mapCurlError(int $errno): string
{
    return match ($errno) {
        5, 6    => 'dns_error',
        7       => 'connection_refused',
        28      => 'timeout',
        35, 58, 59, 60 => 'ssl_error',
        default => 'connection_error',
    };
}

function saveResult(PDO $pdo, string $checkTime, string $name, string $url, array $r): void
{
    $stmt = $pdo->prepare(
        'INSERT INTO uptime_checks
         (check_time, target_name, target_url, request_method, http_status, response_time_ms,
          success, error_type, error_message, redirect_count, final_url, resolved_ip)
         VALUES (:ct, :tn, :tu, :rm, :hs, :rt, :s, :et, :em, :rc, :fu, :ri)'
    );
    $stmt->execute([
        ':ct' => $checkTime,
        ':tn' => $name,
        ':tu' => $url,
        ':rm' => $r['method'],
        ':hs' => $r['http_status'],
        ':rt' => $r['response_time_ms'],
        ':s'  => $r['success'] ? 1 : 0,
        ':et' => $r['error_type'],
        ':em' => $r['error_message'],
        ':rc' => $r['redirect_count'],
        ':fu' => $r['final_url'],
        ':ri' => $r['resolved_ip'],
    ]);
}
