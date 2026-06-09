<?php
session_start();
require_once __DIR__ . '/config/error_handler.php';
if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}
// Tymczasowa strona – funkcja gier w budowie
?>
<!DOCTYPE html>
<html lang="pl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>TwarzBlok - Strefa Gier</title>
    <link rel="stylesheet" href="assets/css/main.css">
</head>
<body>
<nav class="navbar">
    <div class="navbar-brand"><a href="index.php" style="text-decoration:none;color:inherit;">TwarzBlok</a></div>
    <div class="navbar-links">
        <a href="index.php"><svg><use xlink:href="./icons/symbol-defs.svg#icon-home"></use></svg></a>
    </div>
</nav>
<div style="max-width:600px;margin:80px auto;text-align:center;padding:20px;">
    <h2>🎮 Mini Gry</h2>
    <p style="color:var(--text-muted);margin-top:16px;">Ta funkcja jest w trakcie budowy. Wróć wkrótce!</p>
    <a href="index.php" class="btn btn-primary" style="margin-top:24px;display:inline-block;">Wróć do tablicy</a>
</div>
</body>
</html>
