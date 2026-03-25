<?php
$config = require __DIR__ . '/../config/config.php';

$users = $config['users'] ?? null;

echo 'users key exists: ' . ($users !== null ? 'YES' : 'NO') . "\n";
echo 'auth key exists: ' . (isset($config['auth']) ? 'YES' : 'NO') . "\n";
echo 'user count: ' . (is_array($users) ? count($users) : 0) . "\n";

if (is_array($users)) {
    foreach ($users as $u) {
        echo 'user: ' . htmlspecialchars($u['username'])
            . ' | hash length: ' . strlen($u['password_hash']) . "\n";
    }
}

echo 'PHP version: ' . PHP_VERSION . "\n";
