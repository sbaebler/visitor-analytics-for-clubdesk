<?php
declare(strict_types=1);

/**
 * Social Widget – wird via <iframe> in Clubdesk-Seiten eingebettet.
 *
 * URL: https://stats.zurich-sailing.ch/widget.php?url=PAGE_URL&lang=de
 *
 * Parameter:
 *   url   (required) vollständige URL der Clubdesk-Seite
 *   lang  (optional) de | fr | en  (Standard: de)
 */

$config  = require __DIR__ . '/../config/config.php';
$baseUrl = rtrim($config['social']['base_url'] ?? 'https://stats.zurich-sailing.ch', '/');

$pageUrl = filter_var($_GET['url'] ?? '', FILTER_VALIDATE_URL) ? $_GET['url'] : '';
$lang    = in_array($_GET['lang'] ?? 'de', ['de', 'fr', 'en'], true)
           ? ($_GET['lang'] ?? 'de')
           : 'de';

if (empty($pageUrl)) {
    http_response_code(400);
    echo '<p style="font-family:sans-serif;color:#c00;padding:8px">Fehlender URL-Parameter.</p>';
    exit;
}

// iframe darf eingebettet werden; globales DENY vom .htaccess überschreiben
header_remove('X-Frame-Options');
header('X-Frame-Options: ALLOWALL');
header('X-Content-Type-Options: nosniff');
// Minimale CSP: kein externes Laden ausser dem eigenen API-Endpunkt
header("Content-Security-Policy: default-src 'none'; script-src 'unsafe-inline'; style-src 'unsafe-inline'; connect-src " . $baseUrl . "; img-src 'none'; frame-src 'none'");

$apiUrl     = $baseUrl . '/social.php';
$encodedUrl = urlencode($pageUrl);

$labels = [
    'de' => ['views' => 'Aufrufe',  'like' => 'Gefällt mir', 'share' => 'Teilen',   'unlike' => 'Gefällt mir nicht mehr'],
    'fr' => ['views' => 'Vues',     'like' => 'J\'aime',     'share' => 'Partager', 'unlike' => 'Je n\'aime plus'],
    'en' => ['views' => 'Views',    'like' => 'Like',        'share' => 'Share',    'unlike' => 'Unlike'],
];
$l = $labels[$lang];
?>
<!DOCTYPE html>
<html lang="<?= htmlspecialchars($lang) ?>">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Social</title>
<style>
/* ── Reset ── */
*, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }

/*
 * Design-System: CSS-Variablen aus dem bestehenden Dashboard (style.css)
 * --navy, --blue, --blue-l bleiben konsistent mit dem Admin-Dashboard.
 */
:root {
    --navy:   #0A2342;
    --blue:   #2196F3;
    --blue-l: #64B5F6;
    --red:    #E53935;
    --border: #E2E8F0;
    --muted:  #718096;
    --radius: 20px;
}

body {
    font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
    font-size: 13px;
    background: transparent;
    color: #2D3748;
    overflow: hidden;
}

/* ── Widget-Zeile ── */
.widget {
    display: flex;
    align-items: center;
    gap: 6px;
    padding: 6px 0;
    flex-wrap: wrap;
    min-height: 44px;
}

