<?php
session_start();
if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
    header('Location: login.php');
    exit;
}

// Połączenie z bazą
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

$current_user_id = (int) $_SESSION['user_id'];
$message = '';

// Obsługa akceptacji / odrzucenia (POST)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'], $_POST['friendship_id'])) {
    $friendship_id = (int) $_POST['friendship_id'];
    $action = $_POST['action'];

    // Weryfikacja, że zaproszenie dotyczy zalogowanego użytkownika i jest pending
    $check = $pdo->prepare("
        SELECT friendship_id FROM friendships 
        WHERE friendship_id = :fid AND addressee_id = :uid AND status = 'pending'
    ");
    $check->execute([':fid' => $friendship_id, ':uid' => $current_user_id]);

    if ($check->fetch()) {
        if ($action === 'accept') {
            $pdo->prepare("UPDATE friendships SET status = 'accepted' WHERE friendship_id = :fid")
                ->execute([':fid' => $friendship_id]);
            $message = "Zaproszenie przyjęte!";
        } elseif ($action === 'reject') {
            $pdo->prepare("UPDATE friendships SET status = 'rejected' WHERE friendship_id = :fid")
                ->execute([':fid' => $friendship_id]);
            $message = "Zaproszenie odrzucone.";
        }
    }

    // Przekierowanie, aby odświeżyć listę
    header("Location: friend_requests.php?msg=" . urlencode($message));
    exit;
}

// Komunikat zwrotny
$msg = $_GET['msg'] ?? '';

// Wybór zakładki
$tab = $_GET['tab'] ?? 'received'; // 'received' lub 'sent'

