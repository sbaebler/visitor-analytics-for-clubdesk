<?php
return [
    'db' => [
        'host'    => 'localhost',
        'name'    => 'stats_zurich_sailing',  // Datenbankname bei cyon
        'user'    => 'stats_user',            // DB-Benutzer bei cyon
        'pass'    => 'CHANGE_ME',
        'charset' => 'utf8mb4',
    ],
    'auth' => [
        'username'      => 'admin',
        // Generieren mit: php -r "echo password_hash('DEIN_PASSWORT', PASSWORD_DEFAULT);"
        'password_hash' => '',
    ],
    // Salt für anonymes Fingerprinting (zufälliger langer String)
    'salt' => 'CHANGE_ME_RANDOM_STRING_MIN_32_CHARS',
    // Token für das Einmalige Ausführen von setup/install.php
    'install_token' => 'CHANGE_ME_INSTALL_TOKEN',
    // Erlaubte Quellen für den Tracker (CORS)
    'allowed_origins' => [
        'https://www.zurich-sailing.ch',
        'https://zurich-sailing.ch',
    ],
];
