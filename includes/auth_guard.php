<?php
// includes/auth_guard.php
// Zastępuje stary wzorzec: require auth_check.php + if(!$_SESSION['logged_in'])
// Użycie: require_once __DIR__ . '/includes/auth_guard.php';
// Ustaw $current_user_id po dołączeniu pliku.

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (empty($_SESSION['user_id'])) {
    $redirect = urlencode($_SERVER['REQUEST_URI'] ?? '');
    header('Location: /login.php' . ($redirect ? '?redirect=' . $redirect : ''));
    exit;
}

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/auth_check.php';

$current_user_id = (int)$_SESSION['user_id'];

// Sprawdź ban przy każdym żądaniu (korzysta z istniejącej funkcji)
check_user_status(db(), $current_user_id);
