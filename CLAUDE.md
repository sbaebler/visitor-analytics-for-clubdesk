# visitor-analytics-for-clubdesk

Self-hosted, cookielose Besucherstatistiken + Social Widget (Views, Likes, Share)
für Clubdesk-Websites. Deployed auf `stats.zurich-sailing.ch` via GitHub Actions → Cyon FTPS.

## Stack

- **Sprache:** PHP 8.1+ (strict_types, kein Framework)
- **Datenbank:** MariaDB / MySQL 8+ auf Cyon
- **Auth:** Session-basiert via `src/Auth.php` (nur Admin-Dashboard)
- **Deploy:** GitHub Actions → `SamKirkland/FTP-Deploy-Action` → Cyon FTPS

## Verzeichnisstruktur

```
config/
  config.sample.php     Vorlage (committet)
  config.php            ⚠️  Secrets – NICHT committen (.gitignore)
cron/
  check_uptime.php      Uptime-Monitor, läuft alle 15 Min. via Cron auf Cyon
  check_changes.php     ← NEU: Content-Change-Detection, läuft alle 30 Min. via Cron auf Cyon
  check_placements.php  ← NEU: Beitrags-Platzierung, läuft täglich via Cron auf Cyon
public/                 Document Root der Subdomain
  .htaccess             Security-Header, Caching, setup/-Schutz
  collect.php           Tracking-Endpunkt (POST von tracker.js)
  index.php             Admin-Dashboard (Auth-geschützt)
  login.php / logout.php
  beitraege.php         ← NEU: Beitrags-Auswertung (Muster, Lebenszyklus, Themen)
  social.php            ← NEU: Social API (GET stats / POST like)
  tracker.js            Client-seitiges Tracking-Snippet
  widget.php            ← NEU: iframe-Widget für Clubdesk
  assets/
    style.css           Design-System (--navy, --blue, --blue-l etc.)
    dashboard.js        Chart.js-Wrapper (ZSDash.init / ZSDash.initUptime)
setup/
  schema.sql            Kanonisches Voll-Schema – Standard-Setup via phpMyAdmin-Import
  install.php           Optionaler CLI-Helfer (php setup/install.php) – legt DB an + Tabellen
  migrate_social.sql    ← NEU: Social-Tabellen für bestehende Installationen
  migrate_page_changes.sql ← NEU: Content-Change-Detection-Tabellen für bestehende Installationen
src/
  Auth.php              Session-Auth für Admin-Dashboard
  BeitragStats.php      ← NEU: Beitrags-Auswertung (rein lesend, keine Migration)
  Database.php          PDO-Singleton
  PlacementMonitor.php  ← NEU: liest von der Website, wo ein Beitrag eingebettet ist
  Social.php            ← NEU: Like-Logik, URL-Hashing, Stats
  SitemapMonitor.php    ← NEU: Sitemap-Parsing, Content-Diff, Change-Detection
```

## Wichtige Regeln

- `config/config.php` enthält Secrets → nie committen (in .gitignore)
- `setup/install.php` nach erstem Aufruf über .htaccess gesperrt
- Keine globalen Funktionen in neuen Dateien – Klassen verwenden (wie `Auth`, `Database`, `Social`)
- URL-Normalisierung: `Social::normalizePageUrl()` und `normalizePageUrl()` in `collect.php` müssen **identisch** bleiben – sonst stimmen Widget-View-Counts nicht mit Dashboard überein. Kanonische Spec: `docs/url-normalization.md`
- Schweizer Zahlenformat: `number_format($n, 0, '.', "'")` überall wo Zahlen angezeigt werden
  (als `BeitragStats::nf()` verfügbar)
- Legacy-Beiträge: `setup/migrate_beitrag.sql` hat Altzeilen zu `/beitrag/b<block>` umgeschrieben –
  das sind **keine** echten Beiträge. Jede Beitrags-Auswertung filtert sie über den
  Grossbuchstaben-Test aus (`^/beitrag/[A-Z]`). Spec: `docs/beitrags-analyse.md`
- `cleanTitle()` und `formatDuration()` leben in `BeitragStats`; `index.php` delegiert dorthin –
  nicht duplizieren
- `social.php` und `widget.php` überschreiben `X-Frame-Options: DENY` via `header()` (erlauben iframe-Einbettung)

## DB-Schema (Überblick)

```
pageviews      – Rohdaten Tracker (view_id, fingerprint, url, is_cms, duration, ...)
events         – Outbound-Link-Klicks
uptime_checks  – Uptime-Monitor-Resultate
social_likes   – Wer hat welche URL geliked (url_hash + ip_hash, kein PII)
social_stats   – Aggregat-Cache: like_count pro url_hash
tracked_pages  – Content-Change-Detection: aktueller Content-Hash pro überwachter Seite (Vergleichs-Baseline)
page_changes   – Content-Change-Detection: Log jeder erkannten Content-Änderung (inkl. Diff-Excerpt)
beitrag_placements – Wo ein Beitrag auf der Website steht (Block + Trägerseite), inkl. Publikationsdatum
```

