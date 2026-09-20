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

- **Kein Publikationsdatum.** Clubdesk liefert keines, der Tracker bekommt
  keines. Beiträge, die vor dem 3. Juli 2026 online gingen, wirken jünger als
  sie sind, weil `/beitrag/<c>` erst seit Commit `1f6d45d` erfasst wird.
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
