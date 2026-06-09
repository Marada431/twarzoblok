<?php
// Adres bazowy aplikacji (używany w linkach weryfikacyjnych)
if (!defined('APP_URL')) {
    $scheme = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on') ? 'https' : 'http';
    $host   = $_SERVER['HTTP_HOST'] ?? 'localhost';
    define('APP_URL', $scheme . '://' . $host);
}

/**
 * Wysyła e-mail weryfikacyjny.
 *
 * Priorytety:
 *  1. PHPMailer (vendor/phpmailer/) – jeśli zainstalowany i mail_config.php istnieje
 *  2. Wbudowana funkcja mail() PHP  – fallback
 *
 * Zawsze loguje link do error_log – pomocne na XAMPP/dev bez skonfigurowanego SMTP.
 */
function sendVerificationEmail(string $to, string $username, string $token): bool {
    $link = APP_URL . '/auth/verify.php?token=' . rawurlencode($token);

    // Loguj link – na potrzeby developmentu (aktywacja bez e-maila)
    error_log("TwarzoBlok – link weryfikacyjny dla [{$to}]: {$link}");

    $phpmailerPath  = dirname(__DIR__) . '/vendor/phpmailer/PHPMailer.php';
    $mailConfigPath = dirname(__DIR__) . '/config/mail_config.php';

    if (file_exists($phpmailerPath) && file_exists($mailConfigPath)) {
        return _sendWithPHPMailer($to, $username, $token);
    }

    return _sendWithBuiltinMail($to, $username, $link);
}

// ── Wysyłka przez PHPMailer ──────────────────────────────────
function _sendWithPHPMailer(string $to, string $username, string $token): bool {
    require_once dirname(__DIR__) . '/vendor/phpmailer/PHPMailer.php';
    require_once dirname(__DIR__) . '/vendor/phpmailer/SMTP.php';
    require_once dirname(__DIR__) . '/vendor/phpmailer/Exception.php';
    require_once dirname(__DIR__) . '/config/mail_config.php';

    // Pełne nazwy klas (use niedozwolone wewnątrz funkcji w PHP)
    $mail = new \PHPMailer\PHPMailer\PHPMailer(true);
    try {
        $mail->isSMTP();
        $mail->Host       = MAIL_HOST;
        $mail->SMTPAuth   = true;
        $mail->Username   = MAIL_USERNAME;
        $mail->Password   = MAIL_PASSWORD;
        $mail->SMTPSecure = MAIL_ENCRYPTION;
        $mail->Port       = MAIL_PORT;
        $mail->CharSet    = 'UTF-8';
        $mail->setFrom(MAIL_FROM, MAIL_FROM_NAME);
        $mail->addAddress($to);
        $mail->isHTML(true);
        $mail->Subject = 'Aktywuj swoje konto w TwarzoBlok';
        $mail->Body    = getVerificationEmailTemplate($username, $token);
        $mail->AltBody = getVerificationEmailPlaintext($username, $token);
        $mail->send();
        return true;
    } catch (\PHPMailer\PHPMailer\Exception $e) {
        error_log('PHPMailer error dla ' . $to . ': ' . $mail->ErrorInfo);
        return false;
    }
}

