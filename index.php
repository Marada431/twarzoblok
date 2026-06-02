<?php
session_start();

require_once 'config/database.php';

//Jeśli niezalogowany to przekierowywuje na login.php//
if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
    header('Location: login.php');
    exit;
}

$current_user_id = (int) $_SESSION['user_id'];

//Dodawanie postów//
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    // --- DODAWANIE NOWEGO POSTA ---
    if (isset($_POST['submit_post'])) {
        $upload_dir = 'uploads/posts/';
        if (!is_dir($upload_dir)) {
            mkdir($upload_dir, 0777, true);
        }

        $content = trim($_POST['content'] ?? '');
        $media_links = null;
        $has_content = !empty($content);
        $has_file = isset($_FILES['post_media']) && $_FILES['post_media']['error'] === UPLOAD_ERR_OK;

        if ($has_content || $has_file) {
            $media_array = [];

            if ($has_file) {
                $file_tmp = $_FILES['post_media']['tmp_name'];
                $file_name = time() . '_' . basename($_FILES['post_media']['name']);
                $file_path = $upload_dir . $file_name;

                if (getimagesize($file_tmp) !== false) {
                    if (move_uploaded_file($file_tmp, $file_path)) {
                        $media_array[] = $file_path;
                    }
                }
            }

            if (!empty($media_array)) {
                $media_links = json_encode($media_array);
            }

            $stmt = db()->prepare("INSERT INTO posts (author_id, content, media_links, created_at) VALUES (:author_id, :content, :media_links, NOW())");
            $stmt->execute([
                    ':author_id'   => $current_user_id,
                    ':content'     => $content,
                    ':media_links' => $media_links
            ]);

            header("Location: " . $_SERVER['PHP_SELF']);
            exit;
        }
    }

    // --- OBSŁUGA AJAX ---
    if (isset($_POST['action'])) {
        header('Content-Type: application/json');

        if (!isset($_SESSION['user_id'])) {
            echo json_encode(['success' => false, 'message' => 'Nie jesteś zalogowany.']);
            exit;
        }

        $action = $_POST['action'];

        // AJAX: Dodaj znajomego
        if ($action === 'add_friend') {
            $target_id = isset($_POST['target_user_id']) ? (int) $_POST['target_user_id'] : 0;

            if ($target_id <= 0 || $target_id === $current_user_id) {
                echo json_encode(['success' => false, 'message' => 'Nieprawidłowy użytkownik.']);
                exit;
            }

            try {
                $check = db()->prepare("
                    SELECT friendship_id, status FROM friendships 
                    WHERE (requester_id = :u1 AND addressee_id = :u2)
                       OR (requester_id = :u3 AND addressee_id = :u4)
                ");
                $check->execute([
                        ':u1' => $current_user_id, ':u2' => $target_id,
                        ':u3' => $target_id,       ':u4' => $current_user_id
                ]);

                if ($check->fetch()) {
                    echo json_encode(['success' => false, 'message' => 'Zaproszenie już istnieje lub jesteście znajomymi.']);
                    exit;
                }

                $insert = db()->prepare("INSERT INTO friendships (requester_id, addressee_id, status) VALUES (:requester, :addressee, 'pending')");
                $insert->execute([':requester' => $current_user_id, ':addressee' => $target_id]);

                echo json_encode(['success' => true, 'message' => 'Zaproszenie wysłane!']);
            } catch (PDOException $e) {
                echo json_encode(['success' => false, 'message' => 'Błąd bazy danych: ' . $e->getMessage()]);
            }
            exit;
        }

        // AJAX: Usuń propozycję z karuzeli (Ciasteczko)
        if ($action === 'remove_suggestion') {
            $target_id = isset($_POST['target_user_id']) ? (int) $_POST['target_user_id'] : 0;

            if ($target_id <= 0) {
                echo json_encode(['success' => false, 'message' => 'Nieprawidłowy użytkownik.']);
                exit;
            }

            $cookie_name = 'removed_suggestions';
            $removed_ids = isset($_COOKIE[$cookie_name]) ? json_decode($_COOKIE[$cookie_name], true) : [];
            if (!is_array($removed_ids)) $removed_ids = [];

            if (!in_array($target_id, $removed_ids)) {
                $removed_ids[] = $target_id;
            }

            setcookie($cookie_name, json_encode($removed_ids), time() + (30 * 24 * 60 * 60), '/');
            echo json_encode(['success' => true, 'message' => 'Propozycja usunięta.']);
            exit;
        }

        // AJAX: Pobierz licznik oczekujących zaproszeń
        if ($action === 'get_pending_count') {
            $stmt = db()->prepare("SELECT COUNT(*) AS cnt FROM friendships WHERE addressee_id = :uid AND status = 'pending'");
            $stmt->execute([':uid' => $current_user_id]);
            echo json_encode(['success' => true, 'count' => (int) $stmt->fetchColumn()]);
            exit;
        }

        echo json_encode(['success' => false, 'message' => 'Nieznana akcja.']);
        exit;
    }
}

