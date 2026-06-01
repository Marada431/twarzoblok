<?php
session_start();
?>

<!doctype html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport"
          content="width=device-width, user-scalable=no, initial-scale=1.0, maximum-scale=1.0, minimum-scale=1.0">
    <meta http-equiv="X-UA-Compatible" content="ie=edge">
    <title>Document</title>
    <link rel="stylesheet" href="css/style.css">
</head>
<body>

<nav class="navbar">
    <div class="navbar-brand">TwarzBlok
        <input class="suchemashine" type="text" name="wyszukiwarka" placeholder="Szukaj na TwarzBlok">
    </div>
<!--    IKONY-->
    <div class="navbar-links">
        <a href="index.php">
            <svg><use xlink:href="./icons/symbol-defs.svg#icon-home"></use></svg>
        </a>

        <a href="games.php">
            <svg><use xlink:href="./icons/symbol-defs.svg#icon-film"></use></svg>
        </a>

        <a href="#">
            <svg><use xlink:href="./icons/symbol-defs.svg#icon-users"></use></svg>
        </a>

        <a href="#">
            <svg><use xlink:href="./icons/symbol-defs.svg#icon-briefcase"></use></svg>
        </a>

        <a href="messages.php">
            <svg><use xlink:href="./icons/symbol-defs.svg#icon-bubbles4"></use></svg>
        </a>
    </div>

    <div class="navbar-links2">
        <?php if (isset($_SESSION['logged_in']) && $_SESSION['logged_in'] === true && isset($_SESSION['role']) && $_SESSION['role'] === 'admin'): ?>
            <a href="admin_panel.php" title="Panel Administratora">
                <div class="icon-wrapper" style="background-color: rgba(230, 57, 70, 0.1); color: #e63946;">
                    <svg style="fill: currentColor;">
                        <use xlink:href="./icons/symbol-defs.svg#icon-list2"></use>
                    </svg>
                </div>
            </a>
        <?php endif; ?>

        <a href="#"><div class="icon-wrapper"><svg>
                    <use xlink:href="./icons/symbol-defs.svg#icon-list2"></use>
                </svg></div></a>

        <a href="#"><div class="icon-wrapper"><svg>
                    <use xlink:href="./icons/symbol-defs.svg#icon-bubbles2"></use>
                </svg></div></a>

        <a href="#"><div class="icon-wrapper"><svg>
                    <use xlink:href="./icons/symbol-defs.svg#icon-bell"></use>
                </svg></div></a>

        <?php if (isset($_SESSION['logged_in']) && $_SESSION['logged_in'] === true): ?>
            <div class="user-profile-dropdown">
                <div class="avatar-navbar-wrapper">
                    <?php if (!empty($_SESSION['avatar_url'])): ?>
                        <img src="<?php echo htmlspecialchars($_SESSION['avatar_url']); ?>" alt="Profil" style="width: 100%; height: 100%; object-fit: cover; display: block;">
                    <?php else: ?>
                        <svg style="width: 24px; height: 24px;"><use xlink:href="./icons/symbol-defs.svg#icon-user"></use></svg>
                    <?php endif; ?>
                </div>

                <div class="dropdown-menu-content">
                    <a href="settings.php" class="dropdown-item settings-btn">
                        <svg>
                            <use xlink:href="./icons/symbol-defs.svg#icon-user"></use> </svg>
                        Ustawienia i prywatność
                    </a>

                    <a href="logout.php" class="dropdown-item logout-btn"><svg>
                            <use xlink:href="./icons/symbol-defs.svg#icon-user"></use>
                        </svg>
                        <span class="icon"></span> Wyloguj się
                    </a>
                </div>
            </div>
        <?php else: ?>
            <a href="login.php">
                <div class="icon-wrapper">
                    <svg><use xlink:href="./icons/symbol-defs.svg#icon-user"></use></svg>
                </div>
            </a>
        <?php endif; ?>
    </div>
</nav>

