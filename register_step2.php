<?php
session_start();
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/auth/send_verification_email.php';

$msg = '';
$errors = [];
$success = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!isset($_SESSION['email']) || empty($_SESSION['email'])) {
        die("Błąd: Brak sesji. Rozpocznij rejestrację od kroku 1.");
    }

    $email = $_SESSION['email'];
    $pdo = db();

    $country = trim($_POST['country'] ?? '');
    $state = trim($_POST['state'] ?? '');
    $postal_code = trim($_POST['postal_code'] ?? '');
    $municipality = trim($_POST['municipality'] ?? '');
    $city = trim($_POST['city'] ?? '');
    $street = trim($_POST['street'] ?? '');
    $house_number = trim($_POST['house_number'] ?? '');

    $required = [
            'country' => 'Kraj', 'city' => 'Miasto', 'street' => 'Ulica',
            'house_number' => 'Nr domu/lokalu', 'postal_code' => 'Kod pocztowy'
    ];
    foreach ($required as $field => $label) {
        if (empty($$field)) {
            $errors[$field] = "Pole '$label' jest wymagane.";
        }
    }

    if (!isset($errors['postal_code']) && !preg_match('/^\d{2}-\d{3}$/', $postal_code)) {
        $errors['postal_code'] = 'Kod pocztowy musi być w formacie XX-XXX (np. 00-000).';
    }
    if (!isset($errors['house_number']) && !preg_match('/^[1-9]\d{0,3}[a-zA-Z]?(\/\d{1,3}[a-zA-Z]?)?$/', $house_number)) {
        $errors['house_number'] = 'Podaj prawidłowy numer domu (np. 12 lub 12/4).';
    }

    if (!isset($errors['country']) && strlen($country) > 100) $errors['country'] = 'Maksymalnie 100 znaków.';
    if (!isset($errors['state']) && strlen($state) > 100) $errors['state'] = 'Maksymalnie 100 znaków.';
    if (!isset($errors['city']) && strlen($city) > 100) $errors['city'] = 'Maksymalnie 100 znaków.';
    if (!isset($errors['street']) && strlen($street) > 100) $errors['street'] = 'Maksymalnie 100 znaków.';
    if (!isset($errors['municipality']) && strlen($municipality) > 100) $errors['municipality'] = 'Maksymalnie 100 znaków.';

    if (empty($errors)) {
        try {
            $stmt = $pdo->prepare("SELECT user_id, username FROM users WHERE email = ?");
            $stmt->execute([$email]);
            $user = $stmt->fetch();

            if (!$user) {
                die("Nie znaleziono użytkownika.");
            }

            $uid      = $user['user_id'];
            $username = $user['username'];
            $pdo->beginTransaction();

            $stmt = $pdo->prepare("UPDATE users SET city = ? WHERE user_id = ?");
            $stmt->execute([$city, $uid]);

            $stmt = $pdo->prepare(
                    "INSERT INTO addresses (user_id, country, state, postal_code, municipality, city, street, house_number) 
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?)"
            );
            $stmt->execute([$uid, $country, $state, $postal_code, $municipality, $city, $street, $house_number]);

            $pdo->commit();

            // Generuj token weryfikacyjny i wyślij email
            $token   = bin2hex(random_bytes(32));
            $expires = date('Y-m-d H:i:s', strtotime('+24 hours'));

            $stmt = $pdo->prepare(
                "UPDATE users SET verification_token = :token, token_expires_at = :expires WHERE user_id = :id"
            );
            $stmt->execute([':token' => $token, ':expires' => $expires, ':id' => $uid]);

            $emailSent = sendVerificationEmail($email, $username, $token);

            unset($_SESSION['email']);

            if ($emailSent) {
                $msg = 'Rejestracja przebiegła pomyślnie. Sprawdź swoją skrzynkę e-mail i kliknij link aktywacyjny.';
            } else {
                $msg = 'Konto zostało założone, ale nie udało się wysłać maila weryfikacyjnego. Skontaktuj się z administratorem lub użyj opcji ponownego wysłania.';
            }
            $success = true;

        } catch (PDOException $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            $msg = 'Wystąpił błąd podczas zapisu danych: ' . $e->getMessage();
        }
    }
}
?>
<!DOCTYPE html>
<html lang="pl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Rejestracja - Krok 2/2 | TwarzBlok</title>
    <link rel="stylesheet" href="assets/css/register.css">
