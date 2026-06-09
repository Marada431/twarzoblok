<?php
session_start();
header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['error' => 'Nie zalogowany']);
    exit;
}

require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/app/models/GroupChatModel.php';

$userId = (int)$_SESSION['user_id'];
$model  = new GroupChatModel(db());

$action = $_GET['action'] ?? ($_POST['action'] ?? '');

// ── GET: lista uczestników ─────────────────────────────────
if ($action === 'members') {
    $chatId = isset($_GET['chat_id']) ? (int)$_GET['chat_id'] : 0;
    if (!$chatId || !$model->isMember($chatId, $userId)) {
        http_response_code(403);
        echo json_encode(['error' => 'Brak dostępu']);
        exit;
    }
    echo json_encode($model->getMembers($chatId));
    exit;
}

// ── POST: mutacje ──────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Metoda niedozwolona']);
    exit;
}

$csrf = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? ($_POST['csrf_token'] ?? '');
if (empty($_SESSION['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $csrf)) {
    http_response_code(403);
    echo json_encode(['error' => 'Nieprawidłowy token CSRF. Odśwież stronę.']);
    exit;
}

$chatId = (int)($_POST['chat_id'] ?? 0);
if (!$chatId || !$model->isMember($chatId, $userId)) {
    http_response_code(403);
    echo json_encode(['error' => 'Brak dostępu do tej grupy']);
    exit;
}

// ── Dodaj uczestnika ───────────────────────────────────────
if ($action === 'add') {
    if (!$model->isAdmin($chatId, $userId)) {
        http_response_code(403);
        echo json_encode(['error' => 'Tylko administrator może dodawać uczestników']);
        exit;
    }
    $newUserId = (int)($_POST['user_id'] ?? 0);
    if (!$newUserId) {
        http_response_code(400);
        echo json_encode(['error' => 'Brak user_id']);
        exit;
    }
    $stmt = db()->prepare("
        SELECT 1 FROM friendships
        WHERE status = 'accepted'
          AND ((requester_id = ? AND addressee_id = ?)
            OR (requester_id = ? AND addressee_id = ?))
    ");
    $stmt->execute([$userId, $newUserId, $newUserId, $userId]);
    if (!$stmt->fetch()) {
        http_response_code(403);
        echo json_encode(['error' => 'Ten użytkownik nie jest Twoim znajomym']);
        exit;
    }
    $result = $model->addMember($chatId, $newUserId, 'member', $userId);
    echo json_encode([
        'success' => true,
        'message' => $result ? 'Dodano uczestnika' : 'Użytkownik już jest w grupie',
    ]);
    exit;
}

// ── Usuń uczestnika ────────────────────────────────────────
if ($action === 'remove') {
    $targetId = (int)($_POST['user_id'] ?? 0);
    if (!$targetId) {
        http_response_code(400);
        echo json_encode(['error' => 'Brak user_id']);
        exit;
    }
    if ($targetId !== $userId && !$model->isAdmin($chatId, $userId)) {
        http_response_code(403);
        echo json_encode(['error' => 'Brak uprawnień']);
        exit;
    }
    if ($model->isAdmin($chatId, $targetId)) {
        $members    = $model->getMembers($chatId);
        $adminCount = count(array_filter($members, fn($m) => $m['role'] === 'admin'));
        if ($adminCount <= 1) {
            http_response_code(400);
            echo json_encode(['error' => 'Nie można usunąć jedynego administratora grupy']);
            exit;
        }
    }
    echo json_encode(['success' => $model->removeMember($chatId, $targetId)]);
    exit;
}

// ── Opuść grupę ────────────────────────────────────────────
if ($action === 'leave') {
    if ($model->isAdmin($chatId, $userId)) {
        $members    = $model->getMembers($chatId);
        $adminCount = count(array_filter($members, fn($m) => $m['role'] === 'admin'));
        if ($adminCount <= 1 && count($members) > 1) {
            http_response_code(400);
            echo json_encode(['error' => 'Jesteś jedynym administratorem. Mianuj kogoś przed wyjściem z grupy.']);
            exit;
        }
    }
    echo json_encode(['success' => $model->removeMember($chatId, $userId)]);
    exit;
}

http_response_code(400);
echo json_encode(['error' => 'Nieznana akcja']);
