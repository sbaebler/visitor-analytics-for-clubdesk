---
name: analytics
description: Architektur, Muster und Erweiterungsanleitung für visitor-analytics-for-clubdesk. Verwenden bei: Änderungen an collect.php oder Social.php, neuen DB-Tabellen, Dashboard-Erweiterungen, Widget-Anpassungen, URL-Normalisierung oder Fingerprinting-Logik.
---

# Analytics-Projekt – Architektur & Muster

## Kritische Invariante: URL-Normalisierung

`Social::normalizePageUrl()` in `src/Social.php` muss **byte-identisch** mit
`normalizePageUrl()` in `public/collect.php` sein.

Wenn collect.php geändert wird → Social.php synchronisieren, und umgekehrt.
Sonst stimmen die View-Counts im Widget nicht mit dem Admin-Dashboard überein.

Kanonische Spec der Normalisierungsregeln: `docs/url-normalization.md`.
Konsumenten (Dashboard, Social, Auswertungen) normalisieren **nicht** neu – sie machen höchstens
reine Pfad-Extraktion zur Anzeige/Gruppierung.

## Fingerprinting: zwei verschiedene Hashes

| Verwendung | Hash-Input | Warum |
|---|---|---|
| Tracker (collect.php) | salt + IP + UA + Lang + Datum | Täglicher Wechsel → Datenschutz |
| Social Widget (Social.php) | salt + IP | Dauerhaft → Like bleibt erhalten |

Beide nutzen denselben `salt` aus `config.php`.

## View-Count: kein Duplikat

Widget liest Views aus `pageviews` via SHA256-Lookup:
```sql
SELECT COUNT(*) FROM pageviews
 WHERE url = (SELECT url FROM pageviews WHERE SHA2(url,256) = :hash AND is_cms = 0 LIMIT 1)
   AND is_cms = 0
```
Dieser Wert ist identisch mit dem Admin-Dashboard – kein zweites Tracking.

## Muster: Neue Klasse in src/

Analog zu Database.php und Auth.php:
- `declare(strict_types=1);` am Anfang
- Nur statische Methoden (kein Konstruktor, kein State)
- Config via `require __DIR__ . '/../config/config.php'` oder als Parameter

## Muster: Neuer öffentlicher API-Endpunkt in public/

Analog zu collect.php und social.php:
```php
require_once __DIR__ . '/../src/Database.php';
require_once __DIR__ . '/../src/Social.php'; // oder neue Klasse
$config = require __DIR__ . '/../config/config.php';
Social::setCorsHeaders($config);
// ... Routing via switch($action)
Social::jsonResponse($data);
```

## Content-Change-Detection

`cron/check_changes.php` + `src/SitemapMonitor.php` erkennen alle 30 Minuten
Änderungen an allen Sitemap-Seiten (`tracked_pages`/`page_changes`).
Kanonische Spec: `docs/content-change-detection.md`.

Wichtig: `SitemapMonitor` normalisiert Sitemap-URLs **ausschliesslich** über
`Social::normalizePageUrl()` – es gibt bewusst **keine** dritte, eigene
Normalisierungs-Implementierung. Gespeichert wird der normalisierte Pfad
(gleiche Form wie `pageviews.url`), nicht die rohe absolute URL.

## Auswertungsklassen (rein lesend)

`BeitragStats` (Beitrags-Auswertung) ist das Muster für Auswertungen:
- **Rein lesend** – keine Tabelle, keine Spalte, keine Migration
- Normalisiert **nicht** neu, sondern zerlegt nur den gespeicherten Pfad
  (siehe `docs/url-normalization.md`: Konsumenten normalisieren nicht erneut)
- Alle SQL und alle Klassifikation in der Klasse; die Seite stellt nur dar
- Schwellwerte als Klassenkonstanten, die die UI-Texte interpolieren – sonst
  laufen angezeigte Regel und tatsächliches Verhalten auseinander

Zwei Fallen, die dort gelöst sind und bei jeder neuen Auswertung wieder auftreten:

| Falle | Lösung |
|---|---|
| Altzeilen `/beitrag/b<block>` aus `migrate_beitrag.sql` sind keine echten Beiträge | `SUBSTRING(url,10,1) COLLATE utf8mb4_bin BETWEEN 'A' AND 'Z'` – das COLLATE ist Pflicht, sonst ist der Vergleich case-insensitiv |
| `social_stats` über `url_hash` joinen schlägt fehl | Über `url` joinen: `Social::hashUrl()` hasht ohne `strtolower`, `SitemapMonitor` mit |

Kanonische Spec: `docs/beitrags-analyse.md`.

## Platzierung: was Clubdesk hergibt

`PlacementMonitor` liest die Beitragskacheln aus dem **rohen** HTML der Website
(`cleanContent()` würde die `onclick`-Attribute per `strip_tags()` zerstören).
Clubdesk rendert serverseitig, ein `curl` reicht – kein JavaScript nötig.

| Falle | Lösung |
|---|---|
| Die Website hat keine Sitemap (404) | Navigation aus dem Roh-HTML der Startseite (`discoverPages()`) |
| Vollisten antworten ohne `s=`-Signatur mit 404 | Signatur aus dem "Weitere Einträge"-Link der Trägerseite mitnehmen |
| News-Listen haben keinen eigenen Titel | Nächste vorangehende Überschrift als Label (`labelForBlock()`) |
| Ein Beitrag kann in mehreren Blöcken stehen | n:m-Tabelle; im Ortsvergleich ausgeschlossen, weil `pageviews` nur einen Zähler je Beitrag kennt |
| Platzierung per JOIN in die Aggregat-Query | **Nie** – der Fan-out vervielfacht `COUNT(*)`. Eigene Query + Merge in PHP, wie bei den Likes |

## Muster: DB-Migration

1. `setup/schema.sql` → kanonisches Voll-Schema (Quelle für neue Installationen via phpMyAdmin-Import) ergänzen
2. `setup/install.php` → `CREATE TABLE IF NOT EXISTS` Block synchron halten (CLI-Helfer)
3. `setup/migrate_NAME.sql` → für bestehende Installationen
4. `public/index.php` → Falls nötig `SHOW COLUMNS ... LIKE 'neue_spalte'` für graceful degradation

## CSS Design-System

Alle neuen UI-Elemente in `widget.php` oder `index.php` nutzen:
```css
--navy:   #0A2342   /* Buttons, Topbar */
--blue:   #2196F3   /* Hover, Akzente */
--blue-l: #64B5F6   /* Charts, sekundär */
--border: #E2E8F0
--muted:  #718096
--radius: 10px      /* Dashboard */ / 20px /* Widget-Buttons (Pills) */
```

## Deploy-Ausschlüsse (.github/workflows/deploy.yml)

Folgende Pfade werden nicht deployt:
- `**/.git*/**`
- `**/config/config.php`
- `**/docs/**`

Neue sensible Dateien hier ergänzen.
