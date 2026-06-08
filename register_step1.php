<?php
session_start();
require_once __DIR__ . '/config/database.php';

// Konfiguracja uploadu avatara
define('AVATAR_UPLOAD_DIR', __DIR__ . '/upload_img/user_avatar/');
define('MAX_AVATAR_SIZE', 2 * 1024 * 1024); // 2 MB
define('ALLOWED_AVATAR_TYPES', ['image/jpeg', 'image/png', 'image/webp']);

$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $pdo = db();

    // Sprawdzenie wymaganych pól
    $required = ['username', 'password', 'email', 'first_name', 'last_name', 'dob', 'gender', 'privacy_level'];
    foreach ($required as $field) {
        if (empty($_POST[$field])) {
            $errors[$field] = 'To pole jest wymagane.';
        }
    }

    // Walidacja username
    if (!isset($errors['username'])) {
        $username = trim($_POST['username']);
        if (strlen($username) < 3 || strlen($username) > 50) {
            $errors['username'] = 'Login musi mieć od 3 do 50 znaków.';
        } elseif (!preg_match('/^[a-zA-Z0-9_]+$/', $username)) {
            $errors['username'] = 'Login może zawierać tylko litery, cyfry i podkreślenia.';
        } else {
            $stmt = $pdo->prepare("SELECT user_id FROM users WHERE username = ?");
            $stmt->execute([$username]);
            if ($stmt->fetch()) {
                $errors['username'] = 'Ten login jest już zajęty.';
            }
        }
    }

    // Walidacja email
    if (!isset($errors['email'])) {
        $email = filter_var(trim($_POST['email']), FILTER_SANITIZE_EMAIL);
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $errors['email'] = 'Podaj prawidłowy adres email.';
        } else {
            $stmt = $pdo->prepare("SELECT user_id FROM users WHERE email = ?");
            $stmt->execute([$email]);
            if ($stmt->fetch()) {
                $errors['email'] = 'Ten email jest już zarejestrowany.';
            }
        }
    }

    // Walidacja hasła
    if (!isset($errors['password'])) {
        $password = $_POST['password'];
        if (strlen($password) < 8) {
            $errors['password'] = 'Hasło musi mieć minimum 8 znaków.';
        } elseif (!preg_match('/[A-Z]/', $password)) {
            $errors['password'] = 'Hasło musi zawierać przynajmniej jedną wielką literę.';
        } elseif (!preg_match('/[a-z]/', $password)) {
            $errors['password'] = 'Hasło musi zawierać przynajmniej jedną małą literę.';
        } elseif (!preg_match('/[0-9]/', $password)) {
            $errors['password'] = 'Hasło musi zawierać przynajmniej jedną cyfrę.';
        }
    }

    // Walidacja imienia i nazwiska
    if (!isset($errors['first_name'])) {
        $first_name = trim($_POST['first_name']);
        if (strlen($first_name) < 1 || strlen($first_name) > 50) {
            $errors['first_name'] = 'Imię musi mieć od 1 do 50 znaków.';
        }
    }
    if (!isset($errors['last_name'])) {
        $last_name = trim($_POST['last_name']);
        if (strlen($last_name) < 1 || strlen($last_name) > 50) {
            $errors['last_name'] = 'Nazwisko musi mieć od 1 do 50 znaków.';
        }
    }

    // Walidacja daty urodzenia (min. 13 lat)
    if (!isset($errors['dob'])) {
        $dob = $_POST['dob'];
        $bday = new DateTime($dob);
        $today = new DateTime();
        if ($today->diff($bday)->y < 13) {
            $errors['dob'] = 'Musisz mieć minimum 13 lat.';
        }
    }

    // Walidacja płci
    if (!isset($errors['gender'])) {
        $allowed_genders = ['M', 'F', 'Other'];
        if (!in_array($_POST['gender'], $allowed_genders)) {
            $errors['gender'] = 'Nieprawidłowa wartość.';
        }
    }

    // Walidacja telefonu (opcjonalne)
    $phone = null;
    if (!empty($_POST['phone'])) {
        $phone = trim($_POST['phone']);
        if (!preg_match('/^[0-9]{1,9}$/', $phone)) {
            $errors['phone'] = 'Numer telefonu może zawierać tylko cyfry (max 9).';
        }
    }

    // Walidacja poziomu prywatności
    if (!isset($errors['privacy_level'])) {
        $allowed_privacy = ['public', 'private'];
        if (!in_array($_POST['privacy_level'], $allowed_privacy)) {
            $errors['privacy_level'] = 'Nieprawidłowa wartość.';
        }
    }

    // Walidacja bio
    $bio = trim($_POST['bio'] ?? '');
    if (mb_strlen($bio) > 500) {
        $errors['bio'] = 'Opis może mieć maksymalnie 500 znaków.';
    }

    // Obsługa avatara
    $avatar_path = null;
    if (isset($_FILES['avatar']) && $_FILES['avatar']['error'] !== UPLOAD_ERR_NO_FILE) {
        $file = $_FILES['avatar'];

        if ($file['error'] !== UPLOAD_ERR_OK) {
            $errors['avatar'] = 'Błąd przesyłania pliku. Kod: ' . $file['error'];
        } else {
            $finfo = new finfo(FILEINFO_MIME_TYPE);
            $mime = $finfo->file($file['tmp_name']);
            if (!in_array($mime, ALLOWED_AVATAR_TYPES)) {
                $errors['avatar'] = 'Dozwolone formaty: JPG, PNG, WebP.';
            }

            if ($file['size'] > MAX_AVATAR_SIZE) {
                $errors['avatar'] = 'Plik jest za duży. Maksymalny rozmiar: 2 MB.';
            }

            if (!isset($errors['avatar'])) {
                if (!is_dir(AVATAR_UPLOAD_DIR)) {
                    mkdir(AVATAR_UPLOAD_DIR, 0755, true);
                }

                $ext = pathinfo($file['name'], PATHINFO_EXTENSION);
                $filename = uniqid('avatar_', true) . '.' . $ext;
                $destination = AVATAR_UPLOAD_DIR . $filename;

                if (move_uploaded_file($file['tmp_name'], $destination)) {
                    $avatar_path = 'upload_img/user_avatar/' . $filename;
                } else {
                    $errors['avatar'] = 'Nie udało się zapisać pliku.';
                }
            }
        }
    }

    if (empty($errors)) {
        try {
            $password_hash = password_hash($password, PASSWORD_DEFAULT);
            $city = 'Default City';

            $stmt = $pdo->prepare(
                    "INSERT INTO users (username, password_hash, email, phone, first_name, last_name, dob, gender, avatar_url, bio, city, privacy_level) 
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)"
            );
            $stmt->execute([
                    $username, $password_hash, $email, $phone, $first_name, $last_name,
                    $dob, $_POST['gender'], $avatar_path, $bio, $city, $_POST['privacy_level']
            ]);

            $_SESSION['email'] = $email;
            header('Location: register_step2.php');
            exit();
        } catch (PDOException $e) {
            $errors['db'] = "Błąd zapisu: " . $e->getMessage();
        }
    }
}
?>
<!DOCTYPE html>
<html lang="pl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Rejestracja - Krok 1/2 | TwarzBlok</title>
    <link rel="stylesheet" href="assets/css/register.css">
