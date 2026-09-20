# Beitrags-Auswertung – Methodik

Kanonische Spec für `public/beitraege.php` und `src/BeitragStats.php`.
Verwandt: `docs/url-normalization.md` (wie `/beitrag/<c>` entsteht).

## Grundprinzip

Die Auswertung ist **rein lesend**. Sie legt keine Tabellen an, schreibt nichts
und normalisiert keine URLs neu – laut `docs/url-normalization.md` normalisieren
Konsumenten nicht erneut, sie zerlegen höchstens den bereits gespeicherten Pfad.

## Was als Beitrag zählt

```sql
url LIKE '/beitrag/%'
AND is_cms = 0
AND SUBSTRING(url, 10, 1) COLLATE utf8mb4_bin BETWEEN 'A' AND 'Z'
```

`/beitrag/` ist exakt 9 Zeichen; Position 10 ist der erste Buchstabe des
Clubdesk-Schlüssels.

**`COLLATE utf8mb4_bin` ist nicht optional.** Die Tabelle ist
`utf8mb4_unicode_ci`; ohne dieses COLLATE wäre der Vergleich case-insensitiv
und der Filter wirkungslos.

**Warum der Filter existiert:** `setup/migrate_beitrag.sql` hat Altzeilen zu
`/beitrag/b<block>` umgeschrieben (kleines `b` plus Ziffern). Das sind
zusammengefallene News-Blöcke, keine echten Beiträge. Echte Clubdesk-Schlüssel
beginnen gross (`ND` News, `ED` Termine, `CD` Kontakte). Bewusst ein
Buchstabenbereich statt einer festen Präfixliste: das Muster in `collect.php`
erlaubt 1–3 Buchstaben, ein künftiger Typ bleibt damit automatisch erhalten.

`public/index.php` filtert die Karte „Beiträge" mit derselben Regel
(`^/beitrag/[A-Z]`), damit Übersicht und Auswertung dasselbe zeigen.

## Kennzahlen

| Kennzahl | Definition | Fallstrick |
|---|---|---|
| **Erstsichtung** | `MIN(created_at)` je Beitrag | Proxy für das Publikationsdatum – es gibt kein echtes |
| **V7** | Aufrufe mit `created_at < first_at + 7 Tage` | Nur vergleichbar, wenn der Beitrag ≥ 7 Tage alt ist (`v7_complete`) |
| **Aufrufe** | `COUNT(*)` gesamte Laufzeit | Bevorzugt systematisch ältere Beiträge |
| **Besuchertage** | `COUNT(DISTINCT fingerprint)` | Der Fingerprint enthält das Datum und rotiert täglich – das sind Besucher**tage**, keine Unique-Besucher |
| **Ø Lesezeit** | `AVG(duration)` für `0 < duration < 3600` | Nur die messbare Teilmenge; erst ab 10 Messungen ausgewiesen |
| **Likes** | `social_stats.like_count` | Join über `url`, nicht `url_hash` – siehe unten |

### Warum V7 und Gesamtaufrufe gleichwertig nebeneinander stehen

Gesamtaufrufe belohnen Alter, V7 belohnt den Start. Keine der beiden Zahlen ist
für sich die Wahrheit, deshalb sind beide Spalten sortierbar. Beiträge ohne
abgeschlossenes 7-Tage-Fenster zeigen „läuft" statt einer Teilsumme und landen
bei V7-Sortierung immer am Ende – eine Teilsumme würde jeden neuen Beitrag
fälschlich wie einen Flop aussehen lassen.

### Likes: Join über `url`, nicht `url_hash`

`Social::hashUrl()` hasht bewusst **ohne** `strtolower` (`src/Social.php`),
`SitemapMonitor::buildUrlList()` hingegen **mit** (`src/SitemapMonitor.php`).
Bei Beitragspfaden mit Grossbuchstaben sind die beiden Hashes verschieden. Der
Join über die Spalte `url` umgeht das. Zusätzlich fehlt `social_stats` ein Index
auf `url` – deshalb eine eigene Mini-Query plus Merge in PHP statt eines
`LEFT JOIN`, der die Tabelle je Beitragszeile scannen würde.

### Lebenszyklus

Je Beitrag wird erst der Anteil je Tag gebildet, dann über die Beiträge
gemittelt (Makro- statt Mikro-Mittel) – sonst bestimmt ein einzelner grosser
Beitrag die Kurve praktisch allein.

