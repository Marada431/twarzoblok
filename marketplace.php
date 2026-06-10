<?php
session_start();
require_once 'config/database.php'; // Upewnij się, że ta ścieżka jest poprawna

if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
    header('Location: login.php');
    exit;
}

$current_user_id = (int) $_SESSION['user_id'];
$pdo = db(); // Zakładam, że Twoja funkcja zwraca obiekt PDO

// Zamykamy zapis sesji, jeśli nie robimy w niej zmian, aby uniknąć Session Lock
session_write_close();

$message_info = '';
$error_msg = '';

// KATEGORIE MARKETPLACE
$categories = [
        'all'         => 'Wszystko',
        'electronics' => 'Elektronika',
        'vehicles'    => 'Pojazdy',
        'housing'     => 'Nieruchomości',
        'hobbies'     => 'Rozrywka i hobby',
        'clothing'    => 'Odzież i obuwie',
        'home'        => 'Dom i ogród',
        'other'       => 'Inne'
];

// ─────────────────────────────────────────────
// ACC: OBSŁUGA DODAWANIA PRZEDMIOTU
// ─────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['submit_item'])) {
    $title       = trim($_POST['title'] ?? '');
    $description = trim($_POST['description'] ?? '');
    $price       = floatval($_POST['price'] ?? 0);
    $category    = trim($_POST['category'] ?? 'other');
    $city        = trim($_POST['city'] ?? '');

    if (!empty($title) && $price >= 0) {
        $stmt_addr = $pdo->prepare("SELECT address_id FROM addresses WHERE user_id = :uid LIMIT 1");
        $stmt_addr->execute([':uid' => $current_user_id]);
        $addr_res = $stmt_addr->fetch();
        $address_id = $addr_res ? (int)$addr_res['address_id'] : null;

        if (empty($city)) {
            if (isset($_SESSION['city']) && !empty($_SESSION['city'])) {
                $city = $_SESSION['city'];
            } else {
                $stmt_user_city = $pdo->prepare("SELECT city FROM users WHERE user_id = :uid LIMIT 1");
                $stmt_user_city->execute([':uid' => $current_user_id]);
                $user_res = $stmt_user_city->fetch();

                if ($user_res && !empty($user_res['city'])) {
                    $city = $user_res['city'];
                } else {
                    $stmt_addr_city = $pdo->prepare("SELECT city FROM addresses WHERE address_id = :aid LIMIT 1");
                    $stmt_addr_city->execute([':aid' => $address_id]);
                    $addr_city_res = $stmt_addr_city->fetch();
                    $city = ($addr_city_res && !empty($addr_city_res['city'])) ? $addr_city_res['city'] : 'Nieznana';
                }
            }
        }

        $image_dest = null;
        if (isset($_FILES['item_image']) && $_FILES['item_image']['error'] === UPLOAD_ERR_OK) {
            $tmp  = $_FILES['item_image']['tmp_name'];
            $finfo = new finfo(FILEINFO_MIME_TYPE);
            $mime = $finfo->file($tmp);
            $allowed_mimes = ['image/jpeg'=>'jpg','image/png'=>'png','image/webp'=>'webp','image/gif'=>'gif'];

            if (isset($allowed_mimes[$mime])) {
                $ext = $allowed_mimes[$mime];
                $dir = "uploads/marketplace/";
                if (!is_dir($dir)) mkdir($dir, 0775, true);
                $filename = uniqid('item_', true) . '.' . $ext;
                $image_dest = $dir . $filename;

                move_uploaded_file($tmp, $image_dest);
            }
        }

        try {
            $stmt_insert = $pdo->prepare("
                INSERT INTO marketplace_items 
                (seller_id, address_id, title, description, price, category, city, image_url, status, created_at) 
                VALUES 
                (:sid, :aid, :title, :desc, :price, :cat, :city, :img, 'active', NOW())
            ");

            $stmt_insert->execute([
                    ':sid'   => $current_user_id,
                    ':aid'   => $address_id,
                    ':title' => $title,
                    ':desc'  => $description,
                    ':price' => $price,
                    ':cat'   => $category,
                    ':city'  => $city,
                    ':img'   => $image_dest
            ]);

            header('Location: marketplace.php?success=1');
            exit;
        } catch (PDOException $e) {
            $error_msg = "Błąd podczas dodawania ogłoszenia: " . $e->getMessage();
        }
    } else {
        $error_msg = "Wypełnij poprawnie wymagane pola (Tytuł i Cena).";
    }
}

