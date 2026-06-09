<?php if (session_status() === PHP_SESSION_NONE) session_start(); ?>
<!DOCTYPE html>
<html lang="pl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Logowanie – TwarzoBlok</title>
    <link rel="stylesheet" href="assets/css/auth.css">
</head>
<body>

<div class="auth-wrapper">
    <div class="auth-logo">TwarzoBlok</div>

    <div class="card">
        <h2>Zaloguj się</h2>
        <p>Połącz się ze znajomymi</p>

        <?php if (!empty($data['error'])): ?>
            <div class="alert-error" role="alert">
                <?= htmlspecialchars($data['error'], ENT_QUOTES, 'UTF-8') ?>
            </div>
        <?php endif; ?>

        <?php if (isset($_GET['registered'])): ?>
            <div class="alert alert-success">
                Konto zostało utworzone. Możesz się teraz zalogować.
            </div>
        <?php endif; ?>

        <?php if (isset($_GET['reason']) && $_GET['reason'] === 'logged_out'): ?>
            <div class="alert alert-info">
                Zostałeś wylogowany.
            </div>
        <?php endif; ?>

        <?php
        $verif_msg = $_SESSION['verification_success'] ?? '';
        unset($_SESSION['verification_success']);
        if ($verif_msg): ?>
            <div class="alert alert-success">
                <?= htmlspecialchars($verif_msg, ENT_QUOTES, 'UTF-8') ?>
            </div>
        <?php endif; ?>

        <form method="POST" action="login.php" id="loginForm" novalidate>
            <input type="hidden" name="csrf_token"
                   value="<?= generate_csrf_token() ?>">

            <div class="form-group">
                <label for="login_input">Login, e-mail lub telefon</label>
                <input
                    type="text"
                    id="login_input"
                    name="login_input"
                    class="form-input"
                    value="<?= htmlspecialchars(
                        $data['old_input'] ?? '',
                        ENT_QUOTES, 'UTF-8'
                    ) ?>"
                    placeholder="Nazwa użytkownika, e-mail lub telefon"
                    autocomplete="username"
                    required>
            </div>

            <div class="form-group">
                <label for="password">Hasło</label>
                <div class="input-with-toggle">
                    <input
                        type="password"
                        id="password"
                        name="password"
                        class="form-input"
                        placeholder="Twoje hasło"
                        autocomplete="current-password"
                        required>
                    <button type="button"
                            class="toggle-password"
                            aria-label="Pokaż hasło">👁</button>
                </div>
            </div>

            <button type="submit" class="btn-primary">
                Zaloguj się
            </button>
        </form>

        <div class="auth-links">
            <p>Nie masz konta?
               <a href="register.php">Zarejestruj się</a>
            </p>
        </div>
    </div>

    <div class="auth-footer">
        &copy; <?= date('Y') ?> TwarzoBlok – Łączy nas zieleń
    </div>
</div>

<script src="assets/js/auth.js"></script>
</body>
</html>
