<?php
// register.php – router MVC
session_start();

if (isset($_SESSION['user_id'])) {
    header('Location: index.php');
    exit;
}

require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/csrf.php';
require_once __DIR__ . '/app/controllers/AuthController.php';

$controller = new AuthController(db());

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $controller->handleRegister();
} else {
    $controller->showRegister();
}
