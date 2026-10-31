<?php
declare(strict_types=1);

/**
 * Admin: Weiterleitungs-Links anlegen, bearbeiten und Klicks auswerten.
 *   /links.php           Übersicht + Formular "Neuer Link"
 *   /links.php?id=<id>   Detail: Klicks pro Tag, Geräte, Herkunft, Bearbeiten
 * Spec: docs/links.md
 */

require_once __DIR__ . '/../src/Auth.php';
require_once __DIR__ . '/../src/Database.php';
require_once __DIR__ . '/../src/ShortLinks.php';

header("Content-Security-Policy: default-src 'none'; script-src 'unsafe-inline'; style-src 'self' 'unsafe-inline'; img-src 'self' data:; connect-src 'none'; form-action 'self'; frame-src 'none'");
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');

Auth::requireAuth();

$config = require __DIR__ . '/../config/config.php';
$pdo    = Database::get();
$base   = rtrim($config['social']['base_url'] ?? ('https://' . ($_SERVER['HTTP_HOST'] ?? '')), '/');

if (empty($_SESSION['links_csrf'])) {
    $_SESSION['links_csrf'] = bin2hex(random_bytes(16));
}
$csrf = $_SESSION['links_csrf'];

$h = static fn($s): string => htmlspecialchars((string) $s, ENT_QUOTES);

$errors = [];
$form   = ['slug' => '', 'target_url' => '', 'label' => ''];

// --- POST: anlegen / speichern -------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!hash_equals($csrf, (string) ($_POST['csrf'] ?? ''))) {
        http_response_code(400);
        exit('Ungültiges Formular. Bitte Seite neu laden.');
    }
    $action = (string) ($_POST['action'] ?? '');
    $target = trim((string) ($_POST['target_url'] ?? ''));
    $label  = mb_substr(trim((string) ($_POST['label'] ?? '')), 0, 255);

    if (!ShortLinks::isValidTarget($target)) {
        $errors[] = 'Ziel-URL ist ungültig (muss mit http:// oder https:// beginnen).';
    }

    if ($action === 'create') {
        $slug = strtolower(trim((string) ($_POST['slug'] ?? '')));
        if ($slug === '') {
            do { $slug = ShortLinks::randomSlug(); } while (ShortLinks::slugExists($pdo, $slug));
        } elseif (!ShortLinks::isValidSlug($slug)) {
            $errors[] = 'Kurzname: nur Kleinbuchstaben, Ziffern und Bindestriche (max. 64 Zeichen, nicht am Anfang/Ende).';
        } elseif (ShortLinks::slugExists($pdo, $slug)) {
            $errors[] = 'Diesen Kurznamen gibt es schon.';
        }
        $form = ['slug' => $slug, 'target_url' => $target, 'label' => $label];
        if (!$errors) {
            $id = ShortLinks::create($pdo, $slug, $target, $label);
            header('Location: /links.php?id=' . $id . '&created=1');
            exit;
        }
    } elseif ($action === 'update') {
        $id = (int) ($_POST['id'] ?? 0);
        if (!ShortLinks::find($pdo, $id)) {
            $errors[] = 'Link nicht gefunden.';
        }
        if (!$errors) {
            ShortLinks::update($pdo, $id, $target, $label, !empty($_POST['is_active']));
            header('Location: /links.php?id=' . $id . '&saved=1');
            exit;
        }
        $_GET['id'] = (string) $id;
    }
}

// --- Daten -----------------------------------------------------------------
$detail = null;
if (isset($_GET['id'])) {
    $detail = ShortLinks::find($pdo, (int) $_GET['id']);
}

if ($detail) {
    $id        = (int) $detail['id'];
    $daily     = ShortLinks::daily($pdo, $id, 60);
    $devices   = ShortLinks::devices($pdo, $id);
    $referrers = ShortLinks::referrers($pdo, $id);
    $row       = array_values(array_filter(ShortLinks::overview($pdo), fn($r) => (int) $r['id'] === $id))[0] ?? [];
    $maxDay    = max(1, ...array_values($daily));
} else {
    $links = ShortLinks::overview($pdo);
}

$devLabels = ['desktop' => 'Desktop', 'mobile' => 'Mobil', 'tablet' => 'Tablet'];
$fmtDate   = static fn(?string $ts): string => $ts ? date('j.n.Y H:i', strtotime($ts)) : '–';
?>
<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>ZS Analytics – Links</title>
    <link rel="stylesheet" href="/assets/style.css">