// Pobranie odpowiednich zaproszeń
if ($tab === 'sent') {
    // Wysłane (jesteśmy requesterem)
    $stmt = $pdo->prepare("
        SELECT f.friendship_id, u.user_id, u.first_name, u.last_name, u.avatar_url,
               COALESCE(u.city, (SELECT city FROM addresses WHERE user_id = u.user_id LIMIT 1)) AS city
        FROM friendships f
        JOIN users u ON f.addressee_id = u.user_id
        WHERE f.requester_id = :uid AND f.status = 'pending'
        ORDER BY f.friendship_id DESC
    ");
} else {
    // Otrzymane (jesteśmy adresatem)
    $stmt = $pdo->prepare("
        SELECT f.friendship_id, u.user_id, u.first_name, u.last_name, u.avatar_url,
               COALESCE(u.city, (SELECT city FROM addresses WHERE user_id = u.user_id LIMIT 1)) AS city
        FROM friendships f
        JOIN users u ON f.requester_id = u.user_id
        WHERE f.addressee_id = :uid AND f.status = 'pending'
        ORDER BY f.friendship_id DESC
    ");
}
$stmt->execute([':uid' => $current_user_id]);
$requests = $stmt->fetchAll();
?>
<!DOCTYPE html>
<html lang="pl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Zaproszenia do znajomych – TwarzBlok</title>
    <link rel="stylesheet" href="css/style.css">
    <style>
        .requests-container {
            max-width: 700px;
            margin: 30px auto;
            padding: 0 15px;
        }
        .tabs {
            display: flex;
            gap: 20px;
            margin-bottom: 20px;
            border-bottom: 1px solid var(--border-color, #ddd);
            padding-bottom: 10px;
        }
        .tabs a {
            text-decoration: none;
            color: var(--text-muted, #65676b);
            font-weight: 500;
            padding-bottom: 8px;
            border-bottom: 2px solid transparent;
        }
        .tabs a.active {
            color: var(--text-main, #1c1e21);
            border-bottom-color: var(--primary-color, #1877f2);
            font-weight: 600;
        }
        .request-card {
            display: flex;
            align-items: center;
            gap: 15px;
            background: var(--bg-surface, #fff);
            padding: 15px;
            border-radius: 10px;
            margin-bottom: 12px;
            box-shadow: 0 1px 3px rgba(0,0,0,0.1);
        }
        .request-avatar {
            width: 60px;
            height: 60px;
            border-radius: 50%;
            overflow: hidden;
            flex-shrink: 0;
            background: var(--border-color, #ddd);
        }
        .request-avatar img {
            width: 100%;
            height: 100%;
            object-fit: cover;
        }
        .request-info {
            flex: 1;
        }
        .request-name {
            font-weight: 600;
            font-size: 16px;
            color: var(--text-main, #1c1e21);
        }
        .request-meta {
            font-size: 13px;
            color: var(--text-muted, #65676b);
            margin-top: 3px;
        }
        .request-actions {
            display: flex;
            gap: 10px;
        }
        .btn-accept {
            background-color: var(--primary-color, #1877f2);
            color: white;
            border: none;
            padding: 8px 16px;
            border-radius: 6px;
            font-weight: 600;
            cursor: pointer;
        }
        .btn-accept:hover {
            background-color: var(--primary-hover, #166fe5);
        }
        .btn-reject {
            background-color: #e4e6eb;
            color: var(--text-main, #1c1e21);
            border: none;
            padding: 8px 16px;
            border-radius: 6px;
            font-weight: 600;
            cursor: pointer;
        }
        .btn-reject:hover {
            background-color: #d8dadf;
        }
        .empty-message {
            text-align: center;
            color: var(--text-muted);
            margin-top: 40px;
        }
        .alert {
            background: #e6f7e6;
            color: #2e7d32;
            padding: 12px;
            border-radius: 6px;
            margin-bottom: 20px;
            text-align: center;
        }
    </style>
</head>
<body>

<nav class="navbar">
    <div class="navbar-brand">TwarzBlok</div>
    <div class="navbar-links">
        <a href="index.php"><svg><use xlink:href="./icons/symbol-defs.svg#icon-home"></use></svg></a>
        <a href="games.php"><svg><use xlink:href="./icons/symbol-defs.svg#icon-film"></use></svg></a>
        <a href="friend_requests.php"><svg><use xlink:href="./icons/symbol-defs.svg#icon-users"></use></svg></a>
    </div>
</nav>

<div class="requests-container">
    <h2>Zaproszenia do znajomych</h2>

    <?php if ($msg): ?>
        <div class="alert"><?php echo htmlspecialchars($msg); ?></div>
    <?php endif; ?>

    <!-- Zakładki -->
    <div class="tabs">
        <a href="?tab=received" class="<?= $tab === 'received' ? 'active' : '' ?>">Otrzymane</a>
        <a href="?tab=sent" class="<?= $tab === 'sent' ? 'active' : '' ?>">Wysłane</a>
    </div>

    <?php if (empty($requests)): ?>
        <p class="empty-message">
            <?php if ($tab === 'sent'): ?>
                Nie masz żadnych oczekujących wysłanych zaproszeń.
            <?php else: ?>
                Nie masz żadnych oczekujących zaproszeń.
            <?php endif; ?>
        </p>
    <?php else: ?>
        <?php foreach ($requests as $req):
            $req_name = htmlspecialchars($req['first_name'] . ' ' . $req['last_name']);
            $req_avatar = !empty($req['avatar_url']) ? htmlspecialchars($req['avatar_url']) : '';
            $req_location = !empty($req['city']) ? htmlspecialchars($req['city']) : 'Brak lokalizacji';
            ?>
            <div class="request-card">
                <div class="request-avatar">
                    <?php if ($req_avatar): ?>
                        <img src="<?php echo $req_avatar; ?>" alt="<?php echo $req_name; ?>">
                    <?php else: ?>
                        <div style="width:100%; height:100%; display:flex; align-items:center; justify-content:center; background:var(--border-color);">
                            <svg style="width:30px; height:30px; fill:var(--text-muted);"><use xlink:href="./icons/symbol-defs.svg#icon-user"></use></svg>
                        </div>
                    <?php endif; ?>
                </div>
                <div class="request-info">
                    <div class="request-name"><?php echo $req_name; ?></div>
                    <div class="request-meta">
                        <?php echo $req_location; ?>
                        <?php if ($tab === 'sent'): ?>
                            &bull; Oczekujące
                        <?php else: ?>
                            &bull; Oczekujące
                        <?php endif; ?>
                    </div>
                </div>
                <?php if ($tab === 'received'): ?>
                    <div class="request-actions">
                        <form method="post" style="margin:0;">
                            <input type="hidden" name="friendship_id" value="<?php echo $req['friendship_id']; ?>">
                            <button type="submit" name="action" value="accept" class="btn-accept">Przyjmij</button>
                            <button type="submit" name="action" value="reject" class="btn-reject">Odrzuć</button>
                        </form>
                    </div>
                <?php else: ?>
                    <div style="color: var(--text-muted); font-size: 13px;">Wysłane</div>
                <?php endif; ?>
            </div>
        <?php endforeach; ?>
    <?php endif; ?>
</div>

</body>
</html>