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
$chatId = (int)$chatId;

// Weryfikacja uczestnictwa
$stmt = db()->prepare("SELECT 1 FROM chat_participants WHERE chat_id = ? AND user_id = ?");
$stmt->execute([$chatId, $_SESSION['user_id']]);
if (!$stmt->fetch()) {
    http_response_code(403);
    echo json_encode(['error' => 'Brak dostępu']);
    exit;
}

// Paginacja: before_id ładuje wiadomości starsze niż dany ID
$beforeId = isset($_GET['before_id']) && is_numeric($_GET['before_id'])
    ? (int)$_GET['before_id']
    : PHP_INT_MAX;

$limit = 50;

$stmt = db()->prepare("
    SELECT cm.message_id, cm.sender_id, u.username, u.avatar_url,
           cm.content, cm.attachment_url, cm.sent_at, cm.message_type
    FROM chat_messages cm
    JOIN users u ON cm.sender_id = u.user_id
    WHERE cm.chat_id = ? AND cm.status = 'active' AND cm.message_id < ?
    ORDER BY cm.sent_at DESC
    LIMIT ?
");
$stmt->execute([$chatId, $beforeId, $limit]);
$messages = array_reverse($stmt->fetchAll());

echo json_encode([
    'messages' => $messages,
    'has_more'  => count($messages) === $limit
]);
