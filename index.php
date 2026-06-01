<?php
session_start();

// 1. PRZEKIEROWANIE JEŚLI UŻYTKOWNIK NIE JEST ZALOGOWANY
if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
    header("Location: login.php");
    exit; // Przerywamy dalsze ładowanie strony
}

// 2. POŁĄCZENIE Z BAZĄ DANYCH (Zmień na własne dane)
$host = '127.0.0.1';
$db   = 'twarzobok';
$user = 'root';
$pass = '';
$charset = 'utf8mb4';

$dsn = "mysql:host=$host;dbname=$db;charset=$charset";
$options = [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES   => false,
];

try {
    $pdo = new PDO($dsn, $user, $pass, $options);
} catch (\PDOException $e) {
    die("Błąd połączenia z bazą danych: " . $e->getMessage());
}

// 3. LOGIKA DODAWANIA POSTA
$upload_dir = 'uploads/posts/';
if (!is_dir($upload_dir)) {
    mkdir($upload_dir, 0777, true);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['submit_post'])) {
    $author_id = $_SESSION['user_id'];
    $content = trim($_POST['content'] ?? '');
    $media_links = null;

    $has_content = !empty($content);
    $has_file = isset($_FILES['post_media']) && $_FILES['post_media']['error'] === UPLOAD_ERR_OK;

    if ($has_content || $has_file) {
        $media_array = [];

        // Przetwarzanie wgrywanego pliku
        if ($has_file) {
            $file_tmp = $_FILES['post_media']['tmp_name'];
            $file_name = time() . '_' . basename($_FILES['post_media']['name']);
            $file_path = $upload_dir . $file_name;

            // Sprawdzanie czy to obraz
            $check = getimagesize($file_tmp);
            if ($check !== false) {
                if (move_uploaded_file($file_tmp, $file_path)) {
                    $media_array[] = $file_path;
                }
            }
        }

        if (!empty($media_array)) {
            $media_links = json_encode($media_array);
        }

        // Zapis do bazy
        $stmt = $pdo->prepare("INSERT INTO posts (author_id, content, media_links, created_at) VALUES (:author_id, :content, :media_links, NOW())");
        $stmt->execute([
                ':author_id' => $author_id,
                ':content' => $content,
                ':media_links' => $media_links
        ]);

        header("Location: " . $_SERVER['PHP_SELF']);
        exit;
    }
}
?>

