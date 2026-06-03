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
<style>
  :root {
    --bg-main: #f0f4f1;
    --bg-surface: #ffffff;
    --primary-color: #338336;
    --primary-hover: #1b5e20;
    --text-main: #1b1e1b;
    --text-muted: #556056;
    --border-color: #d2ded4;
    --error: #d32f2f;
    --error-bg: #ffebee;
    --radius-main: 8px;
    --shadow: 0 1px 3px rgba(46,125,50,0.1);
    --transition: all 0.2s ease-in-out;
  }
  * { margin:0; padding:0; box-sizing:border-box; font-family:'Segoe UI',Tahoma,Geneva,Verdana,sans-serif; }
  body { background-color:var(--bg-main); color:var(--text-main); min-height:100vh;
         display:flex; flex-direction:column; justify-content:center; align-items:center; padding:20px; }
  .auth-logo { color:var(--primary-color); margin-bottom:24px; font-size:44px; font-weight:800; letter-spacing:-1px; }
  .card { background:var(--bg-surface); border:1px solid var(--border-color); border-radius:var(--radius-main);
          box-shadow:var(--shadow); padding:36px; max-width:440px; width:100%; }
  .card h2 { font-size:20px; margin-bottom:8px; }
  .card p  { font-size:14px; color:var(--text-muted); margin-bottom:24px; }
  .form-group { display:flex; flex-direction:column; gap:6px; margin-bottom:16px; }
  .form-group label { font-size:13px; font-weight:600; }
  .form-input { width:100%; padding:12px; border:1px solid var(--border-color); border-radius:6px;
                font-size:14px; outline:none; transition:var(--transition); }
  .form-input:focus { border-color:var(--primary-color); box-shadow:0 0 0 2px rgba(46,125,50,0.2); }
  .btn-primary { background-color:var(--primary-color); color:#fff; font-weight:600; border:none;
                 border-radius:6px; padding:13px; font-size:15px; width:100%; cursor:pointer;
                 transition:var(--transition); }
  .btn-primary:hover { background-color:var(--primary-hover); }
  .alert { padding:12px 16px; border-radius:var(--radius-main); margin-bottom:20px; font-size:13px; }
  .alert-success { background:#e8f5e9; color:#2e7d32; border:1px solid #a5d6a7; }
  .back-link { display:block; text-align:center; margin-top:16px; font-size:13px; color:var(--primary-color); text-decoration:none; }
  .back-link:hover { text-decoration:underline; }
</style>
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
