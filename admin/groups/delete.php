<?php
require_once dirname(__DIR__) . '/middleware.php';
require_once dirname(__DIR__) . '/helpers.php';

if (!can('manage_groups')) admin_403();
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { header('Location: ' . BASE_URL . '/admin/groups/'); exit; }
if (!csrf_verify()) admin_403('Nieprawidłowy token CSRF');

$pdo      = db();
$my_id    = (int)$_SESSION['user_id'];
$group_id = (int)($_POST['group_id'] ?? 0);

if ($group_id <= 0) { flashSet('error', 'Brak ID grupy.'); header('Location: ' . BASE_URL . '/admin/groups/'); exit; }

try {
    // Soft delete – zmień status na deleted
    $pdo->prepare("UPDATE `groups` SET status = 'deleted' WHERE group_id = :gid")->execute([':gid' => $group_id]);
    // Usuń członkostwa
    $pdo->prepare("DELETE FROM group_members WHERE group_id = :gid")->execute([':gid' => $group_id]);
    logModAction($my_id, 'delete_group', 'group', $group_id, null);
    flashSet('success', "Grupa #$group_id usunięta.");
} catch (PDOException $e) {
    error_log('delete_group: ' . $e->getMessage());
    flashSet('error', 'Błąd podczas usuwania grupy.');
}

header('Location: ' . BASE_URL . '/admin/groups/');
exit;
