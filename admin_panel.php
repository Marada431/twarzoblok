<?php
session_start();

if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
    header('Location: login.php'); exit;
}
if (!in_array($_SESSION['role'] ?? '', ['admin', 'moderator'])) {
    header('Location: index.php'); exit;
}
header('Location: admin/');
exit;