/* ── Views-Badge ── */
.stat {
    display: inline-flex;
    align-items: center;
    gap: 5px;
    padding: 5px 12px;
    background: #f0f4f8;
    border: 1px solid var(--border);
    border-radius: var(--radius);
    color: var(--muted);
    white-space: nowrap;
    user-select: none;
}
.stat-value { font-weight: 600; color: #2D3748; }

/* ── Divider ── */
.sep { width: 1px; height: 22px; background: var(--border); flex-shrink: 0; }

/* ── Buttons (Like + Share) ── */
.btn {
    display: inline-flex;
    align-items: center;
    gap: 5px;
    padding: 5px 14px;
    border: 1.5px solid var(--border);
    border-radius: var(--radius);
    background: #fff;
    color: #4a5568;
    font-size: 13px;
    font-weight: 500;
    cursor: pointer;
    transition: border-color .15s, color .15s, background .15s;
    white-space: nowrap;
    user-select: none;
}
.btn:hover:not(:disabled)  { border-color: var(--blue); color: var(--blue); background: #f0f5ff; }
.btn:disabled               { opacity: .6; cursor: default; }

/* Like-Zustand: aktiv */
.btn-like.liked             { border-color: var(--red); color: var(--red); background: #fff5f5; }

/* ── Share-Dropdown ── */
.share-wrap { position: relative; }

.share-menu {
    display: none;
    position: absolute;
    top: calc(100% + 8px);
    left: 0;
    background: #fff;
    border: 1px solid var(--border);
    border-radius: 10px;
    box-shadow: 0 4px 18px rgba(0,0,0,.12);
    overflow: hidden;
    min-width: 176px;
    z-index: 50;
}
.share-menu.open { display: block; }

.share-item {
    display: flex;
    align-items: center;
    gap: 10px;
    padding: 9px 14px;
    text-decoration: none;
    color: #2D3748;
    font-size: 13px;
    border: none;
    background: none;
    width: 100%;
    cursor: pointer;
    transition: background .1s;
    text-align: left;
}
.share-item:hover { background: #f0f5ff; }
.share-icon { width: 20px; height: 20px; flex-shrink: 0; display: flex; align-items: center; justify-content: center; }

/* ── Toast ── */
.toast {
    position: fixed;
    bottom: 10px;
    left: 50%;
    transform: translateX(-50%) translateY(30px);
    background: var(--navy);
    color: #fff;
    padding: 6px 16px;
    border-radius: var(--radius);
    font-size: 12px;
    opacity: 0;
    transition: opacity .2s, transform .2s;
    pointer-events: none;
    white-space: nowrap;
    z-index: 100;
}
.toast.show { opacity: 1; transform: translateX(-50%) translateY(0); }

/* ── Lade-Skeleton ── */
.skeleton {
    display: inline-block;
    height: 32px;
    border-radius: var(--radius);
    background: linear-gradient(90deg, #e8edf2 25%, #f4f7fa 50%, #e8edf2 75%);
    background-size: 300% 100%;
    animation: shimmer 1.2s infinite;
}
@keyframes shimmer { to { background-position: -200% center } }
</style>
</head>
<body>

<div class="widget" id="widget">
    <span class="skeleton" style="width:90px"></span>
    <span class="skeleton" style="width:100px"></span>
    <span class="skeleton" style="width:80px"></span>
</div>

<div class="toast" id="toast"></div>

<script>
(function () {
'use strict';

const PAGE_URL = <?= json_encode($pageUrl, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) ?>;
const API_URL  = <?= json_encode($apiUrl,  JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) ?>;
const L        = <?= json_encode($l,       JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) ?>;

// ── SVG-Icons ──────────────────────────────────────────────────────────────
const I = {
    eye: `<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>`,

    heart: `<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M20.84 4.61a5.5 5.5 0 0 0-7.78 0L12 5.67l-1.06-1.06a5.5 5.5 0 0 0-7.78 7.78l1.06 1.06L12 21.23l7.78-7.78 1.06-1.06a5.5 5.5 0 0 0 0-7.78z"/></svg>`,

    heartFilled: `<svg width="14" height="14" viewBox="0 0 24 24" fill="currentColor" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M20.84 4.61a5.5 5.5 0 0 0-7.78 0L12 5.67l-1.06-1.06a5.5 5.5 0 0 0-7.78 7.78l1.06 1.06L12 21.23l7.78-7.78 1.06-1.06a5.5 5.5 0 0 0 0-7.78z"/></svg>`,

    share: `<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="18" cy="5" r="3"/><circle cx="6" cy="12" r="3"/><circle cx="18" cy="19" r="3"/><line x1="8.59" y1="13.51" x2="15.42" y2="17.49"/><line x1="15.41" y1="6.51" x2="8.59" y2="10.49"/></svg>`,

    whatsapp: `<svg width="18" height="18" viewBox="0 0 24 24" fill="none"><path fill="#25D366" d="M.057 24l1.687-6.163a11.867 11.867 0 0 1-1.587-5.946C.16 5.335 5.495 0 12.05 0a11.817 11.817 0 0 1 8.413 3.488 11.824 11.824 0 0 1 3.48 8.414c-.003 6.557-5.338 11.892-11.893 11.892a11.9 11.9 0 0 1-5.688-1.448L.057 24z"/><path fill="#fff" d="M17.507 14.307l-.009.075c-.301-.15-1.767-.867-2.04-.966-.272-.099-.47-.148-.669.15-.197.297-.767.966-.94 1.165-.173.198-.347.223-.644.075-.297-.15-1.255-.463-2.39-1.475-.883-.788-1.48-1.761-1.653-2.059-.173-.297-.018-.458.13-.606.134-.133.298-.347.446-.52.149-.174.198-.298.298-.497.099-.198.05-.371-.025-.52-.075-.148-.669-1.612-.916-2.207-.241-.579-.486-.5-.669-.51-.172-.008-.371-.01-.57-.01-.198 0-.52.074-.792.372-.272.297-1.04 1.016-1.04 2.479 0 1.462 1.065 2.875 1.213 3.074.149.198 2.096 3.2 5.077 4.487.709.306 1.262.489 1.694.625.712.227 1.36.195 1.871.118.571-.085 1.758-.719 2.006-1.413.248-.694.248-1.289.173-1.413-.074-.124-.272-.198-.57-.347z"/></svg>`,

    imessage: `<svg width="16" height="16" viewBox="0 0 24 24" fill="#1B9DF8"><path d="M12 2C6.201 2 1.5 5.925 1.5 10.766c0 2.69 1.452 5.096 3.737 6.714-.146 1.29-.66 2.847-1.62 4.085-.142.183-.01.45.219.428 2.077-.198 4.012-1.05 5.404-1.99.96.247 1.973.379 3.06.379 5.799 0 10.5-3.925 10.5-8.766C22.5 5.925 17.799 2 12 2z"/></svg>`,

    facebook: `<svg width="16" height="16" viewBox="0 0 24 24" fill="#1877F2"><path d="M24 12.073c0-6.627-5.373-12-12-12s-12 5.373-12 12c0 5.99 4.388 10.954 10.125 11.854v-8.385H7.078v-3.47h3.047V9.43c0-3.007 1.792-4.669 4.533-4.669 1.312 0 2.686.235 2.686.235v2.953H15.83c-1.491 0-1.956.925-1.956 1.874v2.25h3.328l-.532 3.47h-2.796v8.385C19.612 23.027 24 18.062 24 12.073z"/></svg>`,

    mail: `<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="#4a5568" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M4 4h16c1.1 0 2 .9 2 2v12c0 1.1-.9 2-2 2H4c-1.1 0-2-.9-2-2V6c0-1.1.9-2 2-2z"/><polyline points="22,6 12,13 2,6"/></svg>`,
};

// ── State ──────────────────────────────────────────────────────────────────
let userLiked = false;

// ── Widget rendern ──────────────────────────────────────────────────────────
function render(viewsFmt, likesFmt, liked) {
    userLiked = liked;
    const enc = encodeURIComponent(PAGE_URL);

    document.getElementById('widget').innerHTML = `
        <span class="stat">
            ${I.eye}
            <span class="stat-value" id="view-val">${viewsFmt}</span>
            <span>${L.views}</span>
        </span>

        <span class="sep"></span>

        <button class="btn btn-like ${liked ? 'liked' : ''}"
                id="btn-like" onclick="handleLike()" title="${liked ? L.unlike : L.like}">
            ${liked ? I.heartFilled : I.heart}
            <span id="like-val">${likesFmt}</span>
            <span>${L.like}</span>
        </button>

        <div class="share-wrap">
            <button class="btn" id="btn-share" onclick="toggleShare(event)">
                ${I.share} <span>${L.share}</span>
            </button>
            <div class="share-menu" id="share-menu">
                <a class="share-item" href="https://wa.me/?text=${enc}" target="_blank" rel="noopener noreferrer">
                    <span class="share-icon">${I.whatsapp}</span>WhatsApp
                </a>
                <a class="share-item" href="sms:?&body=${enc}">
                    <span class="share-icon">${I.imessage}</span>iMessage
                </a>
                <a class="share-item" href="https://www.facebook.com/sharer/sharer.php?u=${enc}" target="_blank" rel="noopener noreferrer">
                    <span class="share-icon">${I.facebook}</span>Facebook
                </a>
                <a class="share-item" href="mailto:?body=${enc}" target="_blank">
                    <span class="share-icon">${I.mail}</span>E-Mail
                </a>
            </div>
        </div>
    `;

    // Klick ausserhalb → Share-Menü schliessen
    document.addEventListener('click', (e) => {
        const btn = document.getElementById('btn-share');
        if (btn && !btn.contains(e.target)) {
            closeShareMenu();
        }
    }, { once: false });
}

// ── Stats laden ─────────────────────────────────────────────────────────────
async function loadStats() {
    try {
        const res  = await fetch(`${API_URL}?action=stats&url=${encodeURIComponent(PAGE_URL)}`);
        if (!res.ok) throw new Error('HTTP ' + res.status);
        const data = await res.json();
        render(data.views_fmt, data.likes_fmt, data.user_liked);
    } catch {
        // Fallback: Widget trotzdem anzeigen, Zahlen leer
        render('–', '–', false);
    }
}

// ── Like togglen ─────────────────────────────────────────────────────────────
async function handleLike() {
    const btn = document.getElementById('btn-like');
    if (!btn || btn.disabled) return;
    btn.disabled = true;

    try {
        const body = new URLSearchParams({ url: PAGE_URL });
        const res  = await fetch(`${API_URL}?action=like`, {
            method:  'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body:    body.toString(),
        });
        if (!res.ok) throw new Error('HTTP ' + res.status);
        const data = await res.json();

        userLiked = data.liked;

        // Nur die veränderten Elemente aktualisieren (kein Re-render → kein Flicker)
        const likeVal = document.getElementById('like-val');
        if (likeVal) likeVal.textContent = data.likes_fmt;

        btn.classList.toggle('liked', data.liked);
        btn.title = data.liked ? L.unlike : L.like;
        // Herz-Icon tauschen (erstes Kind des Buttons)
        btn.innerHTML = `${data.liked ? I.heartFilled : I.heart}
            <span id="like-val">${data.likes_fmt}</span>
            <span>${L.like}</span>`;
    } catch {
        toast('⚠ Fehler – bitte nochmals versuchen');
    } finally {
        const b = document.getElementById('btn-like');
        if (b) b.disabled = false;
    }
}

// ── Share-Menü ───────────────────────────────────────────────────────────────
function toggleShare(e) {
    e.stopPropagation();
    const menu = document.getElementById('share-menu');
    if (!menu) return;
    if (menu.classList.contains('open')) {
        closeShareMenu();
    } else {
        menu.classList.add('open');
        notifyParentHeight(true);
    }
}

// ── Iframe-Höhe an Parent melden (Share-Menü braucht mehr Platz als die fixe 60px) ─
function notifyParentHeight(expanded) {
    const widget = document.getElementById('widget');
    if (!widget) return;
    let height = widget.offsetHeight;
    if (expanded) {
        const menu = document.getElementById('share-menu');
        if (menu) height += menu.offsetHeight + 8; // 8px = Lücke aus `top: calc(100% + 8px)`
    }
    window.parent.postMessage({ source: 'zs-widget', height }, '*');
}

// ── Share-Menü schliessen (zentral, damit Resize-Rückmeldung nie ausgelassen wird) ──
function closeShareMenu() {
    const menu = document.getElementById('share-menu');
    if (menu && menu.classList.contains('open')) {
        menu.classList.remove('open');
        notifyParentHeight(false);
    }
}

// ── Toast ─────────────────────────────────────────────────────────────────────
function toast(msg) {
    const el = document.getElementById('toast');
    el.textContent = msg;
    el.classList.add('show');
    setTimeout(() => el.classList.remove('show'), 2400);
}

// ── Globale Funktionen (onclick-Attribute) ────────────────────────────────────
window.handleLike   = handleLike;
window.toggleShare  = toggleShare;

// ── Schliessen auf Klick ausserhalb des iframes (vom Parent gemeldet) ─────────
// Klicks auf die Parent-Seite erreichen den iframe-Dokument-Click-Listener
// nicht (separates Dokument) – der Parent meldet daher Klicks aktiv zurück.
window.addEventListener('message', (e) => {
    if (e.source !== window.parent) return;
    if (e.data && e.data.source === 'zs-widget-host' && e.data.action === 'close') {
        closeShareMenu();
    }
});

// ── Init ──────────────────────────────────────────────────────────────────────
loadStats();

})();
</script>
</body>
</html>
