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

// Policz nieprzeczytane wiadomości od każdego znajomego (prywatne czaty)
$stmt = db()->prepare("
    SELECT cp_friend.user_id AS friend_id,
           COUNT(cm.message_id) AS unread_count
    FROM chat_participants cp_me
    JOIN chats c              ON c.chat_id     = cp_me.chat_id AND c.chat_type = 'private'
    JOIN chat_participants cp_friend ON cp_friend.chat_id = cp_me.chat_id
                                    AND cp_friend.user_id != ?
    JOIN chat_messages cm     ON cm.chat_id   = cp_me.chat_id
                              AND cm.sender_id = cp_friend.user_id
                              AND cm.is_read   = 0
                              AND cm.status    = 'active'
    WHERE cp_me.user_id = ?
    GROUP BY cp_friend.user_id
");
$stmt->execute([$userId, $userId]);
$rows = $stmt->fetchAll();

$result = [];
foreach ($rows as $row) {
    $result[(int)$row['friend_id']] = (int)$row['unread_count'];
}

echo json_encode($result);
