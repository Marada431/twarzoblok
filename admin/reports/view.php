<?php
require_once dirname(__DIR__) . '/middleware.php';
require_once dirname(__DIR__) . '/helpers.php';

if (!can('view_reports')) admin_403();

$pdo = db();
$rid = (int)($_GET['id'] ?? 0);
if ($rid <= 0) { header('Location: ' . BASE_URL . '/admin/reports/'); exit; }

$stmt = $pdo->prepare("
    SELECT r.*,
           reporter.username AS reporter_name, reporter.avatar_url AS reporter_avatar,
           m.username AS mod_name
    FROM reports r
    LEFT JOIN users reporter ON r.reporter_id = reporter.user_id
    LEFT JOIN users m ON r.moderator_id = m.user_id
    WHERE r.report_id = :rid
");
$stmt->execute([':rid' => $rid]);
$report = $stmt->fetch();

if (!$report) { flashSet('error', 'Zgłoszenie nie istnieje.'); header('Location: ' . BASE_URL . '/admin/reports/'); exit; }

// Pobierz zgłoszoną treść
$reported_content = null;
$reported_author  = null;

if ($report['post_id']) {
    $ps = $pdo->prepare("SELECT p.*, u.username, u.avatar_url, u.user_id AS uid FROM posts p JOIN users u ON p.author_id = u.user_id WHERE p.post_id = :pid");
    $ps->execute([':pid' => $report['post_id']]);
    $reported_content = $ps->fetch();
    if ($reported_content) $reported_author = $reported_content;
}

// Historia zgłoszeń reportowanego autora
$prev_reports = [];
if ($reported_author) {
    $ph = $pdo->prepare("SELECT COUNT(*) FROM reports r JOIN posts p ON r.post_id = p.post_id WHERE p.author_id = :uid AND r.report_id != :rid");
    $ph->execute([':uid' => $reported_author['uid'], ':rid' => $rid]);
    $prev_count = (int)$ph->fetchColumn();
}

$page_title  = 'Zgłoszenie #' . $rid;
$active_page = 'reports';
require_once dirname(__DIR__) . '/components/layout_start.php';
?>

<div class="admin-page-header">
    <h1>🚩 Zgłoszenie #<?= $rid ?></h1>
    <div style="display:flex;gap:8px">
        <?= statusBadge($report['status']) ?>
        <a href="<?= BASE_URL ?>/admin/reports/" class="btn-sm btn-secondary">← Lista</a>
    </div>
</div>

<div class="two-col">
    <!-- Lewa kolumna: treść + autor -->
    <div>
        <!-- Info o zgłoszeniu -->
        <div class="card mb-16">
            <div style="font-weight:700;margin-bottom:12px">📋 Informacje o zgłoszeniu</div>
            <table style="width:100%;font-size:13px;border-collapse:collapse">
                <tr><td style="padding:6px 0;color:var(--text-muted);width:120px">Typ:</td><td><?= statusBadge($report['type']) ?></td></tr>
                <tr><td style="padding:6px 0;color:var(--text-muted)">Kategoria:</td><td><?= htmlspecialchars($report['category']) ?></td></tr>
                <tr><td style="padding:6px 0;color:var(--text-muted)">Data:</td><td><?= fmtDate($report['reported_at']) ?></td></tr>
                <tr><td style="padding:6px 0;color:var(--text-muted)">Zgłaszający:</td><td><?= $report['reporter_name'] ? '@'.htmlspecialchars($report['reporter_name']) : '—' ?></td></tr>
                <?php if ($report['moderator_id']): ?>
                <tr><td style="padding:6px 0;color:var(--text-muted)">Moderator:</td><td>@<?= htmlspecialchars($report['mod_name']) ?></td></tr>
                <?php endif; ?>
                <?php if ($report['moderator_note']): ?>
                <tr><td style="padding:6px 0;color:var(--text-muted)">Notatka:</td><td><?= htmlspecialchars($report['moderator_note']) ?></td></tr>
                <?php endif; ?>
            </table>
        </div>

        <!-- Podgląd zgłoszonej treści -->
        <?php if ($reported_content): ?>
        <div class="card mb-16">
            <div style="font-weight:700;margin-bottom:12px">🔍 Zgłoszona treść</div>
            <div style="background:var(--bg-main);border:1px solid var(--border-color);border-radius:8px;padding:14px">
                <div style="display:flex;align-items:center;gap:8px;margin-bottom:10px">
                    <div class="av-ph" style="width:32px;height:32px;border-radius:50%;background:var(--border-color);display:flex;align-items:center;justify-content:center;font-size:14px">👤</div>
                    <strong>@<?= htmlspecialchars($reported_content['username']) ?></strong>
                    <span class="text-muted" style="font-size:12px"><?= fmtDate($reported_content['created_at']) ?></span>
                </div>
                <div style="font-size:14px;color:var(--text-main)"><?= nl2br(htmlspecialchars($reported_content['content'])) ?></div>
            </div>
        </div>
        <?php endif; ?>

        <!-- Profil zgłoszonego -->
        <?php if ($reported_author): ?>
        <div class="card">
            <div style="font-weight:700;margin-bottom:12px">👤 Profil zgłoszonego autora</div>
            <div style="display:flex;align-items:center;gap:12px;margin-bottom:10px">
                <div class="av-ph" style="width:48px;height:48px;font-size:22px;border-radius:50%;background:var(--border-color);display:flex;align-items:center;justify-content:center">👤</div>
                <div>
                    <strong>@<?= htmlspecialchars($reported_author['username']) ?></strong><br>
                    <span class="text-muted" style="font-size:12px">Poprzednich zgłoszeń: <?= $prev_count ?? 0 ?></span>
                </div>
            </div>
            <?php if (can('ban_user_temp')): ?>
                <a href="<?= BASE_URL ?>/admin/users/ban.php?id=<?= $reported_author['uid'] ?>" class="btn-sm btn-warn">Zablokuj tego użytkownika</a>
            <?php endif; ?>
        </div>
        <?php endif; ?>
    </div>

    <!-- Prawa kolumna: formularz akcji moderatora -->
    <div>
        <div class="card">
            <div style="font-weight:700;margin-bottom:16px">⚙️ Akcja moderatora</div>

            <form method="POST" action="<?= BASE_URL ?>/admin/reports/action.php">
                <?= csrf_field() ?>
                <input type="hidden" name="report_id" value="<?= $rid ?>">
                <input type="hidden" name="post_id"   value="<?= $report['post_id'] ?? '' ?>">
                <input type="hidden" name="reported_author_id" value="<?= $reported_author['uid'] ?? '' ?>">

                <div class="admin-form-group">
                    <label>Zmień status</label>
                    <select name="status" required>
                        <option value="in_progress" <?= $report['status']==='in_progress'?'selected':'' ?>>W toku</option>
                        <option value="resolved"    <?= $report['status']==='resolved'?'selected':'' ?>>Rozpatrzone</option>
                        <option value="rejected"    <?= $report['status']==='rejected'?'selected':'' ?>>Odrzucone</option>
                    </select>
                </div>

                <div class="admin-form-group">
                    <label>Notatka moderatora</label>
                    <textarea name="note" placeholder="Opcjonalna notatka…"><?= htmlspecialchars($report['moderator_note'] ?? '') ?></textarea>
                </div>

                <div style="display:flex;flex-direction:column;gap:8px">
                    <button type="submit" name="action" value="update_status" class="btn-sm btn-primary btn-lg">Zapisz status</button>

                    <?php if ($report['post_id'] && can('delete_content')): ?>
                        <button type="submit" name="action" value="delete_post"
                                class="btn-sm btn-danger btn-lg"
                                onclick="return confirm('Usunąć post i rozpatrzyć zgłoszenie?')">
                            Usuń post + rozpatrz
                        </button>
                    <?php endif; ?>

                    <?php if ($reported_author && can('ban_user_temp')): ?>
                        <div style="display:flex;align-items:center;gap:8px;margin-top:4px">
                            <input type="number" name="ban_days" min="1" max="365" value="7" style="width:70px;padding:6px 10px;border:1px solid var(--border-color);border-radius:6px;font-size:13px">
                            <button type="submit" name="action" value="ban_and_resolve"
                                    class="btn-sm btn-warn btn-lg"
                                    onclick="return confirm('Zablokować użytkownika i rozpatrzyć zgłoszenie?')">
                                Zablokuj + rozpatrz
                            </button>
                        </div>
                    <?php endif; ?>
                </div>
            </form>
        </div>
    </div>
</div>

<?php require_once dirname(__DIR__) . '/components/layout_end.php'; ?>
