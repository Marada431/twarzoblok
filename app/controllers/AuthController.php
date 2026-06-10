<?php
// app/controllers/AuthController.php

require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../app/models/UserModel.php';
require_once __DIR__ . '/../../includes/csrf.php';

class AuthController {
    private UserModel $userModel;

    public function __construct(PDO $pdo) {
        $this->userModel = new UserModel($pdo);
    }

    // ════════════════════════════════════════════════════════
    // LOGOWANIE
    // ════════════════════════════════════════════════════════

    public function showLogin(): void {
        $data = ['error' => '', 'old_input' => ''];
        require __DIR__ . '/../views/auth/login.view.php';
    }

    public function handleLogin(): void {
        verify_csrf_token($_POST['csrf_token'] ?? '');

        $input    = trim($_POST['login_input'] ?? '');
        $password = $_POST['password'] ?? '';
        $errors   = [];

        if (empty($input))    $errors[] = 'Podaj login, e-mail lub numer telefonu.';
        if (empty($password)) $errors[] = 'Podaj hasło.';

        if (empty($errors)) {
            $user = $this->userModel->findByLoginInput($input);

            if (!$user || !password_verify($password, $user['password_hash'])) {
                $errors[] = 'Nieprawidłowy login lub hasło.';
                error_log("Nieudana próba logowania dla: " . $input);
            } elseif (!empty($user['is_banned_permanent'])) {
                $reason = !empty($user['ban_reason'])
                    ? ' Powód: ' . $user['ban_reason'] : '';
                $errors[] = 'Twoje konto zostało permanentnie zablokowane.' . $reason;
            } elseif (!empty($user['banned_until'])
                      && strtotime($user['banned_until']) > time()) {
                $until = date('d.m.Y H:i', strtotime($user['banned_until']));
                $errors[] = "Twoje konto jest tymczasowo zablokowane do: {$until}.";
            } elseif ($user['status'] !== 'active') {
                $errors[] = 'Twoje konto nie jest aktywne. Skontaktuj się z administratorem.';
            }

            // ── MAIL: odkomentuj gdy weryfikacja email będzie włączona ──
            // elseif (!$user['is_verified']) {
            //     $errors[] = 'Potwierdź adres e-mail przed zalogowaniem.
            //                  Sprawdź swoją skrzynkę pocztową.';
            // }
        }

        if (!empty($errors)) {
            $data = ['error' => $errors[0], 'old_input' => $input];
            require __DIR__ . '/../views/auth/login.view.php';
            return;
        }

        // ── Sesja – ustawiamy wszystkie pola dla kompatybilności wstecznej ──
        session_regenerate_id(true);
        $_SESSION['user_id']    = $user['user_id'];
        $_SESSION['username']   = $user['username'];
        $_SESSION['first_name'] = $user['first_name'];
        $_SESSION['last_name']  = $user['last_name'];
        $_SESSION['email']      = $user['email'];
        $_SESSION['role']       = $user['role'] ?? 'user';
        $_SESSION['user_role']  = $user['role'] ?? 'user';
        $_SESSION['avatar_url'] = $user['avatar_url'] ?? null;
        $_SESSION['logged_in']  = true; // BC: stare pliki sprawdzają ten klucz

        $this->userModel->updateLastLogin($user['user_id']);

        header('Location: index.php');
        exit;
    }

    // ════════════════════════════════════════════════════════
    // REJESTRACJA
    // ════════════════════════════════════════════════════════

    public function showRegister(array $data = []): void {
        if (empty($data)) {
            $data = ['errors' => [], 'old' => [], 'step' => 1];
        }
        require __DIR__ . '/../views/auth/register.view.php';
    }

