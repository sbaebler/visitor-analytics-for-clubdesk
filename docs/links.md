# Zählende Weiterleitungs-Links (`/go/<slug>`)

Clubdesk zählt keine Downloads über `fileservlet`-Links. Statt der Clubdesk-URL wird
deshalb ein Link auf den Stats-Server geteilt, z.B.
`https://stats.zurich-sailing.ch/go/herbstregatta`. Er zählt den Aufruf und leitet
per `302` auf die eigentliche Datei weiter. Das funktioniert überall, wo der Link
auftaucht: Website, Mail, WhatsApp, QR-Code.

## Bestandteile

| Datei | Zweck |
|---|---|
| `public/go.php` | Öffentliche Weiterleitung, zählt den Klick |
| `public/.htaccess` | Rewrite `/go/<slug>` → `go.php?s=<slug>` |
| `public/links.php` | Admin (Login nötig): Links anlegen, Ziel ändern, Klicks ansehen |
| `src/ShortLinks.php` | Gesamte Logik und alle Queries |
| `setup/migrate_links.sql` | Tabellen `short_links`, `short_link_clicks` für bestehende Installationen |

Funktioniert der Rewrite nicht, geht immer auch `/go.php?s=<slug>`.

## Regeln

- **Slug** (Kurzname): `a-z`, `0-9`, `-`, max. 64 Zeichen, nicht mit `-` beginnend/endend.
  Leer gelassen → zufälliger 6-stelliger Slug. Der Slug ist nach dem Anlegen **nicht
  änderbar**, damit bereits geteilte Links nicht brechen. Die Ziel-URL schon (z.B. wenn
  die Datei in Clubdesk ersetzt wurde und eine neue `id`/`s` bekommt).
- **Ziel**: nur `http`/`https`. Nur eingeloggte Admins können Links anlegen, daher kein
  Domain-Allowlist (kein offener Redirect).
- **Deaktivieren** statt löschen: inaktive Links liefern 404, die Klick-Historie bleibt.
- **Kein Caching**: `Cache-Control: no-store`, sonst würde der Browser den Redirect
  merken und ein zweiter Klick käme nie beim Server an.
- **HEAD** wird weitergeleitet, aber nicht gezählt.
- Fällt die DB beim Zählen aus, wird trotzdem weitergeleitet.

## Was gezählt wird

Pro Aufruf eine Zeile in `short_link_clicks`: `ip_hash` (`SHA256(salt|IP)`, gleich wie
das Social Widget, keine Roh-IP), nur der **Host** des Referrers, Gerätetyp aus dem
User-Agent, `is_bot`.

- **Klicks** = alle Aufrufe mit `is_bot = 0`.
- **Personen** = `COUNT(DISTINCT ip_hash)`. Schätzung: mehrere Personen hinter derselben
  IP (Familie, Firma) zählen als eine, eine Person mit Handy im Mobilnetz und später im
  WLAN als zwei.
- **Bots / Link-Vorschauen** (`ShortLinks::BOT_PATTERN`): WhatsApp, iMessage
  (`facebookexternalhit … Twitterbot`), Telegram, Slack, Teams/Outlook-Vorschau,
  Suchmaschinen, `curl` usw. Sie werden gespeichert, damit man sieht wie viele es waren,
  aber in keinem Zähler mitgezählt. Wer einen Link in WhatsApp teilt, erzeugt also keinen
  Klick, erst wer darauf tippt.
- Link-Scanner von Firmen-Mailservern (z.B. Microsoft Defender Safe Links) tarnen sich
  teils als normaler Browser und können vereinzelt als Klick zählen.
- Referrer ist bei Klicks aus Mail-Apps, WhatsApp usw. leer → „Direkt“.