**Fehlende Tage sind der Fallstrick:** hat ein Beitrag an Tag 3 null Aufrufe,
existiert dafür keine Zeile. `AVG()` würde dann nur über die vorhandenen Zeilen
mitteln und den Anteil systematisch zu hoch schätzen. Deshalb liefert SQL
`SUM(anteil)` je Tag, und PHP teilt durch die **feste** Zahl reifer Beiträge.
Kontrolle: die Kurve muss auf 100 % summieren.

## Platzierung (welcher Beitrag steht wo)

Die Platzierung steckt **nicht** in `pageviews`: Regel 4 der Normalisierung
verwirft Basispfad und Block bewusst, damit derselbe Beitrag über alle
Trägerseiten als eine Seite zählt. `cron/check_placements.php` liest sie
deshalb täglich von der Website nach und füllt `beitrag_placements`.

### Warum Scraping funktioniert

Clubdesk rendert die Kacheln serverseitig – ein `curl` genügt:

```html
<div class="cd-tile-h-box" onclick="window.location.href='/?b=1002305&c=ND1000043&s=…'">
  <div class="cd-tile-h-main-heading">Titel</div>
  <div class="cd-tile-h-main-subheading"><time>15.09.2026</time>, Frey Rolf</div>
```

Daraus kommen Beitrag (`c`), Block (`b`), Titel, **Publikationsdatum** und Autor.
Wichtig: das **rohe** HTML auswerten – `SitemapMonitor::cleanContent()` ruft
`strip_tags()` und würde genau die `onclick`-Attribute entfernen.

### Kategorie ist der Block, nicht die Seite

Derselbe Block kann auf mehreren Seiten eingebettet sein (`1002383` hängt auf
`/regional` **und** `/regional/offen`). Die Tabelle ist deshalb n:m:
`UNIQUE (c_key, block_id, page_url)`.

Die Bezeichnung eines Blocks ist die **nächste vorangehende Überschrift** –
Clubdesk gibt News-Listen selbst keinen Titel. Gegen die Website geprüft: trifft
alle acht Blöcke. Ein Override je Block ist in
`config['beitrag_placements']['blocks']` möglich.

### Seitenliste ohne Sitemap

`/sitemap.xml` liefert 404. Die Navigation steht aber vollständig im Roh-HTML
jeder Seite, deshalb entdeckt `discoverPages()` die 21 Seiten aus der Startseite.

### Vollisten sind Pflicht für die Abdeckung

Die "Weitere Einträge"-Links tragen eine Signatur `s=`; ohne sie antwortet die
Liste mit 404. Mit ihr liefert sie den ganzen Block statt nur der neuesten
Einträge – beim News-Block 19 statt 6 Beiträge, zurück bis Dezember 2025.

### Mehrfach platzierte Beiträge fallen aus dem Vergleich

`pageviews` kennt pro Beitrag **einen** Zähler. Steht eine Meldung in zwei
Blöcken, liesse sie sich keinem Ort zuordnen – sie einem zuzuschlagen oder in
beide zu zählen wäre gleichermassen falsch. `byPlacement()` und `byGroup()`
berücksichtigen deshalb nur `placement_count === 1` und geben die Zahl der
Ausgeschlossenen zurück, damit die UI sie beziffern kann.

Die saubere Ausnahme: wird dieselbe Meldung in zwei Listen gestellt, legt
Clubdesk **zwei Objekte mit eigenen Zählern** an (z. B. `ND1000042` im
News-Block und `ND1000043` in der Hall of Fame, gleicher Titel).
`duplicateStories()` stellt solche Paare gegenüber – der Inhalt ist konstant,
nur der Ort variiert.

### Publikationsdatum

Aus `<time>` der Kachel, für Termin-Blöcke (`ED…`) nicht vorhanden. Verwendet
für die Wochentags-Auswertung ("an welchem Tag veröffentlichen?"); wie oft die
Erstsichtung einspringen musste, meldet `weekdayPerformance()` in `fallback`.

**V7 rechnet bewusst weiter ab Erstsichtung**, nicht ab Veröffentlichung: viele
Beiträge stammen von vor dem Trackingbeginn am 3. Juli 2026: ein Fenster ab
echter Veröffentlichung ergäbe dort schlicht null.

## Schwellwerte

Alle als Konstanten in `BeitragStats`, damit UI-Text und Verhalten nicht
auseinanderlaufen – die Texte interpolieren dieselben Konstanten.

