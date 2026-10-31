<?php
declare(strict_types=1);

/**
 * Zählende Weiterleitungs-Links: stats.example.com/go/<slug> zählt den Aufruf
 * und leitet auf target_url weiter (z.B. einen Clubdesk-fileservlet-Link, der
 * selbst keine Download-Zahlen liefert).
 *
 * Tabellen: short_links, short_link_clicks (setup/migrate_links.sql).
 * Spec: docs/links.md
 */
class ShortLinks
{
    public const SLUG_PATTERN = '/^[a-z0-9](?:[a-z0-9-]{0,62}[a-z0-9])?$/';

    /**
     * User-Agents von Crawlern und Link-Vorschauen. WhatsApp, iMessage, Slack,
     * Teams/Outlook usw. rufen einen geteilten Link selbst ab, um die Vorschau
     * zu bauen – das ist kein Klick eines Menschen.
     * iMessage meldet sich als "facebookexternalhit … Twitterbot".
     */
    private const BOT_PATTERN = '/bot|crawl|spider|slurp|preview|facebookexternalhit|whatsapp|telegram|'
        . 'skypeuripreview|microsoft office|outlook|bingpreview|google-read-aloud|headless|'
        . 'curl|wget|python|go-http|okhttp|java\/|libwww|httpclient|axios|node-fetch|scan|check|monitor/i';

    public static function isValidSlug(string $slug): bool
    {
        return (bool) preg_match(self::SLUG_PATTERN, $slug);
    }

    public static function isValidTarget(string $url): bool
    {
        if (strlen($url) > 2048 || !filter_var($url, FILTER_VALIDATE_URL)) {
            return false;
        }
        $scheme = strtolower((string) parse_url($url, PHP_URL_SCHEME));
        return in_array($scheme, ['http', 'https'], true);
    }

    /** Zufälliger, gut lesbarer Slug (ohne 0/o/1/l). */
    public static function randomSlug(int $len = 6): string
    {
        $chars = 'abcdefghijkmnpqrstuvwxyz23456789';
        $s = '';
        for ($i = 0; $i < $len; $i++) {
            $s .= $chars[random_int(0, strlen($chars) - 1)];
        }
        return $s;
    }

    public static function isBot(string $ua): bool
    {
        return trim($ua) === '' || (bool) preg_match(self::BOT_PATTERN, $ua);
    }

    public static function deviceType(string $ua): string
    {
        if (preg_match('/ipad|tablet|kindle|silk|playbook|(android(?!.*mobile))/i', $ua)) {
            return 'tablet';
        }
        if (preg_match('/mobi|iphone|ipod|android|blackberry|opera mini|iemobile/i', $ua)) {
            return 'mobile';
        }
        return 'desktop';
    }

    public static function referrerHost(string $referrer): ?string
    {
        $host = parse_url($referrer, PHP_URL_HOST);
        if (!is_string($host) || $host === '') {
            return null;
        }
        return substr(strtolower(preg_replace('/^www\./i', '', $host)), 0, 253);
    }

    // -------------------------------------------------------------------------
    // Weiterleitung
    // -------------------------------------------------------------------------

    /** @return array{id:int,target_url:string}|null  Nur aktive Links. */
    public static function findActive(PDO $pdo, string $slug): ?array
    {
        $stmt = $pdo->prepare('SELECT id, target_url FROM short_links WHERE slug = ? AND is_active = 1');
        $stmt->execute([$slug]);
        $row = $stmt->fetch();
        return $row ? ['id' => (int) $row['id'], 'target_url' => (string) $row['target_url']] : null;
    }

