<?php
session_start();
header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['error' => 'Nie zalogowany']);
    exit;
}

require_once __DIR__ . '/config/database.php';

$userId = (int)$_SESSION['user_id'];

$stmt = db()->prepare("
    SELECT c.chat_id,
           COUNT(cm.message_id) AS unread_count
    FROM chats c
    JOIN chat_participants cp ON cp.chat_id = c.chat_id AND cp.user_id = ?
    JOIN chat_messages cm     ON cm.chat_id  = c.chat_id
                              AND cm.sender_id != ?
                              AND cm.is_read   = 0
                              AND cm.status    = 'active'
    WHERE c.chat_type = 'group'
    GROUP BY c.chat_id
");
$stmt->execute([$userId, $userId]);
$rows = $stmt->fetchAll();

$result = [];
foreach ($rows as $row) {
    $result[(int)$row['chat_id']] = (int)$row['unread_count'];
}

echo json_encode($result);
