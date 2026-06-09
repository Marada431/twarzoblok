<?php
session_start();
header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['error' => 'Nie zalogowany']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Metoda niedozwolona']);
    exit;
}

require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/csrf.php';
require_once __DIR__ . '/app/models/GroupChatModel.php';

$csrf = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? ($_POST['csrf_token'] ?? '');
if (empty($_SESSION['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $csrf)) {
    http_response_code(403);
    echo json_encode(['error' => 'Nieprawidłowy token CSRF. Odśwież stronę.']);
    exit;
}

$userId      = (int)$_SESSION['user_id'];
$name        = trim($_POST['name'] ?? '');
$description = trim($_POST['description'] ?? '');
$memberIds   = array_map('intval', (array)($_POST['member_ids'] ?? []));

if ($name === '' || mb_strlen($name) > 100) {
    http_response_code(400);
    echo json_encode(['error' => 'Podaj nazwę grupy (1–100 znaków)']);
    exit;
}

if (empty($memberIds)) {
    http_response_code(400);
    echo json_encode(['error' => 'Wybierz co najmniej jedną osobę do grupy']);
    exit;
}

// Weryfikacja że wszyscy dodawani to znajomi
$placeholders = implode(',', array_fill(0, count($memberIds), '?'));
$stmt = db()->prepare("
    SELECT addressee_id AS uid FROM friendships
    WHERE requester_id = ? AND status = 'accepted' AND addressee_id IN ($placeholders)
    UNION
    SELECT requester_id AS uid FROM friendships
    WHERE addressee_id = ? AND status = 'accepted' AND requester_id IN ($placeholders)
");
$stmt->execute(array_merge([$userId], $memberIds, [$userId], $memberIds));
$validFriends = array_map('intval', array_column($stmt->fetchAll(), 'uid'));

$invalid = array_diff($memberIds, $validFriends);
if (!empty($invalid)) {
    http_response_code(403);
    echo json_encode(['error' => 'Niektórzy użytkownicy nie są Twoimi znajomymi']);
    exit;
}

try {
    $model  = new GroupChatModel(db());
    $chatId = $model->createGroup($name, $description, $userId, $memberIds);
    echo json_encode(['chat_id' => $chatId, 'name' => $name]);
} catch (Exception $e) {
    error_log('[create_group_chat] ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['error' => 'Nie udało się utworzyć grupy']);
}