<!doctype html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, user-scalable=no, initial-scale=1.0, maximum-scale=1.0, minimum-scale=1.0">
    <meta http-equiv="X-UA-Compatible" content="ie=edge">
    <title>TwarzBlok - Feed</title>
    <link rel="stylesheet" href="css/style.css">

    <style>
        /* Zabezpieczenie przed ogromnymi ikonami SVG */
        svg {
            max-width: 24px;
            max-height: 24px;
        }

        /* Wymuszenie poprawnego układu nawigacji */
        .navbar {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 0 15px;
            box-sizing: border-box;
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            z-index: 1000;
        }

        /* Ustawienie głównego kontenera w 3 kolumnach flexboxa */
        .fb-container {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            max-width: 1200px;
            margin: 70px auto 20px auto; /* 70px marginesu na górze z powodu fixed navbar */
            padding: 0 15px;
            gap: 20px;
        }

        /* Boczne paski nie mogą się zwężać/rozszerzać */
        .sidebar-left, .sidebar-right {
            width: 250px;
            flex-shrink: 0;
            position: sticky;
            top: 80px;
        }

        /* Środkowa część (feed) zajmuje dostępną przestrzeń */
        .feed {
            flex-grow: 1;
            max-width: 600px;
            margin: 0 auto;
        }

        /* Style podglądu zdjęcia w formularzu */
        #image-preview-container {
            position: relative;
            margin-top: 15px;
            border-radius: 8px;
            overflow: hidden;
            display: none; /* Ukryte dopóki nie dodasz zdjęcia */
            background-color: var(--bg-hover, #f0f2f5);
        }
        #image-preview {
            width: 100%;
            max-height: 300px;
            object-fit: contain;
            display: block;
        }
        .remove-preview-btn {
            position: absolute;
            top: 10px;
            right: 10px;
            background-color: rgba(255, 255, 255, 0.8);
            border: none;
            border-radius: 50%;
            width: 30px;
            height: 30px;
            font-size: 16px;
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: center;
            box-shadow: 0 2px 4px rgba(0,0,0,0.2);
        }
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
        <a href="#"><svg><use xlink:href="./icons/symbol-defs.svg#icon-users"></use></svg></a>
        <a href="#"><svg><use xlink:href="./icons/symbol-defs.svg#icon-briefcase"></use></svg></a>
        <a href="messages.php"><svg><use xlink:href="./icons/symbol-defs.svg#icon-bubbles4"></use></svg></a>
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
            <li><div class="icon-wrapper"><svg><use xlink:href="./icons/symbol-defs.svg#icon-briefcase"></use></svg></div><a href="messages.php">Wiadomości (Czat)</a></li>
        </ul>
    </aside>

    <main class="feed">

        <div class="card">
            <form method="POST" action="" enctype="multipart/form-data" class="post-create-container" style="background-color: var(--bg-surface); border-radius: 10px; padding: 12px 16px; box-shadow: 0 1px 2px rgba(0, 0, 0, 0.1); width: 100%; margin-bottom: 15px;">

                <div class="post-create-top" style="display: flex; gap: 8px; padding-bottom: 12px;">
                    <div class="avatar-navbar-wrapper" style="width: 40px; height: 40px; flex-shrink: 0;">
                        <?php if (!empty($_SESSION['avatar_url'])): ?>
                            <img src="<?php echo htmlspecialchars($_SESSION['avatar_url']); ?>" alt="Twój profil" style="width: 100%; height: 100%; object-fit: cover; display: block; border-radius: var(--radius-round);">
                        <?php else: ?>
                            <div class="avatar" style="width: 100%; height: 100%; display: flex; align-items: center; justify-content: center; background-color: var(--border-color); border-radius: var(--radius-round);">
                                <svg style="width: 20px; height: 20px; fill: var(--text-muted);"><use xlink:href="./icons/symbol-defs.svg#icon-user"></use></svg>
                            </div>
                        <?php endif; ?>
                    </div>

                    <?php
                    $placeholder_text = !empty($_SESSION['first_name']) ? "O czym teraz myślisz, " . htmlspecialchars($_SESSION['first_name']) . "?" : "O czym teraz myślisz?";
                    ?>
                    <input type="text" name="content" class="form-input" placeholder="<?php echo $placeholder_text; ?>" style="flex-grow: 1; height: 40px; padding: 0 16px; border: 1px solid var(--border-color); border-radius: 20px; background-color: var(--bg-main); outline: none;">
                </div>

                <div id="image-preview-container">
                    <button type="button" class="remove-preview-btn" onclick="removePreview()">✕</button>
                    <img id="image-preview" src="#" alt="Podgląd">
                </div>

                <div class="post-create-bottom" style="display: flex; justify-content: space-between; align-items: center; padding-top: 10px; flex-wrap: wrap; gap: 10px;">
                    <div class="post-create-actions" style="display: flex; gap: 15px;">

                        <input type="file" name="post_media" id="post-media-upload" accept="image/*" style="display: none;" onchange="previewImage(event)">

                        <label for="post-media-upload" class="action-item" style="display: flex; align-items: center; gap: 8px; color: var(--text-muted); font-size: 14px; font-weight: 600; cursor: pointer; padding: 6px 8px; border-radius: 6px; background: transparent;" onmouseover="this.style.backgroundColor='var(--bg-hover)'" onmouseout="this.style.backgroundColor='transparent'">
                            <svg style="width: 18px; height: 18px; fill: var(--primary-color); display: inline-block;">
                                <use xlink:href="./icons/symbol-defs.svg#icon-film"></use>
                            </svg>
                            Dodaj zdjęcie/film
                        </label>

                        <div class="action-item" style="display: flex; align-items: center; gap: 8px; color: var(--text-muted); font-size: 14px; font-weight: 600; cursor: pointer; padding: 6px 8px; border-radius: 6px; background: transparent;" onmouseover="this.style.backgroundColor='var(--bg-hover)'" onmouseout="this.style.backgroundColor='transparent'">
                            <svg style="width: 18px; height: 18px; fill: #2e7d32; display: inline-block;">
                                <use xlink:href="./icons/symbol-defs.svg#icon-users"></use>
                            </svg>
                            Oznacz osoby
                        </div>
                    </div>

                    <button type="submit" name="submit_post" class="btn btn-primary" style="border: none; background-color: var(--primary-color); color: white; padding: 6px 20px; font-weight: 600; border-radius: 20px; cursor: pointer;" onmouseover="this.style.backgroundColor='var(--primary-hover)'" onmouseout="this.style.backgroundColor='var(--primary-color)'">
                        Opublikuj
                    </button>
                </div>
            </form>
        </div>

        <h4 class="reels-section-title">Krótkie formy wideo (Reels)</h4>
        <div class="reels-container">
            <div class="reel-card"><div class="reel-badge">@janek_wideo</div></div>
            <div class="reel-card"><div class="reel-badge">@smieszne_koty</div></div>
            <div class="reel-card"><div class="reel-badge">@gaming_clip</div></div>
        </div>

        <?php
        $stmt = $pdo->prepare("
            SELECT p.post_id, p.content, p.media_links, p.created_at, 
                   u.first_name, u.last_name, u.avatar_url 
            FROM posts p
            JOIN users u ON p.author_id = u.user_id
            ORDER BY p.created_at DESC
        ");
        $stmt->execute();
        $posts = $stmt->fetchAll();

        if ($posts):
            foreach ($posts as $post):
                $author_name = htmlspecialchars($post['first_name'] . ' ' . $post['last_name']);
                $post_time = date('d.m.Y H:i', strtotime($post['created_at']));
                $content = htmlspecialchars($post['content']);
                $media_links = json_decode($post['media_links'], true);
                ?>
                <div class="card1" style="background-color: var(--bg-surface); border-radius: 10px; padding: 15px; margin-bottom: 15px; box-shadow: 0 1px 2px rgba(0,0,0,0.1);">
                    <div class="post-header" style="display: flex; align-items: center; gap: 10px; margin-bottom: 10px;">
                        <div class="avatar" style="width: 40px; height: 40px; border-radius: 50%; overflow: hidden;">
                            <?php if (!empty($post['avatar_url'])): ?>
                                <img src="<?php echo htmlspecialchars($post['avatar_url']); ?>" alt="Avatar" style="width:100%; height:100%; object-fit:cover;">
                            <?php else: ?>
                                <div style="width:100%; height:100%; background-color:var(--border-color); display:flex; align-items:center; justify-content:center;">
                                    <svg style="width:20px; height:20px; fill:var(--text-muted);"><use xlink:href="./icons/symbol-defs.svg#icon-user"></use></svg>
                                </div>
                            <?php endif; ?>
                        </div>
                        <div>
                            <div class="post-author" style="font-weight: 600; font-size: 15px; color: var(--text-main);"><?php echo $author_name; ?></div>
                            <div class="post-time" style="font-size: 12px; color: var(--text-muted);"><?php echo $post_time; ?> • Publiczny</div>
                        </div>
                    </div>

                    <?php if (!empty($content)): ?>
                        <div class="post-content" style="font-size: 15px; color: var(--text-main); margin-bottom: 10px;">
                            <?php echo nl2br($content); ?>
                        </div>
                    <?php endif; ?>

                    <?php if (!empty($media_links)): ?>
                        <div class="post-media" style="margin-top: 10px; border-radius: 8px; overflow: hidden; border: 1px solid var(--border-color, #eee);">
                            <?php foreach ($media_links as $media): ?>
                                <img src="<?php echo htmlspecialchars($media); ?>" alt="Post image" style="width: 100%; display: block; max-height: 600px; object-fit: contain; background-color: var(--bg-hover, #f0f2f5);">
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
        var input = document.getElementById("post-media-upload");
        var imageField = document.getElementById("image-preview");
        var container = document.getElementById("image-preview-container");

        input.value = ""; // Resetowanie inputa pliku
        imageField.src = "#";
        container.style.display = "none";
    }
</script>

</body>
</html>