<?php
require_once dirname(__DIR__) . '/middleware.php';
require_once dirname(__DIR__) . '/helpers.php';

if (!can('ban_user_temp')) admin_403();

$pdo    = db();
$uid    = (int)($_GET['id'] ?? $_POST['user_id'] ?? 0);
$my_id  = (int)$_SESSION['user_id'];

if ($uid <= 0) { header('Location: ' . BASE_URL . '/admin/users/'); exit; }
if ($uid === $my_id) { flashSet('error', 'Nie możesz zablokować samego siebie.'); header('Location: ' . BASE_URL . '/admin/users/'); exit; }

// Pobierz dane użytkownika
$stmt = $pdo->prepare("SELECT user_id, username, status, banned_until, ban_reason, is_banned_permanent FROM users WHERE user_id = :uid AND deleted_at IS NULL");
$stmt->execute([':uid' => $uid]);
$user = $stmt->fetch();

if (!$user) { flashSet('error', 'Użytkownik nie istnieje.'); header('Location: ' . BASE_URL . '/admin/users/'); exit; }

$error = $success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_verify()) admin_403('Nieprawidłowy token CSRF');

    $action = $_POST['action'] ?? '';

    if ($action === 'ban_temp') {
        $days   = max(1, min(365, (int)($_POST['days'] ?? 1)));
        $reason = trim($_POST['reason'] ?? '');
        $until  = date('Y-m-d H:i:s', strtotime("+$days days"));
        $pdo->prepare("UPDATE users SET banned_until = :u, ban_reason = :r, is_banned_permanent = 0 WHERE user_id = :id")
            ->execute([':u' => $until, ':r' => $reason ?: null, ':id' => $uid]);
        logModAction($my_id, 'ban_user_temp', 'user', $uid, "Zablokowano na $days dni. Powód: $reason");
        flashSet('success', "Użytkownik @{$user['username']} zablokowany na $days dni.");
        header('Location: ' . BASE_URL . '/admin/users/'); exit;
    }

    if ($action === 'ban_perm' && can('manage_users')) {
        $reason = trim($_POST['reason'] ?? '');
        $pdo->prepare("UPDATE users SET status = 'blocked', is_banned_permanent = 1, ban_reason = :r WHERE user_id = :id")
            ->execute([':r' => $reason ?: null, ':id' => $uid]);
        logModAction($my_id, 'ban_user_permanent', 'user', $uid, "Trwała blokada. Powód: $reason");
        flashSet('success', "Użytkownik @{$user['username']} zablokowany trwale.");
        header('Location: ' . BASE_URL . '/admin/users/'); exit;
    }

    if ($action === 'unban') {
        $pdo->prepare("UPDATE users SET status = 'active', banned_until = NULL, ban_reason = NULL, is_banned_permanent = 0 WHERE user_id = :id")
            ->execute([':id' => $uid]);
        logModAction($my_id, 'unban_user', 'user', $uid, null);
        flashSet('success', "Użytkownik @{$user['username']} odblokowany.");
        header('Location: ' . BASE_URL . '/admin/users/'); exit;
    }
}

$is_banned_now = $user['is_banned_permanent'] || ($user['banned_until'] && strtotime($user['banned_until']) > time());

$page_title  = 'Blokada użytkownika';
$active_page = 'users';
require_once dirname(__DIR__) . '/components/layout_start.php';
?>

<div class="admin-page-header">
    <h1>🚫 Blokada użytkownika</h1>
    <a href="<?= BASE_URL ?>/admin/users/" class="btn-sm btn-secondary">← Wróć</a>
</div>

<div class="card" style="max-width:560px">
    <div style="display:flex;align-items:center;gap:12px;margin-bottom:20px">
        <div class="av-ph" style="width:48px;height:48px;font-size:22px;border-radius:50%;background:var(--border-color);display:flex;align-items:center;justify-content:center">👤</div>
        <div>
            <div style="font-weight:700;font-size:16px">@<?= htmlspecialchars($user['username']) ?></div>
            <div><?= statusBadge($user['status']) ?> <?= $user['is_banned_permanent'] ? statusBadge('blocked').' (permanentna)' : '' ?></div>
            <?php if ($user['banned_until']): ?>
                <div class="text-muted" style="font-size:12px">Zablokowany do: <?= fmtDate($user['banned_until']) ?></div>
            <?php endif; ?>
            <?php if ($user['ban_reason']): ?>
                <div class="text-muted" style="font-size:12px">Powód: <?= htmlspecialchars($user['ban_reason']) ?></div>
            <?php endif; ?>
        </div>
    </div>

    <?php if ($is_banned_now): ?>
        <form method="POST">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="unban">
            <input type="hidden" name="user_id" value="<?= $uid ?>">
            <button type="submit" class="btn-sm btn-primary btn-lg">✓ Odblokuj użytkownika</button>
        </form>
        <hr style="margin:20px 0">
    <?php endif; ?>

    <!-- Tymczasowa blokada -->
    <h3 style="font-size:15px;margin-bottom:12px">Blokada tymczasowa</h3>
    <form method="POST">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="ban_temp">
        <input type="hidden" name="user_id" value="<?= $uid ?>">
        <div class="admin-form-group">
            <label>Liczba dni (1–365)</label>
            <input type="number" name="days" min="1" max="365" value="7" required style="max-width:100px">
        </div>
        <div class="admin-form-group">
            <label>Powód (opcjonalnie)</label>
            <input type="text" name="reason" placeholder="Naruszenie regulaminu…">
        </div>
        <button type="submit" class="btn-sm btn-warn btn-lg">Zablokuj tymczasowo</button>
    </form>

    <?php if (can('manage_users')): ?>
    <hr style="margin:20px 0">
    <h3 style="font-size:15px;margin-bottom:12px;color:#e41e3f">Blokada permanentna</h3>
    <form method="POST">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="ban_perm">
        <input type="hidden" name="user_id" value="<?= $uid ?>">
        <div class="admin-form-group">
            <label>Powód</label>
            <input type="text" name="reason" placeholder="Powód trwałej blokady…">
        </div>
        <button type="submit" class="btn-sm btn-danger btn-lg" onclick="return confirm('Czy na pewno chcesz trwale zablokować tego użytkownika?')">Zablokuj permanentnie</button>
    </form>
    <?php endif; ?>
</div>

<?php require_once dirname(__DIR__) . '/components/layout_end.php'; ?>
