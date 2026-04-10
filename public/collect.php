<?php
declare(strict_types=1);

require_once __DIR__ . '/../src/Database.php';

// --- CORS ---
$config  = require __DIR__ . '/../config/config.php';
$origin  = $_SERVER['HTTP_ORIGIN'] ?? '';
$allowed = $config['allowed_origins'] ?? [];

header('Vary: Origin');
if (in_array($origin, $allowed, true)) {
    header('Access-Control-Allow-Origin: ' . $origin);
}
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');
header('Content-Security-Policy: default-src \'none\'');
header('X-Content-Type-Options: nosniff');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    exit;
}

// --- Input lesen ---
$raw  = $_POST['d'] ?? '';
if ($raw === '') {
    http_response_code(400);
    exit;
}

$data = json_decode($raw, true);
if (!is_array($data) || empty($data['type'])) {
    http_response_code(400);
    exit;
}

// --- Fingerprint (cookielos, IP wird NICHT gespeichert) ---
function makeFingerprint(array $config): string
{
    $ip   = $_SERVER['REMOTE_ADDR'] ?? '';
    $ua   = $_SERVER['HTTP_USER_AGENT'] ?? '';
    $lang = $_SERVER['HTTP_ACCEPT_LANGUAGE'] ?? '';
    $date = date('Y-m-d');
    return hash('sha256', $ip . '|' . $ua . '|' . $lang . '|' . $date . '|' . $config['salt']);
}

function getCountryCode(): ?string
{
    // Methode 1: Cloudflare-Header (falls Traffic über Cloudflare läuft)
    $cf = strtoupper(trim($_SERVER['HTTP_CF_IPCOUNTRY'] ?? ''));
    if ($cf !== '' && $cf !== 'XX' && preg_match('/^[A-Z]{2}$/', $cf)) {
        return $cf;
    }
    // Methode 2: PHP GeoIP-Extension (falls auf dem Server installiert)
    if (function_exists('geoip_country_code_by_name')) {
        $ip   = $_SERVER['REMOTE_ADDR'] ?? '';
        $code = @geoip_country_code_by_name($ip);
        if ($code !== false && $code !== '') {
            return strtoupper($code);
        }
    }
    // Methode 3: ip-api.com (kostenlos, kein Key, nur für öffentliche IPs)
    $ip = $_SERVER['REMOTE_ADDR'] ?? '';
    if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
        $ctx  = stream_context_create(['http' => ['timeout' => 2]]);
        $json = @file_get_contents('https://ip-api.com/json/' . $ip . '?fields=countryCode', false, $ctx);
        if ($json !== false) {
            $data = json_decode($json, true);
            $code = strtoupper($data['countryCode'] ?? '');
            if (preg_match('/^[A-Z]{2}$/', $code)) {
                return $code;
            }
        }
    }
    return null;
}

function sanitizeUrl(string $url): string
{
    $url = filter_var($url, FILTER_SANITIZE_URL);
    return mb_substr((string)$url, 0, 2048);
}

function sanitizeStr(string $s, int $max = 512): string
{
    return mb_substr(strip_tags($s), 0, $max);
}

function normalizePageUrl(string $url): array
{
    $host  = parse_url($url, PHP_URL_HOST) ?? '';
    $path  = parse_url($url, PHP_URL_PATH) ?? '/';
    $query = parse_url($url, PHP_URL_QUERY);

    // CMS-Editor-URLs unveränderlich lassen
    if ($host === 'app.clubdesk.com') return [$url, $host];

    // Clubdesk-Subdomain: erste Pfadkomponente (Site-ID) entfernen
    // z.B. /myclub-abc123/foo → /foo
    if (str_ends_with($host, '.clubdesk.com')) {
        $path = preg_replace('#^/[^/]+#', '', $path) ?: '/';
    }

    // /willkommen ist dieselbe Seite wie /
    if ($path === '/willkommen' || $path === '/willkommen/') {
        $path = '/';
    }

    // ?c= Parameter entfernen (Clubdesk Kontext-ID, nicht relevant für Analytics)
    if ($query !== null) {
        parse_str($query, $params);
        unset($params['c']);
        $query = $params ? http_build_query($params) : null;
    }

    $normalized = ($path ?: '/') . ($query ? '?' . $query : '');
    return [$normalized, $host];
}

// --- Verarbeitung ---
try {
    $pdo         = Database::get();
    $fingerprint = makeFingerprint($config);
    $type        = $data['type'];

    if ($type === 'pageview') {
        $viewId  = sanitizeStr($data['view_id'] ?? '', 36);
        $url     = sanitizeUrl($data['url'] ?? '');
        $title   = sanitizeStr($data['title'] ?? '');
        $ref     = sanitizeUrl($data['ref'] ?? '');
        $device  = in_array($data['device'] ?? '', ['desktop', 'mobile', 'tablet'], true)
                   ? $data['device'] : 'desktop';
        $width   = is_numeric($data['width'] ?? '') ? (int)$data['width'] : null;
        $lang    = sanitizeStr($data['lang'] ?? '', 32);

        [$url, $urlHost] = normalizePageUrl($url);
        $country = getCountryCode();
        $isCms   = str_contains($url, 'app.clubdesk.com') ? 1 : 0;

        $stmt = $pdo->prepare(
            'INSERT INTO pageviews (view_id, fingerprint, url, host, page_title, referrer, device_type, screen_width, lang, country, is_cms)
             VALUES (:view_id, :fp, :url, :host, :title, :ref, :device, :width, :lang, :country, :is_cms)'
        );
        $stmt->execute([
            ':view_id'  => $viewId,
            ':fp'       => $fingerprint,
            ':url'      => $url,
            ':host'     => $urlHost,
            ':title'    => $title,
            ':ref'      => $ref,
            ':device'   => $device,
            ':width'    => $width,
            ':lang'     => $lang,
            ':country'  => $country,
            ':is_cms'   => $isCms,
        ]);

    } elseif ($type === 'duration') {
        $viewId   = sanitizeStr($data['view_id'] ?? '', 36);
        $duration = is_numeric($data['duration'] ?? '') ? (int)$data['duration'] : 0;
        if ($viewId !== '' && $duration > 0 && $duration < 3600) {
            $stmt = $pdo->prepare(
                'UPDATE pageviews SET duration = :dur
                 WHERE view_id = :view_id AND duration IS NULL LIMIT 1'
            );
            $stmt->execute([':dur' => $duration, ':view_id' => $viewId]);
        }

    } elseif ($type === 'event') {
        $event = sanitizeStr($data['event'] ?? '', 64);
        $value = sanitizeUrl($data['value'] ?? '');
        $url   = sanitizeUrl($data['url'] ?? '');
        if ($event !== '') {
            $stmt = $pdo->prepare(
                'INSERT INTO events (fingerprint, event_type, event_value, url)
                 VALUES (:fp, :event, :value, :url)'
            );
            $stmt->execute([
                ':fp'    => $fingerprint,
                ':event' => $event,
                ':value' => $value,
                ':url'   => $url,
            ]);
        }
    }

    http_response_code(204);
} catch (PDOException $e) {
    http_response_code(500);
}
