<?php
$authFile = __DIR__ . '/../src/Auth.php';
$contents = file_get_contents($authFile);
echo 'Auth.php contains "users": ' . (str_contains($contents, "'users'") ? 'YES' : 'NO') . "\n";
echo 'Auth.php contains "auth": '  . (str_contains($contents, "'auth'")  ? 'YES' : 'NO') . "\n";
echo 'Auth.php modified: ' . date('Y-m-d H:i:s', filemtime($authFile)) . "\n";
