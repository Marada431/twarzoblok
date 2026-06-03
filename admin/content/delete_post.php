<?php
require_once dirname(dirname(__DIR__)) . '/config/database.php';
require_once dirname(__DIR__) . '/middleware.php';
require_once dirname(__DIR__) . '/helpers.php';

if (!can('delete_content')) admin_403();
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { header('Location: ' . BASE_URL . '/admin/reports/'); exit; }
if (!csrf_verify()) admin_403('Nieprawidłowy token CSRF');

$pdo     = db();
$my_id   = (int)$_SESSION['user_id'];
$post_id = (int)($_POST['post_id'] ?? 0);
$note    = trim($_POST['note'] ?? '');
$ref     = $_POST['referer'] ?? BASE_URL . '/admin/reports/';

if ($post_id <= 0) { flashSet('error', 'Brak ID posta.'); header('Location: ' . $ref); exit; }

try {
    // Pobierz media do usunięcia
    $media = $pdo->prepare("SELECT file_url FROM post_media WHERE post_id = :pid");
    $media->execute([':pid' => $post_id]);
    foreach ($media->fetchAll() as $m) {
        $path = dirname(dirname(__DIR__)) . '/' . $m['file_url'];
        if (file_exists($path)) @unlink($path);
    }
    $pdo->prepare("DELETE FROM posts WHERE post_id = :pid")->execute([':pid' => $post_id]);
    logModAction($my_id, 'delete_post', 'post', $post_id, $note ?: null);
    flashSet('success', "Post #$post_id usunięty.");
} catch (PDOException $e) {
    error_log('delete_post: ' . $e->getMessage());
    flashSet('error', 'Błąd podczas usuwania posta.');
}

header('Location: ' . $ref);
exit;
