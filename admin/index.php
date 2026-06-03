<?php
require_once __DIR__ . '/middleware.php';
require_once __DIR__ . '/helpers.php';

if (can('view_dashboard')) {
    header('Location: ' . BASE_URL . '/admin/dashboard.php');
} else {
    header('Location: ' . BASE_URL . '/admin/reports/');
}
exit;