</head>
<body>
<div class="auth-wrapper">
    <div class="auth-logo">TwarzBlok</div>
    <div class="auth-subtitle">Dokończ tworzenie profilu</div>

    <div class="auth-card">
        <?php if ($success): ?>
            <h2>✅ Konto zostało utworzone!</h2>
            <p class="text-muted font-size-13 m-bottom-15">Sprawdź skrzynkę e-mail, aby aktywować konto.</p>
            <div class="alert alert-success">
                <?php echo htmlspecialchars($msg); ?>
            </div>
            <a href="login.php" class="btn btn-success">Przejdź do logowania →</a>
            <p class="text-center text-muted font-size-13" style="margin-top:14px;">
                Nie dostałeś maila?
                <a href="auth/resend_verification.php" style="color:#338336;">Wyślij ponownie</a>
            </p>
        <?php else: ?>
            <h2>Dane adresowe</h2>
            <p class="text-muted font-size-13 m-bottom-15">Krok 2 z 2 – Twoja lokalizacja</p>

            <div class="step-indicator">
                <div class="step done"></div>
                <div class="step active"></div>
            </div>

            <?php if (!empty($msg)): ?>
                <div class="alert alert-error"><?php echo htmlspecialchars($msg); ?></div>
            <?php endif; ?>

            <form method="post" id="addressForm" novalidate>
                <div class="form-grid">
                    <!-- Kraj -->
                    <div class="form-group <?php echo isset($errors['country']) ? 'error' : ''; ?>">
                        <label for="country">Kraj <span class="required-mark">*</span></label>
                        <input id="country" name="country" type="text" class="form-input"
                               placeholder="Wpisz kraj"
                               value="<?php echo htmlspecialchars($_POST['country'] ?? ''); ?>" required>
                        <span class="error-msg"><?php echo $errors['country'] ?? ''; ?></span>
                    </div>

                    <!-- Województwo -->
                    <div class="form-group <?php echo isset($errors['state']) ? 'error' : ''; ?>">
                        <label for="state">Województwo</label>
                        <input id="state" name="state" type="text" class="form-input"
                               placeholder="Wpisz województwo"
                               value="<?php echo htmlspecialchars($_POST['state'] ?? ''); ?>">
                        <span class="error-msg"><?php echo $errors['state'] ?? ''; ?></span>
                    </div>

                    <!-- Kod pocztowy -->
                    <div class="form-group <?php echo isset($errors['postal_code']) ? 'error' : ''; ?>">
                        <label for="postal_code">Kod pocztowy <span class="required-mark">*</span></label>
                        <input id="postal_code" name="postal_code" type="text" class="form-input"
                               placeholder="np. 00-000"
                               value="<?php echo htmlspecialchars($_POST['postal_code'] ?? ''); ?>" required>
                        <span class="error-msg"><?php echo $errors['postal_code'] ?? ''; ?></span>
                    </div>

                    <!-- Gmina / Powiat -->
                    <div class="form-group <?php echo isset($errors['municipality']) ? 'error' : ''; ?>">
                        <label for="municipality">Gmina / Powiat</label>
                        <input id="municipality" name="municipality" type="text" class="form-input"
                               placeholder="Wpisz gminę lub powiat"
                               value="<?php echo htmlspecialchars($_POST['municipality'] ?? ''); ?>">
                        <span class="error-msg"><?php echo $errors['municipality'] ?? ''; ?></span>
                    </div>

                    <!-- Miasto -->
                    <div class="form-group <?php echo isset($errors['city']) ? 'error' : ''; ?>">
                        <label for="city">Miasto <span class="required-mark">*</span></label>
                        <input id="city" name="city" type="text" class="form-input"
                               placeholder="Wpisz miasto"
                               value="<?php echo htmlspecialchars($_POST['city'] ?? ''); ?>" required>
                        <span class="error-msg"><?php echo $errors['city'] ?? ''; ?></span>
                    </div>

                    <!-- Ulica -->
                    <div class="form-group <?php echo isset($errors['street']) ? 'error' : ''; ?>">
                        <label for="street">Ulica <span class="required-mark">*</span></label>
                        <input id="street" name="street" type="text" class="form-input"
                               placeholder="Wpisz ulicę"
                               value="<?php echo htmlspecialchars($_POST['street'] ?? ''); ?>" required>
                        <span class="error-msg"><?php echo $errors['street'] ?? ''; ?></span>
                    </div>

                    <!-- Numer domu / lokalu -->
                    <div class="form-group <?php echo isset($errors['house_number']) ? 'error' : ''; ?>">
                        <label for="house_number">Nr domu / lokalu <span class="required-mark">*</span></label>
                        <input id="house_number" name="house_number" type="text" class="form-input"
                               placeholder="np. 12 lub 12/4"
                               value="<?php echo htmlspecialchars($_POST['house_number'] ?? ''); ?>" required>
                        <span class="error-msg"><?php echo $errors['house_number'] ?? ''; ?></span>
                    </div>
                </div>

                <button type="submit" class="btn btn-primary">Zakończ rejestrację</button>
            </form>
        <?php endif; ?>
    </div>
</div>

<script>
    const form = document.getElementById('addressForm');
    if (form) {
        // Walidacja kodu pocztowego na żywo
        document.getElementById('postal_code').addEventListener('input', function() {
            const group = this.closest('.form-group');
            const errorSpan = group.querySelector('.error-msg');
            const val = this.value.trim();
            if (val.length > 0 && !/^\d{2}-\d{3}$/.test(val)) {
                group.classList.add('error');
                errorSpan.textContent = 'Format: XX-XXX (np. 00-000)';
            } else {
                group.classList.remove('error');
                errorSpan.textContent = '';
            }
        });

        // Walidacja numeru domu na żywo
        document.getElementById('house_number').addEventListener('input', function() {
            const group = this.closest('.form-group');
            const errorSpan = group.querySelector('.error-msg');
            const val = this.value.trim();
            if (val.length > 0 && !/^[1-9]\d{0,3}[a-zA-Z]?(\/\d{1,3}[a-zA-Z]?)?$/.test(val)) {
                group.classList.add('error');
                errorSpan.textContent = 'Podaj prawidłowy numer (np. 12 lub 12/4)';
            } else {
                group.classList.remove('error');
                errorSpan.textContent = '';
            }
        });

        // Walidacja przed wysłaniem
        form.addEventListener('submit', function(e) {
            let valid = true;
            const requiredFields = ['country', 'city', 'street', 'house_number', 'postal_code'];
            requiredFields.forEach(fieldId => {
                const el = document.getElementById(fieldId);
                const group = el.closest('.form-group');
                const errorSpan = group.querySelector('.error-msg');
                if (!el.value.trim()) {
                    group.classList.add('error');
                    errorSpan.textContent = 'To pole jest wymagane.';
                    valid = false;
                }
            });
            if (!valid) e.preventDefault();
        });
    }
</script>
</body>
</html>