    public static function recordClick(PDO $pdo, int $linkId, string $salt): void
    {
        $ua  = (string) ($_SERVER['HTTP_USER_AGENT'] ?? '');
        $ip  = (string) ($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0');
        $pdo->prepare(
            'INSERT INTO short_link_clicks (link_id, ip_hash, referrer_host, device_type, is_bot)
             VALUES (?, ?, ?, ?, ?)'
        )->execute([
            $linkId,
            hash('sha256', $salt . '|' . $ip),
            self::referrerHost((string) ($_SERVER['HTTP_REFERER'] ?? '')),
            self::deviceType($ua),
            self::isBot($ua) ? 1 : 0,
        ]);
    }

    // -------------------------------------------------------------------------
    // Verwaltung
    // -------------------------------------------------------------------------

    public static function slugExists(PDO $pdo, string $slug, ?int $exceptId = null): bool
    {
        $stmt = $pdo->prepare('SELECT id FROM short_links WHERE slug = ? AND id <> ?');
        $stmt->execute([$slug, $exceptId ?? 0]);
        return (bool) $stmt->fetch();
    }

    public static function create(PDO $pdo, string $slug, string $target, string $label): int
    {
        $pdo->prepare('INSERT INTO short_links (slug, target_url, label) VALUES (?, ?, ?)')
            ->execute([$slug, $target, $label]);
        return (int) $pdo->lastInsertId();
    }

    public static function update(PDO $pdo, int $id, string $target, string $label, bool $active): void
    {
        $pdo->prepare('UPDATE short_links SET target_url = ?, label = ?, is_active = ? WHERE id = ?')
            ->execute([$target, $label, $active ? 1 : 0, $id]);
    }

    public static function find(PDO $pdo, int $id): ?array
    {
        $stmt = $pdo->prepare('SELECT * FROM short_links WHERE id = ?');
        $stmt->execute([$id]);
        return $stmt->fetch() ?: null;
    }

    // -------------------------------------------------------------------------
    // Auswertung (Bots immer ausgeblendet)
    // -------------------------------------------------------------------------

    /** Alle Links mit Klickzahlen, neueste zuerst. */
    public static function overview(PDO $pdo): array
    {
        return $pdo->query(
            "SELECT l.id, l.slug, l.target_url, l.label, l.is_active, l.created_at,
                    COALESCE(SUM(c.is_bot = 0), 0)                                              AS clicks,
                    COUNT(DISTINCT CASE WHEN c.is_bot = 0 THEN c.ip_hash END)                     AS uniques,
                    COALESCE(SUM(c.is_bot = 0 AND c.clicked_at >= NOW() - INTERVAL 7 DAY), 0)    AS clicks_7d,
                    COALESCE(SUM(c.is_bot = 1), 0)                                              AS bot_hits,
                    MAX(CASE WHEN c.is_bot = 0 THEN c.clicked_at END)                             AS last_click
               FROM short_links l
               LEFT JOIN short_link_clicks c ON c.link_id = l.id
              GROUP BY l.id
              ORDER BY l.is_active DESC, l.created_at DESC"
        )->fetchAll();
    }

    /** Klicks pro Tag der letzten $days Tage, lückenlos (Tage ohne Klick = 0). */
    public static function daily(PDO $pdo, int $linkId, int $days = 60): array
    {
        $stmt = $pdo->prepare(
            'SELECT DATE(clicked_at) AS d, COUNT(*) AS n
               FROM short_link_clicks
              WHERE link_id = ? AND is_bot = 0 AND clicked_at >= CURDATE() - INTERVAL ? DAY
              GROUP BY d'
        );
        $stmt->execute([$linkId, $days - 1]);
        $byDay = array_column($stmt->fetchAll(), 'n', 'd');

        $out = [];
        for ($i = $days - 1; $i >= 0; $i--) {
            $d = date('Y-m-d', strtotime("-{$i} days"));
            $out[$d] = (int) ($byDay[$d] ?? 0);
        }
        return $out;
    }

    public static function devices(PDO $pdo, int $linkId): array
    {
        $stmt = $pdo->prepare(
            'SELECT device_type, COUNT(*) AS n FROM short_link_clicks
              WHERE link_id = ? AND is_bot = 0 GROUP BY device_type ORDER BY n DESC'
        );
        $stmt->execute([$linkId]);
        return array_map('intval', array_column($stmt->fetchAll(), 'n', 'device_type'));
    }

    public static function referrers(PDO $pdo, int $linkId, int $limit = 10): array
    {
        $stmt = $pdo->prepare(
            "SELECT COALESCE(referrer_host, '') AS host, COUNT(*) AS n FROM short_link_clicks
              WHERE link_id = ? AND is_bot = 0 GROUP BY host ORDER BY n DESC LIMIT " . (int) $limit
        );
        $stmt->execute([$linkId]);
        return array_map('intval', array_column($stmt->fetchAll(), 'n', 'host'));
    }

    public static function nf(int|float $n): string
    {
        return number_format((float) $n, 0, '.', "'");
    }
}
