<?php

class UserManager {
    private PDO $pdo;

    public function __construct(PDO $pdo) {
        $this->pdo = $pdo;
    }

    /**
     * Weryfikuje login (e-mail, username lub telefon) i hasło.
     * Zwraca wiersz użytkownika lub false przy błędzie.
     */
    public function login(string $identifier, string $password): array|false {
        if (filter_var($identifier, FILTER_VALIDATE_EMAIL)) {
            $field = 'email';
        } elseif (preg_match('/^[0-9+\-\s()]+$/', $identifier) && strlen(preg_replace('/[^0-9]/', '', $identifier)) >= 6) {
            $field = 'phone';
        } else {
            $field = 'username';
        }

        $stmt = $this->pdo->prepare(
            "SELECT user_id, username, email, phone, password_hash, first_name, last_name,
                    role, status, avatar_url, is_verified, banned_until, ban_reason, is_banned_permanent
             FROM users WHERE {$field} = ? LIMIT 1"
        );
        $stmt->execute([$identifier]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($user && password_verify($password, $user['password_hash'])) {
            return $user;
        }
        return false;
    }

    public function getById(int $id): array|false {
        $stmt = $this->pdo->prepare(
            "SELECT user_id, username, email, first_name, last_name, role, status,
                    avatar_url, bio, city, dob, gender, privacy_level, banned_until, is_banned_permanent
             FROM users WHERE user_id = ? LIMIT 1"
        );
        $stmt->execute([$id]);
        return $stmt->fetch(PDO::FETCH_ASSOC);
    }

    public function isBanned(int $id): bool {
        $stmt = $this->pdo->prepare(
            "SELECT status, banned_until, is_banned_permanent FROM users WHERE user_id = ? LIMIT 1"
        );
        $stmt->execute([$id]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$user) return true;
        return $user['status'] === 'banned'
            || $user['status'] === 'blocked'
            || !empty($user['is_banned_permanent'])
            || (!empty($user['banned_until']) && strtotime($user['banned_until']) > time());
    }

    public function updateLastLogin(int $id): void {
        $this->pdo->prepare("UPDATE users SET last_login_at = NOW() WHERE user_id = ?")
                  ->execute([$id]);
    }
}