// ==========================================
// 4. POBIERANIE DANYCH DO WYŚWIETLENIA
// ==========================================
$removed_ids = isset($_COOKIE['removed_suggestions']) ? json_decode($_COOKIE['removed_suggestions'], true) : [];
if (!is_array($removed_ids)) $removed_ids = [];
$removed_ids = array_map('intval', $removed_ids);

$sql = "
    SELECT u.user_id, u.first_name, u.last_name, u.avatar_url,
           COALESCE(u.city, MAX(addr.city)) AS display_city
    FROM users u
    LEFT JOIN addresses addr ON u.user_id = addr.user_id
    WHERE u.user_id != :current_user
    AND u.user_id NOT IN (
        SELECT CASE 
            WHEN requester_id = :current_user2 THEN addressee_id 
            WHEN addressee_id = :current_user3 THEN requester_id 
        END
        FROM friendships
        WHERE (requester_id = :current_user4 OR addressee_id = :current_user5)
    )
";

if (!empty($removed_ids)) {
    $placeholders = [];
    foreach ($removed_ids as $i => $id) {
        $placeholders[] = ':removed_' . $i;
    }
    $sql .= " AND u.user_id NOT IN (" . implode(',', $placeholders) . ")";
}
$sql .= " GROUP BY u.user_id ORDER BY RAND() LIMIT 12";

$stmt = db()->prepare($sql);
$params = [
        ':current_user'  => $current_user_id,
        ':current_user2' => $current_user_id,
        ':current_user3' => $current_user_id,
        ':current_user4' => $current_user_id,
        ':current_user5' => $current_user_id,
];
foreach ($removed_ids as $i => $id) {
    $params[':removed_' . $i] = $id;
}
$stmt->execute($params);
$suggestions = $stmt->fetchAll();