    public function handleRegisterStep1(): void {
        verify_csrf_token($_POST['csrf_token'] ?? '');

        $old = [
            'first_name'    => trim($_POST['first_name'] ?? ''),
            'last_name'     => trim($_POST['last_name'] ?? ''),
            'username'      => trim($_POST['username'] ?? ''),
            'email'         => trim($_POST['email'] ?? ''),
            'dob'           => trim($_POST['date_of_birth'] ?? ''),
            'gender'        => $_POST['gender'] ?? '',
            'privacy_level' => $_POST['privacy_level'] ?? 'public',
        ];

        $password  = $_POST['password'] ?? '';
        $password2 = $_POST['password_confirm'] ?? '';
        $errors    = [];

        // ── Imię i nazwisko ──────────────────────────────────
        if (empty($old['first_name']))
            $errors['first_name'] = 'Imię jest wymagane.';
        elseif (mb_strlen($old['first_name']) > 50)
            $errors['first_name'] = 'Imię może mieć maksymalnie 50 znaków.';

        if (empty($old['last_name']))
            $errors['last_name'] = 'Nazwisko jest wymagane.';
        elseif (mb_strlen($old['last_name']) > 50)
            $errors['last_name'] = 'Nazwisko może mieć maksymalnie 50 znaków.';

        // ── Nazwa użytkownika ────────────────────────────────
        if (empty($old['username'])) {
            $errors['username'] = 'Nazwa użytkownika jest wymagana.';
        } elseif (!preg_match('/^[a-zA-Z0-9_]{3,30}$/', $old['username'])) {
            $errors['username'] = 'Nazwa użytkownika może zawierać tylko litery (a-z, A-Z), cyfry i znak _, od 3 do 30 znaków.';
        } elseif ($this->userModel->isUsernameTaken($old['username'])) {
            $errors['username'] = 'Ta nazwa użytkownika jest już zajęta.';
        }

        // ── E-mail ───────────────────────────────────────────
        if (empty($old['email'])) {
            $errors['email'] = 'Adres e-mail jest wymagany.';
        } elseif (!filter_var($old['email'], FILTER_VALIDATE_EMAIL)) {
            $errors['email'] = 'Podaj prawidłowy adres e-mail.';
        } elseif ($this->userModel->isEmailTaken($old['email'])) {
            $errors['email'] = 'Ten adres e-mail jest już zarejestrowany.';
        }

        // ── Hasło ────────────────────────────────────────────
        if (empty($password)) {
            $errors['password'] = 'Hasło jest wymagane.';
        } elseif (strlen($password) < 8) {
            $errors['password'] = 'Hasło musi mieć co najmniej 8 znaków.';
        } elseif (!preg_match('/[A-Z]/', $password)) {
            $errors['password'] = 'Hasło musi zawierać co najmniej jedną wielką literę.';
        } elseif (!preg_match('/[0-9]/', $password)) {
            $errors['password'] = 'Hasło musi zawierać co najmniej jedną cyfrę.';
        } elseif ($password !== $password2) {
            $errors['password_confirm'] = 'Hasła nie są identyczne.';
        }

        // ── Data urodzenia ───────────────────────────────────
        if (empty($old['dob'])) {
            $errors['dob'] = 'Data urodzenia jest wymagana.';
        } else {
            try {
                $dob = new DateTime($old['dob']);
                $age = (new DateTime())->diff($dob)->y;
                if ($age < 13)  $errors['dob'] = 'Musisz mieć co najmniej 13 lat.';
                if ($age > 120) $errors['dob'] = 'Podana data urodzenia jest nieprawidłowa.';
            } catch (Exception) {
                $errors['dob'] = 'Nieprawidłowy format daty.';
            }
        }

        // ── Płeć ─────────────────────────────────────────────
        if (empty($old['gender']) || !in_array($old['gender'], ['M', 'F', 'Other'], true)) {
            $errors['gender'] = 'Wybierz płeć.';
        }

        if (!empty($errors)) {
            $data = ['errors' => $errors, 'old' => $old, 'step' => 1];
            require __DIR__ . '/../views/auth/register.view.php';
            return;
        }

        // Zapisz w sesji – NIE hashujemy tu; create() zrobi to sam
        $_SESSION['register_step1'] = array_merge($old, ['password' => $password]);

        $data = ['errors' => [], 'old' => [], 'step' => 2];
        require __DIR__ . '/../views/auth/register.view.php';
    }

