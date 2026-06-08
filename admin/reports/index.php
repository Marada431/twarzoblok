<?php
require_once dirname(__DIR__) . '/middleware.php';
require_once dirname(__DIR__) . '/helpers.php';

if (!can('view_reports')) admin_403();

$pdo = db();

$filter_status = $_GET['status'] ?? 'new';
$filter_type   = $_GET['type']   ?? '';
$page  = max(1, (int)($_GET['page'] ?? 1));
$limit = 20;
$offset = ($page - 1) * $limit;

$allowed_statuses = ['new','in_progress','resolved','rejected',''];
$allowed_types    = ['post','comment','profile','message',''];
if (!in_array($filter_status, $allowed_statuses, true)) $filter_status = 'new';
if (!in_array($filter_type,   $allowed_types,    true)) $filter_type   = '';

$where  = [];
$params = [];
if ($filter_status !== '') { $where[] = 'r.status = :status'; $params[':status'] = $filter_status; }
if ($filter_type   !== '') { $where[] = 'r.type = :type';     $params[':type']   = $filter_type;   }
$sql_where = $where ? 'WHERE ' . implode(' AND ', $where) : '';

$total = (int)$pdo->prepare("SELECT COUNT(*) FROM reports r $sql_where")->execute($params) ? $pdo->prepare("SELECT COUNT(*) FROM reports r $sql_where")->execute($params) && 0 : 0;

$total_stmt = $pdo->prepare("SELECT COUNT(*) FROM reports r $sql_where");
$total_stmt->execute($params);
$total = (int)$total_stmt->fetchColumn();

$stmt = $pdo->prepare("
    SELECT r.report_id, r.type, r.status, r.category, r.reported_at,
           reporter.username  AS reporter_name,
           p.post_id, p.author_id AS post_author_id,
           pa.username AS reported_username,
           m.username  AS mod_username
    FROM reports r
    LEFT JOIN users reporter ON r.reporter_id = reporter.user_id
    LEFT JOIN posts p  ON r.post_id = p.post_id
    LEFT JOIN users pa ON p.author_id = pa.user_id
    LEFT JOIN users m  ON r.moderator_id = m.user_id
    $sql_where
    ORDER BY r.reported_at DESC
    LIMIT :lim OFFSET :off
");
foreach ($params as $k => $v) $stmt->bindValue($k, $v);
$stmt->bindValue(':lim', $limit, PDO::PARAM_INT);
$stmt->bindValue(':off', $offset, PDO::PARAM_INT);
$stmt->execute();
$reports = $stmt->fetchAll();

$base_url = BASE_URL . '/admin/reports/?status=' . urlencode($filter_status) . '&type=' . urlencode($filter_type);

$page_title  = 'Zgłoszenia';
$active_page = 'reports';
require_once dirname(__DIR__) . '/components/layout_start.php';
?>

<div class="admin-page-header">
    <h1>🚩 Zgłoszenia</h1>
    <span class="text-muted"><?= $total ?> wyników</span>
</div>

<!-- Filtry -->
<form method="GET" class="filter-bar">
    <div class="admin-form-group">
        <label>Status</label>
        <select name="status">
            <option value=""           <?= $filter_status===''?'selected':'' ?>>Wszystkie</option>
            <option value="new"        <?= $filter_status==='new'?'selected':'' ?>>Nowe</option>
            <option value="in_progress"<?= $filter_status==='in_progress'?'selected':'' ?>>W toku</option>
            <option value="resolved"   <?= $filter_status==='resolved'?'selected':'' ?>>Rozpatrzone</option>
            <option value="rejected"   <?= $filter_status==='rejected'?'selected':'' ?>>Odrzucone</option>
        </select>
    </div>
    <div class="admin-form-group">
        <label>Typ</label>
        <select name="type">
            <option value="">Wszystkie</option>
            <option value="post"    <?= $filter_type==='post'?'selected':'' ?>>Post</option>
            <option value="comment" <?= $filter_type==='comment'?'selected':'' ?>>Komentarz</option>
            <option value="profile" <?= $filter_type==='profile'?'selected':'' ?>>Profil</option>
            <option value="message" <?= $filter_type==='message'?'selected':'' ?>>Wiadomość</option>
        </select>
    </div>
    <button type="submit" class="btn-sm btn-primary">Filtruj</button>
    <a href="?" class="btn-sm btn-secondary">Reset</a>
</form>

<div class="admin-table-wrap">
    <table class="admin-table">
        <thead>
        <tr>
            <th>ID</th><th>Typ</th><th>Kategoria</th><th>Zgłaszający</th><th>Zgłoszony</th>
            <th>Status</th><th>Moderator</th><th>Data</th><th>Akcje</th>
        </tr>
        </thead>
        <tbody>
        <?php foreach ($reports as $r): ?>
        <tr>
            <td class="text-muted">#<?= $r['report_id'] ?></td>
            <td><?= statusBadge($r['type']) ?></td>
            <td><?= htmlspecialchars($r['category']) ?></td>
            <td><?= $r['reporter_name'] ? '@'.htmlspecialchars($r['reporter_name']) : '<span class="text-muted">—</span>' ?></td>
            <td><?= $r['reported_username'] ? '@'.htmlspecialchars($r['reported_username']) : '<span class="text-muted">—</span>' ?></td>
            <td><?= statusBadge($r['status']) ?></td>
            <td class="text-muted"><?= $r['mod_username'] ? '@'.htmlspecialchars($r['mod_username']) : '—' ?></td>
            <td class="text-muted" style="white-space:nowrap"><?= fmtDate($r['reported_at']) ?></td>
            <td>
                <a href="<?= BASE_URL ?>/admin/reports/view.php?id=<?= $r['report_id'] ?>" class="btn-sm btn-primary">Rozpatrz</a>
            </td>
        </tr>
        <?php endforeach; ?>
        <?php if (empty($reports)): ?>
        <tr><td colspan="9" class="text-muted" style="text-align:center;padding:24px">Brak zgłoszeń spełniających kryteria.</td></tr>
        <?php endif; ?>
        </tbody>
    </table>
</div>

<?= paginationLinks($total, $limit, $page, $base_url) ?>

<?php require_once dirname(__DIR__) . '/components/layout_end.php'; ?>
