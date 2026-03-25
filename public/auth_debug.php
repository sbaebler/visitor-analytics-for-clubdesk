<?php
$hash = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['password'])) {
    $hash = password_hash($_POST['password'], PASSWORD_BCRYPT, ['cost' => 12]);
}
?>
<form method="post">
    <p>Passwort eingeben → Hash wird vom Server generiert:</p>
    <input name="password" type="password" placeholder="Passwort">
    <button type="submit">Hash generieren</button>
</form>
<?php if ($hash !== ''): ?>
<p>Hash für config.php:</p>
<code style="word-break:break-all"><?= htmlspecialchars($hash) ?></code>
<p>Länge: <?= strlen($hash) ?></p>
<?php endif; ?>
