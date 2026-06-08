<?php
session_start();
require_once dirname(__DIR__) . '/config/database.php';

$error   = '';
$success = false;

$token = $_GET['token'] ?? '';

if (empty($token) || !ctype_xdigit($token)) {
    $error = 'Link aktywacyjny jest nieprawidłowy lub wygasł.';
} else {
    try {
        $pdo  = db();
        $stmt = $pdo->prepare(
            "SELECT user_id FROM users
             WHERE verification_token = :token
               AND is_verified = 0
               AND token_expires_at > NOW()"
        );
        $stmt->execute([':token' => $token]);
        $user = $stmt->fetch();

        if (!$user) {
            $error = 'Link aktywacyjny jest nieprawidłowy lub wygasł.';
        } else {
            $upd = $pdo->prepare(
                "UPDATE users
                 SET is_verified = 1,
                     status = 'active',
                     verification_token = NULL,
                     token_expires_at = NULL
                 WHERE user_id = :id"
            );
            $upd->execute([':id' => $user['user_id']]);
            $success = true;
        }
    } catch (PDOException $e) {
        error_log('Błąd weryfikacji konta: ' . $e->getMessage());
        $error = 'Wystąpił błąd serwera. Spróbuj ponownie później.';
    }
}

if ($success) {
    $_SESSION['verification_success'] = 'Konto zostało aktywowane. Możesz się teraz zalogować.';
    header('Location: ../login.php');
    exit;
}
?>
<!DOCTYPE html>
<html lang="pl">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Weryfikacja konta | TwarzoBlok</title>
    <link rel="stylesheet" href="../assets/css/auth.css">
</head>
<body>
<div class="auth-logo">TwarzoBlok</div>
<div class="card">
  <h2>Weryfikacja konta</h2>
  <?php if ($error): ?>
    <div class="alert-error"><?= htmlspecialchars($error) ?></div>
    <p>Jeśli link wygasł, możesz poprosić o nowy link aktywacyjny.</p>
    <a href="resend_verification.php" class="btn">Wyślij nowy link</a>
  <?php endif; ?>
</div>
</body>
</html>
