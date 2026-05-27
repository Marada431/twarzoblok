
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
            // Sprawdzenie unikalności
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
            // Sprawdzenie typu MIME
            $finfo = new finfo(FILEINFO_MIME_TYPE);
            $mime = $finfo->file($file['tmp_name']);
            if (!in_array($mime, ALLOWED_AVATAR_TYPES)) {
                $errors['avatar'] = 'Dozwolone formaty: JPG, PNG, WebP.';
            }

            // Sprawdzenie rozmiaru
            if ($file['size'] > MAX_AVATAR_SIZE) {
                $errors['avatar'] = 'Plik jest za duży. Maksymalny rozmiar: 2 MB.';
            }

            if (!isset($errors['avatar'])) {
                // Tworzenie katalogu jeśli nie istnieje
                if (!is_dir(AVATAR_UPLOAD_DIR)) {
                    mkdir(AVATAR_UPLOAD_DIR, 0755, true);
                }

                // Generowanie unikalnej nazwy
                $ext = pathinfo($file['name'], PATHINFO_EXTENSION);
                $filename = uniqid('avatar_', true) . '.' . $ext;
                $destination = AVATAR_UPLOAD_DIR . $filename;

                if (move_uploaded_file($file['tmp_name'], $destination)) {
                    // Ścieżka względna do zapisu w bazie
                    $avatar_path = 'upload_img/user_avatar/' . $filename;
                } else {
                    $errors['avatar'] = 'Nie udało się zapisać pliku.';
                }
            }
        }
    }

    // Jeśli brak błędów - zapis do bazy
    if (empty($errors)) {
        try {
            $password_hash = password_hash($password, PASSWORD_DEFAULT);
            $city = 'Default City';

            $stmt = $pdo->prepare(
                    "INSERT INTO users (username, password_hash, email, phone, first_name, last_name, dob, gender, avatar_url, bio, city, privacy_level) 
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)"
            );
            $stmt->execute([
                    $username,
                    $password_hash,
                    $email,
                    $phone,
                    $first_name,
                    $last_name,
                    $dob,
                    $_POST['gender'],
                    $avatar_path,
                    $bio,
                    $city,
                    $_POST['privacy_level']
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
    <title>Registration - Step 1/2</title>
    <style>
        :root {
            --bg: #f0f2f5;
            --card-bg: #ffffff;
            --primary: #4f46e5;
            --primary-hover: #4338ca;
            --text: #1e293b;
            --text-light: #64748b;
            --border: #e2e8f0;
            --error: #ef4444;
            --error-bg: #fef2f2;
            --radius: 12px;
            --shadow: 0 4px 6px -1px rgba(0,0,0,0.1), 0 2px 4px -2px rgba(0,0,0,0.1);
        }

        * { box-sizing: border-box; margin: 0; padding: 0; }

        body {
            font-family: 'Inter', system-ui, -apple-system, sans-serif;
            background: var(--bg);
            color: var(--text);
            display: flex;
            justify-content: center;
            align-items: center;
            min-height: 100vh;
            padding: 20px;
        }

        .container {
            background: var(--card-bg);
            border-radius: var(--radius);
            box-shadow: var(--shadow);
            padding: 40px;
            width: 100%;
            max-width: 640px;
        }

        h1 {
            font-size: 1.8rem;
            margin-bottom: 8px;
            color: var(--text);
        }
        .subtitle {
            color: var(--text-light);
            margin-bottom: 32px;
            font-size: 0.95rem;
        }
        .step-indicator {
            display: flex;
            gap: 8px;
            margin-bottom: 32px;
        }
        .step {
            height: 4px;
            flex: 1;
            background: var(--border);
            border-radius: 2px;
        }
        .step.active { background: var(--primary); }

        .form-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 20px;
        }
        .form-group { display: flex; flex-direction: column; gap: 6px; }
        .form-group.full { grid-column: 1 / -1; }

        label {
            font-size: 0.875rem;
            font-weight: 600;
            color: var(--text);
        }
        input, select, textarea {
            padding: 10px 14px;
            border: 1.5px solid var(--border);
            border-radius: 8px;
            font-size: 0.95rem;
            transition: border-color 0.2s, box-shadow 0.2s;
            background: #fff;
            font-family: inherit;
            width: 100%;
        }
        input:focus, select:focus, textarea:focus {
            outline: none;
            border-color: var(--primary);
            box-shadow: 0 0 0 3px rgba(79,70,229,0.1);
        }
        .error-msg {
            font-size: 0.8rem;
            color: var(--error);
            min-height: 1.2em;
        }
        .form-group.error input,
        .form-group.error select,
        .form-group.error textarea {
            border-color: var(--error);
            background: var(--error-bg);
        }

        .file-upload-area {
            border: 2px dashed var(--border);
            border-radius: 8px;
            padding: 24px;
            text-align: center;
            cursor: pointer;
            transition: border-color 0.2s, background 0.2s;
        }
        .file-upload-area:hover { border-color: var(--primary); }
        .file-upload-area.has-file { border-style: solid; border-color: #22c55e; background: #f0fdf4; }
        .file-upload-area .icon { font-size: 2rem; margin-bottom: 8px; }
        .file-upload-area .text { font-size: 0.9rem; color: var(--text-light); }
        .file-upload-area input[type="file"] { display: none; }

        .btn {
            background: var(--primary);
            color: #fff;
            border: none;
            padding: 12px 28px;
            border-radius: 8px;
            font-size: 1rem;
            font-weight: 600;
            cursor: pointer;
            transition: background 0.2s;
            width: 100%;
            margin-top: 24px;
        }
        .btn:hover { background: var(--primary-hover); }
        .btn:disabled { opacity: 0.6; cursor: not-allowed; }

        .alert {
            background: var(--error-bg);
            color: var(--error);
            padding: 12px 16px;
            border-radius: 8px;
            margin-bottom: 20px;
            font-size: 0.9rem;
        }

        .required-mark { color: var(--error); }

        @media (max-width: 500px) {
            .form-grid { grid-template-columns: 1fr; }
            .container { padding: 24px; }
        }
    </style>
</head>
<body>
<div class="container">
    <h1>Create Account</h1>
    <p class="subtitle">Step 1 of 2 – Basic Information</p>
    <div class="step-indicator">
        <div class="step active"></div>
        <div class="step"></div>
    </div>

    <?php if (!empty($errors['db'])): ?>
        <div class="alert"><?php echo htmlspecialchars($errors['db']); ?></div>
    <?php endif; ?>

    <form method="post" enctype="multipart/form-data" id="registrationForm" novalidate>
        <div class="form-grid">
            <!-- Login -->
            <div class="form-group <?php echo isset($errors['username']) ? 'error' : ''; ?>">
                <label for="username">Username <span class="required-mark">*</span></label>
                <input id="username" name="username" type="text" placeholder="Your username"
                       value="<?php echo htmlspecialchars($_POST['username'] ?? ''); ?>" required minlength="3" maxlength="50">
                <span class="error-msg" id="usernameError"><?php echo $errors['username'] ?? ''; ?></span>
            </div>

            <!-- Email -->
            <div class="form-group <?php echo isset($errors['email']) ? 'error' : ''; ?>">
                <label for="email">Email <span class="required-mark">*</span></label>
                <input id="email" name="email" type="email" placeholder="name@domain.com"
                       value="<?php echo htmlspecialchars($_POST['email'] ?? ''); ?>" required>
                <span class="error-msg" id="emailError"><?php echo $errors['email'] ?? ''; ?></span>
            </div>

            <!-- Password -->
            <div class="form-group <?php echo isset($errors['password']) ? 'error' : ''; ?>">
                <label for="password">Password <span class="required-mark">*</span></label>
                <input id="password" name="password" type="password" placeholder="Minimum 8 characters" required minlength="8">
                <span class="error-msg" id="passwordError"><?php echo $errors['password'] ?? ''; ?></span>
            </div>

            <!-- Phone -->
            <div class="form-group <?php echo isset($errors['phone']) ? 'error' : ''; ?>">
                <label for="phone">Phone</label>
                <input id="phone" name="phone" type="tel" placeholder="Digits only (max 9)"
                       value="<?php echo htmlspecialchars($_POST['phone'] ?? ''); ?>" pattern="[0-9]{0,9}">
                <span class="error-msg" id="phoneError"><?php echo $errors['phone'] ?? ''; ?></span>
            </div>

            <!-- First Name -->
            <div class="form-group <?php echo isset($errors['first_name']) ? 'error' : ''; ?>">
                <label for="first_name">First Name <span class="required-mark">*</span></label>
                <input id="first_name" name="first_name" type="text" placeholder="Your first name"
                       value="<?php echo htmlspecialchars($_POST['first_name'] ?? ''); ?>" required>
                <span class="error-msg" id="firstNameError"><?php echo $errors['first_name'] ?? ''; ?></span>
            </div>

            <!-- Last Name -->
            <div class="form-group <?php echo isset($errors['last_name']) ? 'error' : ''; ?>">
                <label for="last_name">Last Name <span class="required-mark">*</span></label>
                <input id="last_name" name="last_name" type="text" placeholder="Your last name"
                       value="<?php echo htmlspecialchars($_POST['last_name'] ?? ''); ?>" required>
                <span class="error-msg" id="lastNameError"><?php echo $errors['last_name'] ?? ''; ?></span>
            </div>

            <!-- Date of Birth -->
            <div class="form-group <?php echo isset($errors['dob']) ? 'error' : ''; ?>">
                <label for="dob">Date of Birth <span class="required-mark">*</span></label>
                <input id="dob" name="dob" type="date"
                       value="<?php echo htmlspecialchars($_POST['dob'] ?? ''); ?>" required>
                <span class="error-msg" id="dobError"><?php echo $errors['dob'] ?? ''; ?></span>
            </div>

            <!-- Gender -->
            <div class="form-group <?php echo isset($errors['gender']) ? 'error' : ''; ?>">
                <label for="gender">Gender <span class="required-mark">*</span></label>
                <select id="gender" name="gender" required>
                    <option value="">-- Select --</option>
                    <option value="M" <?php echo (isset($_POST['gender']) && $_POST['gender'] === 'M') ? 'selected' : ''; ?>>Male</option>
                    <option value="F" <?php echo (isset($_POST['gender']) && $_POST['gender'] === 'F') ? 'selected' : ''; ?>>Female</option>
                    <option value="Other" <?php echo (isset($_POST['gender']) && $_POST['gender'] === 'Other') ? 'selected' : ''; ?>>Other</option>
                </select>
                <span class="error-msg" id="genderError"><?php echo $errors['gender'] ?? ''; ?></span>
            </div>

            <!-- Privacy Level -->
            <div class="form-group <?php echo isset($errors['privacy_level']) ? 'error' : ''; ?>">
                <label for="privacy_level">Profile Privacy <span class="required-mark">*</span></label>
                <select id="privacy_level" name="privacy_level" required>
                    <option value="">-- Select --</option>
                    <option value="public" <?php echo (isset($_POST['privacy_level']) && $_POST['privacy_level'] === 'public') ? 'selected' : ''; ?>>Public</option>
                    <option value="private" <?php echo (isset($_POST['privacy_level']) && $_POST['privacy_level'] === 'private') ? 'selected' : ''; ?>>Private</option>
                </select>
                <span class="error-msg" id="privacyError"><?php echo $errors['privacy_level'] ?? ''; ?></span>
            </div>

            <!-- Avatar -->
            <div class="form-group full <?php echo isset($errors['avatar']) ? 'error' : ''; ?>">
                <label>Profile Picture</label>
                <div class="file-upload-area" id="avatarDropArea" onclick="document.getElementById('avatarInput').click()">
                    <div class="icon">📷</div>
                    <div class="text" id="avatarText">Click or drag & drop an image (JPG, PNG, WebP, max 2 MB)</div>
                    <input type="file" id="avatarInput" name="avatar" accept="image/jpeg,image/png,image/webp">
                </div>
                <span class="error-msg"><?php echo $errors['avatar'] ?? ''; ?></span>
            </div>

            <!-- Bio -->
            <div class="form-group full <?php echo isset($errors['bio']) ? 'error' : ''; ?>">
                <label for="bio">About Me</label>
                <textarea id="bio" name="bio" rows="3" placeholder="Short description (max 500 characters)" maxlength="500"><?php echo htmlspecialchars($_POST['bio'] ?? ''); ?></textarea>
                <span class="error-msg"><?php echo $errors['bio'] ?? ''; ?></span>
            </div>
        </div>

        <button type="submit" class="btn">Next →</button>
    </form>
</div>

<script>
    // Client-side validation
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

    // Username validation
    document.getElementById('username').addEventListener('input', function() {
        const val = this.value.trim();
        if (val.length > 0 && val.length < 3) {
            showFieldError('username', 'Username must be at least 3 characters.');
        } else if (!/^[a-zA-Z0-9_]*$/.test(val)) {
            showFieldError('username', 'Username can only contain letters, numbers and underscores.');
        } else {
            clearFieldError('username');
        }
    });

    // Email validation
    document.getElementById('email').addEventListener('input', function() {
        const val = this.value.trim();
        if (val.length > 0 && !/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(val)) {
            showFieldError('email', 'Please enter a valid email address.');
        } else {
            clearFieldError('email');
        }
    });

    // Password validation
    document.getElementById('password').addEventListener('input', function() {
        const val = this.value;
        if (val.length > 0 && val.length < 8) {
            showFieldError('password', 'Password must be at least 8 characters.');
        } else if (val.length >= 8 && !/[A-Z]/.test(val)) {
            showFieldError('password', 'Password must contain at least one uppercase letter.');
        } else if (val.length >= 8 && !/[a-z]/.test(val)) {
            showFieldError('password', 'Password must contain at least one lowercase letter.');
        } else if (val.length >= 8 && !/[0-9]/.test(val)) {
            showFieldError('password', 'Password must contain at least one digit.');
        } else {
            clearFieldError('password');
        }
    });

    // Phone validation
    document.getElementById('phone').addEventListener('input', function() {
        const val = this.value;
        if (val.length > 0 && !/^[0-9]{0,9}$/.test(val)) {
            showFieldError('phone', 'Phone number can only contain digits (max 9).');
        } else {
            clearFieldError('phone');
        }
    });

    // Date of birth validation (min. 13 years old)
    document.getElementById('dob').addEventListener('change', function() {
        if (this.value) {
            const bday = new Date(this.value);
            const today = new Date();
            let age = today.getFullYear() - bday.getFullYear();
            const m = today.getMonth() - bday.getMonth();
            if (m < 0 || (m === 0 && today.getDate() < bday.getDate())) age--;
            if (age < 13) {
                showFieldError('dob', 'You must be at least 13 years old.');
            } else {
                clearFieldError('dob');
            }
        }
    });

    // Avatar drag & drop handling
    const dropArea = document.getElementById('avatarDropArea');
    const avatarInput = document.getElementById('avatarInput');
    const avatarText = document.getElementById('avatarText');

    ['dragenter', 'dragover', 'dragleave', 'drop'].forEach(eventName => {
        dropArea.addEventListener(eventName, e => { e.preventDefault(); e.stopPropagation(); });
    });

    ['dragenter', 'dragover'].forEach(eventName => {
        dropArea.addEventListener(eventName, () => dropArea.style.borderColor = '#4f46e5');
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
            avatarText.textContent = 'Invalid format. Allowed: JPG, PNG, WebP.';
            dropArea.classList.remove('has-file');
            return;
        }
        if (file.size > 2 * 1024 * 1024) {
            avatarText.textContent = 'File is too large. Maximum size: 2 MB.';
            dropArea.classList.remove('has-file');
            return;
        }
        avatarText.textContent = `Selected: ${file.name} (${(file.size/1024).toFixed(1)} KB)`;
        dropArea.classList.add('has-file');
    }

    // Form submission validation
    form.addEventListener('submit', function(e) {
        let valid = true;
        const requiredFields = [
            { id: 'username', name: 'Username' },
            { id: 'email', name: 'Email' },
            { id: 'password', name: 'Password' },
            { id: 'first_name', name: 'First Name' },
            { id: 'last_name', name: 'Last Name' },
            { id: 'dob', name: 'Date of Birth' },
            { id: 'gender', name: 'Gender' },
            { id: 'privacy_level', name: 'Profile Privacy' }
        ];

        requiredFields.forEach(field => {
            const el = document.getElementById(field.id);
            if (!el.value.trim()) {
                showFieldError(field.id, `${field.name} is required.`);
                valid = false;
            }
        });

        // Additional password validation
        const pass = document.getElementById('password').value;
        if (pass && (pass.length < 8 || !/[A-Z]/.test(pass) || !/[a-z]/.test(pass) || !/[0-9]/.test(pass))) {
            showFieldError('password', 'Password does not meet requirements.');
            valid = false;
        }

        if (!valid) e.preventDefault();
    });
</script>
</body>
</html>