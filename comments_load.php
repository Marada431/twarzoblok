<?php
session_start();
require_once 'config/database.php';

header('Content-Type: application/json');

if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
    echo json_encode(['success' => false, 'message' => 'Niezalogowany']);
    exit;
}

$current_user_id = (int) $_SESSION['user_id'];
$post_id    = isset($_GET['post_id'])    ? (int)$_GET['post_id']    : 0;
$parent_id  = isset($_GET['parent_id'])  ? (int)$_GET['parent_id']  : null;
$offset     = isset($_GET['offset'])     ? max(0, (int)$_GET['offset']) : 0;
$limit      = 10;

if ($post_id <= 0) {
    echo json_encode(['success' => false, 'message' => 'Brak post_id']);
    exit;
}

$emoji_map = ['like'=>'👍','love'=>'❤️','hug'=>'🤗','haha'=>'😆','wow'=>'😮','sad'=>'😢','angry'=>'😡'];

function getReactionCounts(PDO $pdo, int $comment_id, int $current_user_id): array {
    $stmt = $pdo->prepare("
        SELECT reaction_type, COUNT(*) AS cnt,
               MAX(CASE WHEN user_id = :uid THEN 1 ELSE 0 END) AS is_mine
        FROM comment_reactions WHERE comment_id = :cid
        GROUP BY reaction_type
    ");
    $stmt->execute([':uid' => $current_user_id, ':cid' => $comment_id]);
    return $stmt->fetchAll();
}

function relativeTime(string $datetime): string {
    $diff = time() - strtotime($datetime);
    if ($diff < 60)    return 'przed chwilą';
    if ($diff < 3600)  return (int)($diff/60) . ' min temu';
    if ($diff < 86400) return (int)($diff/3600) . ' godz. temu';
    if ($diff < 604800) return (int)($diff/86400) . ' dni temu';
    return date('d.m.Y', strtotime($datetime));
}

try {
    $pdo = db();

    if ($parent_id !== null) {
        // --- Ładuj odpowiedzi dla komentarza ---
        $stmt = $pdo->prepare("
            SELECT c.comment_id, c.author_id, c.content, c.created_at,
                   c.reply_to_user_id,
                   u.first_name, u.last_name, u.avatar_url,
                   ru.username AS reply_to_username
            FROM comments c
            JOIN users u ON c.author_id = u.user_id
            LEFT JOIN users ru ON c.reply_to_user_id = ru.user_id
            WHERE c.post_id = :pid AND c.parent_comment_id = :parent
            ORDER BY c.created_at ASC
        ");
        $stmt->execute([':pid' => $post_id, ':parent' => $parent_id]);
        $replies = $stmt->fetchAll();

        $result = [];
        foreach ($replies as $r) {
            $cid = (int)$r['comment_id'];
            $reactions = getReactionCounts($pdo, $cid, $current_user_id);
            $result[] = [
                'comment_id'         => $cid,
                'author_id'          => (int)$r['author_id'],
                'author_name'        => $r['first_name'] . ' ' . $r['last_name'],
                'author_avatar'      => $r['avatar_url'] ?? '',
                'content'            => $r['content'],
                'created_at_rel'     => relativeTime($r['created_at']),
                'reply_to_username'  => $r['reply_to_username'] ?? null,
                'reply_to_user_id'   => $r['reply_to_user_id'] ? (int)$r['reply_to_user_id'] : null,
                'is_reply'           => true,
                'parent_comment_id'  => $parent_id,
                'reactions'          => $reactions,
            ];
        }

        echo json_encode(['success' => true, 'comments' => $result, 'has_more' => false]);

    } else {
        // --- Ładuj komentarze główne (paginacja) ---
        $stmt = $pdo->prepare("
            SELECT c.comment_id, c.author_id, c.content, c.created_at,
                   u.first_name, u.last_name, u.avatar_url,
                   (SELECT COUNT(*) FROM comments r WHERE r.parent_comment_id = c.comment_id) AS reply_count
            FROM comments c
            JOIN users u ON c.author_id = u.user_id
            WHERE c.post_id = :pid AND c.parent_comment_id IS NULL
              AND c.visibility_status = 'visible'
            ORDER BY c.created_at ASC
            LIMIT :lim OFFSET :off
        ");
        $stmt->bindValue(':pid', $post_id, PDO::PARAM_INT);
        $stmt->bindValue(':lim', $limit,   PDO::PARAM_INT);
        $stmt->bindValue(':off', $offset,  PDO::PARAM_INT);
        $stmt->execute();
        $comments = $stmt->fetchAll();

        $total_stmt = $pdo->prepare("SELECT COUNT(*) FROM comments WHERE post_id = :pid AND parent_comment_id IS NULL AND visibility_status = 'visible'");
        $total_stmt->execute([':pid' => $post_id]);
        $total = (int)$total_stmt->fetchColumn();

        $result = [];
        foreach ($comments as $c) {
            $cid = (int)$c['comment_id'];
            $reactions = getReactionCounts($pdo, $cid, $current_user_id);
            $result[] = [
                'comment_id'        => $cid,
                'author_id'         => (int)$c['author_id'],
                'author_name'       => $c['first_name'] . ' ' . $c['last_name'],
                'author_avatar'     => $c['avatar_url'] ?? '',
                'content'           => $c['content'],
                'created_at_rel'    => relativeTime($c['created_at']),
                'reply_to_username' => null,
                'reply_to_user_id'  => null,
                'is_reply'          => false,
                'parent_comment_id' => null,
                'reply_count'       => (int)$c['reply_count'],
                'reactions'         => $reactions,
            ];
        }

        $has_more = ($offset + $limit) < $total;
        echo json_encode(['success' => true, 'comments' => $result, 'has_more' => $has_more, 'total' => $total]);
    }

} catch (PDOException $e) {
    echo json_encode(['success' => false, 'message' => 'Błąd bazy danych']);
}
