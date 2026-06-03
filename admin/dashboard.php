<?php
require_once __DIR__ . '/middleware.php';
require_once __DIR__ . '/helpers.php';

if (!can('view_dashboard')) admin_403();

$pdo = db();

// ── Karty statystyk ──────────────────────────────────
$total_users   = (int)$pdo->query("SELECT COUNT(*) FROM users WHERE deleted_at IS NULL")->fetchColumn();
$new_7d        = (int)$pdo->query("SELECT COUNT(*) FROM users WHERE created_at >= NOW() - INTERVAL 7 DAY")->fetchColumn();
$active_24h    = (int)$pdo->query("SELECT COUNT(*) FROM users WHERE last_active_at >= NOW() - INTERVAL 24 HOUR")->fetchColumn();
$open_reports  = newReportsCount();
$total_posts   = (int)$pdo->query("SELECT COUNT(*) FROM posts")->fetchColumn();
$blocked_users = (int)$pdo->query("SELECT COUNT(*) FROM users WHERE status = 'blocked'")->fetchColumn();

// ── Ostatnie 10 zgłoszeń ─────────────────────────────
$stmt = $pdo->prepare("
    SELECT r.report_id, r.type, r.status, r.reported_at, r.category,
           u.username AS reporter
    FROM reports r
    LEFT JOIN users u ON r.reporter_id = u.user_id
    ORDER BY r.reported_at DESC LIMIT 10
");
$stmt->execute();
$recent_reports = $stmt->fetchAll();

// ── Ostatnie 10 rejestracji ──────────────────────────
$stmt2 = $pdo->prepare("SELECT user_id, username, email, role, created_at FROM users ORDER BY created_at DESC LIMIT 10");
$stmt2->execute();
$recent_users = $stmt2->fetchAll();

$page_title  = 'Dashboard';
$active_page = 'dashboard';
require_once __DIR__ . '/components/layout_start.php';
?>

<div class="admin-page-header">
    <h1>📊 Dashboard</h1>
    <span class="text-muted">Przegląd systemu TwarzBlok</span>
</div>

<!-- Karty statystyk -->
<div class="stats-grid">
    <div class="stat-card">
        <div class="stat-card-label">Użytkownicy (łącznie)</div>
        <div class="stat-card-value"><?= $total_users ?></div>
    </div>
    <div class="stat-card">
        <div class="stat-card-label">Nowi (7 dni)</div>
        <div class="stat-card-value info"><?= $new_7d ?></div>
    </div>
    <div class="stat-card">
        <div class="stat-card-label">Aktywni (24h)</div>
        <div class="stat-card-value"><?= $active_24h ?></div>
    </div>
    <div class="stat-card">
        <div class="stat-card-label">Otwarte zgłoszenia</div>
        <div class="stat-card-value <?= $open_reports > 0 ? 'danger' : '' ?>"><?= $open_reports ?></div>
    </div>
    <div class="stat-card">
        <div class="stat-card-label">Wszystkie posty</div>
        <div class="stat-card-value info"><?= $total_posts ?></div>
    </div>
    <div class="stat-card">
        <div class="stat-card-label">Zablokowani</div>
        <div class="stat-card-value <?= $blocked_users > 0 ? 'warn' : '' ?>"><?= $blocked_users ?></div>
    </div>
</div>

<!-- Wykresy (Chart.js) -->
<div class="two-col mb-16">
    <div class="card">
        <div style="font-weight:700;margin-bottom:12px">📈 Rejestracje (30 dni)</div>
        <canvas id="chartRegistrations" height="160"></canvas>
    </div>
    <div class="card">
        <div style="font-weight:700;margin-bottom:12px">📝 Posty (30 dni)</div>
        <canvas id="chartPosts" height="160"></canvas>
    </div>
</div>
<div class="card mb-16" style="max-width:400px">
    <div style="font-weight:700;margin-bottom:12px">🚩 Zgłoszenia wg typu (30 dni)</div>
    <canvas id="chartReports" height="200"></canvas>
</div>

<!-- Tabele ostatnich zdarzeń -->
<div class="two-col">
    <div class="admin-table-wrap">
        <div class="admin-table-title">Ostatnie zgłoszenia <a href="<?= BASE_URL ?>/admin/reports/" class="btn-sm btn-secondary">Wszystkie</a></div>
        <table class="admin-table">
            <thead><tr><th>ID</th><th>Typ</th><th>Kategoria</th><th>Status</th><th>Data</th></tr></thead>
            <tbody>
            <?php foreach ($recent_reports as $r): ?>
                <tr>
                    <td><a href="<?= BASE_URL ?>/admin/reports/view.php?id=<?= $r['report_id'] ?>">#<?= $r['report_id'] ?></a></td>
                    <td><?= statusBadge($r['type']) ?></td>
                    <td><?= htmlspecialchars($r['category']) ?></td>
                    <td><?= statusBadge($r['status']) ?></td>
                    <td class="text-muted"><?= fmtDate($r['reported_at']) ?></td>
                </tr>
            <?php endforeach; ?>
            <?php if (empty($recent_reports)): ?>
                <tr><td colspan="5" class="text-muted" style="text-align:center;padding:20px">Brak zgłoszeń</td></tr>
            <?php endif; ?>
            </tbody>
        </table>
    </div>

    <div class="admin-table-wrap">
        <div class="admin-table-title">Ostatnie rejestracje <a href="<?= BASE_URL ?>/admin/users/" class="btn-sm btn-secondary">Wszyscy</a></div>
        <table class="admin-table">
            <thead><tr><th>Użytkownik</th><th>Rola</th><th>Data</th></tr></thead>
            <tbody>
            <?php foreach ($recent_users as $u): ?>
                <tr>
                    <td><strong><?= htmlspecialchars($u['username']) ?></strong><br><span class="text-muted"><?= htmlspecialchars($u['email']) ?></span></td>
                    <td><?= statusBadge($u['role']) ?></td>
                    <td class="text-muted"><?= fmtDate($u['created_at']) ?></td>
                </tr>
            <?php endforeach; ?>
            <?php if (empty($recent_users)): ?>
                <tr><td colspan="3" class="text-muted" style="text-align:center;padding:20px">Brak</td></tr>
            <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<script>
const PRIMARY = '#338336', INFO = '#2196f3', WARN = '#ff9800', DANGER = '#e41e3f';

fetch('<?= BASE_URL ?>/admin/api/stats.php?q=charts')
    .then(r => r.json())
    .then(data => {
        // Rejestracje
        new Chart(document.getElementById('chartRegistrations'), {
            type: 'line',
            data: {
                labels: data.reg_labels,
                datasets: [{ label: 'Rejestracje', data: data.reg_data, borderColor: PRIMARY, backgroundColor: PRIMARY + '22', fill: true, tension: .4 }]
            },
            options: { plugins: { legend: { display: false } }, scales: { y: { beginAtZero: true, ticks: { stepSize: 1 } } } }
        });
        // Posty
        new Chart(document.getElementById('chartPosts'), {
            type: 'bar',
            data: {
                labels: data.posts_labels,
                datasets: [{ label: 'Posty', data: data.posts_data, backgroundColor: INFO + 'aa', borderColor: INFO, borderWidth: 1 }]
            },
            options: { plugins: { legend: { display: false } }, scales: { y: { beginAtZero: true, ticks: { stepSize: 1 } } } }
        });
        // Zgłoszenia
        new Chart(document.getElementById('chartReports'), {
            type: 'doughnut',
            data: {
                labels: data.rep_labels,
                datasets: [{ data: data.rep_data, backgroundColor: [PRIMARY, INFO, WARN, DANGER] }]
            },
            options: { plugins: { legend: { position: 'bottom' } } }
        });
    }).catch(e => console.error('Stats load error:', e));
</script>

<?php require_once __DIR__ . '/components/layout_end.php'; ?>