</head>
<body>
    <header class="topbar">
        <div class="topbar-brand">
            <span class="topbar-icon">⛵</span>
            <div>
                <span class="topbar-title"><?= $h($config['site_name'] ?? 'Analytics') ?></span>
                <span class="topbar-sub">Links</span>
            </div>
        </div>
        <nav class="view-nav">
            <a href="/" class="range-btn">Übersicht</a>
            <a href="/beitraege.php" class="range-btn">Beiträge</a>
            <a href="/links.php" class="range-btn active">Links</a>
        </nav>
        <a href="/logout.php" class="logout-btn">Abmelden</a>
    </header>

    <main class="content">

    <?php if ($errors): ?>
        <div class="link-errors">
            <?php foreach ($errors as $e): ?><div><?= $h($e) ?></div><?php endforeach; ?>
        </div>
    <?php endif; ?>

    <?php if ($detail): $short = $base . '/go/' . $detail['slug']; ?>

        <?php if (isset($_GET['created'])): ?>
            <div class="cms-hint"><strong>Link angelegt.</strong> Teile ab jetzt diesen Link statt der Original-URL.</div>
        <?php elseif (isset($_GET['saved'])): ?>
            <div class="cms-hint"><strong>Gespeichert.</strong></div>
        <?php endif; ?>

        <div class="link-back"><a href="/links.php">&larr; Alle Links</a></div>

        <div class="card">
            <div class="card-header"><h2 class="card-title"><?= $h($detail['label'] !== '' ? $detail['label'] : $detail['slug']) ?></h2></div>
            <div class="link-share">
                <input type="text" readonly value="<?= $h($short) ?>" id="short-url" class="link-input">
                <button type="button" class="link-btn" data-copy="<?= $h($short) ?>">Kopieren</button>
                <?php if (!(int) $detail['is_active']): ?><span class="link-badge-off">inaktiv</span><?php endif; ?>
            </div>
            <p class="card-desc">Leitet weiter auf <a href="<?= $h($detail['target_url']) ?>" rel="noopener" target="_blank" class="link-target"><?= $h($detail['target_url']) ?></a></p>
        </div>

        <div class="kpi-grid">
            <div class="kpi-card">
                <div class="kpi-label">Klicks total</div>
                <div class="kpi-value"><?= ShortLinks::nf((int) ($row['clicks'] ?? 0)) ?></div>
                <div class="kpi-sub">seit <?= $h(date('j.n.Y', strtotime($detail['created_at']))) ?></div>
            </div>
            <div class="kpi-card">
                <div class="kpi-label">Verschiedene Personen</div>
                <div class="kpi-value"><?= ShortLinks::nf((int) ($row['uniques'] ?? 0)) ?></div>
                <div class="kpi-sub">geschätzt über anonymisierte IP</div>
            </div>
            <div class="kpi-card">
                <div class="kpi-label">Letzte 7 Tage</div>
                <div class="kpi-value"><?= ShortLinks::nf((int) ($row['clicks_7d'] ?? 0)) ?></div>
                <div class="kpi-sub">Klicks</div>
            </div>
            <div class="kpi-card">
                <div class="kpi-label">Letzter Klick</div>
                <div class="kpi-value link-kpi-small"><?= $h($fmtDate($row['last_click'] ?? null)) ?></div>
                <div class="kpi-sub"><?= ShortLinks::nf((int) ($row['bot_hits'] ?? 0)) ?> Vorschau-/Bot-Abrufe nicht gezählt</div>
            </div>
        </div>

        <div class="card">
            <div class="card-header"><h2 class="card-title">Klicks pro Tag (letzte 60 Tage)</h2></div>
            <div class="link-bars" role="img" aria-label="Klicks pro Tag">
                <?php foreach ($daily as $d => $n): ?>
                    <div class="link-bar" title="<?= $h(date('j.n.Y', strtotime($d))) ?>: <?= $n ?> Klick<?= $n === 1 ? '' : 's' ?>">
                        <span style="height: <?= round($n / $maxDay * 100, 1) ?>%"></span>
                    </div>
                <?php endforeach; ?>
            </div>
            <div class="link-bars-axis">
                <span><?= $h(date('j.n.', strtotime(array_key_first($daily)))) ?></span>
                <span>heute</span>
            </div>
        </div>

        <div class="two-col">
            <div class="card">
                <div class="card-header"><h2 class="card-title">Geräte</h2></div>
                <table class="data-table"><tbody>
                    <?php if (!$devices): ?><tr><td class="empty">Noch keine Klicks</td></tr><?php endif; ?>
                    <?php foreach ($devices as $dev => $n): ?>
                        <tr><td><?= $h($devLabels[$dev] ?? $dev) ?></td><td class="num"><?= ShortLinks::nf($n) ?></td></tr>
                    <?php endforeach; ?>
                </tbody></table>
            </div>
            <div class="card">
                <div class="card-header"><h2 class="card-title">Herkunft</h2></div>
                <table class="data-table"><tbody>
                    <?php if (!$referrers): ?><tr><td class="empty">Noch keine Klicks</td></tr><?php endif; ?>
                    <?php foreach ($referrers as $host => $n): ?>
                        <tr>
                            <td><?= $host === '' ? '<span class="url-title">Direkt (Mail, WhatsApp, App …)</span>' : $h($host) ?></td>
                            <td class="num"><?= ShortLinks::nf($n) ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody></table>
            </div>
        </div>

        <div class="card">
            <div class="card-header"><h2 class="card-title">Bearbeiten</h2></div>
            <p class="card-desc">
                Der Kurzname <code>/go/<?= $h($detail['slug']) ?></code> bleibt fix, damit geteilte Links weiter funktionieren.
                Wird die Datei in Clubdesk ersetzt, hier einfach die neue Ziel-URL eintragen.
            </p>
            <form method="post" class="link-form">
                <input type="hidden" name="csrf" value="<?= $h($csrf) ?>">
                <input type="hidden" name="action" value="update">
                <input type="hidden" name="id" value="<?= (int) $detail['id'] ?>">
                <label>Bezeichnung
                    <input type="text" name="label" maxlength="255" class="link-input" value="<?= $h($detail['label']) ?>">
                </label>
                <label>Ziel-URL
                    <input type="url" name="target_url" required class="link-input" value="<?= $h($detail['target_url']) ?>">
                </label>
                <label class="link-check">
                    <input type="checkbox" name="is_active" value="1" <?= (int) $detail['is_active'] ? 'checked' : '' ?>>
                    Aktiv (inaktive Links zeigen „Link nicht gefunden“, die Statistik bleibt erhalten)
                </label>
                <div><button type="submit" class="link-btn">Speichern</button></div>
            </form>
        </div>

    <?php else: ?>

        <div class="card">
            <div class="card-header"><h2 class="card-title">Neuer Link</h2></div>
            <p class="card-desc">
                Ziel-URL einfügen, z.B. den Datei-Link aus Clubdesk. Du bekommst einen Link
                <code><?= $h($base) ?>/go/…</code>, der jeden Klick zählt und dann zur Datei weiterleitet.
                Vorschau-Abrufe von WhatsApp, iMessage, Outlook usw. werden nicht mitgezählt.
            </p>
            <form method="post" class="link-form">
                <input type="hidden" name="csrf" value="<?= $h($csrf) ?>">
                <input type="hidden" name="action" value="create">
                <label>Ziel-URL
                    <input type="url" name="target_url" required class="link-input" placeholder="https://zurich-sailing.ch/clubdesk/…/fileservlet?…" value="<?= $h($form['target_url']) ?>">
                </label>
                <label>Bezeichnung <span class="url-title">(nur für dich, z.B. „Ausschreibung Herbstregatta“)</span>
                    <input type="text" name="label" maxlength="255" class="link-input" value="<?= $h($form['label']) ?>">
                </label>
                <label>Kurzname <span class="url-title">(optional, z.B. „herbstregatta“, sonst zufällig)</span>
                    <span class="link-slug"><span><?= $h($base) ?>/go/</span><input type="text" name="slug" maxlength="64" pattern="[a-z0-9](?:[a-z0-9\-]{0,62}[a-z0-9])?" class="link-input" value="<?= $h($form['slug']) ?>"></span>
                </label>
                <div><button type="submit" class="link-btn">Link anlegen</button></div>
            </form>
        </div>

        <div class="card">
            <div class="card-header"><h2 class="card-title">Alle Links</h2></div>
            <div class="link-table-wrap">
            <table class="data-table">
                <thead><tr>
                    <th>Link</th>
                    <th>Kurz-URL</th>
                    <th class="num">Klicks</th>
                    <th class="num">Personen</th>
                    <th class="num">7 Tage</th>
                    <th>Letzter Klick</th>
                </tr></thead>
                <tbody>
                <?php if (!$links): ?>
                    <tr><td colspan="6" class="empty">Noch keine Links angelegt.</td></tr>
                <?php endif; ?>
                <?php foreach ($links as $l): $short = $base . '/go/' . $l['slug']; ?>
                    <tr<?= (int) $l['is_active'] ? '' : ' class="link-row-off"' ?>>
                        <td>
                            <a class="url-path" href="/links.php?id=<?= (int) $l['id'] ?>"><?= $h($l['label'] !== '' ? $l['label'] : $l['slug']) ?></a>
                            <span class="url-title"><?= $h($l['target_url']) ?></span>
                        </td>
                        <td>
                            <button type="button" class="link-copy" data-copy="<?= $h($short) ?>" title="Kurz-URL kopieren">/go/<?= $h($l['slug']) ?></button>
                            <?php if (!(int) $l['is_active']): ?><span class="link-badge-off">inaktiv</span><?php endif; ?>
                        </td>
                        <td class="num"><?= ShortLinks::nf((int) $l['clicks']) ?></td>
                        <td class="num"><?= ShortLinks::nf((int) $l['uniques']) ?></td>
                        <td class="num"><?= ShortLinks::nf((int) $l['clicks_7d']) ?></td>
                        <td><?= $h($fmtDate($l['last_click'])) ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
            </div>
        </div>

    <?php endif; ?>
    </main>

    <script>
    document.querySelectorAll('[data-copy]').forEach(function (btn) {
        btn.addEventListener('click', function () {
            var text = btn.getAttribute('data-copy');
            var done = function () {
                var old = btn.textContent;
                btn.textContent = 'Kopiert ✓';
                setTimeout(function () { btn.textContent = old; }, 1500);
            };
            if (navigator.clipboard) {
                navigator.clipboard.writeText(text).then(done);
            } else {
                var i = document.getElementById('short-url');
                if (i) { i.select(); document.execCommand('copy'); done(); }
            }
        });
    });
    </script>
</body>
</html>
