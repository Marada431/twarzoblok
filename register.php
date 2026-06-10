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
    $step = (int)($_POST['step'] ?? 1);
    if ($step === 2) {
        $controller->handleRegisterStep2();
    } else {
        $controller->handleRegisterStep1();
    }
} elseif (isset($_GET['back'])) {
    $old = $_SESSION['register_step1'] ?? [];
    unset($old['password']);
    $data = ['errors' => [], 'old' => $old, 'step' => 1];
    $controller->showRegister($data);
} else {
    $step = !empty($_SESSION['register_step1']) ? 2 : 1;
    $data = ['errors' => [], 'old' => [], 'step' => $step];
    $controller->showRegister($data);
}