View-Counts im Widget kommen aus `pageviews` (kein Duplikat).
Like-Counts kommen aus `social_stats` (Aggregat für Performance).
Content-Änderungen kommen aus `page_changes`, siehe `docs/content-change-detection.md`.

## Deployment

Push auf `main` → GitHub Actions deployt via FTPS auf Cyon.
`config/config.php` und `docs/` sind in `deploy.yml` ausgeschlossen.

## Clubdesk-Einbindung

```html
<iframe id="sw" src="" width="100%" height="60"
        frameborder="0" scrolling="no" style="border:none;overflow:hidden;"></iframe>
<script>
  var sw = document.getElementById('sw');
  sw.src = 'https://stats.zurich-sailing.ch/widget.php?url=' +
    encodeURIComponent(window.location.href) + '&lang=de';

  // Widget meldet per postMessage, wenn das Teilen-Menü mehr/weniger Höhe braucht,
  // als die fixe 60px erlauben. e.source statt fester ID, damit das auch bei
  // mehreren Widgets auf einer Seite pro Instanz korrekt funktioniert.
  window.addEventListener('message', function (e) {
    if (e.origin !== 'https://stats.zurich-sailing.ch') return;
    if (!e.data || e.data.source !== 'zs-widget') return;
    if (e.source !== sw.contentWindow) return;
    var h = Math.max(40, Math.min(400, Number(e.data.height) || 60));
    sw.style.height = h + 'px';
  });

  // Klick irgendwo auf der Seite ausserhalb des iframes → Widget meldet das
  // dem iframe, damit das Teilen-Menü dort geschlossen wird (Klicks auf der
  // Parent-Seite erreichen den iframe-eigenen Click-Listener sonst nicht,
  // da iframe und Parent getrennte Dokumente sind).
  document.addEventListener('click', function () {
    sw.contentWindow.postMessage({ source: 'zs-widget-host', action: 'close' }, 'https://stats.zurich-sailing.ch');
  });
</script>
```

`tracker.js` läuft parallel weiter – kein doppeltes Tracking.

## Häufige Aufgaben

**Neue Share-Plattform:** `public/widget.php` → SVG-Icon in `I`-Objekt, neues `<a>` im `share-menu`-Template.
Aktuell aktiv: WhatsApp, iMessage, Facebook, E-Mail.
Ausnahme iMessage: kein statischer Web-Link, sondern `sms:`-Schema mit plattformabhängigem Trennzeichen
(iOS `sms:&body=`, macOS/andere `sms:?body=`). Der `href` wird daher in `render()` als `smsHref` berechnet,
nicht statisch im Template gesetzt.

**Neue Sprache:** `$labels`-Array in `widget.php` und `L`-Objekt in JS erweitern.

**Neue DB-Spalte in pageviews:** `setup/schema.sql` (kanonisches Voll-Schema, für neue Installationen) **und** `setup/install.php` (CREATE TABLE, CLI-Helfer) synchron halten + neue `setup/migrate_*.sql` Datei für bestehende Installationen + `collect.php` anpassen.

**Admin-Dashboard erweitern:** Queries in `public/index.php`, HTML darunter. CSS-Klassen aus `assets/style.css` verwenden.

**Beitrags-Platzierung anpassen:** `src/PlacementMonitor.php` (Crawling, Kachel-Parsing),
`cron/check_placements.php` (täglicher Einstiegspunkt), Config unter
`config['beitrag_placements']`. Die Kategorie ist der Clubdesk-**Block** (`b`), nicht die Seite –
ein Block kann auf mehreren Seiten hängen. Mehrfach platzierte Beiträge fallen aus dem
Ortsvergleich, weil `pageviews` pro Beitrag nur einen Zähler kennt. Spec: `docs/beitrags-analyse.md`

**Beitrags-Auswertung erweitern:** `src/BeitragStats.php` (alle Queries + Klassifikation),
`public/beitraege.php` (nur Darstellung), Charts in `assets/dashboard.js` unter
`ZSDash.initBeitraege`. Kanonische Spec inkl. Schwellwerten und Grenzen:
`docs/beitrags-analyse.md`. Rein lesend – keine DB-Migration.

**Content-Change-Detection anpassen:** `src/SitemapMonitor.php` (Sitemap-Parsing, Content-Cleaning, Diff-Logik), `cron/check_changes.php` (Cron-Einstiegspunkt), Config unter `config['sitemap_monitor']`. Kanonische Spec: `docs/content-change-detection.md`. Normalisierung ausschliesslich via `Social::normalizePageUrl()` (keine dritte Implementierung).

## Skill

Detaillierte Architektur-Dokumentation: `.claude/skills/analytics/SKILL.md`
