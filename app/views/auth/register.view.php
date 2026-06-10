<?php if (session_status() === PHP_SESSION_NONE) session_start(); ?>
<!DOCTYPE html>
<html lang="pl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Rejestracja – TwarzoBlok</title>
    <link rel="stylesheet" href="assets/css/auth.css">
</head>
<body>

<div class="auth-wrapper auth-wrapper--wide">
    <div class="auth-logo">TwarzoBlok</div>

    <div class="card">
        <h2>Utwórz konto</h2>
        <p>Dołącz do społeczności</p>

        <?php
        $errors  = $data['errors'] ?? [];
        $old     = $data['old']    ?? [];
        $step    = $data['step']   ?? 1;

        $e  = fn(string $field) => isset($errors[$field])
            ? '<span class="field-error">'
              . htmlspecialchars($errors[$field], ENT_QUOTES, 'UTF-8')
              . '</span>'
            : '';

        $v  = fn(string $field) => htmlspecialchars(
            $old[$field] ?? '', ENT_QUOTES, 'UTF-8'
        );

        $ec = fn(string $field) => isset($errors[$field]) ? 'input-error' : '';
        ?>

        <?php if (isset($errors['db'])): ?>
            <div class="alert-error" role="alert">
                <?= htmlspecialchars($errors['db'], ENT_QUOTES, 'UTF-8') ?>
            </div>
        <?php endif; ?>

        <!-- Wskaźnik postępu -->
        <div class="register-steps">
            <div class="step <?= $step >= 1 ? 'step--active' : '' ?>">
                <span class="step-number">1</span>
                <span class="step-label">Dane konta</span>
            </div>
            <div class="step-connector"></div>
            <div class="step <?= $step >= 2 ? 'step--active' : '' ?>">
                <span class="step-number">2</span>
                <span class="step-label">Dane kontaktowe</span>
            </div>
        </div>

        <form method="POST" action="register.php" novalidate>
            <input type="hidden" name="csrf_token"
                   value="<?= generate_csrf_token() ?>">

            <?php if ($step === 1): ?>

                <input type="hidden" name="step" value="1">

                <!-- Imię i Nazwisko -->
                <div class="form-row">
                    <div class="form-group">
                        <label for="first_name">Imię *</label>
                        <input type="text" id="first_name" name="first_name"
                               class="form-input <?= $ec('first_name') ?>"
                               value="<?= $v('first_name') ?>"
                               placeholder="Jan" required>
                        <?= $e('first_name') ?>
                    </div>
                    <div class="form-group">
                        <label for="last_name">Nazwisko *</label>
                        <input type="text" id="last_name" name="last_name"
                               class="form-input <?= $ec('last_name') ?>"
                               value="<?= $v('last_name') ?>"
                               placeholder="Kowalski" required>
                        <?= $e('last_name') ?>
                    </div>
                </div>

                <!-- Nazwa użytkownika -->
                <div class="form-group">
                    <label for="username">Nazwa użytkownika *</label>
                    <input type="text" id="username" name="username"
                           class="form-input <?= $ec('username') ?>"
                           value="<?= $v('username') ?>"
                           placeholder="jan_kowalski"
                           pattern="[a-zA-Z0-9_]{3,30}" required>
                    <span class="field-hint">3–30 znaków: litery, cyfry i podkreślnik _</span>
                    <?= $e('username') ?>
                </div>

                <!-- Email -->
                <div class="form-group">
                    <label for="email">Adres e-mail *</label>
                    <input type="email" id="email" name="email"
                           class="form-input <?= $ec('email') ?>"
                           value="<?= $v('email') ?>"
                           placeholder="jan@example.com" required>
                    <?= $e('email') ?>
                </div>

                <!-- Hasła -->
                <div class="form-row">
                    <div class="form-group">
                        <label for="password">Hasło *</label>
                        <div class="input-with-toggle">
                            <input type="password" id="password" name="password"
                                   class="form-input <?= $ec('password') ?>"
                                   placeholder="Min. 8 znaków"
                                   autocomplete="new-password" required>
                            <button type="button" class="toggle-password"
                                    aria-label="Pokaż hasło">👁</button>
                        </div>
                        <div class="password-strength-wrapper">
                            <div class="password-strength-track">
                                <div class="password-strength-bar" id="passwordStrength"></div>
                            </div>
                            <span class="field-hint">Min. 8 znaków, wielka litera, cyfra</span>
                        </div>
                        <?= $e('password') ?>
                    </div>
                    <div class="form-group">
                        <label for="password_confirm">Powtórz hasło *</label>
                        <div class="input-with-toggle">
                            <input type="password" id="password_confirm"
                                   name="password_confirm"
                                   class="form-input <?= $ec('password_confirm') ?>"
                                   placeholder="Powtórz hasło"
                                   autocomplete="new-password" required>
                            <button type="button" class="toggle-password"
                                    aria-label="Pokaż hasło">👁</button>
                        </div>
                        <?= $e('password_confirm') ?>
                    </div>
                </div>

                <!-- Data urodzenia i Płeć -->
                <div class="form-row">
                    <div class="form-group">
                        <label for="date_of_birth">Data urodzenia *</label>
                        <input type="date" id="date_of_birth"
                               name="date_of_birth"
                               class="form-input <?= $ec('dob') ?>"
                               value="<?= $v('dob') ?>"
                               max="<?= date('Y-m-d', strtotime('-13 years')) ?>"
                               required>
                        <?= $e('dob') ?>
                    </div>
                    <div class="form-group">
                        <label for="gender">Płeć *</label>
                        <select id="gender" name="gender"
                                class="form-input <?= $ec('gender') ?>"
                                required>
                            <option value="">Wybierz...</option>
                            <option value="M"
                                <?= ($old['gender'] ?? '') === 'M' ? 'selected' : '' ?>>
                                Mężczyzna
                            </option>
                            <option value="F"
                                <?= ($old['gender'] ?? '') === 'F' ? 'selected' : '' ?>>
                                Kobieta
                            </option>
                            <option value="Other"
                                <?= ($old['gender'] ?? '') === 'Other' ? 'selected' : '' ?>>
                                Inna
                            </option>
                        </select>
                        <?= $e('gender') ?>
                    </div>
                </div>

                <!-- Prywatność profilu -->
                <div class="form-group">
                    <label for="privacy_level">Widoczność profilu</label>
                    <select id="privacy_level" name="privacy_level"
                            class="form-input">
                        <option value="public"
                            <?= (($old['privacy_level'] ?? 'public') === 'public') ? 'selected' : '' ?>>
                            Publiczny
                        </option>
                        <option value="private"
                            <?= (($old['privacy_level'] ?? '') === 'private') ? 'selected' : '' ?>>
                            Prywatny
                        </option>
                    </select>
                </div>

                <button type="submit" class="btn-primary">
                    Dalej →
                </button>

            <?php else: ?>

                <input type="hidden" name="step" value="2">

                <!-- Telefon -->
                <div class="form-group">
                    <label for="phone">Numer telefonu *</label>
                    <input type="tel" id="phone" name="phone"
                           class="form-input <?= $ec('phone') ?>"
                           value="<?= $v('phone') ?>"
                           maxlength="9"
                           pattern="[0-9]{9}"
                           inputmode="numeric"
                           placeholder="123456789"
                           required>
                    <span class="field-hint">Podaj 9 cyfr bez spacji i myślników</span>
                    <?= $e('phone') ?>
                </div>

                <!-- Miasto i kod pocztowy -->
                <div class="form-row">
                    <div class="form-group">
                        <label for="city">Miasto *</label>
                        <input type="text" id="city" name="city"
                               class="form-input <?= $ec('city') ?>"
                               value="<?= $v('city') ?>"
                               placeholder="Warszawa" required>
                        <?= $e('city') ?>
                    </div>
                    <div class="form-group">
                        <label for="postal_code">Kod pocztowy *</label>
                        <input type="text" id="postal_code" name="postal_code"
                               class="form-input <?= $ec('postal_code') ?>"
                               value="<?= $v('postal_code') ?>"
                               placeholder="00-000"
                               pattern="\d{2}-\d{3}"
                               maxlength="6" required>
                        <?= $e('postal_code') ?>
                    </div>
                </div>

                <!-- Ulica i numer -->
                <div class="form-row">
                    <div class="form-group">
                        <label for="street">Ulica *</label>
                        <input type="text" id="street" name="street"
                               class="form-input <?= $ec('street') ?>"
                               value="<?= $v('street') ?>"
                               placeholder="Kwiatowa" required>
                        <?= $e('street') ?>
                    </div>
                    <div class="form-group">
                        <label for="house_number">Numer domu / lokalu *</label>
                        <input type="text" id="house_number" name="house_number"
                               class="form-input <?= $ec('house_number') ?>"
                               value="<?= $v('house_number') ?>"
                               placeholder="12 lub 12/4" required>
                        <?= $e('house_number') ?>
                    </div>
                </div>

                <!-- Regulamin -->
                <div class="terms-box">
                    <div class="terms-scroll" id="termsContent">
                        <?php require __DIR__ . '/terms.view.php'; ?>
                    </div>
                </div>

                <div class="form-group form-group--checkbox">
                    <label class="checkbox-label <?= isset($errors['terms']) ? 'checkbox-label--error' : '' ?>">
                        <input type="checkbox" name="terms_accepted" id="terms_accepted"
                               <?= !empty($old['terms_accepted']) ? 'checked' : '' ?>>
                        <span>
                            Przeczytałem/am i akceptuję
                            <a href="#" target="_blank">Regulamin</a>
                            oraz
                            <a href="#" target="_blank">Politykę Prywatności</a>
                            serwisu TwarzoBlok. *
                        </span>
                    </label>
                    <?= $e('terms') ?>
                </div>

                <div class="register-step2-actions">
                    <a href="register.php?back=1" class="btn-secondary">
                        ← Wróć
                    </a>
                    <button type="submit" class="btn-primary">
                        Utwórz konto
                    </button>
                </div>

            <?php endif; ?>

            <p class="form-note">* Pola obowiązkowe</p>
        </form>

        <div class="auth-links">
            <p>Masz już konto?
               <a href="login.php">Zaloguj się</a>
            </p>
        </div>
    </div>

    <div class="auth-footer">
        &copy; <?= date('Y') ?> TwarzoBlok – Łączy nas zieleń
    </div>
</div>

<script src="assets/js/auth.js"></script>
</body>
</html>