<div class="fb-container">

    <aside class="sidebar-left card2">

        <h3 class="m-bottom-10">Menu</h3>
        <ul class="menu-list">
            <li><div class="icon-wrapper"><svg>
                <use xlink:href="./icons/symbol-defs.svg#icon-users"></use>
            </svg></div><a href="#">Znajomi</a></li>

            <li><div class="icon-wrapper"><svg>
                <use xlink:href="./icons/symbol-defs.svg#icon-star-empty"></use>
            </svg></div><a href="#">Grupy</a></li>

            <li><div class="icon-wrapper"><svg>
                <use xlink:href="./icons/symbol-defs.svg#icon-bubbles4"></use>
            </svg></div><a href="games.php">Mini Gry</a></li>

            <li><div class="icon-wrapper"><svg>
                <use xlink:href="./icons/symbol-defs.svg#icon-briefcase"></use>
            </svg></div><a href="messages.php">Wiadomości (Czat)</a></li> </ul>
    </aside>

    <main class="feed">

        <div class="card">
            <div class="post-create-container" style="background-color: var(--bg-surface); border-radius: 10px; padding: 12px 16px; box-shadow: 0 1px 2px rgba(0, 0, 0, 0.1); max-width: 600px; width: 100%; margin-bottom: 15px;">

                <div class="post-create-top" style="display: flex; ; gap: 8px; padding-bottom: 12px;">

                    <div class="avatar-navbar-wrapper" style="width: 40px; height: 40px; flex-shrink: 0;">
                        <?php if (isset($_SESSION['logged_in']) && $_SESSION['logged_in'] === true && !empty($_SESSION['avatar_url'])): ?>
                            <img src="<?php echo htmlspecialchars($_SESSION['avatar_url']); ?>" alt="Twój profil" style="width: 100%; height: 100%; object-fit: cover; display: block; border-radius: var(--radius-round);">
                        <?php else: ?>

                            <div class="avatar" style="width: 100%; height: 100%; display: flex; align-items: center; justify-content: center; background-color: var(--border-color); border-radius: var(--radius-round);">
                                <svg style="width: 20px; height: 20px; fill: var(--text-muted);"><use xlink:href="./icons/symbol-defs.svg#icon-user"></use></svg>
                            </div>
                        <?php endif; ?>
                    </div>

                    <?php
                    $placeholder_text = "O czym teraz myślisz?";
                    if (isset($_SESSION['logged_in']) && $_SESSION['logged_in'] === true && !empty($_SESSION['first_name'])) {
                        $placeholder_text = "O czym teraz myślisz, " . htmlspecialchars($_SESSION['first_name']) . "?";
                    }
                    ?>
                    <input type="text" class="form-input" placeholder="<?php echo $placeholder_text; ?>" style="flex-grow: 1; height: 40px; padding: 0 16px; border: 1px solid var(--border-color); border-radius: 20px; background-color: var(--bg-main); outline: none;">
                </div>

                <div class="post-create-bottom" style="display: flex; justify-content: space-between; align-items: center; padding-top: 10px; flex-wrap: wrap; gap: 10px;">
                    <div class="post-create-actions" style="display: flex; gap: 15px;">

                        <div class="action-item" style="display: flex; align-items: center; gap: 8px; color: var(--text-muted); font-size: 14px; font-weight: 600; cursor: pointer; padding: 6px 8px; border-radius: 6px; background: transparent;" onmouseover="this.style.backgroundColor='var(--bg-hover)'" onmouseout="this.style.backgroundColor='transparent'">
                            <svg style="width: 18px; height: 18px; fill: var(--primary-color); display: inline-block;">
                                <use xlink:href="./icons/symbol-defs.svg#icon-film"></use>
                            </svg>
                            Dodaj zdjęcie/film
                        </div>

                        <div class="action-item" style="display: flex; align-items: center; gap: 8px; color: var(--text-muted); font-size: 14px; font-weight: 600; cursor: pointer; padding: 6px 8px; border-radius: 6px; background: transparent;" onmouseover="this.style.backgroundColor='var(--bg-hover)'" onmouseout="this.style.backgroundColor='transparent'">
                            <svg style="width: 18px; height: 18px; fill: #2e7d32; display: inline-block;">
                                <use xlink:href="./icons/symbol-defs.svg#icon-users"></use>
                            </svg>
                            Oznacz osoby
                        </div>

                    </div>

                    <a href="#" class="btn btn-primary" style="background-color: var(--primary-color); color: white; padding: 6px 20px; font-weight: 600; border-radius: 20px; text-decoration: none; display: inline-block; text-align: center;" onmouseover="this.style.backgroundColor='var(--primary-hover)'" onmouseout="this.style.backgroundColor='var(--primary-color)'">
                        Opublikuj
                    </a>
                </div>

            </div>
        </div>


        <h4 class="reels-section-title">Krótkie formy wideo (Reels)</h4>
        <div class="reels-container">
            <div class="reel-card"><div class="reel-badge">@janek_wideo</div></div>
            <div class="reel-card"><div class="reel-badge">@smieszne_koty</div></div>
            <div class="reel-card"><div class="reel-badge">@gaming_clip</div></div>
        </div>


        <div class="card1">
            <div class="post-header">
                <div class="avatar"></div>
                <div>
                    <div class="post-author">Kamil Nowak</div>
                    <div class="post-time">Przed chwilą • Publiczny</div>
                </div>
            </div>
            <div class="post-content">
                "Lorem ipsum dolor sit amet, consectetur adipiscing elit, sed do eiusmod tempor incididunt ut labore et dolore magna aliqua. Ut enim ad minim veniam, quis nostrud exercitation ullamco laboris nisi ut aliquip ex ea commodo consequat. Duis aute irure dolor in reprehenderit in voluptate velit esse cillum dolore eu fugiat nulla pariatur. Excepteur sint occaecat cupidatat non proident, sunt in culpa qui officia deserunt mollit anim id est laborum."
            </div>
        </div>
    </main>

    <aside class="sidebar-right card">
        <h3 class="m-bottom-15">Kontakty (Czat)</h3>
        <div class="contact-item2" onclick="window.location.href='wiadomosci.html'">
            <div class="avatar online"></div>
            <span>Anna Kowalska</span>
        </div>
        <div class="contact-item" onclick="window.location.href='wiadomosci.html'">
            <div class="avatar online"></div>
            <span>Piotr Zieliński</span>
        </div>
    </aside>

</div>

</body>
</html>