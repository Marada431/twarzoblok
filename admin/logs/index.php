<?php
require_once dirname(__DIR__) . '/middleware.php';
require_once dirname(__DIR__) . '/helpers.php';

if (!can('view_mod_logs')) admin_403();

$pdo = db();

$filter_mod    = (int)($_GET['mod'] ?? 0);
$filter_action = trim($_GET['action_type'] ?? '');
$page  = max(1, (int)($_GET['page'] ?? 1));
$limit = 50;
$offset = ($page - 1) * $limit;

$where  = [];
$params = [];
if ($filter_mod > 0) { $where[] = 'l.moderator_id = :mod'; $params[':mod'] = $filter_mod; }
if ($filter_action) { $where[] = 'l.action_type = :at'; $params[':at'] = $filter_action; }
$sql_where = $where ? 'WHERE ' . implode(' AND ', $where) : '';

$total_stmt = $pdo->prepare("SELECT COUNT(*) FROM mod_logs l $sql_where");
$total_stmt->execute($params);
$total = (int)$total_stmt->fetchColumn();

$stmt = $pdo->prepare("
    SELECT l.*, u.username AS mod_name
    FROM mod_logs l
    JOIN users u ON l.moderator_id = u.user_id
    $sql_where
    ORDER BY l.created_at DESC
    LIMIT :lim OFFSET :off
");
foreach ($params as $k => $v) $stmt->bindValue($k, $v);
$stmt->bindValue(':lim', $limit, PDO::PARAM_INT);
$stmt->bindValue(':off', $offset, PDO::PARAM_INT);
$stmt->execute();
$logs = $stmt->fetchAll();

// Lista moderatorów do filtra
$mods = $pdo->query("SELECT user_id, username FROM users WHERE role IN ('moderator','admin') ORDER BY username")->fetchAll();

// Unikalne typy akcji
$action_types = $pdo->query("SELECT DISTINCT action_type FROM mod_logs ORDER BY action_type")->fetchAll(PDO::FETCH_COLUMN);

$base_url = BASE_URL . '/admin/logs/?mod=' . $filter_mod . '&action_type=' . urlencode($filter_action);

$page_title  = 'Logi moderacji';
$active_page = 'logs';
require_once dirname(__DIR__) . '/components/layout_start.php';
?>

<div class="admin-page-header">
    <h1>📋 Logi moderacji</h1>
    <span class="text-muted"><?= $total ?> wpisów</span>
</div>

<form method="GET" class="filter-bar">
    <div class="admin-form-group">
        <label>Moderator</label>
        <select name="mod">
            <option value="0">Wszyscy</option>
            <?php foreach ($mods as $m): ?>
            <option value="<?= $m['user_id'] ?>" <?= $filter_mod === (int)$m['user_id'] ? 'selected':'' ?>>@<?= htmlspecialchars($m['username']) ?></option>
            <?php endforeach; ?>
        </select>
    </div>
    <div class="admin-form-group">
        <label>Typ akcji</label>
        <select name="action_type">
            <option value="">Wszystkie</option>
            <?php foreach ($action_types as $at): ?>
            <option value="<?= htmlspecialchars($at) ?>" <?= $filter_action===$at?'selected':'' ?>><?= htmlspecialchars($at) ?></option>
            <?php endforeach; ?>
        </select>
    </div>
    <button type="submit" class="btn-sm btn-primary">Filtruj</button>
    <a href="?" class="btn-sm btn-secondary">Reset</a>
</form>

<div class="admin-table-wrap">
    <table class="admin-table">
        <thead>
        <tr><th>Data</th><th>Moderator</th><th>Akcja</th><th>Cel</th><th>ID celu</th><th>Notatka</th></tr>
        </thead>
        <tbody>
        <?php foreach ($logs as $l): ?>
        <tr>
            <td class="text-muted" style="white-space:nowrap"><?= fmtDate($l['created_at']) ?></td>
            <td>@<?= htmlspecialchars($l['mod_name']) ?></td>
            <td><code style="font-size:11px;background:var(--bg-hover);padding:2px 5px;border-radius:3px"><?= htmlspecialchars($l['action_type']) ?></code></td>
            <td><?= statusBadge($l['target_type']) ?></td>
            <td class="text-muted">#<?= $l['target_id'] ?></td>
            <td class="text-muted" style="max-width:200px;font-size:12px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap" title="<?= htmlspecialchars($l['note']??'') ?>"><?= htmlspecialchars($l['note'] ?? '—') ?></td>
        </tr>
        <?php endforeach; ?>
        <?php if (empty($logs)): ?>
        <tr><td colspan="6" class="text-muted" style="text-align:center;padding:24px">Brak logów spełniających kryteria.</td></tr>
        <?php endif; ?>
        </tbody>
    </table>
</div>

<?= paginationLinks($total, $limit, $page, $base_url) ?>

<?php require_once dirname(__DIR__) . '/components/layout_end.php'; ?>
