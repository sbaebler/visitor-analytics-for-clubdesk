<?php
$config = require __DIR__ . '/../config/config.php';
$users = $config['users'] ?? [];

foreach ($users as $u) {
    $hash = $u['password_hash'];
    echo 'user: ' . htmlspecialchars($u['username']) . "\n";
    echo 'hash length: ' . strlen($hash) . "\n";
    echo 'last 5 chars (hex): ' . bin2hex(substr($hash, -5)) . "\n";
    echo "\n";
}
