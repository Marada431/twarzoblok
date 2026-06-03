<?php
require_once dirname(dirname(__DIR__)) . '/config/database.php';
if (session_status() === PHP_SESSION_NONE) session_start();

header('Content-Type: application/json');

if (empty($_SESSION['logged_in']) || !in_array($_SESSION['role'] ?? '', ['admin', 'moderator'])) {
    echo json_encode(['error' => 'unauthorized']); exit;
}

$q   = $_GET['q'] ?? '';
$pdo = db();

if ($q === 'new_reports') {
    $count = (int)$pdo->query("SELECT COUNT(*) FROM reports WHERE status = 'new'")->fetchColumn();
    echo json_encode(['count' => $count]); exit;
}

if ($q === 'charts') {
    // Generuj etykiety ostatnie 30 dni
    $days = [];
    for ($i = 29; $i >= 0; $i--) {
        $days[] = date('Y-m-d', strtotime("-$i days"));
    }

    // Rejestracje per dzień
    $stmt = $pdo->query("SELECT DATE(created_at) AS d, COUNT(*) AS c FROM users WHERE created_at >= NOW() - INTERVAL 30 DAY GROUP BY DATE(created_at)");
    $reg_raw = array_column($stmt->fetchAll(), 'c', 'd');

    // Posty per dzień
    $stmt2 = $pdo->query("SELECT DATE(created_at) AS d, COUNT(*) AS c FROM posts WHERE created_at >= NOW() - INTERVAL 30 DAY GROUP BY DATE(created_at)");
    $posts_raw = array_column($stmt2->fetchAll(), 'c', 'd');

    $reg_data   = [];
    $posts_data = [];
    foreach ($days as $day) {
        $reg_data[]   = (int)($reg_raw[$day]   ?? 0);
        $posts_data[] = (int)($posts_raw[$day] ?? 0);
    }

    // Etykiety (ostatnie 7 dni pełne, reszta skrócone)
    $labels = array_map(fn($d) => date('d.m', strtotime($d)), $days);

    // Zgłoszenia wg typu
    $stmt3 = $pdo->query("SELECT type, COUNT(*) AS c FROM reports WHERE reported_at >= NOW() - INTERVAL 30 DAY GROUP BY type");
    $rep_raw = $stmt3->fetchAll();
    $rep_labels = array_column($rep_raw, 'type');
    $rep_data   = array_map('intval', array_column($rep_raw, 'c'));

    echo json_encode([
        'reg_labels'   => $labels,
        'reg_data'     => $reg_data,
        'posts_labels' => $labels,
        'posts_data'   => $posts_data,
        'rep_labels'   => $rep_labels,
        'rep_data'     => $rep_data,
    ]);
    exit;
}

echo json_encode(['error' => 'unknown query']);
