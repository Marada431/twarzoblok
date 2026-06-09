<?php
session_start();
require_once __DIR__ . '/config/error_handler.php';
if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}
header('Location: chat.php');
exit;