    public function handleRegisterStep2(): void {
        verify_csrf_token($_POST['csrf_token'] ?? '');

        if (empty($_SESSION['register_step1'])) {
            header('Location: register.php');
            exit;
        }

        $old = [
            'phone'        => preg_replace('/[^0-9]/', '', trim($_POST['phone'] ?? '')),
            'city'         => trim($_POST['city'] ?? ''),
            'street'       => trim($_POST['street'] ?? ''),
            'house_number' => trim($_POST['house_number'] ?? ''),
            'postal_code'  => trim($_POST['postal_code'] ?? ''),
        ];

        $terms  = isset($_POST['terms_accepted']);
        $errors = [];

        // ── Telefon ──────────────────────────────────────────
        if (empty($old['phone'])) {
            $errors['phone'] = 'Numer telefonu jest wymagany.';
        } elseif (!preg_match('/^[0-9]{9}$/', $old['phone'])) {
            $errors['phone'] = 'Numer telefonu musi składać się z dokładnie 9 cyfr.';
        } elseif ($this->userModel->isPhoneTaken($old['phone'])) {
            $errors['phone'] = 'Ten numer telefonu jest już zarejestrowany.';
        }

        // ── Adres ────────────────────────────────────────────
        if (empty($old['city']))
            $errors['city'] = 'Miasto jest wymagane.';

        if (empty($old['street']))
            $errors['street'] = 'Ulica jest wymagana.';

        if (empty($old['house_number']))
            $errors['house_number'] = 'Numer domu/lokalu jest wymagany.';

        if (empty($old['postal_code'])) {
            $errors['postal_code'] = 'Kod pocztowy jest wymagany.';
        } elseif (!preg_match('/^\d{2}-\d{3}$/', $old['postal_code'])) {
            $errors['postal_code'] = 'Podaj kod pocztowy w formacie XX-XXX (np. 12-345).';
        }

        // ── Regulamin ────────────────────────────────────────
        if (!$terms) {
            $errors['terms'] = 'Musisz zaakceptować regulamin i politykę prywatności.';
        }

        if (!empty($errors)) {
            $old['terms_accepted'] = $terms ? '1' : '';
            $data = ['errors' => $errors, 'old' => $old, 'step' => 2];
            require __DIR__ . '/../views/auth/register.view.php';
            return;
        }

        // ── Zapis do bazy ────────────────────────────────────
        $step1             = $_SESSION['register_step1'];
        $verificationToken = bin2hex(random_bytes(32));

        try {
            $userId = $this->userModel->createWithAddress(
                [
                    'username'           => $step1['username'],
                    'email'              => $step1['email'],
                    'password'           => $step1['password'], // surowe – create() hashuje
                    'first_name'         => $step1['first_name'],
                    'last_name'          => $step1['last_name'],
                    'dob'                => $step1['dob'],
                    'gender'             => $step1['gender'],
                    'privacy_level'      => $step1['privacy_level'],
                    'phone'              => $old['phone'],
                    'city'               => $old['city'],
                    'verification_token' => $verificationToken,
                ],
                [
                    'city'         => $old['city'],
                    'street'       => $old['street'],
                    'house_number' => $old['house_number'],
                    'postal_code'  => $old['postal_code'],
                ]
            );
        } catch (PDOException $e) {
            error_log('AuthController::handleRegisterStep2 DB error: ' . $e->getMessage());
            $data = [
                'errors' => ['db' => 'Wystąpił błąd podczas rejestracji. Spróbuj ponownie.'],
                'old'    => $old,
                'step'   => 2,
            ];
            require __DIR__ . '/../views/auth/register.view.php';
            return;
        }

        unset($_SESSION['register_step1']);

        // ── MAIL: odkomentuj gdy będzie gotowy serwer SMTP ───────────────
        // $this->sendVerificationEmail($step1['email'], $verificationToken);
        // header('Location: login.php?registered=1');
        // exit;

        // ── Tymczasowo: zaloguj od razu po rejestracji ───────────────────
        session_regenerate_id(true);
        $user = $this->userModel->findById($userId);
        $_SESSION['user_id']    = $user['user_id'];
        $_SESSION['username']   = $user['username'];
        $_SESSION['first_name'] = $user['first_name'];
        $_SESSION['last_name']  = $user['last_name'];
        $_SESSION['email']      = $user['email'];
        $_SESSION['role']       = $user['role'] ?? 'user';
        $_SESSION['user_role']  = $user['role'] ?? 'user';
        $_SESSION['avatar_url'] = null;
        $_SESSION['logged_in']  = true;

        header('Location: index.php?welcome=1');
        exit;
    }

    // ════════════════════════════════════════════════════════
    // WERYFIKACJA EMAIL – gotowa metoda, tymczasowo nieużywana
    // ════════════════════════════════════════════════════════

    public function verifyEmail(): void {
        $token = trim($_GET['token'] ?? '');

        if (empty($token)) {
            header('Location: login.php?reason=invalid_token');
            exit;
        }

        $success = $this->userModel->verifyEmail($token);

        if ($success) {
            $_SESSION['verification_success'] = 'Konto zostało aktywowane. Możesz się teraz zalogować.';
            header('Location: login.php');
        } else {
            header('Location: login.php?reason=invalid_token');
        }
        exit;
    }

    // ── MAIL: cała metoda gotowa do użycia ───────────────────
    /*
    private function sendVerificationEmail(string $email, string $token): void {
        $base    = rtrim($_SERVER['HTTP_HOST'] ?? 'localhost', '/');
        $link    = 'http://' . $base . '/verify.php?token=' . $token;
        $subject = 'Potwierdź rejestrację w TwarzoBlok';
        $message = "
            <h2>Witaj w TwarzoBlok!</h2>
            <p>Kliknij poniższy link aby aktywować konto:</p>
            <p><a href=\"{$link}\">{$link}</a></p>
            <p>Link jest ważny przez 24 godziny.</p>
        ";
        $headers  = "MIME-Version: 1.0\r\n";
        $headers .= "Content-Type: text/html; charset=UTF-8\r\n";
        $headers .= "From: noreply@twarzoblok.pl\r\n";
        mail($email, $subject, $message, $headers);
    }
    */
}
