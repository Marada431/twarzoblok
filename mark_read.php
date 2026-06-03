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

$userId = (int)$_SESSION['user_id'];
$chatId = isset($_POST['chat_id']) ? (int)$_POST['chat_id'] : 0;

if (!$chatId) {
    http_response_code(400);
    echo json_encode(['error' => 'Brak chat_id']);
    exit;
}

// Sprawdź uczestnictwo
$stmt = db()->prepare("SELECT 1 FROM chat_participants WHERE chat_id = ? AND user_id = ?");
$stmt->execute([$chatId, $userId]);
if (!$stmt->fetch()) {
    http_response_code(403);
    echo json_encode(['error' => 'Brak dostępu do czatu']);
    exit;
}

// Oznacz wszystkie nieodczytane wiadomości od innych jako przeczytane
$stmt = db()->prepare("
    UPDATE chat_messages
    SET is_read = 1,
        read_at  = NOW(),
        viewed_status = 'seen'
    WHERE chat_id   = ?
      AND sender_id != ?
      AND is_read   = 0
      AND status    = 'active'
");
$stmt->execute([$chatId, $userId]);
$affected = $stmt->rowCount();

// Oznacz powiązane powiadomienia o nowych wiadomościach jako przeczytane.
// Tabela notifications nie ma reference_id/chat_id, więc oznaczamy wszystkie
// nieprzeczytane 'new_message' dla tego użytkownika (MVP: otwarcie czatu = wszystko widziane).
$stmt = db()->prepare("
    UPDATE notifications
    SET status = 'read'
    WHERE user_id  = ?
      AND type     = 'new_message'
      AND status   = 'unread'
");
$stmt->execute([$userId]);

// Pobierz avatar bieżącego użytkownika (odczytującego) — potrzebny po stronie nadawcy
$stmt = db()->prepare("SELECT avatar_url FROM users WHERE user_id = ?");
$stmt->execute([$userId]);
$user = $stmt->fetch();

echo json_encode([
    'success'       => true,
    'affected'      => $affected,
    'read_at'       => date('Y-m-d H:i:s'),
    'reader_avatar' => $user['avatar_url'] ?? null
]);
