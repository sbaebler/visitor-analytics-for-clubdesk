<?php
$config = require __DIR__ . '/../config/config.php';
$users  = $config['users'] ?? [];

$result = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $u = $_POST['username'] ?? '';
    $p = $_POST['password'] ?? '';
    $found = false;
    foreach ($users as $user) {
        if ($user['username'] === $u) {
            $found = true;
            $ok = password_verify($p, $user['password_hash']);
            $result = 'User found. password_verify: ' . ($ok ? 'TRUE ✅' : 'FALSE ❌');
        }
    }
    if (!$found) $result = 'User NOT found in config ❌';
}
?>
<form method="post">
    <input name="username" placeholder="Benutzername"><br>
    <input name="password" type="password" placeholder="Passwort"><br>
    <button type="submit">Testen</button>
</form>
<?= $result ?>
