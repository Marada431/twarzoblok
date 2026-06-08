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
$chatId   = isset($_GET['chat_id'])   ? (int)$_GET['chat_id']   : 0;
$friendId = isset($_GET['friend_id']) ? (int)$_GET['friend_id'] : 0;

if (!$chatId || !$friendId) {
    http_response_code(400);
    echo json_encode(['error' => 'Brak parametrów']);
    exit;
}

// Weryfikacja uczestnictwa
$stmt = db()->prepare("SELECT 1 FROM chat_participants WHERE chat_id = ? AND user_id = ?");
$stmt->execute([$chatId, $userId]);
if (!$stmt->fetch()) {
    http_response_code(403);
    echo json_encode(['error' => 'Brak dostępu']);
    exit;
}

// Ostatnia moja wiadomość którą znajomy odczytał
$stmt = db()->prepare("
    SELECT MAX(message_id) AS last_read_id,
           MAX(read_at)    AS read_at
    FROM chat_messages
    WHERE chat_id   = ?
      AND sender_id = ?
      AND is_read   = 1
      AND status    = 'active'
");
$stmt->execute([$chatId, $userId]);
$readInfo = $stmt->fetch();

// Avatar i imię znajomego (odczytującego)
$stmt = db()->prepare("SELECT avatar_url, first_name, last_name FROM users WHERE user_id = ?");
$stmt->execute([$friendId]);
$friend = $stmt->fetch();

echo json_encode([
    'last_read_id'  => $readInfo['last_read_id'],
    'read_at'       => $readInfo['read_at'],
    'friend_avatar' => $friend['avatar_url'] ?? null,
    'friend_name'   => trim(($friend['first_name'] ?? '') . ' ' . ($friend['last_name'] ?? ''))
]);
