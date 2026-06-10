<?php
// Wymagane zmienne przed dołączeniem:
//   $nav_pending_count (int)  – liczba oczekujących zaproszeń
//   $nav_active        (string) – aktywny link: 'feed' | 'games' | 'friends' | 'chat' | ''
// $_SESSION['role'], $_SESSION['avatar_url'] dostępne bezpośrednio
$nav_pending_count = $nav_pending_count ?? 0;
$nav_active        = $nav_active        ?? '';
?>
<nav class="navbar">
    <div class="navbar-brand">TwarzBlok
        <input class="suchemashine" type="text" placeholder="Szukaj na TwarzBlok">
    </div>

    <div class="navbar-links">
        <a href="index.php" <?= $nav_active === 'feed'    ? 'style="color:var(--primary-color)"' : '' ?>>
            <svg><use xlink:href="./icons/symbol-defs.svg#icon-home"></use></svg>
        </a>
        <a href="reels.php" <?= $nav_active === 'reels'   ? 'style="color:var(--primary-color)"' : '' ?>>
            <svg><use xlink:href="./icons/symbol-defs.svg#icon-film"></use></svg>
        </a>
        <a href="marketplace.php" <?= $nav_active === 'games'   ? 'style="color:var(--primary-color)"' : '' ?>>
            <svg><use xlink:href="./icons/symbol-defs.svg#icon-star-empty"></use></svg>
        </a>
        <a href="friend_requests.php" style="position:relative;display:inline-flex;align-items:center;<?= $nav_active === 'friends' ? 'color:var(--primary-color)' : '' ?>">
            <svg><use xlink:href="./icons/symbol-defs.svg#icon-users"></use></svg>
            <span id="pending-friends-count" class="friend-badge"
                  style="<?= $nav_pending_count === 0 ? 'display:none' : '' ?>"><?= (int)$nav_pending_count ?></span>
        </a>
        <a href="chat.php" <?= $nav_active === 'chat'    ? 'style="color:var(--primary-color)"' : '' ?>>
            <svg><use xlink:href="./icons/symbol-defs.svg#icon-bubbles4"></use></svg>
        </a>
    </div>

    <div class="navbar-links2">
        <?php if (isset($_SESSION['role']) && in_array($_SESSION['role'], ['admin', 'moderator'])): ?>
            <a href="admin_panel.php" title="Panel Administratora">
                <div class="icon-wrapper" style="background:rgba(230,57,70,.1);color:#e63946;">
                    <svg style="fill:currentColor"><use xlink:href="./icons/symbol-defs.svg#icon-list2"></use></svg>
                </div>
            </a>
        <?php endif; ?>
        <a href="#"><div class="icon-wrapper"><svg><use xlink:href="./icons/symbol-defs.svg#icon-list2"></use></svg></div></a>
        <a href="#"><div class="icon-wrapper"><svg><use xlink:href="./icons/symbol-defs.svg#icon-bubbles2"></use></svg></div></a>
        <a href="#"><div class="icon-wrapper"><svg><use xlink:href="./icons/symbol-defs.svg#icon-bell"></use></svg></div></a>

        <div class="user-profile-dropdown">
            <div class="avatar-navbar-wrapper">
                <?php if (!empty($_SESSION['avatar_url'])): ?>
                    <img src="<?= htmlspecialchars($_SESSION['avatar_url']) ?>" alt="Profil"
                         style="width:100%;height:100%;object-fit:cover;">
                <?php else: ?>
                    <svg style="width:24px;height:24px;"><use xlink:href="./icons/symbol-defs.svg#icon-user"></use></svg>
                <?php endif; ?>
            </div>
            <div class="dropdown-menu-content">
                <a href="settings.php" class="dropdown-item settings-btn">
                    <svg><use xlink:href="./icons/symbol-defs.svg#icon-user"></use></svg> Ustawienia
                </a>
                <a href="logout.php" class="dropdown-item logout-btn">
                    <svg><use xlink:href="./icons/symbol-defs.svg#icon-user"></use></svg> Wyloguj się
                </a>
            </div>
        </div>
    </div>
</nav>
