<?php
declare(strict_types=1);

require_once __DIR__ . '/../src/Auth.php';

$config = require __DIR__ . '/../config/config.php';

// CSP für Login-Seite
header("Content-Security-Policy: default-src 'none'; style-src 'unsafe-inline'; form-action 'self'");
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');

Auth::start();

// Bereits eingeloggt?
if (Auth::check()) {
    header('Location: /');
    exit;
}

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // CSRF-Token prüfen
    $token = $_POST['csrf'] ?? '';
    if (!hash_equals($_SESSION['csrf'] ?? '', $token)) {
        $error = 'Ungültige Anfrage. Bitte neu laden.';
    } else {
        $username = $_POST['username'] ?? '';
        $password = $_POST['password'] ?? '';
        if (Auth::login($username, $password)) {
            header('Location: /');
            exit;
        }
        // Kurze Verzögerung gegen Brute-Force
        usleep(500000);
        $error = 'Benutzername oder Passwort falsch.';
    }
}

// CSRF-Token generieren
if (empty($_SESSION['csrf'])) {
    $_SESSION['csrf'] = bin2hex(random_bytes(32));
}
$csrfToken = $_SESSION['csrf'];
?>
<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Login – ZS Analytics</title>
    <style>
        *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif;
            background: #0A2342;
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
        }
        .card {
            background: #fff;
            border-radius: 12px;
            padding: 2.5rem 2rem;
            width: 100%;
            max-width: 380px;
            box-shadow: 0 20px 60px rgba(0,0,0,.3);
        }
        .logo {
            text-align: center;
            margin-bottom: 2rem;
        }
        .logo h1 {
            font-size: 1.3rem;
            color: #0A2342;
            font-weight: 700;
        }
        .logo p {
            font-size: .8rem;
            color: #718096;
            margin-top: .25rem;
        }
        label {
            display: block;
            font-size: .85rem;
            font-weight: 600;
            color: #2D3748;
            margin-bottom: .4rem;
        }
        input[type="text"], input[type="password"] {
            width: 100%;
            padding: .65rem .9rem;
            border: 1px solid #CBD5E0;
            border-radius: 6px;
            font-size: .95rem;
            color: #2D3748;
            background: #F7FAFC;
            margin-bottom: 1.2rem;
            transition: border-color .15s;
        }
        input:focus {
            outline: none;
            border-color: #2196F3;
            background: #fff;
        }
        .error {
            background: #FFF5F5;
            border: 1px solid #FC8181;
            color: #C53030;
            padding: .65rem .9rem;
            border-radius: 6px;
            font-size: .85rem;
            margin-bottom: 1.2rem;
        }
        button {
            width: 100%;
            padding: .75rem;
            background: #0A2342;
            color: #fff;
            border: none;
            border-radius: 6px;
            font-size: 1rem;
            font-weight: 600;
            cursor: pointer;
            transition: background .15s;
        }
        button:hover { background: #0d2d57; }
    </style>
</head>
<body>
    <div class="card">
        <div class="logo">
            <h1>⛵ <?= htmlspecialchars($config['site_name'] ?? 'Analytics') ?></h1>
            <p><?= htmlspecialchars($config['self_domain'] ?? '') ?></p>
        </div>
        <?php if ($error !== ''): ?>
            <div class="error"><?= htmlspecialchars($error) ?></div>
        <?php endif; ?>
        <form method="post" action="/login.php">
            <input type="hidden" name="csrf" value="<?= htmlspecialchars($csrfToken) ?>">
            <label for="username">Benutzername</label>
            <input type="text" id="username" name="username" autocomplete="username" required>
            <label for="password">Passwort</label>
            <input type="password" id="password" name="password" autocomplete="current-password" required>
            <button type="submit">Anmelden</button>
        </form>
    </div>
</body>
</html>
