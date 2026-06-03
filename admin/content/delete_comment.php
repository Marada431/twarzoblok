<?php
require_once dirname(dirname(__DIR__)) . '/config/database.php';
require_once dirname(__DIR__) . '/middleware.php';
require_once dirname(__DIR__) . '/helpers.php';

if (!can('delete_content')) admin_403();
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { header('Location: ' . BASE_URL . '/admin/reports/'); exit; }
if (!csrf_verify()) admin_403('Nieprawidłowy token CSRF');

$pdo        = db();
$my_id      = (int)$_SESSION['user_id'];
$comment_id = (int)($_POST['comment_id'] ?? 0);
$note       = trim($_POST['note'] ?? '');
$ref        = $_POST['referer'] ?? BASE_URL . '/admin/reports/';

if ($comment_id <= 0) { flashSet('error', 'Brak ID komentarza.'); header('Location: ' . $ref); exit; }

try {
    $pdo->prepare("UPDATE comments SET visibility_status = 'hidden' WHERE comment_id = :cid")->execute([':cid' => $comment_id]);
    logModAction($my_id, 'delete_comment', 'comment', $comment_id, $note ?: null);
    flashSet('success', "Komentarz #$comment_id ukryty.");
} catch (PDOException $e) {
    error_log('delete_comment: ' . $e->getMessage());
    flashSet('error', 'Błąd podczas ukrywania komentarza.');
}

header('Location: ' . $ref);
exit;