// Pobierz początkowy stan licznika dla Navbaru
$pending_stmt = db()->prepare("SELECT COUNT(*) FROM friendships WHERE addressee_id = :uid AND status = 'pending'");
$pending_stmt->execute([':uid' => $current_user_id]);
$pending_count = (int) $pending_stmt->fetchColumn();
?>
<!doctype html>
<html lang="pl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta http-equiv="X-UA-Compatible" content="ie=edge">
    <title>TwarzBlok - Feed</title>
    <link rel="stylesheet" href="css/style.css">

    <style>
        svg { max-width: 24px; max-height: 24px; }

        .navbar {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 0 15px;
            box-sizing: border-box;
            position: fixed;
            top: 0; left: 0; width: 100%;
            z-index: 1000;
        }

        .fb-container {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            max-width: 1200px;
            margin: 70px auto 20px auto;
            padding: 0 15px;
            gap: 20px;
        }

        .sidebar-left, .sidebar-right {
            width: 250px;
            flex-shrink: 0;
            position: sticky;
            top: 80px;
        }

        .feed { flex-grow: 1; max-width: 600px; margin: 0 auto; }

        .post-form-card {
            background-color: var(--bg-surface);
            border-radius: 10px;
            padding: 12px 16px;
            box-shadow: 0 1px 2px rgba(0, 0, 0, 0.1);
            width: 100%;
            margin-bottom: 15px;
        }
        .post-create-top { display: flex; gap: 8px; padding-bottom: 12px; }
        .post-create-top .avatar-wrapper { width: 40px; height: 40px; flex-shrink: 0; }
        .post-create-top .avatar-wrapper img { width: 100%; height: 100%; object-fit: cover; display: block; border-radius: var(--radius-round); }
        .post-create-top .avatar-placeholder { width: 100%; height: 100%; display: flex; align-items: center; justify-content: center; background-color: var(--border-color); border-radius: var(--radius-round); }
        .post-create-top .avatar-placeholder svg { width: 20px; height: 20px; fill: var(--text-muted); }
        .post-create-top .form-input { flex-grow: 1; height: 40px; padding: 0 16px; border: 1px solid var(--border-color); border-radius: 20px; background-color: var(--bg-main); outline: none; }

        .post-create-bottom { display: flex; justify-content: space-between; align-items: center; padding-top: 10px; flex-wrap: wrap; gap: 10px; }
        .post-create-actions { display: flex; gap: 15px; }
        .post-create-actions label, .post-create-actions .action-item { display: flex; align-items: center; gap: 8px; color: var(--text-muted); font-size: 14px; font-weight: 600; cursor: pointer; padding: 6px 8px; border-radius: 6px; background: transparent; transition: background 0.2s; }
        .post-create-actions label:hover, .post-create-actions .action-item:hover { background-color: var(--bg-hover); }
        .post-create-actions svg { width: 18px; height: 18px; display: inline-block; }
        .btn-submit-post { border: none; background-color: var(--primary-color); color: white; padding: 6px 20px; font-weight: 600; border-radius: 20px; cursor: pointer; transition: background 0.2s; }
        .btn-submit-post:hover { background-color: var(--primary-hover); }

        #image-preview-container { position: relative; margin-top: 15px; border-radius: 8px; overflow: hidden; display: none; background-color: var(--bg-hover, #f0f2f5); }
        #image-preview { width: 100%; max-height: 300px; object-fit: contain; display: block; }
        .remove-preview-btn { position: absolute; top: 10px; right: 10px; background-color: rgba(255, 255, 255, 0.8); border: none; border-radius: 50%; width: 30px; height: 30px; font-size: 16px; cursor: pointer; display: flex; align-items: center; justify-content: center; box-shadow: 0 2px 4px rgba(0,0,0,0.2); }

        .friend-badge { position: absolute; top: -8px; right: -10px; background: #e63946; color: white; border-radius: 50%; min-width: 20px; height: 20px; font-size: 12px; font-weight: bold; display: flex; align-items: center; justify-content: center; padding: 0 4px; box-shadow: 0 0 0 2px var(--bg-main, #fff); }

        .post-feed-card { background-color: var(--bg-surface); border-radius: 10px; padding: 15px; margin-bottom: 15px; box-shadow: 0 1px 2px rgba(0,0,0,0.1); }
        .post-feed-header { display: flex; align-items: center; gap: 10px; margin-bottom: 10px; }
        .post-feed-header .avatar-box { width: 40px; height: 40px; border-radius: 50%; overflow: hidden; }
        .post-feed-header .avatar-box img { width:100%; height:100%; object-fit:cover; }
        .post-feed-header .avatar-box .placeholder-svg { width:100%; height:100%; background-color:var(--border-color); display:flex; align-items:center; justify-content:center; }
        .post-feed-header .avatar-box svg { width:20px; height:20px; fill:var(--text-muted); }
        .post-feed-author { font-weight: 600; font-size: 15px; color: var(--text-main); }
        .post-feed-time { font-size: 12px; color: var(--text-muted); }
        .post-feed-content { font-size: 15px; color: var(--text-main); margin-bottom: 10px; }
        .post-feed-media { margin-top: 10px; border-radius: 8px; overflow: hidden; border: 1px solid var(--border-color, #eee); }
        .post-feed-media img { width: 100%; display: block; max-height: 600px; object-fit: contain; background-color: var(--bg-hover, #f0f2f5); }

        .suggestions-section { margin-bottom: 20px; background-color: var(--bg-surface); border-radius: 10px; padding: 16px; box-shadow: 0 1px 2px rgba(0,0,0,0.1); margin-bottom: 15px; }
        .suggestions-section h4 { font-size: 16px; font-weight: 600; color: var(--text-main, #1c1e21); margin-bottom: 12px; padding: 0 4px; }
        .suggestions-carousel-wrapper { position: relative; display: flex; align-items: center; overflow: visible; }
        .suggestions-carousel { display: flex; gap: 12px; overflow-x: auto; scroll-behavior: smooth; scroll-snap-type: x mandatory; -webkit-overflow-scrolling: touch; padding: 4px 2px 8px 2px; scrollbar-width: thin; scrollbar-color: var(--border-color, #ccc) transparent; }
        .suggestions-carousel::-webkit-scrollbar { height: 6px; }
        .suggestions-carousel::-webkit-scrollbar-track { background: transparent; }
        .suggestions-carousel::-webkit-scrollbar-thumb { background-color: var(--border-color, #ccc); border-radius: 10px; }

        .suggestion-card { flex: 0 0 auto; width: 180px; background-color: var(--bg-surface, #fff); border-radius: 12px; box-shadow: 0 1px 3px rgba(0,0,0,0.1); padding: 16px 12px; text-align: center; scroll-snap-align: start; transition: transform 0.2s, box-shadow 0.2s; position: relative; display: flex; flex-direction: column; align-items: center; gap: 8px; }
        .suggestion-card:hover { transform: translateY(-2px); box-shadow: 0 4px 12px rgba(0,0,0,0.15); }
        .suggestion-card .suggestion-avatar { width: 72px; height: 72px; border-radius: 50%; overflow: hidden; flex-shrink: 0; background-color: var(--border-color, #e0e0e0); }
        .suggestion-card .suggestion-avatar img { width: 100%; height: 100%; object-fit: cover; display: block; }
        .suggestion-card .suggestion-avatar .avatar-placeholder { width: 100%; height: 100%; display: flex; align-items: center; justify-content: center; background-color: var(--border-color, #e0e0e0); }
        .suggestion-card .suggestion-avatar .avatar-placeholder svg { width: 36px; height: 36px; fill: var(--text-muted, #888); }
        .suggestion-card .suggestion-name { font-weight: 600; font-size: 14px; color: var(--text-main, #1c1e21); line-height: 1.3; max-width: 100%; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
        .suggestion-card .suggestion-location { font-size: 12px; color: var(--text-muted, #65676b); display: flex; align-items: center; gap: 4px; justify-content: center; }
        .suggestion-card .suggestion-actions { display: flex; gap: 8px; width: 100%; margin-top: 4px; }
        .suggestion-card .btn-add-friend { flex: 1; padding: 7px 10px; font-size: 13px; font-weight: 600; border: none; border-radius: 6px; cursor: pointer; background-color: var(--primary-color, #1877f2); color: #fff; transition: background-color 0.2s; }
        .suggestion-card .btn-add-friend:hover { background-color: var(--primary-hover, #166fe5); }
        .suggestion-card .btn-add-friend:disabled { background-color: #42b72a; cursor: default; }
        .suggestion-card .btn-remove-suggestion { padding: 7px 10px; font-size: 13px; font-weight: 600; border: 1px solid var(--border-color, #ddd); border-radius: 6px; cursor: pointer; background-color: transparent; color: var(--text-muted, #65676b); transition: background-color 0.2s, color 0.2s; white-space: nowrap; }
        .suggestion-card .btn-remove-suggestion:hover { background-color: var(--bg-hover, #f0f2f5); color: var(--text-main, #1c1e21); }

        .carousel-arrow { position: absolute; top: 50%; transform: translateY(-50%); z-index: 10; width: 36px; height: 36px; border-radius: 50%; border: 1px solid var(--border-color, #ddd); background: rgba(255, 255, 255, 0.95); cursor: pointer; display: flex; align-items: center; justify-content: center; box-shadow: 0 2px 8px rgba(0,0,0,0.2); transition: background-color 0.2s, box-shadow 0.2s; }
        .carousel-arrow:hover { background: #ffffff; box-shadow: 0 4px 12px rgba(0,0,0,0.25); }
        .carousel-arrow-left { left: -12px; }
        .carousel-arrow-right { right: -12px; }
        .carousel-arrow svg { width: 18px; height: 18px; fill: var(--text-main, #1c1e21); }
        .suggestions-empty { text-align: center; padding: 20px; color: var(--text-muted, #888); font-size: 14px; }
    </style>
</head>
<body>

<nav class="navbar">
    <div class="navbar-brand">TwarzBlok
        <input class="suchemashine" type="text" name="wyszukiwarka" placeholder="Szukaj na TwarzBlok">
    </div>

    <div class="navbar-links">
        <a href="index.php"><svg><use xlink:href="./icons/symbol-defs.svg#icon-home"></use></svg></a>
        <a href="games.php"><svg><use xlink:href="./icons/symbol-defs.svg#icon-film"></use></svg></a>

        <a href="friend_requests.php" class="friend-requests-link" style="position: relative; display: inline-flex; align-items: center;">
            <svg><use xlink:href="./icons/symbol-defs.svg#icon-users"></use></svg>
            <span id="pending-friends-count" class="friend-badge" style="<?php echo ($pending_count === 0) ? 'display: none;' : ''; ?>">
                <?php echo $pending_count; ?>
            </span>
        </a>

        <a href="#"><svg><use xlink:href="./icons/symbol-defs.svg#icon-briefcase"></use></svg></a>
        <a href="chat.php"><svg><use xlink:href="./icons/symbol-defs.svg#icon-bubbles4"></use></svg></a>
    </div>

    <div class="navbar-links2">
        <?php if (isset($_SESSION['role']) && $_SESSION['role'] === 'admin'): ?>
            <a href="admin_panel.php" title="Panel Administratora">
                <div class="icon-wrapper" style="background-color: rgba(230, 57, 70, 0.1); color: #e63946;">
                    <svg style="fill: currentColor;"><use xlink:href="./icons/symbol-defs.svg#icon-list2"></use></svg>
                </div>
            </a>
        <?php endif; ?>

        <a href="#"><div class="icon-wrapper"><svg><use xlink:href="./icons/symbol-defs.svg#icon-list2"></use></svg></div></a>
        <a href="#"><div class="icon-wrapper"><svg><use xlink:href="./icons/symbol-defs.svg#icon-bubbles2"></use></svg></div></a>
        <a href="#"><div class="icon-wrapper"><svg><use xlink:href="./icons/symbol-defs.svg#icon-bell"></use></svg></div></a>

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
                    <svg><use xlink:href="./icons/symbol-defs.svg#icon-user"></use></svg> Ustawienia i prywatność
                </a>
                <a href="logout.php" class="dropdown-item logout-btn">
                    <svg><use xlink:href="./icons/symbol-defs.svg#icon-user"></use></svg><span class="icon"></span> Wyloguj się
                </a>
            </div>
        </div>
    </div>
</nav>

<div class="fb-container">

    <aside class="sidebar-left card2">
        <h3 class="m-bottom-10">Menu</h3>
        <ul class="menu-list">
            <li><div class="icon-wrapper"><svg><use xlink:href="./icons/symbol-defs.svg#icon-users"></use></svg></div><a href="#">Znajomi</a></li>
            <li><div class="icon-wrapper"><svg><use xlink:href="./icons/symbol-defs.svg#icon-star-empty"></use></svg></div><a href="#">Grupy</a></li>
            <li><div class="icon-wrapper"><svg><use xlink:href="./icons/symbol-defs.svg#icon-bubbles4"></use></svg></div><a href="games.php">Mini Gry</a></li>
            <li><div class="icon-wrapper"><svg><use xlink:href="./icons/symbol-defs.svg#icon-briefcase"></use></svg></div><a href="chat.php">Wiadomości (Czat)</a></li>
        </ul>
    </aside>

    <main class="feed">

        <div class="post-form-card">
            <form method="POST" action="" enctype="multipart/form-data">
                <div class="post-create-top">
                    <div class="avatar-wrapper">
                        <?php if (!empty($_SESSION['avatar_url'])): ?>
                            <img src="<?php echo htmlspecialchars($_SESSION['avatar_url']); ?>" alt="Twój profil">
                        <?php else: ?>
                            <div class="avatar-placeholder">
                                <svg><use xlink:href="./icons/symbol-defs.svg#icon-user"></use></svg>
                            </div>
                        <?php endif; ?>
                    </div>
                    <?php $placeholder_text = !empty($_SESSION['first_name']) ? "O czym teraz myślisz, " . htmlspecialchars($_SESSION['first_name']) . "?" : "O czym teraz myślisz?"; ?>
                    <input type="text" name="content" class="form-input" placeholder="<?php echo $placeholder_text; ?>">
                </div>

                <div id="image-preview-container">
                    <button type="button" class="remove-preview-btn" onclick="removePreview()">✕</button>
                    <img id="image-preview" src="#" alt="Podgląd">
                </div>

                <div class="post-create-bottom">
                    <div class="post-create-actions">
                        <input type="file" name="post_media" id="post-media-upload" accept="image/*" style="display: none;" onchange="previewImage(event)">
                        <label for="post-media-upload">
                            <svg style="fill: var(--primary-color);"><use xlink:href="./icons/symbol-defs.svg#icon-film"></use></svg>
                            Dodaj zdjęcie/film
                        </label>
                        <div class="action-item">
                            <svg style="fill: #2e7d32;"><use xlink:href="./icons/symbol-defs.svg#icon-users"></use></svg>
                            Oznacz osoby
                        </div>
                    </div>
                    <button type="submit" name="submit_post" class="btn-submit-post">Opublikuj</button>
                </div>
            </form>
        </div>

        <div class="suggestions-section">
            <h4>Propozycje znajomych</h4>
            <?php if (!empty($suggestions)): ?>
                <div class="suggestions-carousel-wrapper">
                    <button class="carousel-arrow carousel-arrow-left" onclick="scrollCarousel(-1)" aria-label="Przewiń w lewo" title="Poprzednie">
                        <svg viewBox="0 0 24 24"><path d="M15.41 7.41L14 6l-6 6 6 6 1.41-1.41L10.83 12z"/></svg>
                    </button>

                    <div class="suggestions-carousel" id="suggestionsCarousel">
                        <?php foreach ($suggestions as $s):
                            $s_user_id = (int) $s['user_id'];
                            $s_name = htmlspecialchars($s['first_name'] . ' ' . $s['last_name']);
                            $s_avatar = !empty($s['avatar_url']) ? htmlspecialchars($s['avatar_url']) : '';
                            $s_location = !empty($s['display_city']) ? htmlspecialchars($s['display_city']) : 'Brak lokalizacji';
                            ?>
                            <div class="suggestion-card" data-user-id="<?php echo $s_user_id; ?>">
                                <div class="suggestion-avatar">
                                    <?php if ($s_avatar): ?>
                                        <img src="<?php echo $s_avatar; ?>" alt="<?php echo $s_name; ?>">
                                    <?php else: ?>
                                        <div class="avatar-placeholder">
                                            <svg viewBox="0 0 24 24"><use xlink:href="./icons/symbol-defs.svg#icon-user"></use></svg>
                                        </div>
                                    <?php endif; ?>
                                </div>
                                <div class="suggestion-name" title="<?php echo $s_name; ?>"><?php echo $s_name; ?></div>
                                <div class="suggestion-location">
                                    <svg style="width:12px;height:12px;fill:currentColor;" viewBox="0 0 24 24"><path d="M12 2C8.13 2 5 5.13 5 9c0 5.25 7 13 7 13s7-7.75 7-13c0-3.87-3.13-7-7-7zm0 9.5c-1.38 0-2.5-1.12-2.5-2.5s1.12-2.5 2.5-2.5 2.5 1.12 2.5 2.5-1.12 2.5-2.5 2.5z"/></svg>
                                    <?php echo $s_location; ?>
                                </div>
                                <div class="suggestion-actions">
                                    <button class="btn-add-friend" onclick="addFriend(<?php echo $s_user_id; ?>, this)" title="Wyślij zaproszenie do znajomości">Dodaj</button>
                                    <button class="btn-remove-suggestion" onclick="removeSuggestion(<?php echo $s_user_id; ?>, this)" title="Usuń propozycję">Usuń</button>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>

                    <button class="carousel-arrow carousel-arrow-right" onclick="scrollCarousel(1)" aria-label="Przewiń w prawo" title="Następne">
                        <svg viewBox="0 0 24 24"><path d="M8.59 16.59L10 18l6-6-6-6-1.41 1.41L13.17 12z"/></svg>
                    </button>
                </div>
            <?php else: ?>
                <div class="suggestions-empty">Brak nowych propozycji znajomych. Zaproś znajomych do serwisu!</div>
            <?php endif; ?>
        </div>

        <?php
        $stmt = db()->prepare("SELECT p.post_id, p.content, p.media_links, p.created_at, u.first_name, u.last_name, u.avatar_url FROM posts p JOIN users u ON p.author_id = u.user_id ORDER BY p.created_at DESC");
        $stmt->execute();
        $posts = $stmt->fetchAll();

        if ($posts):
            foreach ($posts as $post):
                $author_name = htmlspecialchars($post['first_name'] . ' ' . $post['last_name']);
                $post_time = date('d.m.Y H:i', strtotime($post['created_at']));
                $content = htmlspecialchars($post['content']);
                $media_links = json_decode($post['media_links'], true);
                ?>
                <div class="post-feed-card">
                    <div class="post-feed-header">
                        <div class="avatar-box">
                            <?php if (!empty($post['avatar_url'])): ?>
                                <img src="<?php echo htmlspecialchars($post['avatar_url']); ?>" alt="Avatar">
                            <?php else: ?>
                                <div class="placeholder-svg">
                                    <svg><use xlink:href="./icons/symbol-defs.svg#icon-user"></use></svg>
                                </div>
                            <?php endif; ?>
                        </div>
                        <div>
                            <div class="post-feed-author"><?php echo $author_name; ?></div>
                            <div class="post-feed-time"><?php echo $post_time; ?> • Publiczny</div>
                        </div>
                    </div>

                    <?php if (!empty($content)): ?>
                        <div class="post-feed-content"><?php echo nl2br($content); ?></div>
                    <?php endif; ?>

                    <?php if (!empty($media_links)): ?>
                        <div class="post-feed-media">
                            <?php foreach ($media_links as $media): ?>
                                <img src="<?php echo htmlspecialchars($media); ?>" alt="Post image">
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>
            <?php
            endforeach;
        else:
            ?>
            <p style="text-align: center; color: var(--text-muted);">Brak postów do wyświetlenia.</p>
        <?php endif; ?>

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

<script>
    function previewImage(event) {
        var reader = new FileReader();
        var imageField = document.getElementById("image-preview");
        var container = document.getElementById("image-preview-container");

        reader.onload = function() {
            if(reader.readyState === 2) {
                imageField.src = reader.result;
                container.style.display = "block";
            }
        }
        if (event.target.files[0]) {
            reader.readAsDataURL(event.target.files[0]);
        }
    }

    function removePreview() {
        document.getElementById("post-media-upload").value = "";
        document.getElementById("image-preview").src = "#";
        document.getElementById("image-preview-container").style.display = "none";
    }

    function scrollCarousel(direction) {
        var carousel = document.getElementById('suggestionsCarousel');
        carousel.scrollBy({ left: direction * 200, behavior: 'smooth' });
    }

    function addFriend(targetUserId, buttonElement) {
        if (buttonElement.disabled) return;
        buttonElement.disabled = true;
        buttonElement.textContent = 'Wysyłanie...';

        fetch('<?php echo $_SERVER['PHP_SELF']; ?>', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: 'action=add_friend&target_user_id=' + encodeURIComponent(targetUserId)
        })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    buttonElement.textContent = 'Wysłano prośbę';
                    buttonElement.style.backgroundColor = '#42b72a';
                    buttonElement.style.cursor = 'default';
                } else {
                    alert(data.message || 'Nie udało się wysłać zaproszenia.');
                    buttonElement.disabled = false;
                    buttonElement.textContent = 'Dodaj';
                }
            })
            .catch(error => {
                console.error('Błąd:', error);
                buttonElement.disabled = false;
                buttonElement.textContent = 'Dodaj';
            });
    }

    function removeSuggestion(targetUserId, buttonElement) {
        var card = buttonElement.closest('.suggestion-card');

        fetch('<?php echo $_SERVER['PHP_SELF']; ?>', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: 'action=remove_suggestion&target_user_id=' + encodeURIComponent(targetUserId)
        })
            .then(response => response.json())
            .then(data => {
                if (data.success && card) {
                    card.style.transition = 'opacity 0.3s, transform 0.3s';
                    card.style.opacity = '0';
                    card.style.transform = 'scale(0.9)';
                    setTimeout(function() {
                        card.remove();
                        checkEmptyCarousel();
                    }, 300);
                } else if (!data.success) {
                    alert(data.message || 'Nie udało się usunąć propozycji.');
                }
            })
            .catch(error => console.error('Błąd:', error));
    }

    function checkEmptyCarousel() {
        var carousel = document.getElementById('suggestionsCarousel');
        if (carousel && carousel.querySelectorAll('.suggestion-card').length === 0) {
            var section = carousel.closest('.suggestions-section');
            var wrapper = section.querySelector('.suggestions-carousel-wrapper');
            if (wrapper) wrapper.style.display = 'none';

            var emptyMsg = document.createElement('div');
            emptyMsg.className = 'suggestions-empty';
            emptyMsg.textContent = 'Brak nowych propozycji znajomych. Zaproś znajomych do serwisu!';
            section.appendChild(emptyMsg);
        }
    }

    function updatePendingFriendsCount() {
        fetch('<?php echo $_SERVER['PHP_SELF']; ?>', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: 'action=get_pending_count'
        })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    var badge = document.getElementById('pending-friends-count');
                    if (!badge) return;
                    if (data.count > 0) {
                        badge.textContent = data.count;
                        badge.style.display = 'flex';
                    } else {
                        badge.style.display = 'none';
                    }
                }
            })
            .catch(error => console.error('Błąd pobierania licznika:', error));
    }

    setInterval(updatePendingFriendsCount, 15000);
    document.addEventListener('DOMContentLoaded', updatePendingFriendsCount);
</script>

</body>
</html>