// ─────────────────────────────────────────────
// ACC: INICJOWANIE / OTWIERANIE CZATU ZE SPRZEDAWCĄ
// ─────────────────────────────────────────────
if (isset($_GET['contact_seller'])) {
    $seller_id = (int)$_GET['contact_seller'];

    if ($seller_id !== $current_user_id) {
        try {
            $stmt_chat = $pdo->prepare("
                SELECT cp1.chat_id 
                FROM chat_participants cp1
                JOIN chat_participants cp2 ON cp1.chat_id = cp2.chat_id
                JOIN chats c ON cp1.chat_id = c.chat_id
                WHERE cp1.user_id = :me 
                  AND cp2.user_id = :seller 
                  AND c.chat_type = 'private'
                LIMIT 1
            ");
            $stmt_chat->execute([':me' => $current_user_id, ':seller' => $seller_id]);
            $existing_chat = $stmt_chat->fetch();

            if ($existing_chat) {
                header("Location: chat.php?chat_id=" . (int)$existing_chat['chat_id']);
                exit;
            } else {
                $pdo->beginTransaction();

                $stmt_new_chat = $pdo->prepare("INSERT INTO chats (name, chat_type, created_at, created_by) VALUES (NULL, 'private', NOW(), :me)");
                $stmt_new_chat->execute([':me' => $current_user_id]);
                $new_chat_id = $pdo->lastInsertId();

                $stmt_p1 = $pdo->prepare("INSERT INTO chat_participants (chat_id, user_id, role, joined_at) VALUES (:cid, :uid, 'member', NOW())");
                $stmt_p1->execute([':cid' => $new_chat_id, ':uid' => $current_user_id]);

                $stmt_p2 = $pdo->prepare("INSERT INTO chat_participants (chat_id, user_id, role, joined_at) VALUES (:cid, :uid, 'member', NOW())");
                $stmt_p2->execute([':cid' => $new_chat_id, ':uid' => $seller_id]);

                $pdo->commit();

                header("Location: chat.php?chat_id=" . (int)$new_chat_id);
                exit;
            }
        } catch (Exception $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            $error_msg = "Nie udało się połączyć ze sprzedawcą: " . $e->getMessage();
        }
    }
}

// ─────────────────────────────────────────────
// GET: POBIERANIE OFERT I FILTROWANIE
// ─────────────────────────────────────────────
$search = isset($_GET['search']) ? trim($_GET['search']) : '';
$cat_filter = isset($_GET['cat']) ? trim($_GET['cat']) : '';
$city_filter = isset($_GET['location']) ? trim($_GET['location']) : '';

$query = "SELECT m.*, u.first_name, u.last_name, u.avatar_url 
          FROM marketplace_items m 
          JOIN users u ON m.seller_id = u.user_id 
          WHERE m.status = 'active'";
$params = [];

if ($search !== '') {
    $query .= " AND (m.title LIKE :search OR m.description LIKE :search2)";
    $params[':search'] = '%' . $search . '%';
    $params[':search2'] = '%' . $search . '%';
}
// Poprawka: Jeśli wybrana kategoria to 'all', ignorujemy filtr kategorii i pokazujemy wszystko
if ($cat_filter !== '' && $cat_filter !== 'all' && array_key_exists($cat_filter, $categories)) {
    $query .= " AND m.category = :category";
    $params[':category'] = $cat_filter;
}
if ($city_filter !== '') {
    $query .= " AND m.city LIKE :city";
    $params[':city'] = '%' . $city_filter . '%';
}

$query .= " ORDER BY m.created_at DESC";
$stmt = $pdo->prepare($query);
$stmt->execute($params);
$items = $stmt->fetchAll();

if (isset($_GET['success'])) {
    $message_info = "Ogłoszenie zostało pomyślnie wystawione!";
}
?>
    <!doctype html>
    <html lang="pl">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title>TwarzBlok – Marketplace</title>
        <link rel="stylesheet" href="css/style.css">
        <style>
            body {
                margin: 0;
                padding-top: 70px;
                background-color: var(--bg-main, #f0f2f5);
            }

            .marketplace-layout {
                display: flex;
                width: 100%;
                min-height: calc(100vh - 70px);
            }

            .market-sidebar {
                width: 360px;
                min-width: 360px;
                background: var(--bg-surface, #ffffff);
                padding: 16px;
                border-right: 1px solid var(--border-color, #e4e6eb);
                box-shadow: 2px 0 5px rgba(0,0,0,0.05);
                height: calc(100vh - 70px);
                position: sticky;
                top: 70px;
                overflow-y: auto;
                box-sizing: border-box;
            }

            .market-main-content {
                flex-grow: 1;
                padding: 24px;
                box-sizing: border-box;
                background: var(--bg-main, #f0f2f5);
            }

            .market-sidebar h2 {
                font-size: 24px;
                color: var(--text-main, #050505);
                margin-bottom: 15px;
                font-weight: 700;
                letter-spacing: -0.5px;
            }

            .market-sidebar h3 {
                font-size: 17px;
                color: var(--text-main, #050505);
                margin-top: 20px;
                margin-bottom: 10px;
                font-weight: 600;
            }

            .market-form-input {
                width: 100%;
                padding: 10px 12px;
                margin-top: 5px;
                margin-bottom: 15px;
                border: 1px solid var(--border-color, #ced4da);
                border-radius: 8px;
                background: var(--bg-main, #f0f2f5);
                color: var(--text-main, #050505);
                font-size: 15px;
                font-family: inherit;
                box-sizing: border-box;
            }

            .market-form-input:focus {
                border-color: var(--primary-color, #338336);
                background: var(--bg-surface, #fff);
                outline: none;
            }

            .market-nav-link {
                display: flex;
                align-items: center;
                padding: 12px 10px;
                border-radius: 8px;
                color: var(--text-main, #050505);
                text-decoration: none;
                font-weight: 500;
                margin-bottom: 2px;
                font-size: 15px;
                transition: background 0.2s;
            }

            .market-nav-link:hover {
                background: var(--bg-hover, #f2f2f2);
                text-decoration: none;
            }

            .market-nav-link.active {
                background: var(--bg-hover, #e7f3ff);
                color: var(--primary-color, #338336);
                font-weight: 600;
            }

            .marketplace-grid {
                display: grid;
                grid-template-columns: repeat(auto-fill, minmax(210px, 1fr));
                gap: 16px;
                margin-top: 15px;
            }

            .item-card {
                background: transparent;
                border: none;
                overflow: hidden;
                display: flex;
                flex-direction: column;
                cursor: pointer;
                transition: transform 0.2s;
            }

            .item-card:hover {
                transform: scale(1.01);
            }

            .item-image-wrapper {
                width: 100%;
                position: relative;
                padding-top: 100%;
                border-radius: 8px;
                overflow: hidden;
                background: #e4e6eb;
            }

            .item-image {
                position: absolute;
                top: 0; left: 0; width: 100%; height: 100%;
                object-fit: cover;
            }

            .item-image-placeholder {
                position: absolute;
                top: 0; left: 0; width: 100%; height: 100%;
                display: flex;
                align-items: center;
                justify-content: center;
                font-size: 48px;
                background: #e4e6eb;
            }

            .item-info {
                padding: 8px 4px;
                display: flex;
                flex-direction: column;
            }

            .item-price {
                font-size: 16px;
                font-weight: 600;
                color: var(--text-main, #050505);
                margin-bottom: 2px;
            }

            .item-title {
                font-size: 14px;
                color: var(--text-main, #050505);
                margin-bottom: 2px;
                overflow: hidden;
                text-overflow: ellipsis;
                white-space: nowrap;
            }

            .item-location {
                font-size: 13px;
                color: var(--text-muted, #65676b);
            }

            .fb-view-overlay {
                display: none;
                position: fixed;
                top: 0; left: 0; width: 100%; height: 100%;
                background: rgba(0, 0, 0, 0.9);
                z-index: 10000;
            }

            .fb-view-container {
                display: flex;
                width: 100%;
                height: 100%;
                position: relative;
            }

            .fb-view-media {
                flex-grow: 1;
                display: flex;
                align-items: center;
                justify-content: center;
                position: relative;
                padding: 40px;
            }

            .fb-view-media img {
                max-width: 100%;
                max-height: 90vh;
                object-fit: contain;
                box-shadow: 0 4px 12px rgba(0,0,0,0.5);
                width: auto; height: auto;
            }

            .fb-view-media .placeholder-big {
                font-size: 120px;
            }

            .fb-close-view {
                position: absolute;
                top: 15px; left: 15px;
                background: rgba(255,255,255,0.2);
                border: none; color: white;
                font-size: 24px; width: 45px; height: 45px;
                border-radius: 50%; cursor: pointer;
                display: flex; align-items: center; justify-content: center;
                transition: background 0.2s;
            }
            .fb-close-view:hover { background: rgba(255,255,255,0.4); }

            .fb-view-sidebar {
                width: 440px;
                min-width: 440px;
                background: var(--bg-surface, #fff);
                height: 100%;
                overflow-y: auto;
                box-sizing: border-box;
                padding: 24px;
                display: flex;
                flex-direction: column;
                box-shadow: -2px 0 10px rgba(0,0,0,0.3);
            }

            .fb-view-price {
                font-size: 24px;
                font-weight: 700;
                margin-bottom: 5px;
                color: var(--text-main, #050505);
            }

            .fb-view-title {
                font-size: 22px;
                font-weight: 600;
                margin-bottom: 15px;
                line-height: 1.2;
                color: var(--text-main, #050505);
            }

            .fb-view-meta-box {
                border-top: 1px solid var(--border-color, #e4e6eb);
                border-bottom: 1px solid var(--border-color, #e4e6eb);
                padding: 12px 0;
                margin-bottom: 15px;
                font-size: 14px;
                color: var(--text-muted, #65676b);
            }

            .fb-view-desc-title {
                font-size: 16px;
                font-weight: 600;
                margin-bottom: 8px;
            }

            .fb-view-desc {
                font-size: 15px;
                line-height: 1.4;
                color: var(--text-main, #050505);
                white-space: pre-line;
                margin-bottom: 25px;
            }

            .fb-view-footer {
                margin-top: auto;
                padding-top: 15px;
                border-top: 1px solid var(--border-color, #e4e6eb);
            }

            .fb-seller-info {
                display: flex;
                align-items: center;
                gap: 12px;
                margin-bottom: 15px;
            }

            .fb-seller-avatar {
                width: 40px; height: 40px; border-radius: 50%; object-fit: cover;
            }

            .alert { padding: 12px; border-radius: 8px; margin-bottom: 15px; }
            .alert-success { background: #e7f3ff; color: #1877f2; }
            .alert-danger { background: #ffebe6; color: #cc3333; }
        </style>
    </head>
    <body>

    <nav class="navbar" style="display: flex; justify-content: space-between; padding: 0 20px; background: var(--bg-surface, #fff); align-items: center;">
        <div class="navbar-brand" style="font-weight: bold; font-size: 22px; color: var(--primary-color, #338336); display: flex; align-items: center;">
            TwarzBlok
            <form action="marketplace.php" method="GET" style="display:inline-block; margin-left: 15px;">
                <input type="text" name="search" class="suchemashine" placeholder="Szukaj na TwarzBlok" value="<?= htmlspecialchars($search) ?>">
                <?php if($cat_filter !== ''): ?>
                    <input type="hidden" name="cat" value="<?= htmlspecialchars($cat_filter) ?>">
                <?php endif; ?>
                <?php if($city_filter !== ''): ?>
                    <input type="hidden" name="location" value="<?= htmlspecialchars($city_filter) ?>">
                <?php endif; ?>
            </form>
        </div>
        <div class="navbar-links" style="display: flex; gap: 25px; align-items: center;">
            <a href="index.php" title="Strona Główna">
                <svg><use xlink:href="./icons/symbol-defs.svg#icon-home"></use></svg>
            </a>
            <a href="chat.php" title="Wiadomości">
                <svg><use xlink:href="./icons/symbol-defs.svg#icon-bubbles4"></use></svg>
            </a>
            <a href="marketplace.php" class="active" title="Marketplace">
                <svg><use xlink:href="./icons/symbol-defs.svg#icon-briefcase"></use></svg>
            </a>
        </div>
    </nav>

    <div class="marketplace-layout">

        <aside class="market-sidebar">
            <h2>Marketplace</h2>

            <button style="width: 100%; padding: 12px; margin-bottom: 20px; background: var(--primary-color, #338336); color: white; border: none; border-radius: 8px; cursor: pointer; font-weight: bold; font-size: 15px;" onclick="document.getElementById('modal-add-item').style.display='flex'">+ Utwórz nowe ogłoszenie</button>

            <form action="marketplace.php" method="GET" style="margin-bottom: 20px; padding-bottom: 15px; border-bottom: 1px solid var(--border-color, #e4e6eb);">
                <label style="font-size: 14px; font-weight: 600; color: var(--text-main);">Filtruj wg lokalizacji:</label>
                <input type="text" name="location" class="market-form-input" placeholder="Wpisz miasto..." value="<?= htmlspecialchars($city_filter) ?>">
                <?php if($cat_filter !== ''): ?>
                    <input type="hidden" name="cat" value="<?= htmlspecialchars($cat_filter) ?>">
                <?php endif; ?>
                <?php if($search !== ''): ?>
                    <input type="hidden" name="search" value="<?= htmlspecialchars($search) ?>">
                <?php endif; ?>
                <button type="submit" style="width: 100%; padding: 10px; background: #e4e6eb; border: none; border-radius: 8px; cursor: pointer; font-weight: 600; font-size: 14px; color: #050505;">Zastosuj filtr</button>
            </form>

            <h3>Kategorie</h3>
            <nav>
                <?php foreach($categories as $key => $label): ?>
                    <a href="marketplace.php?cat=<?= $key ?><?= $city_filter!==''?'&location='.urlencode($city_filter):'' ?><?= $search!=''?'&search='.urlencode($search):'' ?>" class="market-nav-link <?= ($cat_filter === $key || ($cat_filter === '' && $key === 'all')) ? 'active':'' ?>">
                        🔹 <?= $label ?>
                    </a>
                <?php endforeach; ?>
            </nav>
        </aside>

        <main class="market-main-content">
            <?php if(!empty($message_info)): ?>
                <div class="alert alert-success"><?= $message_info ?></div>
            <?php endif; ?>
            <?php if(!empty($error_msg)): ?>
                <div class="alert alert-danger"><?= $error_msg ?></div>
            <?php endif; ?>

            <h2 style="margin-bottom: 15px; font-size: 20px; font-weight: 600;">Dzisiejsze polecane artykuły</h2>

            <?php if(empty($items)): ?>
                <div style="text-align: center; padding: 60px; color: var(--text-muted);">
                    <p style="font-size: 16px;">Nie znaleziono żadnych ogłoszeń pasujących do kryteriów.</p>
                </div>
            <?php else: ?>
                <div class="marketplace-grid">
                    <?php foreach($items as $item): ?>
                        <div class="item-card" onclick="openItemView(this)"
                             data-title="<?= htmlspecialchars($item['title']) ?>"
                             data-price="<?= number_format($item['price'], 2, ',', ' ') ?>"
                             data-desc="<?= htmlspecialchars($item['description']) ?>"
                             data-city="<?= htmlspecialchars($item['city'] ?? 'Brak lokalizacji') ?>"
                             data-image="<?= htmlspecialchars($item['image_url'] ?? '') ?>"
                             data-seller-id="<?= $item['seller_id'] ?>"
                             data-seller-name="<?= htmlspecialchars($item['first_name'] . ' ' . $item['last_name']) ?>"
                             data-seller-avatar="<?= htmlspecialchars($item['avatar_url'] ?? '') ?>"
                             data-is-mine="<?= ($item['seller_id'] == $current_user_id) ? '1' : '0' ?>">

                            <div class="item-image-wrapper">
                                <?php if(!empty($item['image_url'])): ?>
                                    <img src="<?= htmlspecialchars($item['image_url']) ?>" class="item-image" alt="">
                                <?php else: ?>
                                    <div class="item-image-placeholder">🛍️</div>
                                <?php endif; ?>
                            </div>

                            <div class="item-info">
                                <div class="item-price"><?= number_format($item['price'], 2, ',', ' ') ?> zł</div>
                                <div class="item-title"><?= htmlspecialchars($item['title']) ?></div>
                                <div class="item-location"><?= htmlspecialchars($item['city'] ?? 'Brak lokalizacji') ?></div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </main>
    </div>

    <div id="fb-item-view" class="fb-view-overlay">
        <div class="fb-view-container">
            <div class="fb-view-media" onclick="closeItemViewOnBg(event)">
                <button class="fb-close-view" onclick="closeItemView()">&times;</button>
                <div id="view-media-content" style="width:100%; text-align:center;"></div>
            </div>

            <div class="fb-view-sidebar">
                <div id="view-title" class="fb-view-title"></div>
                <div id="view-price" class="fb-view-price"></div>

                <div class="fb-view-meta-box">
                    <div style="margin-bottom: 5px;">📍 Lokalizacja: <span id="view-location" style="color:#050505; font-weight:500;"></span></div>
                    <div>Stan: Aktywne</div>
                </div>

                <div class="fb-view-desc-title">Opis</div>
                <div id="view-description" class="fb-view-desc"></div>

                <div class="fb-view-footer">
                    <div class="fb-seller-info">
                        <!-- Poprawka: Oczyszczona struktura tagu img, usunięto nieprawidłowy tag svg wewnątrz atrybutów -->
                        <img id="view-seller-avatar" src="" class="fb-seller-avatar" alt="Avatar">
                        <div>
                            <div style="font-weight: 600; font-size: 15px;" id="view-seller-name"></div>
                            <div style="font-size: 12px; color: var(--text-muted);">Sprzedawca na TwarzBlok</div>
                        </div>
                    </div>

                    <div id="view-action-container"></div>
                </div>
            </div>
        </div>
    </div>

    <div id="modal-add-item" style="display:none; position:fixed; top:0; left:0; width:100%; height:100%; background:rgba(0,0,0,0.5); justify-content:center; align-items:center; z-index:9999;">
        <div style="background: var(--bg-surface, #fff); width: 100%; max-width: 500px; padding: 20px; border-radius: 8px; box-shadow: 0 4px 15px rgba(0,0,0,0.2);">
            <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom: 15px;">
                <h3 style="margin:0; font-size:18px; font-weight:600;">Stwórz nowe ogłoszenie</h3>
                <button style="background:none; border:none; font-size:24px; cursor:pointer;" onclick="document.getElementById('modal-add-item').style.display='none'">&times;</button>
            </div>
            <form action="marketplace.php" method="POST" enctype="multipart/form-data">
                <label style="font-size:13px; font-weight:600;">Tytuł przedmiotu *</label>
                <input type="text" name="title" class="market-form-input" required placeholder="Co sprzedajesz?">

                <label style="font-size:13px; font-weight:600;">Cena (zł) *</label>
                <input type="number" name="price" step="0.01" min="0" class="market-form-input" required placeholder="0.00">

                <label style="font-size:13px; font-weight:600;">Kategoria</label>
                <select name="category" class="market-form-input">
                    <?php foreach($categories as $key => $label): ?>
                        <?php if($key !== 'all'): ?>
                            <option value="<?= $key ?>"><?= $label ?></option>
                        <?php endif; ?>
                    <?php endforeach; ?>
                </select>

                <label style="font-size:13px; font-weight:600;">Lokalizacja przedmiotu (opcjonalnie)</label>
                <input type="text" name="city" class="market-form-input" placeholder="Zostaw puste, aby pobrać z profilu">

                <label style="font-size:13px; font-weight:600;">Opis przedmiotu</label>
                <textarea name="description" class="market-form-input" style="height: 100px; resize: none;" placeholder="Napisz coś więcej o przedmiocie..."></textarea>

                <label style="font-size:13px; font-weight:600;">Zdjęcie przedmiotu</label>
                <input type="file" name="item_image" accept="image/*" class="market-form-input">

                <div style="display: flex; justify-content: flex-end; gap: 10px; margin-top: 15px;">
                    <button type="button" style="padding: 10px 16px; background:#e4e6eb; border:none; border-radius:6px; cursor:pointer; font-weight:600;" onclick="document.getElementById('modal-add-item').style.display='none'">Anuluj</button>
                    <button type="submit" name="submit_item" style="padding: 10px 16px; background: var(--primary-color, #338336); color:white; border:none; border-radius:6px; cursor:pointer; font-weight:bold;">Opublikuj</button>
                </div>
            </form>
        </div>
    </div>

    <script>
        function openItemView(card) {
            const title = card.getAttribute('data-title');
            const price = card.getAttribute('data-price');
            const desc = card.getAttribute('data-desc');
            const city = card.getAttribute('data-city');
            const imageUrl = card.getAttribute('data-image');
            const sellerId = card.getAttribute('data-seller-id');
            const sellerName = card.getAttribute('data-seller-name');
            const sellerAvatar = card.getAttribute('data-seller-avatar');
            const isMine = card.getAttribute('data-is-mine');

            const mediaContent = document.getElementById('view-media-content');
            if(imageUrl && imageUrl !== '') {
                mediaContent.innerHTML = `<img src="${imageUrl}" alt="">`;
            } else {
                mediaContent.innerHTML = `<div class="placeholder-big">🛍️</div>`;
            }

            document.getElementById('view-title').innerText = title;
            document.getElementById('view-price').innerText = price + " zł";
            document.getElementById('view-location').innerText = city;
            document.getElementById('view-description').innerText = desc ? desc : "Brak opisu przedmiotu.";
            document.getElementById('view-seller-name').innerText = sellerName;
            document.getElementById('view-seller-avatar').src = sellerAvatar ? sellerAvatar : 'images/default-avatar.png';

            const actionContainer = document.getElementById('view-action-container');
            if(isMine === '1') {
                actionContainer.innerHTML = `<div style="text-align:center; padding:12px; background:#f0f2f5; border-radius:8px; color:var(--primary-color); font-weight:bold;">To jest Twoje ogłoszenie</div>`;
            } else {
                actionContainer.innerHTML = `<a href="chat.php?seller_id=${sellerId}" style="display:block; text-align:center; padding:12px; background:var(--primary-color, #338336); color:white; border-radius:8px; text-decoration:none; font-weight:bold; font-size:16px;">Napisz do sprzedawcy</a>`;
            }

            document.getElementById('fb-item-view').style.display = 'block';
            document.body.style.overflow = 'hidden';
        }

        function closeItemView() {
            document.getElementById('fb-item-view').style.display = 'none';
            document.body.style.overflow = 'auto';
        }

        function closeItemViewOnBg(event) {
            if(event.target.classList.contains('fb-view-media')) {
                closeItemView();
            }
        }

        window.onclick = function(event) {
            let modalAdd = document.getElementById('modal-add-item');
            if (event.target == modalAdd) {
                modalAdd.style.display = "none";
            }
        }
    </script>
    </body>
    </html>
<?php
if (ob_get_level() > 0) {
    ob_end_flush();
}
?>