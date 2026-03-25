<?php

class Auth
{
    private const SESSION_KEY  = 'zs_admin';
    private const SESSION_LIFE = 28800; // 8 Stunden

    public static function start(): void
    {
        if (session_status() === PHP_SESSION_NONE) {
            session_set_cookie_params([
                'lifetime' => 0,
                'path'     => '/',
                'secure'   => true,
                'httponly' => true,
                'samesite' => 'Strict',
            ]);
            session_start();
        }
    }

    public static function check(): bool
    {
        self::start();
        if (empty($_SESSION[self::SESSION_KEY])) {
            return false;
        }
        if ((time() - ($_SESSION[self::SESSION_KEY]['ts'] ?? 0)) > self::SESSION_LIFE) {
            self::logout();
            return false;
        }
        // Session-Timeout verlängern
        $_SESSION[self::SESSION_KEY]['ts'] = time();
        return true;
    }

    public static function requireAuth(): void
    {
        if (!self::check()) {
            header('Location: /login.php');
            exit;
        }
    }

    public static function login(string $username, string $password): bool
    {
        $config = require __DIR__ . '/../config/config.php';
        $users  = $config['users'] ?? [];
        foreach ($users as $user) {
            if (
                $username === $user['username'] &&
                password_verify($password, $user['password_hash'])
            ) {
                self::start();
                session_regenerate_id(true);
                $_SESSION[self::SESSION_KEY] = ['ts' => time()];
                return true;
            }
        }
        return false;
    }

    public static function logout(): void
    {
        self::start();
        $_SESSION = [];
        session_destroy();
    }
}
