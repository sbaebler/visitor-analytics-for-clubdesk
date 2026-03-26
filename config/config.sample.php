<?php
return [
    'db' => [
        'host'    => 'localhost',
        'name'    => 'stats_zurich_sailing',  // Datenbankname bei cyon
        'user'    => 'stats_user',            // DB-Benutzer bei cyon
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
];
