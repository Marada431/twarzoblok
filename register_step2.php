<?php
session_start();
require_once __DIR__ . '/config/database.php';

$msg = '';
$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!isset($_SESSION['email']) || empty($_SESSION['email'])) {
        die("Error: No session found. Please start registration from step 1.");
    }

    $email = $_SESSION['email'];
    $pdo = db();

    // Sanitize inputs
    $country = trim($_POST['country'] ?? '');
    $state = trim($_POST['state'] ?? '');
    $postal_code = trim($_POST['postal_code'] ?? '');
    $municipality = trim($_POST['municipality'] ?? '');
    $city = trim($_POST['city'] ?? '');
    $street = trim($_POST['street'] ?? '');
    $house_number = trim($_POST['house_number'] ?? '');

    // Sprawdzanie wymaganych pól
    $required = [
        'country' => 'Country',
        'city' => 'City',
        'street' => 'Street',
        'house_number' => 'House/Apartment Number',
        'postal_code' => 'Postal Code'
    ];

    foreach ($required as $field => $label) {
        if (empty($$field)) {
            $errors[$field] = "Field '$label' is required.";
        }
    }

    // Sprawdzenie formatu kody pocztowego
    if (!isset($errors['postal_code']) && !preg_match('/^\d{2}-\d{3}$/', $postal_code)) {
        $errors['postal_code'] = 'Postal code must be in XX-XXX format (e.g., 00-000).';
    }

    // Sprawdzenie numeru domu
    if (!isset($errors['house_number']) && !preg_match('/^[1-9]\d{0,3}[a-zA-Z]?(\/\d{1,3}[a-zA-Z]?)?$/', $house_number)) {
        $errors['house_number'] = 'Enter a valid house number (e.g., 12 or 12/4).';
    }

    // Walidacja długości pól
    if (!isset($errors['country']) && strlen($country) > 100) $errors['country'] = 'Maximum 100 characters.';
    if (!isset($errors['state']) && strlen($state) > 100) $errors['state'] = 'Maximum 100 characters.';
    if (!isset($errors['city']) && strlen($city) > 100) $errors['city'] = 'Maximum 100 characters.';
    if (!isset($errors['street']) && strlen($street) > 100) $errors['street'] = 'Maximum 100 characters.';
    if (!isset($errors['municipality']) && strlen($municipality) > 100) $errors['municipality'] = 'Maximum 100 characters.';

    if (empty($errors)) {
        try {
            // USer id z sesji
            $stmt = $pdo->prepare("SELECT user_id FROM users WHERE email = ?");
            $stmt->execute([$email]);
            $user = $stmt->fetch();

            if (!$user) {
                die("User not found.");
            }

            $uid = $user['user_id'];


            $pdo->beginTransaction();

            // Dodanie pola miasto do tabeli użytkownik
            $stmt = $pdo->prepare("UPDATE users SET city = ? WHERE user_id = ?");
            $stmt->execute([$city, $uid]);

            // Dodanie adresu
            $stmt = $pdo->prepare(
                "INSERT INTO addresses (user_id, country, state, postal_code, municipality, city, street, house_number) 
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?)"
            );
            $stmt->execute([$uid, $country, $state, $postal_code, $municipality, $city, $street, $house_number]);


            $pdo->commit();

            // Czyści sesje po udanym zajestrowaniu
            unset($_SESSION['email']);
            $msg = 'Registration completed successfully! You can now log in.';

        } catch (PDOException $e) {

            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $msg = 'An error occurred while saving data: ' . $e->getMessage();
        }
    }
}
?>
<!DOCTYPE html>
<html lang="pl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Registration - Step 2/2</title>
    <style>
        :root {
            --bg: #f0f2f5;
            --card-bg: #ffffff;
            --primary: #4f46e5;
            --primary-hover: #4338ca;
            --success: #22c55e;
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
            max-width: 600px;
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
        .step.done { background: var(--success); }

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
        input {
            padding: 10px 14px;
            border: 1.5px solid var(--border);
            border-radius: 8px;
            font-size: 0.95rem;
            transition: border-color 0.2s, box-shadow 0.2s;
            background: #fff;
            font-family: inherit;
            width: 100%;
        }
        input:focus {
            outline: none;
            border-color: var(--primary);
            box-shadow: 0 0 0 3px rgba(79,70,229,0.1);
        }
        .error-msg {
            font-size: 0.8rem;
            color: var(--error);
            min-height: 1.2em;
        }
        .form-group.error input {
            border-color: var(--error);
            background: var(--error-bg);
        }

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

        .success-msg {
            background: #f0fdf4;
            color: #166534;
            padding: 16px;
            border-radius: 8px;
            margin-bottom: 20px;
            font-size: 0.95rem;
            border: 1px solid #bbf7d0;
            text-align: center;
        }
        .error-alert {
            background: var(--error-bg);
            color: var(--error);
            padding: 12px 16px;
            border-radius: 8px;
            margin-bottom: 20px;
            font-size: 0.9rem;
        }
        .login-link {
            text-align: center;
            margin-top: 16px;
        }
        .login-link a {
            color: var(--primary);
            text-decoration: none;
            font-weight: 600;
        }
        .login-link a:hover { text-decoration: underline; }

        .required-mark { color: var(--error); }

        @media (max-width: 500px) {
            .form-grid { grid-template-columns: 1fr; }
            .container { padding: 24px; }
        }
    </style>
</head>
<body>
<div class="container">
    <h1>Address Information</h1>
    <p class="subtitle">Step 2 of 2 – Complete Your Profile</p>
    <div class="step-indicator">
        <div class="step done"></div>
        <div class="step active"></div>
    </div>

    <?php if (!empty($msg) && empty($errors)): ?>
        <div class="success-msg">
            <?php echo htmlspecialchars($msg); ?>
        </div>
        <div class="login-link">
            <a href="login.php">Go to Login →</a>
        </div>
    <?php elseif (!empty($msg) && !empty($errors)): ?>
        <div class="error-alert">
            <?php echo htmlspecialchars($msg); ?>
        </div>
    <?php endif; ?>

    <?php if (empty($msg) || !empty($errors)): ?>
        <form method="post" id="addressForm" novalidate>
            <div class="form-grid">
                <!-- Country -->
                <div class="form-group <?php echo isset($errors['country']) ? 'error' : ''; ?>">
                    <label for="country">Country <span class="required-mark">*</span></label>
                    <input id="country" name="country" type="text" placeholder="Enter country"
                           value="<?php echo htmlspecialchars($_POST['country'] ?? ''); ?>" required>
                    <span class="error-msg"><?php echo $errors['country'] ?? ''; ?></span>
                </div>

                <!-- State/Province -->
                <div class="form-group <?php echo isset($errors['state']) ? 'error' : ''; ?>">
                    <label for="state">State/Province</label>
                    <input id="state" name="state" type="text" placeholder="Enter state or province"
                           value="<?php echo htmlspecialchars($_POST['state'] ?? ''); ?>">
                    <span class="error-msg"><?php echo $errors['state'] ?? ''; ?></span>
                </div>

                <!-- Postal Code -->
                <div class="form-group <?php echo isset($errors['postal_code']) ? 'error' : ''; ?>">
                    <label for="postal_code">Postal Code <span class="required-mark">*</span></label>
                    <input id="postal_code" name="postal_code" type="text" placeholder="e.g., 00-000"
                           value="<?php echo htmlspecialchars($_POST['postal_code'] ?? ''); ?>" required>
                    <span class="error-msg"><?php echo $errors['postal_code'] ?? ''; ?></span>
                </div>

                <!-- Municipality/County -->
                <div class="form-group <?php echo isset($errors['municipality']) ? 'error' : ''; ?>">
                    <label for="municipality">Municipality/County</label>
                    <input id="municipality" name="municipality" type="text" placeholder="Enter municipality"
                           value="<?php echo htmlspecialchars($_POST['municipality'] ?? ''); ?>">
                    <span class="error-msg"><?php echo $errors['municipality'] ?? ''; ?></span>
                </div>

                <!-- City -->
                <div class="form-group <?php echo isset($errors['city']) ? 'error' : ''; ?>">
                    <label for="city">City <span class="required-mark">*</span></label>
                    <input id="city" name="city" type="text" placeholder="Enter city"
                           value="<?php echo htmlspecialchars($_POST['city'] ?? ''); ?>" required>
                    <span class="error-msg"><?php echo $errors['city'] ?? ''; ?></span>
                </div>

                <!-- Street -->
                <div class="form-group <?php echo isset($errors['street']) ? 'error' : ''; ?>">
                    <label for="street">Street <span class="required-mark">*</span></label>
                    <input id="street" name="street" type="text" placeholder="Enter street name"
                           value="<?php echo htmlspecialchars($_POST['street'] ?? ''); ?>" required>
                    <span class="error-msg"><?php echo $errors['street'] ?? ''; ?></span>
                </div>

                <!-- House/Apartment Number -->
                <div class="form-group <?php echo isset($errors['house_number']) ? 'error' : ''; ?>">
                    <label for="house_number">House/Apartment No. <span class="required-mark">*</span></label>
                    <input id="house_number" name="house_number" type="text" placeholder="e.g., 12 or 12/4"
                           value="<?php echo htmlspecialchars($_POST['house_number'] ?? ''); ?>" required>
                    <span class="error-msg"><?php echo $errors['house_number'] ?? ''; ?></span>
                </div>
            </div>

            <button type="submit" class="btn">Complete Registration</button>
        </form>
    <?php endif; ?>
</div>

<script>
    const form = document.getElementById('addressForm');
    if (form) {
        // Postal code validation (live)
        document.getElementById('postal_code').addEventListener('input', function() {
            const group = this.closest('.form-group');
            const errorSpan = group.querySelector('.error-msg');
            const val = this.value.trim();

            if (val.length > 0 && !/^\d{2}-\d{3}$/.test(val)) {
                group.classList.add('error');
                errorSpan.textContent = 'Format: XX-XXX (e.g., 00-000)';
            } else {
                group.classList.remove('error');
                errorSpan.textContent = '';
            }
        });

        // House number validation (live)
        document.getElementById('house_number').addEventListener('input', function() {
            const group = this.closest('.form-group');
            const errorSpan = group.querySelector('.error-msg');
            const val = this.value.trim();

            if (val.length > 0 && !/^[1-9]\d{0,3}[a-zA-Z]?(\/\d{1,3}[a-zA-Z]?)?$/.test(val)) {
                group.classList.add('error');
                errorSpan.textContent = 'Enter a valid number (e.g., 12 or 12/4)';
            } else {
                group.classList.remove('error');
                errorSpan.textContent = '';
            }
        });

        // Form submission validation
        form.addEventListener('submit', function(e) {
            let valid = true;
            const requiredFields = ['country', 'city', 'street', 'house_number', 'postal_code'];

            requiredFields.forEach(fieldId => {
                const el = document.getElementById(fieldId);
                const group = el.closest('.form-group');
                const errorSpan = group.querySelector('.error-msg');

                if (!el.value.trim()) {
                    group.classList.add('error');
                    errorSpan.textContent = 'This field is required.';
                    valid = false;
                }
            });

            if (!valid) e.preventDefault();
        });
    }
</script>
</body>
</html>