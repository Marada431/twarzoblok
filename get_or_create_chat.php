<?php
session_start();
header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['error' => 'Nie zalogowany']);
    exit;
}

require_once __DIR__ . '/config/database.php';

$userId   = (int)$_SESSION['user_id'];
$friendId = isset($_GET['friend_id']) ? (int)$_GET['friend_id'] : 0;
if (!$friendId) {
    http_response_code(400);
    echo json_encode(['error' => 'Brak friend_id']);
    exit;
}

// Sprawdzenie znajomości
$stmt = db()->prepare("
    SELECT 1 FROM friendships
    WHERE status = 'accepted'
      AND ((requester_id = ? AND addressee_id = ?) OR (requester_id = ? AND addressee_id = ?))
");
$stmt->execute([$userId, $friendId, $friendId, $userId]);
if (!$stmt->fetch()) {
    http_response_code(403);
    echo json_encode(['error' => 'Nie jesteście znajomymi']);
    exit;
}

// Sprawdzenie blokady
$stmt = db()->prepare("SELECT 1 FROM user_blocks WHERE (blocker_id = ? AND blocked_id = ?) OR (blocker_id = ? AND blocked_id = ?)");
$stmt->execute([$userId, $friendId, $friendId, $userId]);
if ($stmt->fetch()) {
    http_response_code(403);
    echo json_encode(['error' => 'Użytkownik zablokowany']);
    exit;
}

// Szukaj istniejącego czatu prywatnego
$stmt = db()->prepare("
    SELECT c.chat_id FROM chats c
    JOIN chat_participants p1 ON c.chat_id = p1.chat_id AND p1.user_id = ?
    JOIN chat_participants p2 ON c.chat_id = p2.chat_id AND p2.user_id = ?
    WHERE c.chat_type = 'private'
    LIMIT 1
");
$stmt->execute([$userId, $friendId]);
$chat = $stmt->fetch();

if ($chat) {
    echo json_encode(['chat_id' => $chat['chat_id']]);
    exit;
}

// Tworzenie nowego czatu
db()->beginTransaction();
try {
    $stmt = db()->prepare("INSERT INTO chats (chat_type, created_by) VALUES ('private', ?)");
    $stmt->execute([$userId]);
    $chatId = db()->lastInsertId();

    $stmt = db()->prepare("INSERT INTO chat_participants (chat_id, user_id) VALUES (?, ?), (?, ?)");
    $stmt->execute([$chatId, $userId, $chatId, $friendId]);

    db()->commit();
    echo json_encode(['chat_id' => $chatId]);
} catch (Exception $e) {
    db()->rollBack();
    http_response_code(500);
    echo json_encode(['error' => 'Nie udało się utworzyć czatu']);
}