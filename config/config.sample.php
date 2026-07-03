<?php
return [
    'db' => [
        'host'    => 'localhost',
        'name'    => 'YOUR_DB_NAME',
        'user'    => 'YOUR_DB_USER',
        'pass'    => 'CHANGE_ME',
        'charset' => 'utf8mb4',
    ],
    'users' => [
        [
            'username'      => 'admin',
            // Generieren mit: php -r "echo password_hash('DEIN_PASSWORT', PASSWORD_DEFAULT);"
            'password_hash' => '',
        ],
    ],
    // Salt für anonymes Fingerprinting (zufälliger langer String)
    'salt' => 'CHANGE_ME_RANDOM_STRING_MIN_32_CHARS',
    // Token für das Einmalige Ausführen von setup/install.php
    'install_token' => 'CHANGE_ME_INSTALL_TOKEN',
    // Erlaubte Quellen für den Tracker (CORS)
    'allowed_origins' => [
        'https://www.YOUR-DOMAIN.COM',
        'https://YOUR-DOMAIN.COM',
    ],
    // Anzeigename im Dashboard und auf der Login-Seite
    'site_name' => 'YOUR_SITE_NAME',
    // Eigene Domain für Referrer-Filter (ohne https://, z.B. "YOUR-DOMAIN.COM")
    'self_domain' => 'YOUR-DOMAIN.COM',
    // Uptime-Monitoring: zu überwachende Ziele
    // 'reference' => true markiert ein Ziel als Referenz (erscheint in Status-Karten, nicht in Uptime-Auswertung)
    'uptime_targets' => [
        ['name' => 'main-site',  'url' => 'https://YOUR-DOMAIN.COM',  'reference' => false],
        ['name' => 'clubdesk',   'url' => 'https://app.clubdesk.com', 'reference' => false],
        ['name' => 'reference',  'url' => 'https://google.com',        'reference' => true],
    ],

    // Social Widget
    'social' => [
        // Basis-URL des Analytics-Servers (ohne trailing slash)
        'base_url' => 'https://stats.YOUR-DOMAIN.COM',
    ],

    // Content-Change-Detection: erkennt Änderungen an allen Sitemap-Seiten
    // (läuft via cron/check_changes.php, siehe docs/content-change-detection.md)
    'sitemap_monitor' => [
        'enabled'          => true,
        // Sitemap-URL (Sitemap-Index mit Unter-Sitemaps wird automatisch aufgelöst)
        'sitemap_url'      => 'https://YOUR-DOMAIN.COM/sitemap.xml',
        // Zusätzliche/alternative URLs, die immer geprüft werden – Ergänzung zur
        // Sitemap oder alleinige Quelle, falls sitemap_url leer bleibt oder der
        // Abruf fehlschlägt
        'fallback_urls'    => [
            // 'https://YOUR-DOMAIN.COM/',
        ],
        'timeout'          => 10,     // Sekunden pro Request
        'delay_ms'         => 200,    // Pause zwischen Seitenabrufen (Ziel-Server schonen)
        'max_pages'        => 300,    // Sicherheitslimit pro Lauf (Laufzeit-/Lastschutz)
        // Regex-Muster gegen den normalisierten Pfad – zusätzlich zu eingebauten
        // Datei-Endungs-Ausschlüssen (pdf, jpg, zip, ...)
        'exclude_patterns' => [
            // '#^/media/#',
        ],
        'retention_days'   => 180,    // Aufbewahrung page_changes-Log
    ],
];

