<?php
declare(strict_types=1);

/**
 * Social – Like- und Stats-Logik für das Social Widget.
 *
 * Designprinzipien:
 * - Nutzt denselben `salt` und dieselbe `Database`-Verbindung wie der Tracker
 * - View-Counts kommen aus der bestehenden `pageviews`-Tabelle (kein Duplikat)
 * - URL-Normalisierung identisch zu collect.php (gleiche Funktion, hier als Methode)
 * - Keine globalen Funktionen, kein State ausserhalb der Klasse
 */
class Social
{
    // -------------------------------------------------------------------------
    // Hashing
    // -------------------------------------------------------------------------

    /**
     * SHA256 der normalisierten URL – Primärschlüssel für alle Social-Tabellen.
     */
    public static function hashUrl(string $url): string
    {
        [$normalized] = self::normalizePageUrl($url);
        return hash('sha256', strtolower($normalized));
    }

    /**
     * SHA256(salt + IP) – identische Methode wie makeFingerprint() in collect.php,
     * jedoch ohne User-Agent und Datum: IP-Hash ist dauerhaft (kein täglicher Wechsel),
     * damit ein Like nicht täglich zurückgesetzt wird.
     */
    public static function hashIp(string $salt): string
    {
        $ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
        return hash('sha256', $salt . '|' . $ip);
    }

    // -------------------------------------------------------------------------
    // Stats lesen
    // -------------------------------------------------------------------------

    /**
     * Gibt View-Count (aus pageviews), Like-Count (aus social_stats) und
     * ob der aktuelle Besucher bereits geliked hat zurück.
     *
     * @return array{views: int, likes: int, views_fmt: string, likes_fmt: string, user_liked: bool}
     */
    public static function getStats(PDO $pdo, string $urlHash, string $ipHash): array
    {
        // View-Count: direkt aus pageviews (kein Duplikat, kein Drift)
        // Normalisierte URL muss mit der in collect.php übereinstimmen.
        $viewStmt = $pdo->prepare(
            'SELECT COUNT(*) FROM pageviews
             WHERE url = (
                 SELECT url FROM pageviews WHERE SHA2(url, 256) = :hash AND is_cms = 0 LIMIT 1
             ) AND is_cms = 0'
        );
        $viewStmt->execute([':hash' => $urlHash]);
        $views = (int) $viewStmt->fetchColumn();

        // Like-Count aus Aggregat-Cache
        $likeStmt = $pdo->prepare(
            'SELECT like_count FROM social_stats WHERE url_hash = :hash LIMIT 1'
        );
        $likeStmt->execute([':hash' => $urlHash]);
        $likes = (int) ($likeStmt->fetchColumn() ?: 0);

        return [
            'views'      => $views,
            'likes'      => $likes,
            'views_fmt'  => self::formatNumber($views),
            'likes_fmt'  => self::formatNumber($likes),
            'user_liked' => self::hasLiked($pdo, $urlHash, $ipHash),
        ];
    }

    /**
     * Prüft ob die aktuelle IP diese URL bereits geliked hat.
     */
    public static function hasLiked(PDO $pdo, string $urlHash, string $ipHash): bool
    {
        $stmt = $pdo->prepare(
            'SELECT 1 FROM social_likes
             WHERE url_hash = :hash AND ip_hash = :ip LIMIT 1'
        );
        $stmt->execute([':hash' => $urlHash, ':ip' => $ipHash]);
        return (bool) $stmt->fetch();
    }

    // -------------------------------------------------------------------------
    // Like togglen
    // -------------------------------------------------------------------------