</head>
<body>
<div class="auth-wrapper">
    <div class="auth-logo">TwarzBlok</div>
    <div class="auth-subtitle">Utwórz swoje konto</div>

    <div class="auth-card">
        <h2>Podstawowe informacje</h2>
        <p class="text-muted font-size-13 m-bottom-15">Krok 1 z 2 – Wprowadź swoje dane</p>

        <div class="step-indicator">
            <div class="step active"></div>
            <div class="step"></div>
        </div>

        <?php if (!empty($errors['db'])): ?>
            <div class="alert alert-error"><?php echo htmlspecialchars($errors['db']); ?></div>
        <?php endif; ?>

        <form method="post" enctype="multipart/form-data" id="registrationForm" novalidate>
            <div class="form-grid">
                <!-- Login -->
                <div class="form-group <?php echo isset($errors['username']) ? 'error' : ''; ?>">
                    <label for="username">Login <span class="required-mark">*</span></label>
                    <input id="username" name="username" type="text" class="form-input"
                           placeholder="Twój login"
                           value="<?php echo htmlspecialchars($_POST['username'] ?? ''); ?>"
                           required minlength="3" maxlength="50">
                    <span class="error-msg"><?php echo $errors['username'] ?? ''; ?></span>
                </div>

                <!-- Email -->
                <div class="form-group <?php echo isset($errors['email']) ? 'error' : ''; ?>">
                    <label for="email">Email <span class="required-mark">*</span></label>
                    <input id="email" name="email" type="email" class="form-input"
                           placeholder="nazwa@domena.pl"
                           value="<?php echo htmlspecialchars($_POST['email'] ?? ''); ?>" required>
                    <span class="error-msg"><?php echo $errors['email'] ?? ''; ?></span>
                </div>

                <!-- Hasło -->
                <div class="form-group <?php echo isset($errors['password']) ? 'error' : ''; ?>">
                    <label for="password">Hasło <span class="required-mark">*</span></label>
                    <input id="password" name="password" type="password" class="form-input"
                           placeholder="Minimum 8 znaków" required minlength="8">
                    <span class="error-msg"><?php echo $errors['password'] ?? ''; ?></span>
                </div>

                <!-- Telefon -->
                <div class="form-group <?php echo isset($errors['phone']) ? 'error' : ''; ?>">
                    <label for="phone">Telefon</label>
                    <input id="phone" name="phone" type="tel" class="form-input"
                           placeholder="Tylko cyfry (max 9)"
                           value="<?php echo htmlspecialchars($_POST['phone'] ?? ''); ?>" pattern="[0-9]{0,9}">
                    <span class="error-msg"><?php echo $errors['phone'] ?? ''; ?></span>
                </div>

                <!-- Imię -->
                <div class="form-group <?php echo isset($errors['first_name']) ? 'error' : ''; ?>">
                    <label for="first_name">Imię <span class="required-mark">*</span></label>
                    <input id="first_name" name="first_name" type="text" class="form-input"
                           placeholder="Twoje imię"
                           value="<?php echo htmlspecialchars($_POST['first_name'] ?? ''); ?>" required>
                    <span class="error-msg"><?php echo $errors['first_name'] ?? ''; ?></span>
                </div>

                <!-- Nazwisko -->
                <div class="form-group <?php echo isset($errors['last_name']) ? 'error' : ''; ?>">
                    <label for="last_name">Nazwisko <span class="required-mark">*</span></label>
                    <input id="last_name" name="last_name" type="text" class="form-input"
                           placeholder="Twoje nazwisko"
                           value="<?php echo htmlspecialchars($_POST['last_name'] ?? ''); ?>" required>
                    <span class="error-msg"><?php echo $errors['last_name'] ?? ''; ?></span>
                </div>

                <!-- Data urodzenia -->
                <div class="form-group <?php echo isset($errors['dob']) ? 'error' : ''; ?>">
                    <label for="dob">Data urodzenia <span class="required-mark">*</span></label>
                    <input id="dob" name="dob" type="date" class="form-input"
                           value="<?php echo htmlspecialchars($_POST['dob'] ?? ''); ?>" required>
                    <span class="error-msg"><?php echo $errors['dob'] ?? ''; ?></span>
                </div>

                <!-- Płeć -->
                <div class="form-group <?php echo isset($errors['gender']) ? 'error' : ''; ?>">
                    <label for="gender">Płeć <span class="required-mark">*</span></label>
                    <select id="gender" name="gender" class="form-input" required>
                        <option value="">-- Wybierz --</option>
                        <option value="M" <?php echo (isset($_POST['gender']) && $_POST['gender'] === 'M') ? 'selected' : ''; ?>>Mężczyzna</option>
                        <option value="F" <?php echo (isset($_POST['gender']) && $_POST['gender'] === 'F') ? 'selected' : ''; ?>>Kobieta</option>
                        <option value="Other" <?php echo (isset($_POST['gender']) && $_POST['gender'] === 'Other') ? 'selected' : ''; ?>>Inna</option>
                    </select>
                    <span class="error-msg"><?php echo $errors['gender'] ?? ''; ?></span>
                </div>

                <!-- Prywatność -->
                <div class="form-group <?php echo isset($errors['privacy_level']) ? 'error' : ''; ?>">
                    <label for="privacy_level">Prywatność profilu <span class="required-mark">*</span></label>
                    <select id="privacy_level" name="privacy_level" class="form-input" required>
                        <option value="">-- Wybierz --</option>
                        <option value="public" <?php echo (isset($_POST['privacy_level']) && $_POST['privacy_level'] === 'public') ? 'selected' : ''; ?>>Publiczny</option>
                        <option value="private" <?php echo (isset($_POST['privacy_level']) && $_POST['privacy_level'] === 'private') ? 'selected' : ''; ?>>Prywatny</option>
                    </select>
                    <span class="error-msg"><?php echo $errors['privacy_level'] ?? ''; ?></span>
                </div>

                <!-- Avatar -->
                <div class="form-group full <?php echo isset($errors['avatar']) ? 'error' : ''; ?>">
                    <label>Zdjęcie profilowe</label>
                    <div class="file-upload-area" id="avatarDropArea" onclick="document.getElementById('avatarInput').click()">
                        <div class="icon">📷</div>
                        <div class="text" id="avatarText">Kliknij lub przeciągnij zdjęcie (JPG, PNG, WebP, max 2 MB)</div>
                        <input type="file" id="avatarInput" name="avatar" accept="image/jpeg,image/png,image/webp">
                    </div>
                    <span class="error-msg"><?php echo $errors['avatar'] ?? ''; ?></span>
                </div>

                <!-- O sobie -->
                <div class="form-group full <?php echo isset($errors['bio']) ? 'error' : ''; ?>">
                    <label for="bio">O sobie</label>
                    <textarea id="bio" name="bio" class="form-input" rows="3"
                              placeholder="Krótki opis (max 500 znaków)" maxlength="500"><?php echo htmlspecialchars($_POST['bio'] ?? ''); ?></textarea>
                    <span class="error-msg"><?php echo $errors['bio'] ?? ''; ?></span>
                </div>
            </div>

            <button type="submit" class="btn btn-primary">Dalej →</button>
        </form>
    </div>
