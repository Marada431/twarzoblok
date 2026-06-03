<?php
require_once dirname(__DIR__) . '/vendor/phpmailer/PHPMailer.php';
require_once dirname(__DIR__) . '/vendor/phpmailer/SMTP.php';
require_once dirname(__DIR__) . '/vendor/phpmailer/Exception.php';
require_once dirname(__DIR__) . '/config/mail_config.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

function sendVerificationEmail(string $to, string $username, string $token): bool {
    $mail = new PHPMailer(true);
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
    } catch (Exception $e) {
        error_log('Błąd wysyłki maila aktywacyjnego do ' . $to . ': ' . $mail->ErrorInfo);
        return false;
    }
}

function getVerificationEmailTemplate(string $username, string $token): string {
    $link       = APP_URL . '/auth/verify.php?token=' . rawurlencode($token);
    $safeUser   = htmlspecialchars($username, ENT_QUOTES, 'UTF-8');
    $safeLink   = htmlspecialchars($link, ENT_QUOTES, 'UTF-8');

    return <<<HTML
<!DOCTYPE html>
<html lang="pl">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
</head>
<body style="margin:0;padding:0;background-color:#f0f4f1;font-family:'Segoe UI',Tahoma,Geneva,Verdana,sans-serif;">
<table width="100%" cellpadding="0" cellspacing="0" style="background-color:#f0f4f1;padding:30px 0;">
  <tr>
    <td align="center">
      <table width="600" cellpadding="0" cellspacing="0" style="max-width:600px;width:100%;">
        <!-- Logo -->
        <tr>
          <td align="center" style="padding:20px 0;">
            <span style="font-size:36px;font-weight:800;color:#338336;letter-spacing:-1px;">TwarzoBlok</span>
          </td>
        </tr>
        <!-- Karta -->
        <tr>
          <td style="background-color:#ffffff;border-radius:8px;border:1px solid #d2ded4;padding:40px 40px 30px;">
            <h1 style="margin:0 0 16px;font-size:22px;color:#1b1e1b;">
              Cześć, {$safeUser}!
            </h1>
            <p style="margin:0 0 24px;font-size:15px;color:#556056;line-height:1.6;">
              Dziękujemy za rejestrację w TwarzoBlok. Kliknij przycisk poniżej, aby aktywować swoje konto.
            </p>
            <table cellpadding="0" cellspacing="0" style="margin:0 auto 28px;">
              <tr>
                <td align="center" style="background-color:#338336;border-radius:6px;">
                  <a href="{$safeLink}"
                     style="display:inline-block;padding:14px 36px;font-size:16px;font-weight:700;
                            color:#ffffff;text-decoration:none;border-radius:6px;">
                    Aktywuj konto
                  </a>
                </td>
              </tr>
            </table>
            <p style="margin:0 0 8px;font-size:13px;color:#556056;">
              Jeśli przycisk nie działa, skopiuj ten link do przeglądarki:
            </p>
            <p style="margin:0 0 24px;font-size:12px;word-break:break-all;">
              <a href="{$safeLink}" style="color:#338336;">{$safeLink}</a>
            </p>
            <p style="margin:0;font-size:13px;color:#556056;">
              Link jest ważny przez <strong>24 godziny</strong>.
              Jeśli nie zakładałeś konta w TwarzoBlok, zignoruj tę wiadomość.
            </p>
          </td>
        </tr>
        <!-- Stopka -->
        <tr>
          <td align="center" style="padding:20px 0;font-size:12px;color:#556056;">
            TwarzoBlok &copy; <?php echo date('Y'); ?> &mdash; Łączy nas zieleń
          </td>
        </tr>
      </table>
    </td>
  </tr>
</table>
</body>
</html>
HTML;
}

function getVerificationEmailPlaintext(string $username, string $token): string {
    $link = APP_URL . '/auth/verify.php?token=' . rawurlencode($token);
    return "Cześć, {$username}!\n\n"
        . "Dziękujemy za rejestrację w TwarzoBlok.\n"
        . "Kliknij link poniżej, aby aktywować swoje konto:\n\n"
        . $link . "\n\n"
        . "Link jest ważny przez 24 godziny.\n"
        . "Jeśli nie zakładałeś konta, zignoruj tę wiadomość.\n\n"
        . "-- TwarzoBlok";
}
