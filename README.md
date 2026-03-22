# stats.zurich-sailing.ch – Analytics

Self-hosted, cookielose Besucherstatistiken für zurich-sailing.ch.

## Stack
- PHP 8.x + MariaDB auf cyon.ch (LiteSpeed)
- Vanilla JavaScript Tracking-Snippet
- Kein Cookie, kein Cookie-Banner (DSG-konform)

## Deployment

### 1. Subdomain bei cyon anlegen
- Subdomain: `stats.zurich-sailing.ch`
- Document Root auf das `public/`-Verzeichnis zeigen lassen
- HTTPS/Let's Encrypt aktivieren

### 2. Datenbank anlegen
Bei cyon im Control Panel eine MariaDB-Datenbank erstellen.

### 3. Konfiguration
```bash
cp config/config.sample.php config/config.php
```
`config/config.php` ausfüllen:
- DB-Zugangsdaten eintragen
- `salt` = langer, zufälliger String (z.B. `openssl rand -hex 32`)
- `install_token` = beliebiges Passwort für einmaligen Setup-Aufruf
- Passwort-Hash generieren:
  ```bash
  php -r "echo password_hash('DEIN_PASSWORT', PASSWORD_DEFAULT);"
  ```
  In `auth.password_hash` eintragen.

### 4. Tabellen erstellen
Einmalig aufrufen (danach URL aus der Datei entfernen oder Datei löschen):
```
https://stats.zurich-sailing.ch/setup/install.php?token=DEIN_INSTALL_TOKEN
```

### 5. Tracker auf zurich-sailing.ch einbinden
Im Clubdesk HTML-Modul folgenden Code einfügen:
```html
<script src="https://stats.zurich-sailing.ch/tracker.js" defer></script>
```

### 6. Dashboard
```
https://stats.zurich-sailing.ch/
```

## Sicherheitshinweise
- `config/config.php` ist in `.gitignore` – nie committen
- `setup/install.php` nach dem ersten Aufruf löschen oder über cyon-Panel sperren
- Alle Security Headers sind in `.htaccess` gesetzt (HSTS, X-Frame, etc.)
- CORS beschränkt auf `*.zurich-sailing.ch`
- IP-Adressen werden nie gespeichert (nur täglicher SHA-256-Hash)

## Datenschutz (DSG)
- Cookielos: kein Cookie-Banner erforderlich
- Kein Fingerprinting über Tage hinweg (täglicher Salt im Hash)
- Daten liegen auf eigenem Server in der Schweiz (cyon, Basel)