</div>

<script>
    const form = document.getElementById('registrationForm');

    function showFieldError(fieldId, message) {
        const group = document.getElementById(fieldId).closest('.form-group');
        const errorSpan = group.querySelector('.error-msg');
        group.classList.add('error');
        if (errorSpan && !errorSpan.dataset.serverError) {
            errorSpan.textContent = message;
        }
    }

    function clearFieldError(fieldId) {
        const group = document.getElementById(fieldId).closest('.form-group');
        const errorSpan = group.querySelector('.error-msg');
        group.classList.remove('error');
        if (errorSpan && !errorSpan.dataset.serverError) {
            errorSpan.textContent = '';
        }
    }

    // Walidacja loginu
    document.getElementById('username').addEventListener('input', function() {
        const val = this.value.trim();
        if (val.length > 0 && val.length < 3) {
            showFieldError('username', 'Login musi mieć minimum 3 znaki.');
        } else if (!/^[a-zA-Z0-9_]*$/.test(val)) {
            showFieldError('username', 'Login może zawierać tylko litery, cyfry i podkreślenia.');
        } else {
            clearFieldError('username');
        }
    });

    // Walidacja email
    document.getElementById('email').addEventListener('input', function() {
        const val = this.value.trim();
        if (val.length > 0 && !/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(val)) {
            showFieldError('email', 'Podaj prawidłowy adres email.');
        } else {
            clearFieldError('email');
        }
    });

    // Walidacja hasła
    document.getElementById('password').addEventListener('input', function() {
        const val = this.value;
        if (val.length > 0 && val.length < 8) {
            showFieldError('password', 'Hasło musi mieć minimum 8 znaków.');
        } else if (val.length >= 8 && !/[A-Z]/.test(val)) {
            showFieldError('password', 'Hasło musi zawierać przynajmniej jedną wielką literę.');
        } else if (val.length >= 8 && !/[a-z]/.test(val)) {
            showFieldError('password', 'Hasło musi zawierać przynajmniej jedną małą literę.');
        } else if (val.length >= 8 && !/[0-9]/.test(val)) {
            showFieldError('password', 'Hasło musi zawierać przynajmniej jedną cyfrę.');
        } else {
            clearFieldError('password');
        }
    });

    // Walidacja telefonu
    document.getElementById('phone').addEventListener('input', function() {
        const val = this.value;
        if (val.length > 0 && !/^[0-9]{0,9}$/.test(val)) {
            showFieldError('phone', 'Numer telefonu może zawierać tylko cyfry (max 9).');
        } else {
            clearFieldError('phone');
        }
    });

    // Walidacja daty urodzenia
    document.getElementById('dob').addEventListener('change', function() {
        if (this.value) {
            const bday = new Date(this.value);
            const today = new Date();
            let age = today.getFullYear() - bday.getFullYear();
            const m = today.getMonth() - bday.getMonth();
            if (m < 0 || (m === 0 && today.getDate() < bday.getDate())) age--;
            if (age < 13) {
                showFieldError('dob', 'Musisz mieć minimum 13 lat.');
            } else {
                clearFieldError('dob');
            }
        }
    });

    // Obsługa avatara - drag & drop
    const dropArea = document.getElementById('avatarDropArea');
    const avatarInput = document.getElementById('avatarInput');
    const avatarText = document.getElementById('avatarText');

    ['dragenter', 'dragover', 'dragleave', 'drop'].forEach(eventName => {
        dropArea.addEventListener(eventName, e => { e.preventDefault(); e.stopPropagation(); });
    });

    ['dragenter', 'dragover'].forEach(eventName => {
        dropArea.addEventListener(eventName, () => dropArea.style.borderColor = '#338336');
    });

    ['dragleave', 'drop'].forEach(eventName => {
        dropArea.addEventListener(eventName, () => dropArea.style.borderColor = '');
    });

    dropArea.addEventListener('drop', e => {
        const files = e.dataTransfer.files;
        if (files.length) {
            avatarInput.files = files;
            updateAvatarPreview(files[0]);
        }
    });

    avatarInput.addEventListener('change', function() {
        if (this.files.length) {
            updateAvatarPreview(this.files[0]);
        }
    });

    function updateAvatarPreview(file) {
        const validTypes = ['image/jpeg', 'image/png', 'image/webp'];
        if (!validTypes.includes(file.type)) {
            avatarText.textContent = 'Nieprawidłowy format. Dozwolone: JPG, PNG, WebP.';
            dropArea.classList.remove('has-file');
            return;
        }
        if (file.size > 2 * 1024 * 1024) {
            avatarText.textContent = 'Plik jest za duży. Maksymalny rozmiar: 2 MB.';
            dropArea.classList.remove('has-file');
            return;
        }
        avatarText.textContent = 'Wybrano: ' + file.name + ' (' + (file.size/1024).toFixed(1) + ' KB)';
        dropArea.classList.add('has-file');
    }

    // Walidacja przed wysłaniem
    form.addEventListener('submit', function(e) {
        let valid = true;
        const requiredFields = [
            'username', 'email', 'password', 'first_name', 'last_name', 'dob', 'gender', 'privacy_level'
        ];

        requiredFields.forEach(id => {
            const el = document.getElementById(id);
            if (!el.value.trim()) {
                showFieldError(id, 'To pole jest wymagane.');
                valid = false;
            }
        });

        const pass = document.getElementById('password').value;
        if (pass && (pass.length < 8 || !/[A-Z]/.test(pass) || !/[a-z]/.test(pass) || !/[0-9]/.test(pass))) {
            showFieldError('password', 'Hasło nie spełnia wymagań.');
            valid = false;
        }

        if (!valid) e.preventDefault();
    });
</script>
</body>
</html>