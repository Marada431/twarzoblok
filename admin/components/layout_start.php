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
    <link rel="stylesheet" href="<?= $_base ?>/assets/css/main.css">
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
