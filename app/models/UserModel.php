<?php
// app/models/UserModel.php
// Rzeczywista struktura tabeli users:
//   PK: user_id | hasło: password_hash | weryfikacja: is_verified
//   data ur.: dob | logowanie: last_login_at | płeć: M/F/Other

class UserModel {
    private PDO $pdo;

    public function __construct(PDO $pdo) {
        $this->pdo = $pdo;
    }

    // ── Wyszukiwanie ──────────────────────────────────────────

    // Obsługuje login przez email, numer telefonu lub username
    public function findByLoginInput(string $input): array|false {
        if (filter_var($input, FILTER_VALIDATE_EMAIL)) {
            $field = 'email';
        } elseif (preg_match('/^[0-9+\-\s()]+$/', $input)
                  && strlen(preg_replace('/[^0-9]/', '', $input)) >= 6) {
            $field = 'phone';
        } else {
            $field = 'username';
        }
        $stmt = $this->pdo->prepare(
            "SELECT * FROM users WHERE {$field} = ? LIMIT 1"
        );
        $stmt->execute([$input]);
        return $stmt->fetch(PDO::FETCH_ASSOC);
    }

    public function findByEmail(string $email): array|false {
        $stmt = $this->pdo->prepare(
            "SELECT * FROM users WHERE email = ? LIMIT 1"
        );
        $stmt->execute([$email]);
        return $stmt->fetch(PDO::FETCH_ASSOC);
    }

    public function findByUsername(string $username): array|false {
        $stmt = $this->pdo->prepare(
            "SELECT * FROM users WHERE username = ? LIMIT 1"
        );
        $stmt->execute([$username]);
        return $stmt->fetch(PDO::FETCH_ASSOC);
    }

    public function findById(int $id): array|false {
        $stmt = $this->pdo->prepare(
            "SELECT * FROM users WHERE user_id = ? LIMIT 1"
        );
        $stmt->execute([$id]);
        return $stmt->fetch(PDO::FETCH_ASSOC);
    }

    // ── Rejestracja ───────────────────────────────────────────

    public function create(array $data): int {
        $stmt = $this->pdo->prepare("
            INSERT INTO users (
                username,
                email,
                password_hash,
                first_name,
                last_name,
                dob,
                gender,
                phone,
                city,
                privacy_level,
                terms_accepted,
                terms_accepted_at,
                is_verified,
                verification_token,
                status
            ) VALUES (
                :username,
                :email,
                :password_hash,
                :first_name,
                :last_name,
                :dob,
                :gender,
                :phone,
                :city,
                :privacy_level,
                :terms_accepted,
                :terms_accepted_at,
                :is_verified,
                :verification_token,
                :status
            )
        ");

        $stmt->execute([
            ':username'           => $data['username'],
            ':email'              => $data['email'],
            ':password_hash'      => password_hash(
                                        $data['password'],
                                        PASSWORD_BCRYPT,
                                        ['cost' => 12]
                                     ),
            ':first_name'         => $data['first_name'],
            ':last_name'          => $data['last_name'],
            ':dob'                => $data['dob'],
            ':gender'             => $data['gender'],
            ':phone'              => $data['phone'],
            ':city'               => $data['city'] ?? '',
            ':privacy_level'      => $data['privacy_level'] ?? 'public',
            ':terms_accepted'     => 1,
            ':terms_accepted_at'  => date('Y-m-d H:i:s'),
            ':is_verified'        => 0,
            ':verification_token' => $data['verification_token'],
            ':status'             => 'active',
            // ── MAIL: gdy weryfikacja email będzie włączona ──
            // ':status'          => 'pending',
        ]);

        return (int)$this->pdo->lastInsertId();
    }

    // ── Weryfikacja email (gotowa, tymczasowo nieużywana) ─────

    public function verifyEmail(string $token): bool {
        $stmt = $this->pdo->prepare("
            UPDATE users
            SET is_verified = 1,
                verification_token = NULL,
                status = 'active'
            WHERE verification_token = ?
              AND is_verified = 0
        ");
        $stmt->execute([$token]);
        return $stmt->rowCount() > 0;
    }

    public function findByVerificationToken(string $token): array|false {
        $stmt = $this->pdo->prepare(
            "SELECT * FROM users WHERE verification_token = ? LIMIT 1"
        );
        $stmt->execute([$token]);
        return $stmt->fetch(PDO::FETCH_ASSOC);
    }

    // ── Status i bezpieczeństwo ───────────────────────────────

    public function isBanned(int $id): bool {
        $stmt = $this->pdo->prepare(
            "SELECT status, banned_until, is_banned_permanent
             FROM users WHERE user_id = ?"
        );
        $stmt->execute([$id]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$user) return true;
        if (in_array($user['status'], ['banned', 'blocked'], true)) return true;
        if (!empty($user['is_banned_permanent'])) return true;
        if (!empty($user['banned_until'])
            && strtotime($user['banned_until']) > time()) return true;

        return false;
    }

    public function updateLastLogin(int $id): void {
        $this->pdo->prepare(
            "UPDATE users SET last_login_at = NOW() WHERE user_id = ?"
        )->execute([$id]);
    }

    // ── Rejestracja z adresem (transakcja) ───────────────────

    public function createWithAddress(array $userData, array $addressData): int {
        $this->pdo->beginTransaction();
        try {
            $userId = $this->create($userData);
            $this->insertAddress($userId, $addressData);
            $this->pdo->commit();
            return $userId;
        } catch (PDOException $e) {
            $this->pdo->rollBack();
            throw $e;
        }
    }

    public function insertAddress(int $userId, array $data): void {
        $stmt = $this->pdo->prepare("
            INSERT INTO addresses
                (user_id, country, state, postal_code, municipality, city, street, house_number)
            VALUES
                (:user_id, :country, :state, :postal_code, :municipality, :city, :street, :house_number)
        ");
        $stmt->execute([
            ':user_id'      => $userId,
            ':country'      => $data['country']      ?? 'Polska',
            ':state'        => $data['state']         ?? '',
            ':postal_code'  => $data['postal_code'],
            ':municipality' => $data['municipality']  ?? '',
            ':city'         => $data['city'],
            ':street'       => $data['street'],
            ':house_number' => $data['house_number'],
        ]);
    }

    // ── Sprawdzenie unikalności ───────────────────────────────

    public function isEmailTaken(string $email): bool {
        return (bool)$this->findByEmail($email);
    }

    public function isUsernameTaken(string $username): bool {
        return (bool)$this->findByUsername($username);
    }

    public function isPhoneTaken(string $phone): bool {
        $stmt = $this->pdo->prepare(
            "SELECT user_id FROM users WHERE phone = ? LIMIT 1"
        );
        $stmt->execute([$phone]);
        return (bool)$stmt->fetch();
    }
}
