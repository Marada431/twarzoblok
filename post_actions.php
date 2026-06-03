<?php
session_start();
require_once 'config/database.php';

if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
    header('Location: login.php');
    exit;
}

$current_user_id = (int) $_SESSION['user_id'];

function createNotification(PDO $pdo, int $user_id, int $actor_id, string $type, int $reference_id): void {
    if ($user_id === $actor_id) return;
    try {
        $stmt = $pdo->prepare("
            SELECT notification_id, `count` FROM notifications
            WHERE user_id = :uid AND type = :type AND reference_id = :ref AND status = 'unread'
              AND updated_at >= DATE_SUB(NOW(), INTERVAL 30 MINUTE)
            LIMIT 1
        ");
        $stmt->execute([':uid' => $user_id, ':type' => $type, ':ref' => $reference_id]);
        $existing = $stmt->fetch();

        if ($existing) {
            $pdo->prepare("UPDATE notifications SET `count` = `count` + 1, updated_at = NOW() WHERE notification_id = :nid")
                ->execute([':nid' => $existing['notification_id']]);
        } else {
            $pdo->prepare("INSERT INTO notifications (user_id, actor_id, type, reference_id, `count`, updated_at, created_at)
                           VALUES (:uid, :actor, :type, :ref, 1, NOW(), NOW())")
                ->execute([':uid' => $user_id, ':actor' => $actor_id, ':type' => $type, ':ref' => $reference_id]);
        }
    } catch (PDOException $e) {
        // Powiadomienia nie są krytyczne
    }
}

function relativeTime(string $datetime): string {
    $diff = time() - strtotime($datetime);
    if ($diff < 60)    return 'przed chwilą';
    if ($diff < 3600)  return (int)($diff/60) . ' min temu';
    if ($diff < 86400) return (int)($diff/3600) . ' godz. temu';
    return date('d.m.Y', strtotime($datetime));
}

// --- OBSŁUGA FORMULARZY POST ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    $action  = $_POST['action'];
    $post_id = (int)($_POST['post_id'] ?? 0);

    // --- DODAWANIE KOMENTARZA ---
    if ($action === 'comment') {
        header('Content-Type: application/json');

        $comment_content    = trim($_POST['comment_content'] ?? '');
        $parent_comment_id  = isset($_POST['parent_comment_id']) && (int)$_POST['parent_comment_id'] > 0
                                ? (int)$_POST['parent_comment_id'] : null;
        $reply_to_user_id   = isset($_POST['reply_to_user_id']) && (int)$_POST['reply_to_user_id'] > 0
                                ? (int)$_POST['reply_to_user_id'] : null;

        if (empty($comment_content) || $post_id <= 0) {
            echo json_encode(['success' => false, 'message' => 'Brak treści lub ID posta.']);
            exit;
        }

        try {
            $pdo = db();

            // Jeśli odpowiedź na odpowiedź (głębokość 3+) — spłaszczamy do poziomu 2
            if ($parent_comment_id) {
                $parent_check = $pdo->prepare("SELECT parent_comment_id FROM comments WHERE comment_id = :cid");
                $parent_check->execute([':cid' => $parent_comment_id]);
                $parent_row = $parent_check->fetch();
                if ($parent_row && $parent_row['parent_comment_id'] !== null) {
                    // Rodzic sam jest odpowiedzią → spłaszczamy: używamy tego samego parent
                    $parent_comment_id = (int)$parent_row['parent_comment_id'];
                }
            }

            $stmt = $pdo->prepare("
                INSERT INTO comments (post_id, author_id, content, parent_comment_id, reply_to_user_id, created_at)
                VALUES (:pid, :uid, :content, :parent, :reply_to, NOW())
            ");
            $stmt->execute([
                ':pid'      => $post_id,
                ':uid'      => $current_user_id,
                ':content'  => $comment_content,
                ':parent'   => $parent_comment_id,
                ':reply_to' => $reply_to_user_id,
            ]);
            $new_comment_id = (int)$pdo->lastInsertId();

            $user_stmt = $pdo->prepare("SELECT first_name, last_name, avatar_url FROM users WHERE user_id = :uid");
            $user_stmt->execute([':uid' => $current_user_id]);
            $user_data = $user_stmt->fetch();

            $reply_to_username = null;
            if ($reply_to_user_id) {
                $ru = $pdo->prepare("SELECT username FROM users WHERE user_id = :uid");
                $ru->execute([':uid' => $reply_to_user_id]);
                $reply_to_username = $ru->fetchColumn() ?: null;
            }

            // Powiadomienia
            if ($parent_comment_id) {
                // Odpowiedź: powiadom autora komentarza nadrzędnego
                $pa = $pdo->prepare("SELECT author_id FROM comments WHERE comment_id = :cid");
                $pa->execute([':cid' => $parent_comment_id]);
                $parent_author_id = (int)$pa->fetchColumn();
                createNotification($pdo, $parent_author_id, $current_user_id, 'comment_reply', $new_comment_id);
            } else {
                // Nowy komentarz: powiadom autora posta
                $pa = $pdo->prepare("SELECT author_id FROM posts WHERE post_id = :pid");
                $pa->execute([':pid' => $post_id]);
                $post_author_id = (int)$pa->fetchColumn();
                createNotification($pdo, $post_author_id, $current_user_id, 'post_comment', $post_id);
            }

            echo json_encode([
                'success'           => true,
                'comment_id'        => $new_comment_id,
                'user_name'         => $user_data['first_name'] . ' ' . $user_data['last_name'],
                'user_avatar'       => $user_data['avatar_url'] ?? null,
                'content'           => $comment_content,
                'created_at_rel'    => 'przed chwilą',
                'parent_comment_id' => $parent_comment_id,
                'reply_to_user_id'  => $reply_to_user_id,
                'reply_to_username' => $reply_to_username,
            ]);
            exit;

        } catch (PDOException $e) {
            echo json_encode(['success' => false, 'message' => 'Błąd bazy danych.']);
            exit;
        }
    }

    if ($post_id <= 0) {
        header('Location: index.php');
        exit;
    }

    // --- EDYCJA POSTA ---
    if ($action === 'edit') {
        $new_content = trim($_POST['content'] ?? '');
        if (!empty($new_content)) {
            try {
                $stmt = db()->prepare("UPDATE posts SET content = :content WHERE post_id = :pid AND (author_id = :uid OR :role = 'admin')");
                $stmt->execute([':content' => $new_content, ':pid' => $post_id, ':uid' => $current_user_id, ':role' => $_SESSION['role'] ?? 'user']);
            } catch (PDOException $e) {}
        }
    }

    // --- USUWANIE POSTA ---
    if ($action === 'delete') {
        try {
            $pdo = db();
            // Usuń media pliki
            $media = $pdo->prepare("SELECT file_url FROM post_media WHERE post_id = :pid");
            $media->execute([':pid' => $post_id]);
            foreach ($media->fetchAll() as $m) {
                $path = __DIR__ . '/' . $m['file_url'];
                if (file_exists($path)) @unlink($path);
            }
            // Kaskada przez FK usuwa post_media, comment_reactions, post_reactions, comments
            $stmt = $pdo->prepare("DELETE FROM posts WHERE post_id = :pid AND (author_id = :uid OR :role = 'admin')");
            $stmt->execute([':pid' => $post_id, ':uid' => $current_user_id, ':role' => $_SESSION['role'] ?? 'user']);
        } catch (PDOException $e) {}
    }

    // --- ZGŁASZANIE POSTA ---
    if ($action === 'report') {
        $reason = trim($_POST['reason'] ?? 'other');
        $allowed_reasons = ['spam', 'harassment', 'hate_speech', 'other'];
        if (!in_array($reason, $allowed_reasons, true)) $reason = 'other';
        try {
            $stmt = db()->prepare("INSERT INTO reports (post_id, reported_user_id, category, content, reported_at) VALUES (:pid, :uid, :cat, '', NOW())");
            $stmt->execute([':pid' => $post_id, ':uid' => $current_user_id, ':cat' => $reason]);
        } catch (PDOException $e) {}
    }
}

// Przekierowanie z powrotem
if (isset($_SERVER['HTTP_REFERER']) && strpos($_SERVER['HTTP_REFERER'], $_SERVER['HTTP_HOST']) !== false) {
    header('Location: ' . $_SERVER['HTTP_REFERER']);
} else {
    header('Location: index.php');
}
exit;
