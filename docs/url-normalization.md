# URL-Normalisierung

Kanonische Spezifikation, wie Seiten-URLs in *visitor-analytics-for-clubdesk* normalisiert
werden. **Single Source of Truth** für alle Stellen, die URLs verarbeiten. Gilt einheitlich für
alle Clubdesk-Webseiten.

> ⚠️ **Pflege:** Ändert sich die Logik, müssen **alle drei** zusammen angepasst werden:
> `public/collect.php` (`normalizePageUrl()`), `src/Social.php` (`Social::normalizePageUrl()`)
> **und** diese Datei. Die beiden PHP-Funktionen müssen byte-identisch bleiben (sonst stimmen
> Widget-View-Counts nicht mehr mit dem Dashboard überein).

## Grundprinzip: einmal beim Ingest normalisieren

Die Normalisierung passiert **genau einmal** – beim Empfang eines Pageviews in
`public/collect.php`. Was in `pageviews.url` gespeichert wird, ist bereits das normalisierte
Ergebnis.

**Konsumenten normalisieren nicht erneut.** Dashboard, Social-Widget und Auswertungen verlassen
sich auf die gespeicherte, normalisierte Form. Sie führen höchstens eine reine **Pfad-Extraktion**
zur Anzeige/Gruppierung durch (siehe unten) – aber niemals abweichende Normalisierungsregeln.

## Ingest-Normalisierung (Schritt für Schritt)

Eingabe: rohe URL vom Tracker. Ausgabe: `[$normalized, $host]`.

Aus der URL werden zunächst `host`, `path` und `query` via `parse_url()` extrahiert. Dann:

1. **CMS-Editor-URLs unverändert lassen.**
   Wenn `host === 'app.clubdesk.com'` → die rohe URL **unverändert** zurückgeben (inkl. Query).
   Das ist die Editor-Ansicht im Clubdesk-CMS. Solche Zeilen werden später als `is_cms = 1`
   markiert und enthalten bewusst die volle URL inkl. Host und Query.

2. **Site-ID aus Clubdesk-Subdomains entfernen.**
   Wenn `host` auf `.clubdesk.com` endet → die erste Pfadkomponente (Site-ID) entfernen:
   `preg_replace('#^/[^/]+#', '', $path)`. Beispiel: `/myclub-abc123/foo` → `/foo`.

3. **`/willkommen` auf Root abbilden.**
   `/willkommen` und `/willkommen/` sind dieselbe Seite wie `/`.

4. **Beitrag/Detail-Objekt (`?c=…`) → eigene Seite.**
   Enthält der Query einen Parameter `c`, der dem Muster `^[A-Za-z]{1,3}\d+$` entspricht
   (Clubdesk-Detail-Objekt, z. B. `ND…`=News, `ED…`=Events, `CD…`=Kontakte/Clubs), wird die
   Normalisierung **kurzgeschlossen** und ein synthetischer Pfad `/beitrag/<c>` zurückgegeben.

   `c` kann eine **Kette** sein: die Vollisten ("Weitere Einträge") liefern
   `c=NL,ND1000043` – erst der Listen-Kontext (`NL`/`EL`, ohne Ziffern), dann der Beitrag.
   Massgeblich ist das **letzte Glied mit Ziffern**. Ein reines `c=NL` ist nur die Liste
   und ergibt keinen Beitrag.

   > Bis September 2026 prüfte die Regel den Wert nur als Ganzes. Beiträge, die über eine
   > Vollliste geöffnet wurden, fielen deshalb durch, `c` wurde verworfen, und der Aufruf
   > landete auf der Trägerseite – meist der Startseite. Die Beitragszahlen waren dadurch
   > zu tief, die Startseite zu hoch.
   `b` (News-Block) und `s` (Signatur) fliessen bewusst **nicht** in den Schlüssel, ebenso wird
   der Basispfad verworfen – so zählt derselbe Beitrag über verschiedene Blöcke/Trägerseiten als
   **eine** Seite. Greift nur für Besucher-URLs (nicht für `app.clubdesk.com`, siehe Regel 1).

5. **Tracking-Parameter aus dem Query-String entfernen**, damit nicht jede Variante als eigene
   Seite zählt:
   - Clubdesk: `c` (falls kein gültiges Detail-Objekt nach Regel 4), `b` (News-Block), `s`
     (Signatur), `rfb` (Formular-Referenz)
   - Marketing/Klick-Tracker: alle `utm_*` sowie
     `fbclid`, `gclid`, `dclid`, `gclsrc`, `msclkid`, `yclid`, `twclid`, `igshid`, `mc_cid`,
     `mc_eid`, `wsidchk`, `pdata`
   - Verbleibende Parameter bleiben erhalten (werden via `http_build_query()` neu zusammengesetzt).

6. **Ergebnis zusammensetzen:** `path` (Fallback `/`) plus `?query`, falls noch Parameter übrig.
   Der `host` wird separat zurückgegeben (und in `pageviews.host` gespeichert).

### Beispiel Beitrag

`https://zurich-sailing.ch/?b=1002305&c=ND1000030&s=djEt…` → `/beitrag/ND1000030`.
Der Beitrags-Titel steht wie bei normalen Seiten in `pageviews.page_title` und wird im Dashboard
als Label verwendet.

## Folgen für die gespeicherten Daten

- **Besucher-Seiten (`is_cms = 0`)** stehen als reine Pfade in `pageviews.url`
  (z. B. `/`, `/segelsport/breitensport/zlc`), ggf. mit verbleibendem Query.
- **CMS-Seiten (`is_cms = 1`)** enthalten die **volle** `app.clubdesk.com`-URL – sie wurden nach
  Regel 1 absichtlich nicht beschnitten.

## Regel für Konsumenten (Dashboard / Social / Auswertungen)

Nicht neu normalisieren. Für Anzeige oder Gruppierung nach Pfad gilt eine reine
**Pfad-Extraktion**:

- Query abschneiden,
- Host/Schema entfernen (`parse_url(PHP_URL_PATH)` bzw. SQL `REGEXP_REPLACE(url, '^https?://[^/]+', '')`),
- Trailing Slash trimmen (außer Root `/`).

Beispiel: die gespeicherte CMS-URL `https://app.clubdesk.com/clubdesk/www/zsv-sstr54` wird zur
Anzeige zu `/clubdesk/www/zsv-sstr54`. So bleiben Widget-View-Counts und Dashboard-Gruppierung
konsistent.