    /**
     * Toggled den Like-Status der aktuellen IP für die gegebene URL.
     * Gibt true zurück wenn der neue Zustand = geliked, false = nicht geliked.
     */
    public static function toggleLike(
        PDO    $pdo,
        string $urlHash,
        string $url,
        string $ipHash
    ): bool {
        $alreadyLiked = self::hasLiked($pdo, $urlHash, $ipHash);

        if ($alreadyLiked) {
            // Unlike
            $pdo->prepare(
                'DELETE FROM social_likes WHERE url_hash = :hash AND ip_hash = :ip'
            )->execute([':hash' => $urlHash, ':ip' => $ipHash]);

            $pdo->prepare(
                'UPDATE social_stats
                 SET like_count = GREATEST(0, like_count - 1)
                 WHERE url_hash = :hash'
            )->execute([':hash' => $urlHash]);

            return false;
        }

        // Like
        $pdo->prepare(
            'INSERT IGNORE INTO social_likes (url_hash, url, ip_hash) VALUES (:hash, :url, :ip)'
        )->execute([':hash' => $urlHash, ':url' => $url, ':ip' => $ipHash]);

        $pdo->prepare(
            'INSERT INTO social_stats (url_hash, url, like_count)
             VALUES (:hash, :url, 1)
             ON DUPLICATE KEY UPDATE like_count = like_count + 1, url = VALUES(url)'
        )->execute([':hash' => $urlHash, ':url' => $url]);

        return true;
    }

    // -------------------------------------------------------------------------
    // URL-Normalisierung (identisch zu collect.php, als Klassenmethode)
    // -------------------------------------------------------------------------

    /**
     * Normalisiert eine URL exakt wie normalizePageUrl() in collect.php.
     * Muss synchron gehalten werden wenn collect.php geändert wird.
     *
     * @return array{0: string, 1: string} [$normalizedPath, $host]
     */
    public static function normalizePageUrl(string $url): array
    {
        $host  = parse_url($url, PHP_URL_HOST) ?? '';
        $path  = parse_url($url, PHP_URL_PATH) ?? '/';
        $query = parse_url($url, PHP_URL_QUERY);

        // CMS-Editor-URLs unveränderlich lassen
        if ($host === 'app.clubdesk.com') {
            return [$url, $host];
        }

        // Clubdesk-Subdomain: erste Pfadkomponente (Site-ID) entfernen
        if (str_ends_with($host, '.clubdesk.com')) {
            $path = preg_replace('#^/[^/]+#', '', $path) ?: '/';
        }

        // /willkommen ist dieselbe Seite wie /
        if ($path === '/willkommen' || $path === '/willkommen/') {
            $path = '/';
        }

        // Tracking-Parameter entfernen
        if ($query !== null) {
            parse_str($query, $params);
            unset($params['c'], $params['b'], $params['s'], $params['rfb']);
            $query = $params ? http_build_query($params) : null;
        }

        $normalized = ($path ?: '/') . ($query ? '?' . $query : '');
        return [$normalized, $host];
    }

    // -------------------------------------------------------------------------
    // Formatierung
    // -------------------------------------------------------------------------

    /**
     * Schweizer Tausendertrennzeichen (Apostroph): 1'204
     */
    public static function formatNumber(int $n): string
    {
        return number_format($n, 0, '.', '\'');
    }

    // -------------------------------------------------------------------------
    // HTTP-Hilfsfunktionen
    // -------------------------------------------------------------------------

    /**
     * CORS-Header setzen – nutzt allowed_origins aus config.php.
     */
    public static function setCorsHeaders(array $config): void
    {
        $origin  = $_SERVER['HTTP_ORIGIN'] ?? '';
        $allowed = $config['allowed_origins'] ?? [];

        header('Vary: Origin');
        if (in_array($origin, $allowed, true)) {
            header('Access-Control-Allow-Origin: ' . $origin);
        }
        header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
        header('Access-Control-Allow-Headers: Content-Type');

        if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
            http_response_code(204);
            exit;
        }
    }

    /**
     * JSON-Antwort senden und Script beenden.
     *
     * @param array<string, mixed> $data
     */
    public static function jsonResponse(array $data, int $status = 200): never
    {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        header('X-Content-Type-Options: nosniff');
        echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        exit;
    }
}
