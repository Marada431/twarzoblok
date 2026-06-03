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

        #image-preview-container { position: relative; margin-top: 15px; overflow: hidden; display: none; }
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
        .post-feed-media { margin-top: 10px; border-radius: 8px; overflow: hidden; }
        .post-feed-media img { width: 100%; display: block; max-height: 600px; object-fit: contain; height: 100%; }

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

        .relative-header { position: relative !important; }
        .post-options-dropdown {
            position: absolute;
            right: 0;
            top: 50%;
            transform: translateY(-50%);
            z-index: 10;
        }

        .post-options-trigger {
            width: 36px;
            height: 36px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            cursor: pointer;
        }

        .post-options-trigger svg {
            width: 20px !important;
            height: 20px !important;
            fill: var(--text-muted);
        }

        .post-options-trigger:hover {
            background-color: var(--bg-hover);
        }

        .post-options-menu {
            display: none;
            position: absolute;
            right: 0;
            top: 100%;
            background-color: #ffffff;
            min-width: 140px;
            border: 1px solid var(--border-color);
            border-radius: var(--radius-main);
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.15);
            padding: 5px 0;
            z-index: 100;
        }

        .post-options-dropdown:hover .post-options-menu {
            display: block;
        }

        .post-options-item {
            display: block;
            padding: 8px 16px;
            color: #1c1e21 !important;
            font-size: 14px;
            font-weight: 500;
            text-decoration: none !important;
            text-align: left;
        }

        .post-options-item:hover {
            background-color: var(--bg-hover);
            text-decoration: none !important;
        }

        .post-options-item.danger:hover {
            background-color: #ffebe9;
            color: #e41e3f !important;
        }

        /* Styl dla ciemnego tła blokującego resztę strony */
        .fb-modal-overlay {
            display: none; /* Domyślnie ukryte */
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background-color: rgba(0, 0, 0, 0.5); /* Przyciemnienie tła */
            z-index: 9999;
            align-items: center;
            justify-content: center;
        }

        /* Karta okna modalnego (jak na FB) */
        .fb-modal-card {
            background-color: #ffffff;
            width: 100%;
            max-width: 500px;
            border-radius: var(--radius-main);
            box-shadow: 0 12px 28px 0 rgba(0, 0, 0, 0.2);
            overflow: hidden;
            animation: modalFadeIn 0.2s ease-out;
            font-family: Arial, sans-serif;
        }

        @keyframes modalFadeIn {
            from { transform: scale(0.95); opacity: 0; }
            to { transform: scale(1); opacity: 1; }
        }

        /* Nagłówek okna */
        .fb-modal-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 16px;
            border-bottom: 1px solid var(--border-color);
        }
        .fb-modal-header h3 {
            margin: 0;
            font-size: 20px;
            color: var(--text-main);
            font-weight: 700;
        }
        .fb-modal-close {
            background: none;
            border: none;
            font-size: 24px;
            cursor: pointer;
            color: var(--text-muted);
        }
        .fb-modal-close:hover {
            color: var(--text-main);
        }

        /* Zawartość środka */
        .fb-modal-body {
            padding: 16px;
            color: var(--text-main);
        }

        /* Pole tekstowe do edycji */
        .fb-modal-textarea {
            width: 100%;
            height: 120px;
            padding: 12px;
            border: 1px solid var(--border-color);
            border-radius: var(--radius-main);
            resize: none;
            font-family: inherit;
            font-size: 15px;
            outline: none;
            box-sizing: border-box;
        }
        .fb-modal-textarea:focus {
            border-color: var(--primary-color);
        }

        /* Stopka z przyciskami */
        .fb-modal-footer {
            padding: 12px 16px;
            background-color: #f0f2f5;
            display: flex;
            justify-content: flex-end;
            gap: 8px;
        }

        /* Przyciski */
        .fb-btn {
            padding: 8px 16px;
            border: none;
            border-radius: 6px;
            font-size: 14px;
            font-weight: 600;
            cursor: pointer;
        }
        .fb-btn-secondary {
            background-color: #e4e6eb;
            color: #050505;
        }
        .fb-btn-secondary:hover { background-color: #d8dadf; }

        .fb-btn-primary {
            background-color: var(--primary-color);
            color: white;
        }
        .fb-btn-primary:hover { background-color: var(--primary-hover); }

        .fb-btn-danger {
            background-color: #e41e3f;
            color: white;
        }
        .fb-btn-danger:hover { background-color: #c91a37; }

        /* Powody zgłoszeń radio */
        .report-reason {
            display: flex;
            align-items: center;
            gap: 10px;
            padding: 8px;
            cursor: pointer;
            border-radius: 6px;
        }
        .report-reason:hover {
            background-color: var(--bg-hover);
        }
        .post-feed-card:hover {
            z-index: 999;
            position: relative;
        }
        /* Pasek akcji na dole posta */
        .post-actions-bar {
            display: flex;
            justify-content: space-between;
            align-items: center;
            border-top: 1px solid var(--border-color);
            margin-top: 12px;
            padding-top: 4px;
        }

        /* Kontener dla pojedynczego przycisku (Reakcja / Komentarz) */
        .action-button-wrapper {
            flex: 1;
            position: relative;
            display: flex;
            justify-content: center;
        }

        /* Wygląd przycisków akcji */
        .action-btn {
            width: 100%;
            background: none;
            border: none;
            padding: 10px;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            color: var(--text-muted);
            font-size: 14px;
            font-weight: 600;
            cursor: pointer;
            border-radius: 6px;
            transition: background-color 0.2s, color 0.2s;
        }

        .action-btn:hover {
            background-color: var(--bg-hover);
            color: var(--primary-color);
        }

        /* Wymuszenie odpowiedniego rozmiaru ikon w pasku akcji */
        .action-icon {
            width: 20px !important;
            height: 20px !important;
            fill: currentColor; /* Ikona przyjmie kolor tekstu przycisku */
        }

        /* --- POZIOME MENU EMOTEK (POP-UP) --- */
        .reactions-popup {
            display: flex;
            gap: 12px;
            position: absolute;
            bottom: 100%;             /* Pojawia się NAD przyciskiem */
            left: 16px;               /* Lekkie przesunięcie od lewej krawędzi karty */
            background-color: #ffffff;
            padding: 6px 12px;
            border-radius: 30px;      /* Mocno zaokrąglone rogi jak na FB */
            box-shadow: 0 4px 16px rgba(0, 0, 0, 0.15);
            border: 1px solid var(--border-color);

            /* Ukrywanie i przygotowanie pod płynną animację */
            opacity: 0;
            visibility: hidden;
            transform: translateY(10px);
            transition: opacity 0.2s ease, transform 0.2s ease, visibility 0.2s;
            z-index: 10000 !important; /* Gwarancja, że nie schowa się pod inne posty */
        }

        /* Pokazywanie menu z emotkami po najechaniu na kontener reakcji */
        .reaction-container:hover .reactions-popup {
            opacity: 1;
            visibility: visible;
            transform: translateY(-4px); /* Płynne uniesienie dymka w górę */
        }

        /* Styl pojedynczej emotki */
        .reaction-emoji {
            font-size: 24px;
            cursor: pointer;
            transition: transform 0.15s ease;
            user-select: none;
        }

        /* Efekt najechania na konkretną emotkę - powiększenie jak na FB */
        .reaction-emoji:hover {
            transform: scale(1.3); /* Powiększa emotkę */
        }

        /* Licznik reakcji nad paskiem akcji */
        .post-reactions-counter {
            display: flex;
            align-items: center;
            gap: 6px;
            padding: 6px 4px;
            font-size: 13px;
            color: var(--text-muted);
            cursor: pointer;
        }
        .counter-icon {
            background-color: #1877f2;
            border-radius: 50%;
            width: 18px;
            height: 18px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 11px;
            color: white;
        }

        /* Stan aktywnej reakcji użytkownika */
        .active-reacted {
            color: var(--primary-color) !important;
        }

        /* Sekcja komentarzy */
        .comments-section {
            border-top: 1px solid var(--border-color);
            padding-top: 10px;
            margin-top: 8px;
        }
        .comments-list {
            max-height: 300px;
            overflow-y: auto;
            margin-bottom: 10px;
        }
        .comment-item {
            display: flex;
            gap: 8px;
            margin-bottom: 10px;
            align-items: flex-start;
        }
        .comment-avatar img, .comment-avatar-placeholder {
            width: 32px;
            height: 32px;
            border-radius: 50%;
            object-fit: cover;
        }
        .comment-avatar-placeholder {
            background-color: var(--bg-hover);
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 14px;
        }
        .comment-content-box {
            background-color: #f0f2f5;
            padding: 8px 12px;
            border-radius: 18px;
            max-width: 85%;
        }
        .comment-author {
            font-size: 13px;
            font-weight: 700;
            color: #050505;
            margin-bottom: 2px;
        }
        .comment-text {
            font-size: 14px;
            color: #050505;
            word-break: break-word;
        }

        /* Formularz komentarza */
        .comment-form {
            display: flex;
            align-items: center;
            gap: 8px;
            background-color: #f0f2f5;
            border-radius: 20px;
            padding: 4px 12px;
        }
        .comment-input {
            flex: 1;
            background: none;
            border: none;
            padding: 8px 0;
            outline: none;
            font-size: 14px;
            color: #050505;
        }
        .comment-submit-btn {
            background: none;
            border: none;
            color: var(--primary-color);
            font-size: 16px;
            cursor: pointer;
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
                    <div class="post-feed-header relative-header">
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

                        <div class="post-options-dropdown">
                            <div class="post-options-trigger">
                                <svg><use xlink:href="./icons/symbol-defs.svg#icon-list2"></use></svg>
                            </div>
                            <div class="post-options-menu">
                                <a href="javascript:void(0)" class="post-options-item" onclick="openModal('edit', <?php echo $post['post_id']; ?>, '<?php echo urlencode($content); ?>')">Edytuj</a>
                                <a href="javascript:void(0)" class="post-options-item danger" onclick="openModal('delete', <?php echo $post['post_id']; ?>)">Usuń</a>
                                <a href="javascript:void(0)" class="post-options-item" onclick="openModal('report', <?php echo $post['post_id']; ?>)">Zgłoś</a>
                            </div>
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

                    <?php
                    $pid = (int)$post['post_id'];

                    // 1. Pobieramy liczbę wszystkich reakcji i listę użytkowników, którzy je dali
                    $stmt_react = db()->prepare("
    SELECT r.reaction_type, u.first_name, u.last_name, u.user_id 
    FROM post_reactions r 
    JOIN users u ON r.user_id = u.user_id 
    WHERE r.post_id = :pid
");
                    $stmt_react->execute([':pid' => $pid]);
                    $all_reactions = $stmt_react->fetchAll();

                    $reaction_count = count($all_reactions);

                    // Sprawdzamy, czy aktualny użytkownik już zareagował i czym
                    // --- NOWY / POPRAWIONY BLOK POBIERANIA REAKCJI ---
                    try {
                        // 1. Pobieramy wszystkie reakcje dla tego posta wraz z danymi użytkowników
                        $stmt_react = db()->prepare("
                                SELECT pr.reaction_type, pr.user_id, u.first_name, u.last_name 
                                FROM post_reactions pr
                                JOIN users u ON pr.user_id = u.user_id
                                WHERE pr.post_id = :pid
                            ");
                        $stmt_react->execute([':pid' => $pid]);
                        $all_reactions = $stmt_react->fetchAll();
                        $reaction_count = count($all_reactions);
                    } catch (PDOException $e) {
                        $all_reactions = [];
                        $reaction_count = 0;
                    }

                    $user_current_reaction = null;
                    $reactors_names = [];
                    foreach ($all_reactions as $r) {
                        if ((int)$r['user_id'] === $current_user_id) {
                            $user_current_reaction = $r['reaction_type'];
                        }
                        $reactors_names[] = htmlspecialchars($r['first_name'] . ' ' . $r['last_name'] . ' (' . $r['reaction_type'] . ')');
                    }
                    $reactors_list_string = implode("\n", $reactors_names);

                    // Słownik emotek unicode dla bazy danych
                    $emoji_dict = [
                            'like'  => '👍',
                            'love'  => '❤️',
                            'hug'   => '🤗',
                            'haha'  => '😆',
                            'wow'   => '😮',
                            'sad'   => '😢',
                            'angry' => '😡'
                    ];

                    // Ustawienie tekstu i domyślnej akcji głównego przycisku
                    $btn_text = "Reakcja";
                    // Jeśli użytkownik już kliknął jakąś reakcję, ponowne kliknięcie głównego przycisku ją cofnie (wysyła ten sam typ)
                    // Jeśli nie klikał nic, domyślnie wysyłany jest 'like'
                    $next_action_type = $user_current_reaction ? $user_current_reaction : 'like';

                    if ($user_current_reaction && isset($emoji_dict[$user_current_reaction])) {
                        $btn_text = $emoji_dict[$user_current_reaction] . " Zmieniono";
                    }

                    // 2. Pobieramy komentarze dla tego posta (Twój dotychczasowy kod)
                    $stmt_comments = db()->prepare("
                            SELECT c.*, u.first_name, u.last_name, u.avatar_url 
                            FROM comments c
                            JOIN users u ON c.author_id = u.user_id
                            WHERE c.post_id = :pid
                            ORDER BY c.created_at ASC
                        ");
                    $stmt_comments->execute([':pid' => $pid]);
                    $post_comments = $stmt_comments->fetchAll();
                    ?>

                    <?php if ($reaction_count > 0): ?>
                        <div class="post-reactions-counter" title="<?php echo $reactors_list_string; ?>">
                            <span class="counter-icon">👍</span>
                            <span class="counter-text"><?php echo $reaction_count; ?> <?php echo ($reaction_count == 1) ? 'osoba' : 'osoby'; ?></span>
                        </div>
                    <?php endif; ?>

                    <div class="post-actions-bar">
                        <div class="action-button-wrapper reaction-container">
                            <a href="post_actions.php?action=react&post_id=<?php echo $pid; ?>&type=<?php echo $next_action_type; ?>" style="text-decoration: none; width: 100%; display: block;">
                                <button class="action-btn <?php echo $user_current_reaction ? 'active-reacted' : ''; ?>" type="button" style="width: 100%;">
                                    <svg class="action-icon"><use xlink:href="./icons/symbol-defs.svg#icon-user"></use></svg>
                                    <span><?php echo $btn_text; ?></span>
                                </button>
                            </a>

                            <div class="reactions-popup">
                                <a href="post_actions.php?action=react&post_id=<?php echo $pid; ?>&type=like" class="reaction-emoji" title="Lubię to!">&#x1F44D;</a>
                                <a href="post_actions.php?action=react&post_id=<?php echo $pid; ?>&type=love" class="reaction-emoji" title="Super">&#x2764;&#xFE0F;</a>
                                <a href="post_actions.php?action=react&post_id=<?php echo $pid; ?>&type=hug" class="reaction-emoji" title="Trzymaj się">&#x1F917;</a>
                                <a href="post_actions.php?action=react&post_id=<?php echo $pid; ?>&type=haha" class="reaction-emoji" title="Haha">&#x1F606;</a>
                                <a href="post_actions.php?action=react&post_id=<?php echo $pid; ?>&type=wow" class="reaction-emoji" title="Wow">&#x1F62E;</a>
                                <a href="post_actions.php?action=react&post_id=<?php echo $pid; ?>&type=sad" class="reaction-emoji" title="Przykro mi">&#x1F622;</a>
                                <a href="post_actions.php?action=react&post_id=<?php echo $pid; ?>&type=angry" class="reaction-emoji" title="Wrre">&#x1F621;</a>
                            </div>
                        </div>

                        <div class="action-button-wrapper">
                            <button class="action-btn" onclick="toggleComments(<?php echo $pid; ?>)">
                                <svg class="action-icon"><use xlink:href="./icons/symbol-defs.svg#icon-list2"></use></svg>
                                <span>Komentarz (<?php echo count($post_comments); ?>)</span>
                            </button>
                        </div>
                    </div>

                    <div class="comments-section" id="comments-<?php echo $pid; ?>" style="display: none;">

                        <div class="comments-list">
                            <?php foreach ($post_comments as $comment): ?>
                                <div class="comment-item">
                                    <div class="comment-avatar">
                                        <?php if (!empty($comment['avatar_url'])): ?>
                                            <img src="<?php echo htmlspecialchars($comment['avatar_url']); ?>" alt="Avatar">
                                        <?php else: ?>
                                            <div class="comment-avatar-placeholder">👤</div>
                                        <?php endif; ?>
                                    </div>
                                    <div class="comment-content-box">
                                        <div class="comment-author"><?php echo htmlspecialchars($comment['first_name'] . ' ' . $comment['last_name']); ?></div>
                                        <div class="comment-text"><?php echo nl2br(htmlspecialchars($comment['content'])); ?></div>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>

                        <form action="post_actions.php" method="POST" class="comment-form" onsubmit="submitComment(event, this, <?php echo $pid; ?>)">
                            <input type="hidden" name="action" value="comment">
                            <input type="hidden" name="post_id" value="<?php echo $pid; ?>">
                            <input type="text" name="comment_content" class="comment-input" placeholder="Napisz komentarz..." required autocomplete="off">
                            <button type="submit" class="comment-submit-btn">➔</button>
                        </form>
                    </div>

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

    function openModal(type, postId, encodedContent = '') {
        // Ustawiamy ID posta w ukrytym polu formularza danego modala
        document.getElementById(type + '-post-id').value = postId;

        // Jeśli to edycja, poprawnie zamieniamy plusy na spacje i dekodujemy znaki specjalne
        if (type === 'edit') {
            // .replace(/\+/g, ' ') zamienia wszystkie "+" z powrotem na zwykłe spacje
            var decodedText = decodeURIComponent(encodedContent.replace(/\+/g, ' '));
            document.getElementById('edit-post-content').value = decodedText;
        }

        // Wyświetlamy odpowiedni modal na ekranie
        var modal = document.getElementById('modal-' + type);
        modal.style.display = 'flex';
    }

    function closeModal(type) {
        // Ukrywamy modal
        var modal = document.getElementById('modal-' + type);
        modal.style.display = 'none';
    }

    // Opcjonalnie: zamknięcie modala po kliknięciu w szare tło dookoła okienka
    window.onclick = function(event) {
        if (event.target.classList.contains('fb-modal-overlay')) {
            event.target.style.display = 'none';
        }
    }

    function toggleComments(postId) {
        var section = document.getElementById('comments-' + postId);
        if (section.style.display === 'none') {
            section.style.display = 'block';
        } else {
            section.style.display = 'none';
        }
    }

    function submitComment(event, formElement, postId) {
        // Blokujemy domyślne przeładowanie strony przez formularz
        event.preventDefault();

        const inputField = formElement.querySelector('.comment-input');
        const commentContent = inputField.value.trim();
        if (!commentContent) return;

        // Przygotowanie danych do wysyłki
        const formData = new FormData(formElement);

        fetch('post_actions.php', {
            method: 'POST',
            body: formData
        })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    // Czyszczenie pola tekstowego
                    inputField.value = '';

                    // Znalezienie listy komentarzy dla tego posta
                    const commentsSection = document.getElementById('comments-' + postId);
                    const commentsList = commentsSection.querySelector('.comments-list');

                    // Tworzenie kodu HTML dla nowego komentarza i wstrzyknięcie go na stronę
                    const newCommentHtml = `
                <div class="comment-item">
                    <div class="comment-avatar">
                        ${data.user_avatar ? `<img src="${data.user_avatar}" alt="Avatar">` : `<div class="comment-avatar-placeholder">👤</div>`}
                    </div>
                    <div class="comment-content-box">
                        <div class="comment-author">${data.user_name}</div>
                        <div class="comment-text">${data.content}</div>
                    </div>
                </div>
            `;

                    commentsList.insertAdjacentHTML('beforeend', newCommentHtml);

                    // Opcjonalnie: Przewiń listę komentarzy na sam dół, by zobaczyć swój komentarz
                    commentsList.scrollTop = commentsList.scrollHeight;

                    // Aktualizacja licznika w przycisku (opcjonalnie)
                    const commentBtnSpan = formElement.closest('.post-feed-card').querySelector('.action-btn:not(.active-reacted) span');
                    if (commentBtnSpan && commentBtnSpan.textContent.includes('Komentarz')) {
                        const currentCount = parseInt(commentBtnSpan.textContent.replace(/[^0-9]/g, '')) || 0;
                        commentBtnSpan.textContent = `Komentarz (${currentCount + 1})`;
                    }
                } else {
                    alert(data.message || 'Wystąpił błąd podczas dodawania komentarza.');
                }
            })
            .catch(error => {
                console.error('Błąd:', error);
                alert('Błąd połączenia z serwerem.');
            });
    }
</script>

<div id="modal-edit" class="fb-modal-overlay">
    <div class="fb-modal-card">
        <div class="fb-modal-header">
            <h3>Edytuj post</h3>
            <button class="fb-modal-close" onclick="closeModal('edit')">&times;</button>
        </div>
        <form action="post_actions.php" method="POST">
            <div class="fb-modal-body">
                <input type="hidden" name="action" value="edit">
                <input type="hidden" name="post_id" id="edit-post-id">
                <textarea name="content" id="edit-post-content" class="fb-modal-textarea" required></textarea>
            </div>
            <div class="fb-modal-footer">
                <button type="button" class="fb-btn fb-btn-secondary" onclick="closeModal('edit')">Anuluj</button>
                <button type="submit" class="fb-btn fb-btn-primary">Zapisz zmiany</button>
            </div>
        </form>
    </div>
</div>

<div id="modal-delete" class="fb-modal-overlay">
    <div class="fb-modal-card select-warning">
        <div class="fb-modal-header">
            <h3>Usunąć post?</h3>
            <button class="fb-modal-close" onclick="closeModal('delete')">&times;</button>
        </div>
        <form action="post_actions.php" method="POST">
            <div class="fb-modal-body">
                <input type="hidden" name="action" value="delete">
                <input type="hidden" name="post_id" id="delete-post-id">
                <p>Czy na pewno chcesz usunąć ten post? Tej operacji nie da się cofnąć.</p>
            </div>
            <div class="fb-modal-footer">
                <button type="button" class="fb-btn fb-btn-secondary" onclick="closeModal('delete')">Anuluj</button>
                <button type="submit" class="fb-btn fb-btn-danger">Usuń</button>
            </div>
        </form>
    </div>
</div>

<div id="modal-report" class="fb-modal-overlay">
    <div class="fb-modal-card">
        <div class="fb-modal-header">
            <h3>Zgłoś post</h3>
            <button class="fb-modal-close" onclick="closeModal('report')">&times;</button>
        </div>
        <form action="post_actions.php" method="POST">
            <div class="fb-modal-body">
                <input type="hidden" name="action" value="report">
                <input type="hidden" name="post_id" id="report-post-id">
                <p style="margin-bottom: 12px; color: var(--text-muted);">Wybierz powód zgłoszenia:</p>
                <label class="report-reason"><input type="radio" name="reason" value="spam" checked> Spam</label>
                <label class="report-reason"><input type="radio" name="reason" value="harassment"> Nękanie lub obraźliwe treści</label>
                <label class="report-reason"><input type="radio" name="reason" value="hate_speech"> Mowa nienawiści</label>
                <label class="report-reason"><input type="radio" name="reason" value="other"> Inne</label>
            </div>
            <div class="fb-modal-footer">
                <button type="button" class="fb-btn fb-btn-secondary" onclick="closeModal('report')">Anuluj</button>
                <button type="submit" class="fb-btn fb-btn-primary">Wyślij zgłoszenie</button>
            </div>
        </form>
    </div>
</div>

</body>
</html>