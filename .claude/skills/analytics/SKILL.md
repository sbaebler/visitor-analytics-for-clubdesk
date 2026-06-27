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
