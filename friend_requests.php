<?php
session_start();
if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
    header('Location: login.php');
    exit;
}

// Dołączamy plik konfiguracyjny z bazą danych
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/csrf.php';
require_once __DIR__ . '/includes/auth_check.php';

$pdo = db();
$current_user_id = (int) $_SESSION['user_id'];
check_user_status($pdo, $current_user_id);
$message = '';

// Obsługa akceptacji / odrzucenia (POST)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'], $_POST['friendship_id'])) {
    verify_csrf_token($_POST['csrf_token'] ?? '');
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
    <link rel="stylesheet" href="assets/css/main.css">
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
                            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(generate_csrf_token(), ENT_QUOTES, 'UTF-8') ?>">
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
