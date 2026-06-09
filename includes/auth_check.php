<?php
function check_user_status(PDO $pdo, int $user_id): void {
    try {
        $stmt = $pdo->prepare(
            "SELECT status, banned_until, is_banned_permanent FROM users WHERE user_id = ? LIMIT 1"
        );
        $stmt->execute([$user_id]);
        $user = $stmt->fetch();
    } catch (PDOException $e) {
        error_log('check_user_status error: ' . $e->getMessage());
        return;
    }

    if (!$user) {
        session_destroy();
        header('Location: /login.php?reason=banned');
        exit;
    }

    $blocked    = $user['status'] === 'banned' || $user['status'] === 'blocked';
    $perm_ban   = !empty($user['is_banned_permanent']);
    $temp_ban   = !empty($user['banned_until']) && strtotime($user['banned_until']) > time();

    if ($blocked || $perm_ban || $temp_ban) {
        session_destroy();
        header('Location: /login.php?reason=banned');
        exit;
    }
}
