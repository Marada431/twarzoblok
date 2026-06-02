<?php
session_start();
header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['error' => 'Nie zalogowany']);
    exit;
}

require_once __DIR__ . '/config/database.php';

$chatId = $_GET['chat_id'] ?? null;
if (!$chatId || !is_numeric($chatId)) {
    http_response_code(400);
    echo json_encode(['error' => 'Brak chat_id']);
    exit;
}

// Czy uczestnik?
$stmt = db()->prepare("SELECT 1 FROM chat_participants WHERE chat_id = ? AND user_id = ?");
$stmt->execute([$chatId, $_SESSION['user_id']]);
if (!$stmt->fetch()) {
    http_response_code(403);
    echo json_encode(['error' => 'Brak dostępu']);
    exit;
}

$stmt = db()->prepare("
    SELECT cm.message_id, cm.sender_id, u.username, cm.content, cm.sent_at, cm.message_type
    FROM chat_messages cm
    JOIN users u ON cm.sender_id = u.user_id
    WHERE cm.chat_id = ? AND cm.status = 'active'
    ORDER BY cm.sent_at ASC
    LIMIT 100
");
$stmt->execute([$chatId]);
$messages = $stmt->fetchAll();

echo json_encode(['messages' => $messages]);