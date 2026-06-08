<?php
session_start();
require_once dirname(__DIR__) . '/config/database.php';
require_once __DIR__ . '/send_verification_email.php';

$msg        = '';
$msg_type   = '';
$submitted  = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $submitted = true;
    $email = filter_var(trim($_POST['email'] ?? ''), FILTER_VALIDATE_EMAIL);

    // Zawsze ten sam komunikat — nie ujawniamy czy e-mail istnieje
    $generic = 'Jeśli podany adres e-mail jest powiązany z niezweryfikowanym kontem, wysłaliśmy nowy link aktywacyjny.';

    if ($email) {
        try {
            $pdo  = db();
            $stmt = $pdo->prepare(
                "SELECT user_id, username, token_expires_at
                 FROM users
                 WHERE email = :email AND is_verified = 0"
            );
            $stmt->execute([':email' => $email]);
            $user = $stmt->fetch();

            if ($user) {
                // Limit: nie wolno wysyłać częściej niż raz na 2 minuty
                $tooSoon = false;
                if ($user['token_expires_at']) {
                    $expiresAt  = new DateTime($user['token_expires_at']);
                    $threshold  = new DateTime('+23 hours 58 minutes');
                    // token_expires_at = now + 24h, więc jeśli expires_at > now+23h58m → wygenerowany < 2 min temu
                    if ($expiresAt > $threshold) {
                        $tooSoon = true;
                    }
                }

                if (!$tooSoon) {
                    $token   = bin2hex(random_bytes(32));
                    $expires = date('Y-m-d H:i:s', strtotime('+24 hours'));

                    $upd = $pdo->prepare(
                        "UPDATE users SET verification_token = :token, token_expires_at = :expires WHERE user_id = :id"
                    );
                    $upd->execute([':token' => $token, ':expires' => $expires, ':id' => $user['user_id']]);

                    sendVerificationEmail($email, $user['username'], $token);
                }
            }
        } catch (PDOException $e) {
            error_log('Błąd resend_verification: ' . $e->getMessage());
        }
    }

    $msg      = $generic;
    $msg_type = 'success';
}
?>
<!DOCTYPE html>
<html lang="pl">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Wyślij ponownie link aktywacyjny | TwarzoBlok</title>
    <link rel="stylesheet" href="../assets/css/auth.css">
</head>
<body>
<div class="auth-logo">TwarzoBlok</div>
<div class="card">
  <h2>Wyślij ponownie link</h2>
  <p>Podaj adres e-mail użyty przy rejestracji, a wyślemy nowy link aktywacyjny.</p>

  <?php if ($msg): ?>
    <div class="alert alert-success"><?= htmlspecialchars($msg) ?></div>
  <?php endif; ?>

  <?php if (!$submitted): ?>
  <form method="POST" action="">
    <div class="form-group">
      <label for="email">Adres e-mail</label>
      <input type="email" id="email" name="email" class="form-input"
             placeholder="nazwa@domena.pl" required autocomplete="email">
    </div>
    <button type="submit" class="btn-primary">Wyślij link aktywacyjny</button>
  </form>
  <?php endif; ?>

  <a href="../login.php" class="back-link">Wróć do logowania</a>
</div>
</body>
</html>
