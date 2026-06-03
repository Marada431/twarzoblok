<?php
// Wymaga: $page_title, $active_page, $current_role — ustawione przez stronę
// Wymaga: BASE_URL zdefiniowany przez middleware.php
$_base    = BASE_URL;
$_role_label = $current_role === 'admin' ? 'Admin' : 'Moderator';
$_new_rep = newReportsCount();
$_uid     = (int)$_SESSION['user_id'];
$_uname   = htmlspecialchars($_SESSION['username'] ?? '');
$_avatar  = !empty($_SESSION['avatar_url']) ? htmlspecialchars($_SESSION['avatar_url']) : null;
?>
<!doctype html>
<html lang="pl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($page_title ?? 'Panel') ?> – TwarzBlok <?= $_role_label ?></title>
    <link rel="stylesheet" href="<?= $_base ?>/css/style.css">
    <style>
        /* ── Admin Layout ── */
        .admin-wrapper {
            display: flex;
            max-width: 1400px;
            margin: 80px auto 20px;
            padding: 0 16px;
            gap: 20px;
            align-items: flex-start;
        }
        .admin-sidebar {
            width: 220px;
            flex-shrink: 0;
            background: var(--bg-surface);
            border: 1px solid var(--border-color);
            border-radius: var(--radius-main);
            box-shadow: var(--shadow);
            position: sticky;
            top: 80px;
            overflow: hidden;
        }
        .admin-sidebar-header {
            background: var(--primary-color);
            color: #fff;
            padding: 14px 16px;
            font-weight: 700;
            font-size: 13px;
            text-transform: uppercase;
            letter-spacing: .5px;
        }
        .admin-nav { padding: 8px 0; }
        .admin-nav-item {
            display: flex;
            align-items: center;
            gap: 10px;
            padding: 10px 16px;
            color: var(--text-main);
            font-size: 14px;
            font-weight: 500;
            text-decoration: none;
            transition: background .15s;
            position: relative;
        }
        .admin-nav-item:hover { background: var(--bg-hover); text-decoration: none; }
        .admin-nav-item.active {
            background: rgba(51,131,54,.12);
            color: var(--primary-color);
            font-weight: 700;
            border-left: 3px solid var(--primary-color);
        }
        .nav-badge {
            margin-left: auto;
            background: #e63946;
            color: #fff;
            border-radius: 10px;
            min-width: 20px;
            height: 20px;
            font-size: 11px;
            font-weight: 700;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            padding: 0 5px;
        }
        .admin-nav-sep {
            border: none;
            border-top: 1px solid var(--border-color);
            margin: 6px 0;
        }
        .admin-content {
            flex: 1;
            min-width: 0;
        }
        .admin-page-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            margin-bottom: 20px;
            flex-wrap: wrap;
            gap: 10px;
        }
        .admin-page-header h1 {
            font-size: 22px;
            color: var(--text-main);
            margin: 0;
        }

        /* ── Stats cards ── */
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 16px;
            margin-bottom: 24px;
        }
        .stat-card {
            background: var(--bg-surface);
            border: 1px solid var(--border-color);
            border-radius: var(--radius-main);
            padding: 18px 20px;
            box-shadow: var(--shadow);
        }
        .stat-card-label {
            font-size: 12px;
            text-transform: uppercase;
            color: var(--text-muted);
            font-weight: 600;
            letter-spacing: .5px;
            margin-bottom: 8px;
        }
        .stat-card-value {
            font-size: 30px;
            font-weight: 700;
            color: var(--primary-color);
            line-height: 1;
        }
        .stat-card-value.danger { color: #e41e3f; }
        .stat-card-value.info   { color: #2196f3; }
        .stat-card-value.warn   { color: #ff9800; }

        /* ── Tables ── */
        .admin-table-wrap {
            background: var(--bg-surface);
            border: 1px solid var(--border-color);
            border-radius: var(--radius-main);
            box-shadow: var(--shadow);
            overflow-x: auto;
        }
        .admin-table-wrap + .admin-table-wrap { margin-top: 20px; }
        .admin-table-title {
            padding: 14px 18px;
            font-weight: 700;
            font-size: 15px;
            border-bottom: 1px solid var(--border-color);
            display: flex;
            align-items: center;
            justify-content: space-between;
        }
        .admin-table {
            width: 100%;
            border-collapse: collapse;
            font-size: 13px;
        }
        .admin-table th {
            background: var(--bg-hover);
            padding: 10px 14px;
            text-align: left;
            font-weight: 600;
            color: var(--text-muted);
            font-size: 12px;
            text-transform: uppercase;
            letter-spacing: .4px;
            white-space: nowrap;
        }
        .admin-table td {
            padding: 10px 14px;
            border-bottom: 1px solid var(--border-color);
            vertical-align: middle;
        }
        .admin-table tr:last-child td { border-bottom: none; }
        .admin-table tr:hover td { background: var(--bg-hover); }
        .admin-table .cell-avatar {
            display: flex;
            align-items: center;
            gap: 8px;
        }
        .admin-table .cell-avatar img {
            width: 30px; height: 30px;
            border-radius: 50%; object-fit: cover;
            flex-shrink: 0;
        }
        .admin-table .cell-avatar .av-ph {
            width: 30px; height: 30px;
            border-radius: 50%;
            background: var(--border-color);
            display: flex; align-items: center; justify-content: center;
            font-size: 13px;
            flex-shrink: 0;
        }
        .admin-table .actions { display: flex; gap: 5px; flex-wrap: wrap; }

        /* ── Badges ── */
        .badge {
            display: inline-block;
            padding: 3px 8px;
            border-radius: 10px;
            font-size: 11px;
            font-weight: 700;
            line-height: 1.4;
            white-space: nowrap;
        }
        .bdg-new    { background: #fff3cd; color: #856404; }
        .bdg-prog   { background: #cce5ff; color: #004085; }
        .bdg-ok     { background: #d4edda; color: #155724; }
        .bdg-rej    { background: #f8d7da; color: #721c24; }
        .bdg-err    { background: #f8d7da; color: #721c24; }
        .bdg-usr    { background: #e2e3e5; color: #383d41; }
        .bdg-mod    { background: #cce5ff; color: #004085; }
        .bdg-adm    { background: #ffeef0; color: #c0392b; }

        /* ── Alerts ── */
        .alert {
            padding: 12px 16px;
            border-radius: var(--radius-main);
            margin-bottom: 16px;
            font-size: 14px;
            display: flex;
            align-items: center;
            gap: 8px;
        }
        .alert-success { background: #d4edda; color: #155724; border: 1px solid #c3e6cb; }
        .alert-danger  { background: #f8d7da; color: #721c24; border: 1px solid #f5c6cb; }
        .alert-info    { background: #cce5ff; color: #004085; border: 1px solid #b8daff; }
        .alert-warning { background: #fff3cd; color: #856404; border: 1px solid #ffeeba; }

        /* ── Buttons ── */
        .btn-sm {
            padding: 5px 10px; font-size: 12px; font-weight: 600;
            border: none; border-radius: 5px; cursor: pointer;
            text-decoration: none; display: inline-flex; align-items: center; gap: 4px;
            transition: background .15s, color .15s;
        }
        .btn-primary { background: var(--primary-color); color: #fff; }
        .btn-primary:hover { background: var(--primary-hover); color: #fff; text-decoration: none; }
        .btn-secondary { background: var(--bg-hover); color: var(--text-main); border: 1px solid var(--border-color); }
        .btn-secondary:hover { background: var(--border-color); text-decoration: none; }
        .btn-danger  { background: #e41e3f; color: #fff; }
        .btn-danger:hover  { background: #c91a37; text-decoration: none; }
        .btn-warn    { background: #ff9800; color: #fff; }
        .btn-warn:hover    { background: #e65100; text-decoration: none; }
        .btn-info    { background: #2196f3; color: #fff; }
        .btn-info:hover    { background: #1565c0; text-decoration: none; }
        .btn-lg { padding: 9px 18px; font-size: 14px; }

        /* ── Forms ── */
        .admin-form-group { margin-bottom: 16px; }
        .admin-form-group label { display: block; margin-bottom: 5px; font-size: 13px; font-weight: 600; color: var(--text-muted); }
        .admin-form-group input[type=text],
        .admin-form-group input[type=number],
        .admin-form-group input[type=email],
        .admin-form-group select,
        .admin-form-group textarea {
            width: 100%;
            padding: 8px 12px;
            border: 1px solid var(--border-color);
            border-radius: 6px;
            font-size: 14px;
            background: var(--bg-main);
            color: var(--text-main);
            outline: none;
            transition: border-color .15s;
        }
        .admin-form-group input:focus,
        .admin-form-group select:focus,
        .admin-form-group textarea:focus { border-color: var(--primary-color); }
        .admin-form-group textarea { resize: vertical; min-height: 80px; }

        /* ── Filter bar ── */
        .filter-bar {
            display: flex; gap: 10px; flex-wrap: wrap;
            background: var(--bg-surface);
            border: 1px solid var(--border-color);
            border-radius: var(--radius-main);
            padding: 12px 16px;
            margin-bottom: 16px;
            align-items: flex-end;
        }
        .filter-bar .admin-form-group { margin: 0; }
        .filter-bar .admin-form-group label { font-size: 11px; }
        .filter-bar input, .filter-bar select { width: auto; min-width: 120px; }

        /* ── Modal ── */
        .modal-overlay {
            display: none; position: fixed; inset: 0;
            background: rgba(0,0,0,.5); z-index: 9999;
            align-items: center; justify-content: center;
        }
        .modal-overlay.open { display: flex; }
        .modal-card {
            background: #fff; border-radius: var(--radius-main);
            box-shadow: 0 10px 30px rgba(0,0,0,.2);
            width: 100%; max-width: 440px; padding: 24px;
            animation: modalIn .2s ease-out;
        }
        @keyframes modalIn { from{transform:scale(.95);opacity:0} to{transform:scale(1);opacity:1} }
        .modal-title { font-size: 18px; font-weight: 700; margin-bottom: 12px; color: var(--text-main); }
        .modal-actions { display: flex; gap: 8px; justify-content: flex-end; margin-top: 16px; }

        /* ── Pagination ── */
        .pagination { display: flex; gap: 4px; margin-top: 16px; flex-wrap: wrap; }
        .pagination a {
            padding: 6px 10px; border: 1px solid var(--border-color);
            border-radius: 5px; font-size: 13px; color: var(--text-main);
            text-decoration: none; transition: background .15s;
        }
        .pagination a:hover { background: var(--bg-hover); }
        .pagination a.active { background: var(--primary-color); color: #fff; border-color: var(--primary-color); }

        /* ── Misc ── */
        .text-muted { color: var(--text-muted); font-size: 13px; }
        .mb-16 { margin-bottom: 16px; }
        .mb-8  { margin-bottom: 8px; }
        .card {
            background: var(--bg-surface); border: 1px solid var(--border-color);
            border-radius: var(--radius-main); padding: 20px; box-shadow: var(--shadow);
            margin-bottom: 16px;
        }
        .two-col { display: grid; grid-template-columns: 1fr 1fr; gap: 16px; }
        @media (max-width: 900px) {
            .admin-sidebar { display: none; }
            .two-col { grid-template-columns: 1fr; }
        }
        @media (max-width: 600px) {
            .admin-wrapper { padding: 0 8px; }
            .admin-page-header { flex-direction: column; align-items: flex-start; }
        }
    </style>
</head>
<body>

<!-- ── NAVBAR (taki sam jak główny serwis) ── -->
<nav class="navbar">
    <div class="navbar-brand">
        <a href="<?= $_base ?>/index.php" style="text-decoration:none;color:var(--primary-color)">TwarzBlok</a>
        <span style="font-size:12px;background:rgba(51,131,54,.15);color:var(--primary-color);padding:3px 8px;border-radius:4px;margin-left:8px;font-weight:600"><?= $_role_label ?></span>
    </div>
    <div class="navbar-links2">
        <a href="<?= $_base ?>/index.php" title="Powrót do portalu">
            <div class="icon-wrapper"><svg><use xlink:href="<?= $_base ?>/icons/symbol-defs.svg#icon-home"></use></svg></div>
        </a>
        <div class="user-profile-dropdown">
            <div class="avatar-navbar-wrapper">
                <?php if ($_avatar): ?>
                    <img src="<?= $_avatar ?>" alt="Profil" style="width:100%;height:100%;object-fit:cover">
                <?php else: ?>
                    <svg style="width:22px;height:22px"><use xlink:href="<?= $_base ?>/icons/symbol-defs.svg#icon-user"></use></svg>
                <?php endif; ?>
            </div>
            <div class="dropdown-menu-content">
                <a href="<?= $_base ?>/settings.php" class="dropdown-item">Ustawienia</a>
                <a href="<?= $_base ?>/logout.php" class="dropdown-item">Wyloguj się</a>
            </div>
        </div>
    </div>
</nav>

<!-- ── ADMIN WRAPPER ── -->
<div class="admin-wrapper">

    <!-- SIDEBAR -->
    <aside class="admin-sidebar">
        <div class="admin-sidebar-header">Panel <?= $_role_label ?></div>
        <nav class="admin-nav">
            <?php if (can('view_dashboard')): ?>
                <a href="<?= $_base ?>/admin/dashboard.php" class="admin-nav-item <?= ($active_page??'')===('dashboard')?'active':'' ?>">
                    📊 Dashboard
                </a>
            <?php endif; ?>

            <?php if (can('manage_users')): ?>
                <a href="<?= $_base ?>/admin/users/" class="admin-nav-item <?= ($active_page??'')===('users')?'active':'' ?>">
                    👥 Użytkownicy
                </a>
            <?php endif; ?>

            <?php if (can('view_reports')): ?>
                <a href="<?= $_base ?>/admin/reports/" class="admin-nav-item <?= ($active_page??'')===('reports')?'active':'' ?>">
                    🚩 Zgłoszenia
                    <?php if ($_new_rep > 0): ?><span class="nav-badge" id="reports-badge"><?= $_new_rep ?></span><?php endif; ?>
                </a>
            <?php endif; ?>

            <?php if (can('manage_groups')): ?>
                <hr class="admin-nav-sep">
                <a href="<?= $_base ?>/admin/groups/" class="admin-nav-item <?= ($active_page??'')===('groups')?'active':'' ?>">
                    🏘 Grupy
                </a>
            <?php endif; ?>

            <?php if (can('view_mod_logs')): ?>
                <a href="<?= $_base ?>/admin/logs/" class="admin-nav-item <?= ($active_page??'')===('logs')?'active':'' ?>">
                    📋 Logi moderacji
                </a>
            <?php endif; ?>

            <?php if (can('system_settings')): ?>
                <a href="<?= $_base ?>/admin/settings/" class="admin-nav-item <?= ($active_page??'')===('settings')?'active':'' ?>">
                    ⚙️ Ustawienia
                </a>
            <?php endif; ?>

            <hr class="admin-nav-sep">
            <a href="<?= $_base ?>/index.php" class="admin-nav-item">← Wróć do portalu</a>
        </nav>
    </aside>

    <!-- CONTENT -->
    <div class="admin-content">
        <?php
        $__fs  = flashGet('success');
        $__fe  = flashGet('error');
        if ($__fs) echo '<div class="alert alert-success">✅ ' . htmlspecialchars($__fs) . '</div>';
        if ($__fe) echo '<div class="alert alert-danger">❌ ' . htmlspecialchars($__fe) . '</div>';
        ?>
