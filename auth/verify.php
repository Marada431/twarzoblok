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
<style>
  :root {
    --bg-main: #f0f4f1;
    --bg-surface: #ffffff;
    --primary-color: #338336;
    --text-main: #1b1e1b;
    --text-muted: #556056;
    --border-color: #d2ded4;
    --error: #d32f2f;
    --error-bg: #ffebee;
    --radius-main: 8px;
    --shadow: 0 1px 3px rgba(46,125,50,0.1);
  }
  * { margin:0; padding:0; box-sizing:border-box; font-family:'Segoe UI',Tahoma,Geneva,Verdana,sans-serif; }
  body { background-color:var(--bg-main); color:var(--text-main); min-height:100vh;
         display:flex; flex-direction:column; justify-content:center; align-items:center; padding:20px; }
  .auth-logo { color:var(--primary-color); margin-bottom:24px; font-size:44px; font-weight:800; letter-spacing:-1px; }
  .card { background:var(--bg-surface); border:1px solid var(--border-color); border-radius:var(--radius-main);
          box-shadow:var(--shadow); padding:36px; max-width:480px; width:100%; text-align:center; }
  .alert-error { background:var(--error-bg); color:var(--error); border:1px solid #ffcdd2;
                 padding:14px 16px; border-radius:var(--radius-main); margin-bottom:20px; font-size:14px; }
  .card h2 { font-size:20px; margin-bottom:12px; }
  .card p  { font-size:14px; color:var(--text-muted); margin-bottom:20px; }
  .btn { display:inline-block; background-color:var(--primary-color); color:#fff; font-weight:600;
         padding:12px 28px; border-radius:6px; text-decoration:none; font-size:15px; }
  .btn:hover { background-color:#1b5e20; }
  .link-small { font-size:13px; color:var(--primary-color); text-decoration:none; }
  .link-small:hover { text-decoration:underline; }
</style>
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
