<?php
require_once dirname(__DIR__) . '/middleware.php';
require_once dirname(__DIR__) . '/helpers.php';

if (!can('view_reports')) admin_403();
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { header('Location: ' . BASE_URL . '/admin/reports/'); exit; }
if (!csrf_verify()) admin_403('Nieprawidłowy token CSRF');

$pdo    = db();
$my_id  = (int)$_SESSION['user_id'];
$rid    = (int)($_POST['report_id'] ?? 0);
$action = $_POST['action'] ?? '';
$note   = trim($_POST['note'] ?? '');

if ($rid <= 0) { flashSet('error', 'Brak ID zgłoszenia.'); header('Location: ' . BASE_URL . '/admin/reports/'); exit; }

// Pobierz zgłoszenie
$stmt = $pdo->prepare("SELECT * FROM reports WHERE report_id = :rid");
$stmt->execute([':rid' => $rid]);
$report = $stmt->fetch();
if (!$report) { flashSet('error', 'Zgłoszenie nie istnieje.'); header('Location: ' . BASE_URL . '/admin/reports/'); exit; }

$new_status = $_POST['status'] ?? 'in_progress';
if (!in_array($new_status, ['in_progress','resolved','rejected'], true)) $new_status = 'in_progress';

// ── AKTUALIZACJA STATUSU ────────────────────────────
if ($action === 'update_status') {
    $pdo->prepare("UPDATE reports SET status = :s, moderator_id = :mid, moderator_note = :n, resolved_at = IF(:s2 IN ('resolved','rejected'), NOW(), resolved_at) WHERE report_id = :rid")
        ->execute([':s' => $new_status, ':s2' => $new_status, ':mid' => $my_id, ':n' => $note ?: null, ':rid' => $rid]);
    logModAction($my_id, 'resolve_report', 'report', $rid, "Status: $new_status. $note");
    flashSet('success', 'Status zgłoszenia zaktualizowany.');
}

// ── USUŃ POST + ROZPATRZ ────────────────────────────
if ($action === 'delete_post' && can('delete_content')) {
    $post_id = (int)($_POST['post_id'] ?? 0);
    if ($post_id > 0) {
        try {
            $pdo->prepare("DELETE FROM posts WHERE post_id = :pid")->execute([':pid' => $post_id]);
            logModAction($my_id, 'delete_post', 'post', $post_id, "Usunięto w wyniku zgłoszenia #$rid");
        } catch (PDOException $e) { error_log('delete_post: ' . $e->getMessage()); }
    }
    $pdo->prepare("UPDATE reports SET status = 'resolved', moderator_id = :mid, moderator_note = :n, resolved_at = NOW() WHERE report_id = :rid")
        ->execute([':mid' => $my_id, ':n' => ($note ?: 'Treść usunięta'), ':rid' => $rid]);
    logModAction($my_id, 'resolve_report', 'report', $rid, 'Rozpatrzone – treść usunięta');
    flashSet('success', 'Post usunięty i zgłoszenie rozpatrzone.');
}

// ── ZABLOKUJ + ROZPATRZ ─────────────────────────────
if ($action === 'ban_and_resolve' && can('ban_user_temp')) {
    $target_id = (int)($_POST['reported_author_id'] ?? 0);
    $days      = max(1, min(365, (int)($_POST['ban_days'] ?? 7)));
    if ($target_id > 0 && $target_id !== $my_id) {
        $until = date('Y-m-d H:i:s', strtotime("+$days days"));
        $pdo->prepare("UPDATE users SET banned_until = :u, ban_reason = :r, is_banned_permanent = 0 WHERE user_id = :id")
            ->execute([':u' => $until, ':r' => "Zgłoszenie #$rid", ':id' => $target_id]);
        logModAction($my_id, 'ban_user_temp', 'user', $target_id, "Zgłoszenie #$rid, ban na $days dni");
    }
    $pdo->prepare("UPDATE reports SET status = 'resolved', moderator_id = :mid, moderator_note = :n, resolved_at = NOW() WHERE report_id = :rid")
        ->execute([':mid' => $my_id, ':n' => ($note ?: "Użytkownik zablokowany na $days dni"), ':rid' => $rid]);
    logModAction($my_id, 'resolve_report', 'report', $rid, "Rozpatrzone – użytkownik zablokowany");
    flashSet('success', "Użytkownik zablokowany i zgłoszenie rozpatrzone.");
}

header('Location: ' . BASE_URL . '/admin/reports/view.php?id=' . $rid);
exit;