// ── Wysyłka przez wbudowane mail() PHP ──────────────────────
function _sendWithBuiltinMail(string $to, string $username, string $link): bool {
    $subject  = '=?UTF-8?B?' . base64_encode('Aktywuj swoje konto w TwarzoBlok') . '?=';
    $boundary = md5(uniqid());
    $from     = 'noreply@twarzoblok.local';

    $headers  = "From: TwarzoBlok <{$from}>\r\n";
    $headers .= "Reply-To: {$from}\r\n";
    $headers .= "MIME-Version: 1.0\r\n";
    $headers .= "Content-Type: multipart/alternative; boundary=\"{$boundary}\"\r\n";
    $headers .= "X-Mailer: PHP/" . PHP_VERSION;

    $safeUser = htmlspecialchars($username, ENT_QUOTES, 'UTF-8');
    $safeLink = htmlspecialchars($link, ENT_QUOTES, 'UTF-8');

    $htmlPart = <<<HTML
<!DOCTYPE html><html lang="pl"><head><meta charset="UTF-8"></head>
<body style="font-family:sans-serif;background:#f0f4f1;padding:30px;">
  <div style="max-width:520px;margin:0 auto;background:#fff;border-radius:8px;
              border:1px solid #d2ded4;padding:36px;">
    <h2 style="color:#338336;margin-top:0;">TwarzoBlok</h2>
    <p>Cześć, <strong>{$safeUser}</strong>!</p>
    <p>Kliknij przycisk poniżej, aby aktywować swoje konto:</p>
    <p style="text-align:center;margin:28px 0;">
      <a href="{$safeLink}"
         style="background:#338336;color:#fff;padding:13px 32px;border-radius:6px;
                text-decoration:none;font-weight:700;font-size:15px;">
        Aktywuj konto
      </a>
    </p>
    <p style="font-size:12px;color:#888;word-break:break-all;">
      Link: <a href="{$safeLink}" style="color:#338336;">{$safeLink}</a>
    </p>
    <p style="font-size:12px;color:#888;">Link ważny 24 godziny.</p>
  </div>
</body></html>
HTML;

    $textPart = "Cześć, {$username}!\n\n"
        . "Aktywuj konto klikając link:\n{$link}\n\n"
        . "Link ważny 24 godziny.\n-- TwarzoBlok";

    $body  = "--{$boundary}\r\n";
    $body .= "Content-Type: text/plain; charset=UTF-8\r\n\r\n{$textPart}\r\n\r\n";
    $body .= "--{$boundary}\r\n";
    $body .= "Content-Type: text/html; charset=UTF-8\r\n\r\n{$htmlPart}\r\n\r\n";
    $body .= "--{$boundary}--";

    $result = @mail($to, $subject, $body, $headers);
    if (!$result) {
        error_log("mail() zwróciło false dla: {$to}");
    }
    return $result;
}

// ── Szablony (używane przez PHPMailer) ───────────────────────
function getVerificationEmailTemplate(string $username, string $token): string {
    $link     = APP_URL . '/auth/verify.php?token=' . rawurlencode($token);
    $safeUser = htmlspecialchars($username, ENT_QUOTES, 'UTF-8');
    $safeLink = htmlspecialchars($link,     ENT_QUOTES, 'UTF-8');
    $year     = date('Y');
    return <<<HTML
<!DOCTYPE html><html lang="pl"><head><meta charset="UTF-8"></head>
<body style="background:#f0f4f1;font-family:'Segoe UI',sans-serif;padding:30px 0;">
  <div style="max-width:560px;margin:0 auto;background:#fff;border-radius:8px;
              border:1px solid #d2ded4;padding:40px;">
    <h1 style="color:#338336;font-size:28px;margin-top:0;">TwarzoBlok</h1>
    <p>Cześć, <strong>{$safeUser}</strong>!</p>
    <p>Kliknij przycisk poniżej, aby aktywować swoje konto:</p>
    <p style="text-align:center;margin:28px 0;">
      <a href="{$safeLink}"
         style="background:#338336;color:#fff;padding:14px 36px;border-radius:6px;
                text-decoration:none;font-weight:700;font-size:16px;">Aktywuj konto</a>
    </p>
    <p style="font-size:12px;color:#888;word-break:break-all;">
      <a href="{$safeLink}" style="color:#338336;">{$safeLink}</a>
    </p>
    <p style="font-size:12px;color:#888;">Link ważny przez <strong>24 godziny</strong>.</p>
    <hr style="border:none;border-top:1px solid #eee;margin-top:24px;">
    <p style="font-size:11px;color:#aaa;text-align:center;">TwarzoBlok &copy; {$year} – Łączy nas zieleń</p>
  </div>
</body></html>
HTML;
}

function getVerificationEmailPlaintext(string $username, string $token): string {
    $link = APP_URL . '/auth/verify.php?token=' . rawurlencode($token);
    return "Cześć, {$username}!\n\n"
        . "Aktywuj konto klikając link:\n{$link}\n\n"
        . "Link ważny 24 godziny.\n-- TwarzoBlok";
}
