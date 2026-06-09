<?php
session_start();
header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['error' => 'Nie zalogowany']);
    exit;
}

require_once __DIR__ . '/includes/csrf.php';
$csrf = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? ($_POST['csrf_token'] ?? '');
verify_csrf_token($csrf);

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || empty($_FILES['image'])) {
    http_response_code(400);
    echo json_encode(['error' => 'Brak pliku']);
    exit;
}

$file = $_FILES['image'];
$allowedMime = ['image/jpeg', 'image/png', 'image/webp', 'image/gif'];
$maxSize = 10 * 1024 * 1024; // 10 MB

if ($file['error'] !== UPLOAD_ERR_OK) {
    $errMap = [
        UPLOAD_ERR_INI_SIZE   => 'Plik za duży (limit serwera)',
        UPLOAD_ERR_FORM_SIZE  => 'Plik za duży (limit formularza)',
        UPLOAD_ERR_PARTIAL    => 'Plik przesłany częściowo',
        UPLOAD_ERR_NO_FILE    => 'Nie wybrano pliku',
    ];
    echo json_encode(['error' => $errMap[$file['error']] ?? 'Błąd przesyłania']);
    exit;
}

if ($file['size'] > $maxSize) {
    echo json_encode(['error' => 'Plik za duży (max 10 MB)']);
    exit;
}

// Walidacja MIME z zawartości pliku (nie z nagłówka HTTP)
$finfo = new finfo(FILEINFO_MIME_TYPE);
$mime = $finfo->file($file['tmp_name']);

if (!in_array($mime, $allowedMime, true)) {
    echo json_encode(['error' => 'Niedozwolony typ pliku. Dozwolone: JPG, PNG, WebP, GIF']);
    exit;
}

$extMap = [
    'image/jpeg' => 'jpg',
    'image/png'  => 'png',
    'image/webp' => 'webp',
    'image/gif'  => 'gif',
];
$ext = $extMap[$mime];

$uploadDir = __DIR__ . '/uploads/chat/';
if (!is_dir($uploadDir)) {
    mkdir($uploadDir, 0755, true);
}

$filename = uniqid('chat_', true) . '_' . $_SESSION['user_id'] . '.' . $ext;
$destPath = $uploadDir . $filename;

if (!move_uploaded_file($file['tmp_name'], $destPath)) {
    echo json_encode(['error' => 'Nie udało się zapisać pliku na serwerze']);
    exit;
}

echo json_encode(['url' => 'uploads/chat/' . $filename]);
