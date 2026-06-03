<?php
require_once dirname(__DIR__) . '/middleware.php';
require_once dirname(__DIR__) . '/helpers.php';

if (!can('manage_users')) admin_403();

$pdo = db();

// ── Filtry ────────────────────────────────────────────
$filter_role   = $_GET['role']   ?? '';
$filter_status = $_GET['status'] ?? '';
$filter_search = trim($_GET['q'] ?? '');
$page  = max(1, (int)($_GET['page'] ?? 1));
$limit = 25;
$offset = ($page - 1) * $limit;

$where  = ['deleted_at IS NULL'];
$params = [];

if ($filter_role && in_array($filter_role, ['user','moderator','admin'], true)) {
    $where[]           = 'role = :role';
    $params[':role']   = $filter_role;
}
if ($filter_status && in_array($filter_status, ['active','blocked','pending'], true)) {
    $where[]              = 'status = :status';
    $params[':status']    = $filter_status;
}
if ($filter_search !== '') {
    $where[]           = '(username LIKE :q OR email LIKE :q2)';
    $params[':q']      = "%$filter_search%";
    $params[':q2']     = "%$filter_search%";
}

$sql_where = $where ? 'WHERE ' . implode(' AND ', $where) : '';

$total_stmt = $pdo->prepare("SELECT COUNT(*) FROM users $sql_where");
$total_stmt->execute($params);
$total = (int)$total_stmt->fetchColumn();

$stmt = $pdo->prepare("SELECT user_id, username, email, first_name, last_name, role, status, avatar_url, created_at, banned_until, is_banned_permanent FROM users $sql_where ORDER BY created_at DESC LIMIT :lim OFFSET :off");
foreach ($params as $k => $v) $stmt->bindValue($k, $v);
$stmt->bindValue(':lim', $limit, PDO::PARAM_INT);
$stmt->bindValue(':off', $offset, PDO::PARAM_INT);
$stmt->execute();
$users = $stmt->fetchAll();

$base_url = BASE_URL . '/admin/users/?role=' . urlencode($filter_role) . '&status=' . urlencode($filter_status) . '&q=' . urlencode($filter_search);

$page_title  = 'Użytkownicy';
$active_page = 'users';
require_once dirname(__DIR__) . '/components/layout_start.php';
?>

<div class="admin-page-header">
    <h1>👥 Użytkownicy</h1>
    <span class="text-muted"><?= $total ?> łącznie</span>
</div>

<!-- Filtrowanie -->
<form method="GET" action="" class="filter-bar">
    <div class="admin-form-group">
        <label>Rola</label>
        <select name="role">
            <option value="">Wszystkie</option>
            <option value="user"      <?= $filter_role==='user'?'selected':'' ?>>Użytkownik</option>
            <option value="moderator" <?= $filter_role==='moderator'?'selected':'' ?>>Moderator</option>
            <option value="admin"     <?= $filter_role==='admin'?'selected':'' ?>>Admin</option>
        </select>
    </div>
    <div class="admin-form-group">
        <label>Status</label>
        <select name="status">
            <option value="">Wszystkie</option>
            <option value="active"  <?= $filter_status==='active'?'selected':'' ?>>Aktywny</option>
            <option value="blocked" <?= $filter_status==='blocked'?'selected':'' ?>>Zablokowany</option>
        </select>
    </div>
    <div class="admin-form-group">
        <label>Szukaj</label>
        <input type="text" name="q" value="<?= htmlspecialchars($filter_search) ?>" placeholder="username lub e-mail">
    </div>
    <button type="submit" class="btn-sm btn-primary">Filtruj</button>
    <a href="?" class="btn-sm btn-secondary">Reset</a>
</form>

<div class="admin-table-wrap">
    <table class="admin-table">
        <thead>
        <tr>
            <th>ID</th><th>Użytkownik</th><th>E-mail</th><th>Rola</th><th>Status</th><th>Rejestracja</th><th>Akcje</th>
        </tr>
        </thead>
        <tbody>
        <?php foreach ($users as $u):
            $uid = (int)$u['user_id'];
            $is_self = $uid === (int)$_SESSION['user_id'];
            $banned_label = '';
            if ($u['is_banned_permanent']) $banned_label = ' (perm.)';
            elseif ($u['banned_until'] && strtotime($u['banned_until']) > time()) $banned_label = ' (do '.date('d.m.Y', strtotime($u['banned_until'])).')';
        ?>
            <tr>
                <td class="text-muted">#<?= $uid ?></td>
                <td>
                    <div class="cell-avatar">
                        <?php if ($u['avatar_url']): ?>
                            <img src="<?= htmlspecialchars($u['avatar_url']) ?>" alt="">
                        <?php else: ?>
                            <div class="av-ph">👤</div>
                        <?php endif; ?>
                        <div>
                            <strong><?= htmlspecialchars($u['username']) ?></strong><br>
                            <span class="text-muted"><?= htmlspecialchars($u['first_name'].' '.$u['last_name']) ?></span>
                        </div>
                    </div>
                </td>
                <td class="text-muted"><?= htmlspecialchars($u['email']) ?></td>
                <td><?= statusBadge($u['role']) ?></td>
                <td><?= statusBadge($u['status']) ?><?= $banned_label ?></td>
                <td class="text-muted"><?= fmtDate($u['created_at']) ?></td>
                <td>
                    <div class="actions">
                        <?php if (!$is_self): ?>
                            <a href="<?= BASE_URL ?>/admin/users/ban.php?id=<?= $uid ?>" class="btn-sm btn-warn">Blokada</a>
                            <a href="<?= BASE_URL ?>/admin/users/edit.php?id=<?= $uid ?>" class="btn-sm btn-info">Rola</a>
                            <button class="btn-sm btn-danger" data-open-modal="modal-del-<?= $uid ?>">Usuń</button>
                        <?php else: ?>
                            <span class="text-muted" style="font-size:11px">To Ty</span>
                        <?php endif; ?>
                    </div>
                </td>
            </tr>

            <!-- Modal usunięcia -->
            <?php if (!$is_self): ?>
            <div class="modal-overlay" id="modal-del-<?= $uid ?>">
                <div class="modal-card">
                    <div class="modal-title">Usunąć konto @<?= htmlspecialchars($u['username']) ?>?</div>
                    <p style="color:var(--text-muted);margin-bottom:16px;font-size:14px">Wpisz <strong>USUŃ</strong> aby potwierdzić. Operacja jest nieodwracalna.</p>
                    <form method="POST" action="<?= BASE_URL ?>/admin/users/edit.php">
                        <?= csrf_field() ?>
                        <input type="hidden" name="action" value="delete">
                        <input type="hidden" name="user_id" value="<?= $uid ?>">
                        <div class="admin-form-group">
                            <input type="text" name="confirm_text" placeholder="Wpisz: USUŃ" required pattern="USUŃ">
                        </div>
                        <div class="modal-actions">
                            <button type="button" class="btn-sm btn-secondary" data-close-modal="modal-del-<?= $uid ?>">Anuluj</button>
                            <button type="submit" class="btn-sm btn-danger">Usuń konto</button>
                        </div>
                    </form>
                </div>
            </div>
            <?php endif; ?>
        <?php endforeach; ?>
        <?php if (empty($users)): ?>
            <tr><td colspan="7" class="text-muted" style="text-align:center;padding:24px">Brak użytkowników spełniających kryteria.</td></tr>
        <?php endif; ?>
        </tbody>
    </table>
</div>

<?= paginationLinks($total, $limit, $page, $base_url) ?>

<?php require_once dirname(__DIR__) . '/components/layout_end.php'; ?>
