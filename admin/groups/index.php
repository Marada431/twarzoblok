<?php
require_once dirname(__DIR__) . '/middleware.php';
require_once dirname(__DIR__) . '/helpers.php';

if (!can('manage_groups')) admin_403();

$pdo   = db();
$page  = max(1, (int)($_GET['page'] ?? 1));
$limit = 25;
$offset = ($page - 1) * $limit;

$total = (int)$pdo->query("SELECT COUNT(*) FROM `groups` WHERE status != 'deleted'")->fetchColumn();

$stmt = $pdo->prepare("
    SELECT g.group_id, g.name, g.group_type, g.status, g.created_at,
           u.username AS owner_name,
           (SELECT COUNT(*) FROM group_members gm WHERE gm.group_id = g.group_id AND gm.membership_status = 'active') AS member_count
    FROM `groups` g
    LEFT JOIN users u ON g.created_by = u.user_id
    WHERE g.status != 'deleted'
    ORDER BY g.created_at DESC
    LIMIT :lim OFFSET :off
");
$stmt->bindValue(':lim', $limit, PDO::PARAM_INT);
$stmt->bindValue(':off', $offset, PDO::PARAM_INT);
$stmt->execute();
$groups = $stmt->fetchAll();

$page_title  = 'Grupy';
$active_page = 'groups';
require_once dirname(__DIR__) . '/components/layout_start.php';
?>

<div class="admin-page-header">
    <h1>🏘 Grupy</h1>
    <span class="text-muted"><?= $total ?> grup</span>
</div>

<div class="admin-table-wrap">
    <table class="admin-table">
        <thead>
        <tr><th>ID</th><th>Nazwa</th><th>Typ</th><th>Właściciel</th><th>Członkowie</th><th>Status</th><th>Data</th><th>Akcje</th></tr>
        </thead>
        <tbody>
        <?php foreach ($groups as $g): ?>
        <tr>
            <td class="text-muted">#<?= $g['group_id'] ?></td>
            <td><strong><?= htmlspecialchars($g['name']) ?></strong></td>
            <td><?= htmlspecialchars($g['group_type']) ?></td>
            <td>@<?= htmlspecialchars($g['owner_name'] ?? '—') ?></td>
            <td><?= $g['member_count'] ?></td>
            <td><?= statusBadge($g['status']) ?></td>
            <td class="text-muted"><?= fmtDate($g['created_at']) ?></td>
            <td>
                <button class="btn-sm btn-danger" data-open-modal="modal-grp-<?= $g['group_id'] ?>">Usuń</button>
            </td>
        </tr>

        <div class="modal-overlay" id="modal-grp-<?= $g['group_id'] ?>">
            <div class="modal-card">
                <div class="modal-title">Usunąć grupę "<?= htmlspecialchars($g['name']) ?>"?</div>
                <p style="color:var(--text-muted);font-size:14px;margin-bottom:16px">Usunięcie jest nieodwracalne. Wszyscy członkowie zostaną usunięci.</p>
                <form method="POST" action="<?= BASE_URL ?>/admin/groups/delete.php">
                    <?= csrf_field() ?>
                    <input type="hidden" name="group_id" value="<?= $g['group_id'] ?>">
                    <div class="modal-actions">
                        <button type="button" class="btn-sm btn-secondary" data-close-modal="modal-grp-<?= $g['group_id'] ?>">Anuluj</button>
                        <button type="submit" class="btn-sm btn-danger">Usuń grupę</button>
                    </div>
                </form>
            </div>
        </div>
        <?php endforeach; ?>
        <?php if (empty($groups)): ?>
        <tr><td colspan="8" class="text-muted" style="text-align:center;padding:24px">Brak grup.</td></tr>
        <?php endif; ?>
        </tbody>
    </table>
</div>

<?= paginationLinks($total, $limit, $page, BASE_URL . '/admin/groups/') ?>

<?php require_once dirname(__DIR__) . '/components/layout_end.php'; ?>
