<?php
session_start();
require_once '../config/database.php';

header('Content-Type: application/json');

if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
    echo json_encode(['success' => false, 'message' => 'Niezalogowany']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'message' => 'Metoda niedozwolona']);
    exit;
}

$current_user_id = (int) $_SESSION['user_id'];
$type      = trim($_POST['type'] ?? '');
$post_id   = isset($_POST['post_id'])    ? (int)$_POST['post_id']    : null;
$comment_id = isset($_POST['comment_id']) ? (int)$_POST['comment_id'] : null;

$allowed_types = ['like', 'love', 'hug', 'haha', 'wow', 'sad', 'angry'];

if (!in_array($type, $allowed_types, true)) {
    echo json_encode(['success' => false, 'message' => 'Nieprawidłowy typ reakcji']);
    exit;
}

if (!$post_id && !$comment_id) {
    echo json_encode(['success' => false, 'message' => 'Brak ID posta lub komentarza']);
    exit;
}

function buildCounts(PDO $pdo, string $table, string $col, int $id, array $allowed): array {
    $counts = array_fill_keys($allowed, 0);
    $stmt = $pdo->prepare("SELECT reaction_type, COUNT(*) AS cnt FROM `$table` WHERE `$col` = :id GROUP BY reaction_type");
    $stmt->execute([':id' => $id]);
    foreach ($stmt->fetchAll() as $row) {
        if (isset($counts[$row['reaction_type']])) {
            $counts[$row['reaction_type']] = (int)$row['cnt'];
        }
    }
    return $counts;
}

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

try {
    $pdo = db();
    $user_reaction = null;

    if ($post_id) {
        // --- Reakcja na post ---
        $stmt = $pdo->prepare("SELECT reaction_id, reaction_type FROM post_reactions WHERE post_id = :pid AND user_id = :uid");
        $stmt->execute([':pid' => $post_id, ':uid' => $current_user_id]);
        $existing = $stmt->fetch();

        if ($existing) {
            if ($existing['reaction_type'] === $type) {
                $pdo->prepare("DELETE FROM post_reactions WHERE reaction_id = :rid")
                    ->execute([':rid' => $existing['reaction_id']]);
                $user_reaction = null;
            } else {
                $pdo->prepare("UPDATE post_reactions SET reaction_type = :type, reacted_at = NOW() WHERE reaction_id = :rid")
                    ->execute([':type' => $type, ':rid' => $existing['reaction_id']]);
                $user_reaction = $type;
            }
        } else {
            $pdo->prepare("INSERT INTO post_reactions (post_id, user_id, reaction_type) VALUES (:pid, :uid, :type)")
                ->execute([':pid' => $post_id, ':uid' => $current_user_id, ':type' => $type]);
            $user_reaction = $type;
        }

        // Powiadomienie dla autora posta
        if ($user_reaction) {
            $author = $pdo->prepare("SELECT author_id FROM posts WHERE post_id = :pid");
            $author->execute([':pid' => $post_id]);
            $author_id = (int)$author->fetchColumn();
            createNotification($pdo, $author_id, $current_user_id, 'post_reaction', $post_id);
        }

        $counts = buildCounts($pdo, 'post_reactions', 'post_id', $post_id, $allowed_types);
        echo json_encode(['success' => true, 'counts' => $counts, 'user_reaction' => $user_reaction]);

    } else {
        // --- Reakcja na komentarz ---
        $stmt = $pdo->prepare("SELECT reaction_id, reaction_type FROM comment_reactions WHERE comment_id = :cid AND user_id = :uid");
        $stmt->execute([':cid' => $comment_id, ':uid' => $current_user_id]);
        $existing = $stmt->fetch();

        if ($existing) {
            if ($existing['reaction_type'] === $type) {
                $pdo->prepare("DELETE FROM comment_reactions WHERE reaction_id = :rid")
                    ->execute([':rid' => $existing['reaction_id']]);
                $user_reaction = null;
            } else {
                $pdo->prepare("UPDATE comment_reactions SET reaction_type = :type, reacted_at = NOW() WHERE reaction_id = :rid")
                    ->execute([':type' => $type, ':rid' => $existing['reaction_id']]);
                $user_reaction = $type;
            }
        } else {
            $pdo->prepare("INSERT INTO comment_reactions (comment_id, user_id, reaction_type) VALUES (:cid, :uid, :type)")
                ->execute([':cid' => $comment_id, ':uid' => $current_user_id, ':type' => $type]);
            $user_reaction = $type;
        }

        // Powiadomienie dla autora komentarza
        if ($user_reaction) {
            $author = $pdo->prepare("SELECT author_id FROM comments WHERE comment_id = :cid");
            $author->execute([':cid' => $comment_id]);
            $author_id = (int)$author->fetchColumn();
            createNotification($pdo, $author_id, $current_user_id, 'comment_reaction', $comment_id);
        }

        $counts = buildCounts($pdo, 'comment_reactions', 'comment_id', $comment_id, $allowed_types);
        echo json_encode(['success' => true, 'counts' => $counts, 'user_reaction' => $user_reaction]);
    }

} catch (PDOException $e) {
    echo json_encode(['success' => false, 'message' => 'Błąd bazy danych']);
}
