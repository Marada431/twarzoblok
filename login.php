<?php
session_start();

error_reporting(E_ALL);
ini_set('display_errors', 1);
// Sprwadza czy jest się zalogowanym
if (isset($_SESSION['user_id'])) {
    header('Location: index.php');
    exit;
}

$error = '';
$success = '';

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
                           role, status, avatar_url 
                    FROM users 
                    WHERE " . ($field_type === 'email' ? "email = :input" : ($field_type === 'phone' ? "phone = :input" : "username = :input"));

            $stmt = $db->prepare($sql);
            $stmt->execute([':input' => $login_input]);
            $user = $stmt->fetch();

            // Weryfikacja hasła
            if ($user && password_verify($password, $user['password_hash'])) {
                // Sprawdzenie statusu konta
                if ($user['status'] !== 'active') {
                    $error = 'Twoje konto nie jest aktywne. Skontaktuj się z administratorem.';
                } else {
                    // Aktualizacja last_login_at
                    $updateStmt = $db->prepare("UPDATE users SET last_login_at = NOW() WHERE user_id = :user_id");
                    $updateStmt->execute([':user_id' => $user['user_id']]);

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
    <link rel="stylesheet" href="css/style.css">
    <style>
        /* Dodatkowe style specyficzne dla strony logowania */
        .login-options {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin: 15px 0;
        }
        .remember-me {
            display: flex;
            align-items: center;
            gap: 8px;
            cursor: pointer;
        }
        .remember-me input {
            width: 18px;
            height: 18px;
            cursor: pointer;
            accent-color: var(--primary-color);
        }
        .forgot-password {
            color: var(--primary-color);
            font-size: 14px;
        }
        .register-link {
            margin-top: 20px;
            text-align: center;
            padding-top: 15px;
            border-top: 1px solid var(--border-color);
        }
        .error-message {
            background-color: #fbe9e7;
            color: #c62828;
            padding: 12px;
            border-radius: var(--radius-main);
            margin-bottom: 20px;
            border-left: 4px solid #c62828;
        }
        .success-message {
            background-color: #e8f5e9;
            color: #2e7d32;
            padding: 12px;
            border-radius: var(--radius-main);
            margin-bottom: 20px;
            border-left: 4px solid #2e7d32;
        }
        .input-hint {
            font-size: 12px;
            color: var(--text-muted);
            margin-top: 5px;
        }
        .auth-footer {
            text-align: center;
            margin-top: 30px;
            color: var(--text-muted);
            font-size: 12px;
        }
    </style>
</head>
<body>
<div class="auth-wrapper">
    <div class="auth-logo">Twarzoblok</div>

    <div class="card auth-card">
        <h2 style="text-align: center; margin-bottom: 20px;">Zaloguj się</h2>

        <?php if ($error): ?>
            <div class="error-message"><?php echo htmlspecialchars($error); ?></div>
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