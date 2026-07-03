# Content-Change-Detection

Kanonische Spec für den Mechanismus, der Änderungen an Sitemap-Seiten
erkennt und protokolliert (`cron/check_changes.php`, `src/SitemapMonitor.php`).
Grundlage für spätere Notification-E-Mails und Analysen im Zusammenhang mit
Besucherzahlen (z.B. Traffic-Anstieg nach Content-Änderung).

## Ablauf pro Lauf (alle 30 Minuten)

1. **Sitemap laden** (`SitemapMonitor::fetchSitemapUrls()`): `config['sitemap_monitor']['sitemap_url']`
   abrufen. Ist es ein `<sitemapindex>`, werden alle referenzierten Unter-Sitemaps
   eine Ebene tief nachgeladen. Schlägt der Abruf fehl, wird das nicht als
   Fataler Fehler behandelt – der Lauf verwendet dann nur `fallback_urls`.
2. **URL-Liste aufbauen** (`SitemapMonitor::buildUrlList()`): Sitemap- und
   `fallback_urls` zusammenführen, pro URL via `Social::normalizePageUrl()`
   normalisieren (**keine eigene Normalisierung** – siehe
   `docs/url-normalization.md`), nach `url_hash` deduplizieren, bekannte
   Nicht-HTML-Dateiendungen sowie `exclude_patterns` herausfiltern, auf
   `max_pages` kappen.
3. **Pro Seite abrufen** (`SitemapMonitor::fetchPage()`): cURL-GET mit Timeout,
   SSL-Verify, `delay_ms`-Pause zwischen Requests (Ziel-Server schonen).
   Nur `text/html`-Antworten werden verarbeitet.
4. **Content bereinigen & hashen** (`SitemapMonitor::cleanContent()` /
   `hashContent()`): `<script>`/`<style>` entfernen, Block-Elemente in
   Zeilenumbrüche wandeln (Struktur für Diff erhalten), Tags strippen,
   Whitespace normalisieren, SHA256 über den bereinigten Text bilden.
5. **Vergleichen & protokollieren** (`SitemapMonitor::recordResult()`):
   - Seite noch nie gesehen → Baseline in `tracked_pages` anlegen, **kein**
     `page_changes`-Eintrag (kein "Change" ohne Vergleichswert).
   - Hash identisch zum letzten Stand → nur `last_checked_at` aktualisieren.
   - Hash unterschiedlich → Zeilen-Diff gegen den zuletzt gespeicherten Text
     berechnen (`SitemapMonitor::diffLines()`, einfacher LCS-Algorithmus),
     Eintrag in `page_changes` (inkl. `diff_excerpt`, `lines_added`,
     `lines_removed`, `change_size`), `tracked_pages` aktualisieren.
   - Abruf-Fehler → `consecutive_errors` hochzählen, `content_hash`
     unverändert lassen (kein False-Positive-Change durch Fehlerseiten).
6. **Verwaiste Seiten markieren** (`SitemapMonitor::markMissingFromSitemap()`):
   Seiten, die im aktuellen Lauf nicht mehr in der URL-Liste auftauchen,
   werden auf `in_sitemap = 0` gesetzt statt gelöscht – Historie bleibt
   erhalten.
7. **Retention-Cleanup**: einmal täglich (Stundenfenster 04:00–04:59, siehe
   `cron/check_changes.php`) werden `page_changes`-Einträge älter als
   `retention_days` gelöscht. `tracked_pages` wird nicht bereinigt.

## Diff-Berechnung

`SitemapMonitor::diffLines()` vergleicht den zuvor gespeicherten bereinigten
Text (`tracked_pages.content_text`) zeilenweise mit dem neuen Text via
Longest-Common-Subsequence (reines PHP, keine Extension nötig). Ergebnis:
Anzahl hinzugefügter/entfernter Zeilen sowie ein auf ca. 4'000 Zeichen /
40 Zeilen gekapptes Diff-Excerpt im unified-diff-artigen Format (`+`/`-`
Präfix pro Zeile). `change_size` ist eine grobe Näherung (Zeichen-Differenz
zwischen altem und neuem Text), keine exakte Diff-Metrik.

## Verhältnis zu `docs/url-normalization.md`

`SitemapMonitor` normalisiert Sitemap-URLs **ausschliesslich** über
`Social::normalizePageUrl()`. Es gibt keine dritte, eigene
Normalisierungs-Implementierung. Gespeichert wird – wie bei `pageviews.url` –
der normalisierte **Pfad ohne Host/Schema** (z.B. `/segelsport/breitensport`),
damit `page_changes.url`/`tracked_pages.url` später mit `pageviews.url`
gejoint werden können (z.B. für eine Traffic-Korrelations-Auswertung).

## Anschlussstelle für Notifications

`page_changes.notified_at` ist bewusst `NULL`-bar und wird von diesem
Feature nie gesetzt. Ein künftiges, separates Notification-Feature (z.B.
`cron/send_notifications.php`) kann einfach `WHERE notified_at IS NULL`
abfragen, E-Mails versenden und danach `notified_at` setzen – ohne dass an
`SitemapMonitor` oder `check_changes.php` etwas geändert werden muss.

## Config-Optionen (`config['sitemap_monitor']`)

| Schlüssel | Bedeutung |
|---|---|
| `enabled` | Schaltet den Cron-Lauf komplett aus (`false` → sofortiger Exit) |
| `sitemap_url` | Sitemap oder Sitemap-Index; leer lassen, um nur `fallback_urls` zu nutzen |
| `fallback_urls` | Zusätzliche/alternative URLs, immer geprüft |
| `timeout` | Sekunden pro HTTP-Request |
| `delay_ms` | Pause zwischen Seitenabrufen |
| `max_pages` | Sicherheitslimit pro Lauf |
| `exclude_patterns` | Regex-Liste gegen den normalisierten Pfad |
| `retention_days` | Aufbewahrung für `page_changes` |

## Performance-Hinweise

Sequenzielle Abrufe mit `delay_ms`-Pause reichen für 50–200 Seiten locker
innerhalb des 30-Minuten-Fensters aus. `curl_multi` (paralleler Abruf) wurde
bewusst nicht verwendet – für diese Grössenordnung nicht nötig und würde
Fehlerbehandlung sowie Last auf dem Zielserver unnötig komplizieren.
