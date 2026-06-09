<?php
session_start();
require_once __DIR__ . '/config/error_handler.php';
// Sprwadza czy jest się zalogowanym
if (isset($_SESSION['user_id'])) {
    header('Location: index.php');
    exit;
}

$error       = '';
$success     = '';
$show_resend = false;

$_reason = $_GET['reason'] ?? '';
if ($_reason === 'banned') {
    $error = 'Twoje konto zostało zablokowane. Skontaktuj się z administratorem.';
} elseif ($_reason === 'csrf') {
    $error = 'Sesja wygasła lub wykryto nieprawidłowe żądanie. Zaloguj się ponownie.';
}
unset($_reason);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_once 'config/database.php';

    $login_input = trim($_POST['login_input'] ?? '');
    $password = $_POST['password'] ?? '';

    // Walidacja
    if (empty($login_input) || empty($password)) {
        $error = 'Wszystkie pola są wymagane.';
    } else {
        // Wykrywa czy to numer telefonu login albo mail
        $field_type = 'username'; // domyślnie

        if (filter_var($login_input, FILTER_VALIDATE_EMAIL)) {
            $field_type = 'email';
        } elseif (preg_match('/^[0-9+\-\s()]+$/', $login_input) && strlen(preg_replace('/[^0-9]/', '', $login_input)) >= 6) {
            $field_type = 'phone';
        }

        try {
            $db = db();

            // Zapytanie w zależności od typu danych
            $sql = "SELECT user_id, username, email, phone, password_hash, first_name, last_name,
                           role, status, avatar_url, is_verified,
                           banned_until, ban_reason, is_banned_permanent
                    FROM users
                    WHERE " . ($field_type === 'email' ? "email = :input" : ($field_type === 'phone' ? "phone = :input" : "username = :input"));

            $stmt = $db->prepare($sql);
            $stmt->execute([':input' => $login_input]);
            $user = $stmt->fetch();

            // Weryfikacja hasła
            if ($user && password_verify($password, $user['password_hash'])) {
                // Sprawdzenie weryfikacji e-mail
                if (!$user['is_verified']) {
                    $error = 'Potwierdź swój adres e-mail przed zalogowaniem.';
                    $show_resend = true;
                } elseif (!empty($user['is_banned_permanent'])) {
                    $reason = !empty($user['ban_reason']) ? ' Powód: ' . htmlspecialchars($user['ban_reason']) : '';
                    $error = 'Twoje konto zostało permanentnie zablokowane.' . $reason;
                } elseif (!empty($user['banned_until']) && strtotime($user['banned_until']) > time()) {
                    $until = date('d.m.Y H:i', strtotime($user['banned_until']));
                    $reason = !empty($user['ban_reason']) ? ' Powód: ' . htmlspecialchars($user['ban_reason']) : '';
                    $error = "Twoje konto jest tymczasowo zablokowane do: $until.$reason";
                } elseif ($user['status'] !== 'active') {
                    $error = 'Twoje konto nie jest aktywne. Skontaktuj się z administratorem.';
                } else {
                    // Aktualizacja last_login_at
                    $updateStmt = $db->prepare("UPDATE users SET last_login_at = NOW() WHERE user_id = :user_id");
                    $updateStmt->execute([':user_id' => $user['user_id']]);

                    session_regenerate_id(true);

                    // Wszysto do sesji dla łatwiejszego robienia strony
                    $_SESSION['user_id'] = $user['user_id'];
                    $_SESSION['username'] = $user['username'];
                    $_SESSION['first_name'] = $user['first_name'];
                    $_SESSION['last_name'] = $user['last_name'];
                    $_SESSION['email'] = $user['email'];
                    $_SESSION['role'] = $user['role'];
                    $_SESSION['avatar_url'] = $user['avatar_url'];
                    $_SESSION['logged_in'] = true;

                    // Zamiepiętaj mnie do zrobnieania na przyszłość
                    if (isset($_POST['remember_me'])) {

                    }


                    header('Location: index.php');
                    exit;
                }
            } else {
                $error = 'Nieprawidłowy login lub hasło.';
               // Jeśli logowannie się nie uda do rozszerzenia na przyszłość aby bardziej pokazywało co nie tak
                error_log("Nieudana próba logowania dla: " . $login_input);
            }
        } catch (PDOException $e) {
            $error = 'Wystąpił błąd serwera. Spróbuj ponownie później.';
            error_log("Błąd logowania: " . $e->getMessage());
        }
    }
}
?>
<!DOCTYPE html>
<html lang="pl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, user-scalable=yes">
    <title>Logowanie - Twarzoblok</title>
    <link rel="stylesheet" href="assets/css/main.css">
</head>
<body>
<div class="auth-wrapper">
    <div class="auth-logo">Twarzoblok</div>

    <div class="card auth-card">
        <h2 style="text-align: center; margin-bottom: 20px;">Zaloguj się</h2>

        <?php if ($error): ?>
            <div class="error-message"><?php echo htmlspecialchars($error); ?></div>
            <?php if ($show_resend): ?>
                <p style="text-align:center;margin-top:8px;font-size:13px;">
                    <a href="auth/resend_verification.php" style="color:#338336;">Wyślij ponownie link aktywacyjny</a>
                </p>
            <?php endif; ?>
        <?php endif; ?>

        <?php
        $verif_msg = $_SESSION['verification_success'] ?? '';
        unset($_SESSION['verification_success']);
        if ($verif_msg): ?>
            <div class="success-message"><?php echo htmlspecialchars($verif_msg); ?></div>
        <?php endif; ?>

        <?php if ($success): ?>
            <div class="success-message"><?php echo htmlspecialchars($success); ?></div>
        <?php endif; ?>

        <form method="POST" action="" id="loginForm">
            <div class="form-group">
                <input type="text"
                       name="login_input"
                       id="login_input"
                       class="form-input"
                       placeholder="Nazwa użytkownika, e-mail lub numer telefonu"
                       value="<?php echo htmlspecialchars($_POST['login_input'] ?? ''); ?>"
                       required
                       autocomplete="username">
                <div class="input-hint" id="inputHint">
                    Możesz użyć nazwy użytkownika, adresu e-mail lub numeru telefonu
                </div>
            </div>

            <div class="form-group">
                <input type="password"
                       name="password"
                       id="password"
                       class="form-input"
                       placeholder="Hasło"
                       required
                       autocomplete="current-password">
            </div>

            <div class="login-options">
                <label class="remember-me">
                    <input type="checkbox" name="remember_me" id="remember_me">
                    <span>Zapamiętaj mnie</span>
                </label>
                <a href="forgot-password.php" class="forgot-password">Nie pamiętasz hasła?</a>
            </div>

            <button type="submit" class="btn btn-primary w-100 btn-submit-size">Zaloguj się</button>

            <div class="register-link">
                <span class="text-muted">Nie masz konta?</span>
                <a href="register_step1.php">Zarejestruj się</a>
            </div>
        </form>
    </div>

    <div class="auth-footer">
        &copy; 2025 Twarzoblok - Łączy nas zieleń
    </div>
</div>

<script src="assets/js/login.js"></script>
</body>
</html>