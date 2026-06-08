<?php
require_once dirname(__DIR__) . '/middleware.php';
require_once dirname(__DIR__) . '/helpers.php';

if (!can('manage_roles')) admin_403();

$pdo   = db();
$my_id = (int)$_SESSION['user_id'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_verify()) admin_403('Nieprawidłowy token CSRF');

    $action  = $_POST['action'] ?? '';
    $target  = (int)($_POST['user_id'] ?? 0);

    if ($target === $my_id) { flashSet('error', 'Nie możesz modyfikować własnej roli.'); header('Location: ' . BASE_URL . '/admin/users/'); exit; }

    if ($action === 'change_role') {
        $new_role = $_POST['new_role'] ?? '';
        if (!in_array($new_role, ['user','moderator','admin'], true)) { flashSet('error', 'Nieprawidłowa rola.'); header('Location: ' . BASE_URL . '/admin/users/'); exit; }
        $pdo->prepare("UPDATE users SET role = :r WHERE user_id = :id")->execute([':r' => $new_role, ':id' => $target]);
        // Pobierz username do loga
        $uname = $pdo->prepare("SELECT username FROM users WHERE user_id = :id");
        $uname->execute([':id' => $target]);
        $un = $uname->fetchColumn();
        logModAction($my_id, 'change_role', 'user', $target, "Nowa rola: $new_role dla @$un");
        flashSet('success', "Rola użytkownika @$un zmieniona na: $new_role");
        header('Location: ' . BASE_URL . '/admin/users/'); exit;
    }

    if ($action === 'delete') {
        $confirm = trim($_POST['confirm_text'] ?? '');
        if ($confirm !== 'USUŃ') { flashSet('error', 'Nieprawidłowe potwierdzenie.'); header('Location: ' . BASE_URL . '/admin/users/'); exit; }
        // Soft delete
        $pdo->prepare("UPDATE users SET deleted_at = NOW(), status = 'blocked', email = CONCAT('deleted_', user_id, '_', email) WHERE user_id = :id")
            ->execute([':id' => $target]);
        logModAction($my_id, 'delete_user', 'user', $target, 'Soft delete konta');
        flashSet('success', 'Konto użytkownika zostało usunięte.');
        header('Location: ' . BASE_URL . '/admin/users/'); exit;
    }
}

// GET – formularz edycji roli
$uid = (int)($_GET['id'] ?? 0);
if ($uid <= 0) { header('Location: ' . BASE_URL . '/admin/users/'); exit; }

$stmt = $pdo->prepare("SELECT user_id, username, email, role FROM users WHERE user_id = :id AND deleted_at IS NULL");
$stmt->execute([':id' => $uid]);
$user = $stmt->fetch();
if (!$user) { flashSet('error', 'Użytkownik nie istnieje.'); header('Location: ' . BASE_URL . '/admin/users/'); exit; }

$page_title  = 'Edycja roli';
$active_page = 'users';
require_once dirname(__DIR__) . '/components/layout_start.php';
?>

<div class="admin-page-header">
    <h1>✏️ Zmiana roli</h1>
    <a href="<?= BASE_URL ?>/admin/users/" class="btn-sm btn-secondary">← Wróć</a>
</div>

<div class="card" style="max-width:400px">
    <p class="mb-16">Użytkownik: <strong>@<?= htmlspecialchars($user['username']) ?></strong><br>
    Aktualna rola: <?= statusBadge($user['role']) ?></p>

    <form method="POST">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="change_role">
        <input type="hidden" name="user_id" value="<?= $uid ?>">
        <div class="admin-form-group">
            <label>Nowa rola</label>
            <select name="new_role" required>
                <option value="user"      <?= $user['role']==='user'?'selected':'' ?>>Użytkownik</option>
                <option value="moderator" <?= $user['role']==='moderator'?'selected':'' ?>>Moderator</option>
                <option value="admin"     <?= $user['role']==='admin'?'selected':'' ?>>Admin</option>
            </select>
        </div>
        <button type="submit" class="btn-sm btn-primary btn-lg">Zapisz zmianę roli</button>
    </form>
</div>

<?php require_once dirname(__DIR__) . '/components/layout_end.php'; ?>
