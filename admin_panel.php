<?php
session_start();

// 1. OCHRONA: Sprawdzamy czy użytkownik jest zalogowany
if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
    header('Location: login.php');
    exit;
}

// 2. OCHRONA: Sprawdzamy czy zalogowany użytkownik ma rolę admina
if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'admin') {
    header('Location: index.php');
    exit;
}

// Dołączenie bazy danych
require_once 'config/database.php';

$error = '';
$success = '';

try {
    $db = db();

    // ── OBSŁUGA AKCJI MODERATORSKICH (POST) ───────────────────────────
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
        $target_user_id = (int)($_POST['target_user_id'] ?? 0);

        // Zabezpieczenie: Admin nie może zablokować samego siebie
        if ($target_user_id === (int)$_SESSION['user_id']) {
            $error = "Nie możesz modyfikować własnego konta z poziomu panelu.";
        } else {
            if ($_POST['action'] === 'toggle_block') {
                $current_status = $_POST['current_status'] ?? 'active';
                $new_status = ($current_status === 'blocked') ? 'active' : 'blocked';

                $stmt = $db->prepare("UPDATE users SET status = :status WHERE user_id = :id");
                $stmt->execute([':status' => $new_status, ':id' => $target_user_id]);
                $success = "Status użytkownika został pomyślnie zmieniony.";
            }

            if ($_POST['action'] === 'change_role') {
                $current_role = $_POST['current_role'] ?? 'user';
                $new_role = ($current_role === 'admin') ? 'user' : 'admin';

                $stmt = $db->prepare("UPDATE users SET role = :role WHERE user_id = :id");
                $stmt->execute([':role' => $new_role, ':id' => $target_user_id]);
                $success = "Rola użytkownika została pomyślnie zmieniona.";
            }
        }
    }

    // ── POBIERANIE STATYSTYK DO DASHBOARDU ───────────────────────────
    // Łączna liczba użytkowników
    $total_users = $db->query("SELECT COUNT(*) FROM users")->fetchColumn();
    // Liczba zablokowanych użytkowników
    $blocked_users = $db->query("SELECT COUNT(*) FROM users WHERE status = 'blocked'")->fetchColumn();
    // Łączna liczba postów (zakładając istnienie tabeli posts)
    $total_posts = 0;
    try {
        $total_posts = $db->query("SELECT COUNT(*) FROM posts")->fetchColumn();
    } catch (PDOException $e) {
        // Tabela posts może jeszcze nie istnieć
    }

    // ── POBIERANIE LISTY UŻYTKOWNIKÓW ────────────────────────────────
    $stmt = $db->query("SELECT user_id, username, email, first_name, last_name, role, status FROM users ORDER BY user_id DESC");
    $users_list = $stmt->fetchAll();

} catch (PDOException $e) {
    $error = "Błąd bazy danych: " . $e->getMessage();
}
?>
<!doctype html>
<html lang="pl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Panel Administratora - TwarzBlok</title>
    <link rel="stylesheet" href="css/style.css">
    <style>
        /* Dedykowane style dla panelu admina harmonizujące z motywem TwarzBloku */
        .admin-container {
            display: flex;
            max-width: 1200px;
            margin: 30px auto;
            gap: 20px;
            padding: 0 15px;
        }
        .admin-main {
            flex-grow: 1;
        }
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
            gap: 20px;
            margin-bottom: 30px;
        }
        .stat-card {
            background-color: var(--bg-surface);
            border: 1px solid var(--border-color);
            border-radius: var(--radius-main);
            padding: 20px;
            text-align: center;
            box-shadow: 0 2px 4px rgba(0,0,0,0.05);
        }
        .stat-card h3 {
            color: var(--text-muted);
            font-size: 14px;
            text-transform: uppercase;
            margin-bottom: 10px;
        }
        .stat-card .number {
            font-size: 32px;
            font-weight: bold;
            color: var(--primary-color);
        }
        .admin-table-wrapper {
            background-color: var(--bg-surface);
            border: 1px solid var(--border-color);
            border-radius: var(--radius-main);
            padding: 20px;
            box-shadow: 0 2px 4px rgba(0,0,0,0.05);
            overflow-x: auto;
        }
        .admin-table {
            width: 100%;
            border-collapse: collapse;
            text-align: left;
        }
        .admin-table th, .admin-table td {
            padding: 12px 15px;
            border-bottom: 1px solid var(--border-color);
            font-size: 14px;
        }
        .admin-table th {
            background-color: var(--bg-hover);
            color: var(--text-main);
            font-weight: 600;
        }
        .badge {
            padding: 4px 8px;
            border-radius: 4px;
            font-size: 12px;
            font-weight: 600;
            display: inline-block;
        }
        .badge-admin { background-color: #ffeef0; color: #f50057; }
        .badge-user { background-color: #e8f5e9; color: var(--primary-color); }
        .badge-active { background-color: #e8f5e9; color: #2e7d32; }
        .badge-blocked { background-color: #eceff1; color: #37474f; }

        .alert {
            padding: 12px 15px;
            border-radius: var(--radius-main);
            margin-bottom: 20px;
            font-size: 14px;
        }
        .alert-success { background-color: #e8f5e9; color: #2e7d32; border: 1px solid #c8e6c9; }
        .alert-danger { background-color: #ffeef0; color: #c62828; border: 1px solid #ffcdd2; }

        .btn-action {
            padding: 6px 12px;
            font-size: 12px;
            border: none;
            border-radius: 4px;
            cursor: pointer;
            font-weight: 600;
            transition: background 0.2s;
        }
        .btn-block { background-color: #f44336; color: white; }
        .btn-block:hover { background-color: #d32f2f; }
        .btn-unblock { background-color: #4caf50; color: white; }
        .btn-unblock:hover { background-color: #388e3c; }
        .btn-role { background-color: #2196f3; color: white; }
        .btn-role:hover { background-color: #1976d2; }
    </style>
</head>
<body>

<nav class="navbar" style="margin-bottom: 0;">
    <div class="navbar-brand">
        <a href="index.php" style="text-decoration: none; color: inherit;">TwarzBlok</a>
        <span style="font-size: 14px; background: rgba(255,255,255,0.2); padding: 3px 8px; border-radius: 4px; margin-left: 10px;"></span>
    </div>
    <div class="navbar-links2">
        <a href="index.php" title="Powrót do portalu">
            <div class="icon-wrapper">
                <svg><use xlink:href="./icons/symbol-defs.svg#icon-home"></use></svg>
            </div>
        </a>
    </div>
</nav>

<div class="admin-container">
    <main class="admin-main">

        <h2 class="m-bottom-15" style="color: var(--text-main);">Centrum Zarządzania Portalem</h2>

        <?php if (!empty($success)): ?>
            <div class="alert alert-success"><?php echo htmlspecialchars($success); ?></div>
        <?php endif; ?>
        <?php if (!empty($error)): ?>
            <div class="alert alert-danger"><?php echo htmlspecialchars($error); ?></div>
        <?php endif; ?>

        <div class="stats-grid">
            <div class="stat-card">
                <h3>Użytkownicy</h3>
                <div class="number"><?php echo $total_users; ?></div>
            </div>
            <div class="stat-card">
                <h3>Zablokowani</h3>
                <div class="number" style="color: #f44336;"><?php echo $blocked_users; ?></div>
            </div>
            <div class="stat-card">
                <h3>Wszystkie Posty</h3>
                <div class="number" style="color: #2196f3;"><?php echo $total_posts; ?></div>
            </div>
        </div>

        <h3 class="m-bottom-15" style="color: var(--text-main);">Zarejestrowani Użytkownicy</h3>
        <div class="admin-table-wrapper">
            <?php if (!empty($users_list)): ?>
                <table class="admin-table">
                    <thead>
                    <tr>
                        <th>ID</th>
                        <th>Użytkownik</th>
                        <th>E-mail</th>
                        <th>Imię i Nazwisko</th>
                        <th>Rola</th>
                        <th>Status</th>
                        <th>Akcje</th>
                    </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($users_list as $user): ?>
                        <tr>
                            <td>#<?php echo $user['user_id']; ?></td>
                            <td><strong><?php echo htmlspecialchars($user['username']); ?></strong></td>
                            <td><?php echo htmlspecialchars($user['email']); ?></td>
                            <td><?php echo htmlspecialchars($user['first_name'] . ' ' . $user['last_name']); ?></td>
                            <td>
                                    <span class="badge badge-<?php echo $user['role']; ?>">
                                        <?php echo ($user['role'] === 'admin') ? 'Admin' : 'Użytkownik'; ?>
                                    </span>
                            </td>
                            <td>
                                    <span class="badge badge-<?php echo $user['status']; ?>">
                                        <?php echo ($user['status'] === 'active') ? 'Aktywny' : 'Zablokowany'; ?>
                                    </span>
                            </td>
                            <td>
                                <div style="display: flex; gap: 5px;">

                                    <form method="POST" onsubmit="return confirm('Czy na pewno chcesz zmienić status tego użytkownika?');">
                                        <input type="hidden" name="target_user_id" value="<?php echo $user['user_id']; ?>">
                                        <input type="hidden" name="current_status" value="<?php echo $user['status']; ?>">
                                        <input type="hidden" name="action" value="toggle_block">
                                        <?php if ($user['status'] === 'blocked'): ?>
                                            <button type="submit" class="btn-action btn-unblock">Odblokuj</button>
                                        <?php else: ?>
                                            <button type="submit" class="btn-action btn-block">Zablokuj</button>
                                        <?php endif; ?>
                                    </form>

                                    <form method="POST" onsubmit="return confirm('Czy na pewno chcesz zmienić uprawnienia (rolę) tego użytkownika?');">
                                        <input type="hidden" name="target_user_id" value="<?php echo $user['user_id']; ?>">
                                        <input type="hidden" name="current_role" value="<?php echo $user['role']; ?>">
                                        <input type="hidden" name="action" value="change_role">
                                        <button type="submit" class="btn-action btn-role">Zmień rolę</button>
                                    </form>

                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            <?php else: ?>
                <p style="color: var(--text-muted); text-align: center;">Brak zarejestrowanych użytkowników.</p>
            <?php endif; ?>
        </div>

    </main>
</div>

</body>
</html>