| Konstante | Wert | Wirkung |
|---|---|---|
| `MIN_GROUP` | 5 | Ab hier gilt eine Gruppe als Muster; darunter „–" und graue Zeile, im Wochentags-Chart kein Balken |
| `MIN_GROUP_SHOW` | 3 | Darunter gar nicht anzeigen |
| `MIN_VIEWS_FOR_RATE` | 50 | Für Likes je 100 Aufrufe |
| `MIN_DURATION_SAMPLE` | 10 | Für Ø Lesezeit je Beitrag |
| `MATURE_DAYS_V7` | 7 | V7 gilt als abgeschlossen |
| `MATURE_DAYS_LIFECYCLE` | 14 | Beitrag fliesst in die Lebenszyklus-Kurve |
| `KEYWORD_MIN_DOCS` | 3 | Begriff erscheint in der Themen-Tabelle |

Verglichen wird durchgehend mit dem **Median**, nicht dem Mittelwert: bei diesen
Fallzahlen kippt ein einzelner viral gegangener Beitrag jeden Durchschnitt.

## Bekannte Grenzen

Diese stehen auch im UI (Karte „Was diese Zahlen nicht sagen") – hier die
technische Begründung:

- **Publikationsdatum nur teilweise.** Der Tracker bekommt keines; der
  Platzierungs-Monitor liest es aus der Kachel. Termin-Blöcke haben keines, und
  Beiträge, die aus allen Listen gefallen sind, auch nicht – dort greift die
  Erstsichtung.
- **Bruchstelle September 2026.** Bis zum Kettenfix in Regel 4 zählten Beiträge,
  die über eine Vollliste geöffnet wurden, als Startseiten-Aufruf. Ein Anstieg
  der Beitragszahlen zu diesem Zeitpunkt ist ein Mess-, kein Reichweiteneffekt.
- **Herkunft vor dem tracker.js-Fix.** `lastTrackedUrl` wurde beim verzögerten
  Senden bereits überschrieben, client-seitig geöffnete Beiträge meldeten
  deshalb ihre eigene URL als Referrer. Solche Zeilen laufen in `origins()`
  bewusst als "Herkunft nicht erfasst" statt als interne Navigation.
- **Kein Inventar.** Ein Beitrag ohne einen einzigen Aufruf existiert in
  `pageviews` nicht. „Wenig Aufrufe" ist immer relativ zu den gemessenen.
- **Keine Wiederkehrer.** Der Tracker-Fingerprint rotiert täglich (Datenschutz,
  siehe `.claude/skills/analytics/SKILL.md`). Über Tage hinweg ist keine
  Wiedererkennung möglich – bewusst nicht.
- **Keine Outbound-Klicks je Beitrag.** `collect.php` speichert `events.url`
  unnormalisiert (nur `sanitizeUrl`, nicht `normalizePageUrl`); der Klick landet
  beim Basispfad. Die Kennzahl fehlt deshalb ganz, statt geschätzt zu werden.
- **Newsletter sieht aus wie „direkt".** Klicks aus Apple Mail oder der
  Outlook-App kommen ohne Referrer an. Nur Webmail ist als „E-Mail" erkennbar –
  die Zahl ist eine Untergrenze.
- **Stundenangaben** folgen der Zeitzone der DB-Sitzung. Sie wird in der
  Methodik-Karte ausgewiesen, damit ein Versatz sichtbar wird statt still die
  Aussage „abends wird gelesen" zu verschieben.

## Erweitern

- **Neue Kennzahl je Beitrag:** in `BeitragStats::overview()` ergänzen, in
  `enrich()` die Rate samt Mindest-Stichprobe, dann die Spalte in
  `beitraege.php`. Keine neue Query – die eine Aggregat-Query deckt alles ab.
- **Neues Muster:** wenn es aus `overview()` ableitbar ist, als reine
  PHP-Methode (wie `weekdayPerformance()`, `byType()`, `keywordStats()`). Nur
  für echte Rohdaten-Aggregate eine eigene Query.
- **Stoppwörter:** Konstante `STOPWORDS` in `BeitragStats`.
- **Neuer Chart:** Funktion in `dashboard.js`, Aufruf in `ZSDash.initBeitraege`.
  Chart.js kommt von `cdn.jsdelivr.net`; die CSP in `beitraege.php` erlaubt
  genau diese Quelle – Plugins von anderen Hosts werden blockiert.
