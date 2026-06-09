<?php
session_start();
require_once 'config/database.php';
require_once 'includes/csrf.php';
require_once 'includes/auth_check.php';

if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
    header('Location: login.php');
    exit;
}

$current_user_id = (int) $_SESSION['user_id'];
check_user_status(db(), $current_user_id);

// ─────────────────────────────────────────────
// HELPERS
// ─────────────────────────────────────────────

function relativeTime(string $dt): string {
    $diff = time() - strtotime($dt);
    if ($diff < 60)     return 'przed chwilą';
    if ($diff < 3600)   return (int)($diff/60) . ' min temu';
    if ($diff < 86400)  return (int)($diff/3600) . ' godz. temu';
    if ($diff < 604800) return (int)($diff/86400) . ' dni temu';
    return date('d.m.Y', strtotime($dt));
}

function createThumbnail(string $path, string $mime): void {
    if (!extension_loaded('gd')) return;
    $src = match($mime) {
        'image/jpeg' => @imagecreatefromjpeg($path),
        'image/png'  => @imagecreatefrompng($path),
        'image/webp' => @imagecreatefromwebp($path),
        'image/gif'  => @imagecreatefromgif($path),
        default      => false
    };
    if (!$src) return;
    $w = imagesx($src); $h = imagesy($src);
    if ($w <= 800) { imagedestroy($src); return; }
    $nw = 800; $nh = (int)($h * $nw / $w);
    $dst = imagecreatetruecolor($nw, $nh);
    if (in_array($mime, ['image/png','image/gif'])) {
        imagealphablending($dst, false); imagesavealpha($dst, true);
    }
    imagecopyresampled($dst, $src, 0, 0, 0, 0, $nw, $nh, $w, $h);
    match($mime) {
        'image/jpeg' => imagejpeg($dst, $path, 85),
        'image/png'  => imagepng($dst, $path, 6),
        'image/webp' => imagewebp($dst, $path, 85),
        'image/gif'  => imagegif($dst, $path),
        default      => null
    };
    imagedestroy($src); imagedestroy($dst);
}

// ─────────────────────────────────────────────
// POST: Nowy post z galerią mediów
// ─────────────────────────────────────────────

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['submit_post'])) {
    verify_csrf_token($_POST['csrf_token'] ?? '');
    $content   = trim($_POST['content'] ?? '');
    $max_files = 10;
    $allowed_mimes = ['image/jpeg'=>'jpg','image/png'=>'png','image/webp'=>'webp','image/gif'=>'gif','video/mp4'=>'mp4','video/webm'=>'webm'];
    $max_img   = 10 * 1024 * 1024;
    $max_vid   = 100 * 1024 * 1024;

    $has_files = isset($_FILES['post_media']) && is_array($_FILES['post_media']['name']) && $_FILES['post_media']['error'][0] !== UPLOAD_ERR_NO_FILE;

    if (!empty($content) || $has_files) {
        $pdo = db();
        // Wstaw post
        $stmt = $pdo->prepare("INSERT INTO posts (author_id, content, created_at) VALUES (:uid, :content, NOW())");
        $stmt->execute([':uid' => $current_user_id, ':content' => $content]);
        $new_post_id = (int)$pdo->lastInsertId();

        if ($has_files) {
            $finfo   = new finfo(FILEINFO_MIME_TYPE);
            $files   = $_FILES['post_media'];
            $count   = min(count($files['name']), $max_files);
            $pos     = 0;
            $ins     = $pdo->prepare("INSERT INTO post_media (post_id, media_type, file_url, position) VALUES (:pid, :mtype, :url, :pos)");

            for ($i = 0; $i < $count; $i++) {
                if ($files['error'][$i] !== UPLOAD_ERR_OK) continue;
                $tmp  = $files['tmp_name'][$i];
                $mime = $finfo->file($tmp);
                if (!isset($allowed_mimes[$mime])) continue;
                $is_video = str_starts_with($mime, 'video/');
                if ($files['size'][$i] > ($is_video ? $max_vid : $max_img)) continue;

                $ext = $allowed_mimes[$mime];
                $dir = "uploads/posts/{$current_user_id}/{$new_post_id}/";
                if (!is_dir($dir)) mkdir($dir, 0775, true);
                $filename = uniqid('', true) . '.' . $ext;
                $dest = $dir . $filename;

                if (!move_uploaded_file($tmp, $dest)) continue;
                if (!$is_video) createThumbnail($dest, $mime);

                $ins->execute([':pid' => $new_post_id, ':mtype' => $is_video ? 'video' : 'image', ':url' => $dest, ':pos' => $pos]);
                $pos++;
            }
        }

        header('Location: index.php');
        exit;
    }
}

// ─────────────────────────────────────────────
// POST: AJAX akcje (znajomi, licznik)
// ─────────────────────────────────────────────

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    verify_csrf_token($_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');
    header('Content-Type: application/json');
    $action = $_POST['action'];

    if ($action === 'add_friend') {
        $target_id = (int)($_POST['target_user_id'] ?? 0);
        if ($target_id <= 0 || $target_id === $current_user_id) {
            echo json_encode(['success' => false, 'message' => 'Nieprawidłowy użytkownik.']); exit;
        }
        try {
            $check = db()->prepare("SELECT friendship_id FROM friendships WHERE (requester_id=:u1 AND addressee_id=:u2) OR (requester_id=:u3 AND addressee_id=:u4)");
            $check->execute([':u1'=>$current_user_id,':u2'=>$target_id,':u3'=>$target_id,':u4'=>$current_user_id]);
            if ($check->fetch()) { echo json_encode(['success'=>false,'message'=>'Zaproszenie już istnieje.']); exit; }
            db()->prepare("INSERT INTO friendships (requester_id,addressee_id,status) VALUES (:r,:a,'pending')")->execute([':r'=>$current_user_id,':a'=>$target_id]);
            echo json_encode(['success'=>true,'message'=>'Zaproszenie wysłane!']);
        } catch (PDOException $e) { echo json_encode(['success'=>false,'message'=>'Błąd bazy danych.']); }
        exit;
    }

    if ($action === 'remove_suggestion') {
        $target_id = (int)($_POST['target_user_id'] ?? 0);
        if ($target_id <= 0) { echo json_encode(['success'=>false,'message'=>'Błąd.']); exit; }
        $removed = isset($_COOKIE['removed_suggestions']) ? json_decode($_COOKIE['removed_suggestions'], true) : [];
        if (!is_array($removed)) $removed = [];
        if (!in_array($target_id, $removed)) $removed[] = $target_id;
        setcookie('removed_suggestions', json_encode($removed), time() + 30*24*3600, '/');
        echo json_encode(['success'=>true]);
        exit;
    }

    if ($action === 'get_pending_count') {
        $stmt = db()->prepare("SELECT COUNT(*) FROM friendships WHERE addressee_id=:uid AND status='pending'");
        $stmt->execute([':uid'=>$current_user_id]);
        echo json_encode(['success'=>true,'count'=>(int)$stmt->fetchColumn()]);
        exit;
    }

    // AJAX: load more posts
    if ($action === 'load_posts') {
        $offset = max(0, (int)($_POST['offset'] ?? 0));
        $posts  = fetchFeedPosts($current_user_id, $offset);
        $batch  = batchLoadPostData($posts, $current_user_id);
        $html   = '';
        foreach ($posts as $post) {
            $html .= renderPost($post, $current_user_id, $batch[$post['post_id']] ?? []);
        }
        echo json_encode(['success'=>true,'html'=>$html,'has_more'=>count($posts)===20]);
        exit;
    }

    echo json_encode(['success'=>false,'message'=>'Nieznana akcja.']); exit;
}

// ─────────────────────────────────────────────
// DANE: feed + sugestie + licznik zaproszeń
// ─────────────────────────────────────────────

/**
 * Pobiera w 5 zapytaniach WSZYSTKIE dane dla tablicy postów (eliminacja N+1).
 * Zwraca mapę: post_id → ['media', 'reactions', 'comments', 'total_comments']
 */
function batchLoadPostData(array $posts, int $uid): array {
    if (empty($posts)) return [];
    $pdo     = db();
    $postIds = array_column($posts, 'post_id');
    $phs     = implode(',', array_fill(0, count($postIds), '?'));

    // 1. Media
    $stmt = $pdo->prepare("SELECT media_id, post_id, media_type, file_url FROM post_media WHERE post_id IN ($phs) ORDER BY position ASC");
    $stmt->execute($postIds);
    $mediaByPost = [];
    foreach ($stmt->fetchAll() as $m) { $mediaByPost[$m['post_id']][] = $m; }

    // 2. Reakcje (zagregowane)
    $stmt = $pdo->prepare("
        SELECT post_id, reaction_type, COUNT(*) AS cnt,
               MAX(CASE WHEN user_id = ? THEN 1 ELSE 0 END) AS is_mine
        FROM post_reactions WHERE post_id IN ($phs)
        GROUP BY post_id, reaction_type ORDER BY cnt DESC
    ");
    $stmt->execute(array_merge([$uid], $postIds));
    $reactionsByPost = [];
    foreach ($stmt->fetchAll() as $r) { $reactionsByPost[$r['post_id']][] = $r; }

    // 3. Komentarze (wszystkie top-level) — slice do 5 w PHP
    $stmt = $pdo->prepare("
        SELECT c.comment_id, c.post_id, c.author_id, c.content, c.created_at, c.reply_to_user_id,
               u.first_name, u.last_name, u.avatar_url,
               (SELECT COUNT(*) FROM comments r WHERE r.parent_comment_id = c.comment_id) AS reply_count
        FROM comments c
        JOIN users u ON c.author_id = u.user_id
        WHERE c.post_id IN ($phs) AND c.parent_comment_id IS NULL AND c.visibility_status = 'visible'
        ORDER BY c.post_id ASC, c.created_at ASC
    ");
    $stmt->execute($postIds);
    $commentsByPost = [];
    foreach ($stmt->fetchAll() as $c) {
        if (!isset($commentsByPost[$c['post_id']])) $commentsByPost[$c['post_id']] = [];
        if (count($commentsByPost[$c['post_id']]) < 5) $commentsByPost[$c['post_id']][] = $c;
    }

    // 4. Liczba komentarzy
    $stmt = $pdo->prepare("
        SELECT post_id, COUNT(*) AS cnt FROM comments
        WHERE post_id IN ($phs) AND parent_comment_id IS NULL AND visibility_status = 'visible'
        GROUP BY post_id
    ");
    $stmt->execute($postIds);
    $countByPost = [];
    foreach ($stmt->fetchAll() as $row) { $countByPost[$row['post_id']] = (int)$row['cnt']; }

    // 5. Reakcje na komentarze
    $commentIds = [];
    foreach ($commentsByPost as $comments) {
        foreach ($comments as $c) { $commentIds[] = $c['comment_id']; }
    }
    $cmtReactions = [];
    if (!empty($commentIds)) {
        $cphs = implode(',', array_fill(0, count($commentIds), '?'));
        $stmt = $pdo->prepare("
            SELECT comment_id, reaction_type, COUNT(*) AS cnt,
                   MAX(CASE WHEN user_id = ? THEN 1 ELSE 0 END) AS is_mine
            FROM comment_reactions WHERE comment_id IN ($cphs)
            GROUP BY comment_id, reaction_type
        ");
        $stmt->execute(array_merge([$uid], $commentIds));
        foreach ($stmt->fetchAll() as $r) { $cmtReactions[$r['comment_id']][] = $r; }
    }
    foreach ($commentsByPost as $pid => &$comments) {
        foreach ($comments as &$c) { $c['reactions'] = $cmtReactions[$c['comment_id']] ?? []; }
    }
    unset($comments, $c);

    $result = [];
    foreach ($postIds as $pid) {
        $result[$pid] = [
            'media'          => $mediaByPost[$pid]  ?? [],
            'reactions'      => $reactionsByPost[$pid] ?? [],
            'comments'       => $commentsByPost[$pid]  ?? [],
            'total_comments' => $countByPost[$pid]      ?? 0,
        ];
    }
    return $result;
}

function fetchFeedPosts(int $uid, int $offset, int $limit = 20): array {
    $pdo = db();
    $stmt = $pdo->prepare("
        SELECT
            p.post_id, p.author_id, p.content, p.created_at,
            u.first_name, u.last_name, u.avatar_url,
            COUNT(DISTINCT pr.reaction_id)  AS reaction_count,
            COUNT(DISTINCT c.comment_id)    AS comment_count,
            MAX(CASE WHEN f.friendship_id IS NOT NULL OR p.author_id = :own THEN 1 ELSE 0 END) AS is_friend,
            (
                MAX(CASE WHEN f.friendship_id IS NOT NULL OR p.author_id = :own2 THEN 1 ELSE 0 END) * 1000
                + 3  * COUNT(DISTINCT pr.reaction_id)
                + 5  * COUNT(DISTINCT c.comment_id)
                + 200 * (1 / (1 + TIMESTAMPDIFF(HOUR, p.created_at, NOW()) / 12))
            ) AS score
        FROM posts p
        JOIN users u ON p.author_id = u.user_id
        LEFT JOIN post_reactions pr ON pr.post_id = p.post_id
        LEFT JOIN comments c  ON c.post_id = p.post_id AND c.parent_comment_id IS NULL
        LEFT JOIN friendships f ON (
            (f.requester_id = :uid  AND f.addressee_id = p.author_id)
            OR
            (f.addressee_id = :uid2 AND f.requester_id = p.author_id)
        ) AND f.status = 'accepted'
        WHERE p.created_at >= NOW() - INTERVAL 30 DAY
        GROUP BY p.post_id, p.author_id, p.content, p.created_at, u.first_name, u.last_name, u.avatar_url
        HAVING (is_friend = 1 OR reaction_count >= 1 OR p.author_id = :own3)
        ORDER BY score DESC
        LIMIT :lim OFFSET :off
    ");
    $stmt->bindValue(':own',  $uid, PDO::PARAM_INT);
    $stmt->bindValue(':own2', $uid, PDO::PARAM_INT);
    $stmt->bindValue(':own3', $uid, PDO::PARAM_INT);
    $stmt->bindValue(':uid',  $uid, PDO::PARAM_INT);
    $stmt->bindValue(':uid2', $uid, PDO::PARAM_INT);
    $stmt->bindValue(':lim',  $limit, PDO::PARAM_INT);
    $stmt->bindValue(':off',  $offset, PDO::PARAM_INT);
    $stmt->execute();
    return $stmt->fetchAll();
}

function fetchPostMedia(int $post_id): array {
    $stmt = db()->prepare("SELECT media_id, media_type, file_url FROM post_media WHERE post_id = :pid ORDER BY position ASC");
    $stmt->execute([':pid' => $post_id]);
    return $stmt->fetchAll();
}

function fetchPostReactions(int $post_id, int $uid): array {
    $stmt = db()->prepare("
        SELECT reaction_type, COUNT(*) AS cnt,
               MAX(CASE WHEN user_id = :uid THEN 1 ELSE 0 END) AS is_mine
        FROM post_reactions WHERE post_id = :pid
        GROUP BY reaction_type ORDER BY cnt DESC
    ");
    $stmt->execute([':uid' => $uid, ':pid' => $post_id]);
    return $stmt->fetchAll();
}

function fetchInitialComments(int $post_id, int $uid): array {
    $pdo = db();
    $stmt = $pdo->prepare("
        SELECT c.comment_id, c.author_id, c.content, c.created_at, c.reply_to_user_id,
               u.first_name, u.last_name, u.avatar_url,
               (SELECT COUNT(*) FROM comments r WHERE r.parent_comment_id = c.comment_id) AS reply_count
        FROM comments c
        JOIN users u ON c.author_id = u.user_id
        WHERE c.post_id = :pid AND c.parent_comment_id IS NULL AND c.visibility_status = 'visible'
        ORDER BY c.created_at ASC
        LIMIT 5
    ");
    $stmt->execute([':pid' => $post_id]);
    $comments = $stmt->fetchAll();

    foreach ($comments as &$c) {
        $rs = $pdo->prepare("SELECT reaction_type, COUNT(*) AS cnt, MAX(CASE WHEN user_id=:uid THEN 1 ELSE 0 END) AS is_mine FROM comment_reactions WHERE comment_id=:cid GROUP BY reaction_type");
        $rs->execute([':uid'=>$uid,':cid'=>$c['comment_id']]);
        $c['reactions'] = $rs->fetchAll();
    }
    return $comments;
}

function fetchTotalComments(int $post_id): int {
    $stmt = db()->prepare("SELECT COUNT(*) FROM comments WHERE post_id=:pid AND parent_comment_id IS NULL AND visibility_status='visible'");
    $stmt->execute([':pid'=>$post_id]);
    return (int)$stmt->fetchColumn();
}

// ─────────────────────────────────────────────
// RENDER HELPERS
// ─────────────────────────────────────────────

function renderGallery(array $media): string {
    if (empty($media)) return '';
    $count = count($media);
    $class = match(true) {
        $count === 1 => 'gallery-1',
        $count === 2 => 'gallery-2',
        $count === 3 => 'gallery-3',
        default      => 'gallery-4plus',
    };
    $visible = $count <= 4 ? $media : array_slice($media, 0, 4);
    $hidden  = max(0, $count - 4);

    $html = "<div class=\"media-gallery $class\" data-media='";
    $media_data = [];
    foreach ($media as $m) {
        $media_data[] = ['type' => $m['media_type'], 'url' => htmlspecialchars($m['file_url'], ENT_QUOTES)];
    }
    $html .= htmlspecialchars(json_encode($media_data), ENT_QUOTES) . "'>";

    foreach ($visible as $idx => $m) {
        $url  = htmlspecialchars($m['file_url']);
        $is_last = $idx === 3 && $hidden > 0;
        $html .= "<div class=\"media-tile\" onclick=\"openLightbox(this.closest('.media-gallery'),$idx)\">";
        if ($is_last) {
            $html .= "<div class=\"media-tile-more\" style=\"background-image:url('$url')\"><span>+$hidden</span></div>";
        } elseif ($m['media_type'] === 'video') {
            $html .= "<video src=\"$url\" preload=\"metadata\"></video>";
        } else {
            $html .= "<img src=\"$url\" alt=\"Media\" loading=\"lazy\">";
        }
        $html .= "</div>";
    }
    $html .= "</div>";
    return $html;
}

function renderReactionBadges(array $reactions): string {
    $emoji_map = ['like'=>'👍','love'=>'❤️','hug'=>'🤗','haha'=>'😆','wow'=>'😮','sad'=>'😢','angry'=>'😡'];
    $labels    = ['like'=>'Lubię to','love'=>'Super','hug'=>'Trzymaj się','haha'=>'Haha','wow'=>'Wow','sad'=>'Smutne','angry'=>'Złość'];
    $html = '';
    foreach ($reactions as $r) {
        $e = $emoji_map[$r['reaction_type']] ?? '?';
        $l = $labels[$r['reaction_type']] ?? '';
        $mine = $r['is_mine'] ? ' mine' : '';
        $html .= "<span class=\"reaction-badge$mine\" title=\"$l\">$e <b>{$r['cnt']}</b></span>";
    }
    return $html;
}

function renderCommentReactions(array $reactions, int $comment_id): string {
    $emoji_map = ['like'=>'👍','love'=>'❤️','hug'=>'🤗','haha'=>'😆','wow'=>'😮','sad'=>'😢','angry'=>'😡'];
    $labels    = ['like'=>'Lubię to','love'=>'Super','hug'=>'Trzymaj się','haha'=>'Haha','wow'=>'Wow','sad'=>'Smutne','angry'=>'Złość'];
    $html = "<div class=\"comment-reactions\" data-comment-id=\"$comment_id\">";

    $reaction_types = ['like','love','hug','haha','wow','sad','angry'];
    foreach ($reactions as $r) {
        $e    = $emoji_map[$r['reaction_type']] ?? '?';
        $l    = $labels[$r['reaction_type']] ?? '';
        $mine = $r['is_mine'] ? ' mine' : '';
        $html .= "<span class=\"comment-reaction-badge$mine\" data-type=\"{$r['reaction_type']}\" title=\"$l\">$e {$r['cnt']}</span>";
    }

    $html .= "<div class=\"comment-reaction-picker\" data-comment-id=\"$comment_id\">";
    foreach ($reaction_types as $t) {
        $e = $emoji_map[$t];
        $html .= "<span class=\"comment-pick-emoji\" data-type=\"$t\" data-comment-id=\"$comment_id\" title=\"{$labels[$t]}\">$e</span>";
    }
    $html .= "</div></div>";
    return $html;
}

function renderComment(array $c, bool $is_reply = false): string {
    $cid         = (int)$c['comment_id'];
    $author_id   = (int)$c['author_id'];
    $name        = htmlspecialchars($c['first_name'] . ' ' . $c['last_name']);
    $time        = relativeTime($c['created_at']);
    $text        = nl2br(htmlspecialchars($c['content']));
    $reply_count = (int)($c['reply_count'] ?? 0);
    $indent      = $is_reply ? ' reply-comment' : '';
    $reactions_html = isset($c['reactions']) ? renderCommentReactions($c['reactions'], $cid) : '';

    ob_start(); ?>
<div class="comment-item<?= $indent ?>" id="comment-<?= $cid ?>"
     data-comment-id="<?= $cid ?>" data-author-id="<?= $author_id ?>">
    <div class="cmt-avatar">
        <?php if (!empty($c['avatar_url'])): ?>
            <img src="<?= htmlspecialchars($c['avatar_url']) ?>" alt="Avatar" loading="lazy">
        <?php else: ?>
            <span class="cmt-av-ph">👤</span>
        <?php endif; ?>
    </div>
    <div class="cmt-body">
        <div class="cmt-bubble">
            <span class="cmt-author"><?= $name ?></span>
            <span class="cmt-text">
                <?php if (!empty($c['reply_to_username'])): ?>
                    <a class="reply-mention" href="#">@<?= htmlspecialchars($c['reply_to_username']) ?></a>
                <?php endif; ?>
                <?= $text ?>
            </span>
        </div>
        <div class="cmt-meta">
            <?= $reactions_html ?>
            <button class="cmt-action-btn reply-btn"
                    data-comment-id="<?= $cid ?>"
                    data-author-id="<?= $author_id ?>"
                    data-author-name="<?= $name ?>">Odpowiedz</button>
            <span class="cmt-time"><?= $time ?></span>
        </div>
        <?php if (!$is_reply && $reply_count > 0): ?>
            <div class="replies-container" id="replies-<?= $cid ?>" data-loaded="0">
                <button class="load-replies-btn"
                        data-comment-id="<?= $cid ?>"
                        data-count="<?= $reply_count ?>">Zobacz <?= $reply_count ?> odpowiedzi</button>
            </div>
        <?php elseif (!$is_reply): ?>
            <div class="replies-container" id="replies-<?= $cid ?>" data-loaded="0"></div>
        <?php endif; ?>
    </div>
</div>
    <?php return ob_get_clean();
}

function renderPost(array $post, int $uid, array $preloaded = []): string {
    $pid        = (int)$post['post_id'];
    $name       = htmlspecialchars($post['first_name'] . ' ' . $post['last_name']);
    $time       = relativeTime($post['created_at']);
    $content    = nl2br(htmlspecialchars($post['content']));
    $avatar_url = $post['avatar_url'] ?? '';

    $avatar = $avatar_url
        ? "<img src=\"" . htmlspecialchars($avatar_url) . "\" alt=\"Avatar\">"
        : '<div class="placeholder-svg"><svg><use xlink:href="./icons/symbol-defs.svg#icon-user"></use></svg></div>';

    // Używaj pre-załadowanych danych (batch) lub fallback do pojedynczych zapytań
    $media     = $preloaded['media']          ?? fetchPostMedia($pid);
    $reactions = $preloaded['reactions']      ?? fetchPostReactions($pid, $uid);
    $comments  = $preloaded['comments']       ?? fetchInitialComments($pid, $uid);
    $total_cmt = $preloaded['total_comments'] ?? fetchTotalComments($pid);

    $emoji_map = ['like'=>'👍','love'=>'❤️','hug'=>'🤗','haha'=>'😆','wow'=>'😮','sad'=>'😢','angry'=>'😡'];
    $labels    = ['like'=>'Lubię to','love'=>'Super','hug'=>'Trzymaj się','haha'=>'Haha','wow'=>'Wow','sad'=>'Smutne','angry'=>'Złość'];

    $user_reaction = null;
    $total_react   = 0;
    foreach ($reactions as $r) {
        $total_react += $r['cnt'];
        if ($r['is_mine']) $user_reaction = $r['reaction_type'];
    }

    $react_badge_html = renderReactionBadges($reactions);
    $btn_text  = $user_reaction ? ($emoji_map[$user_reaction] . ' ' . $labels[$user_reaction]) : 'Reakcja';
    $btn_class = $user_reaction ? 'action-btn active-reacted' : 'action-btn';

    ob_start(); ?>
<div class="post-feed-card" id="post-<?= $pid ?>">
    <div class="post-feed-header relative-header">
        <div class="avatar-box"><?= $avatar ?></div>
        <div>
            <div class="post-feed-author"><?= $name ?></div>
            <div class="post-feed-time"><?= $time ?> · Publiczny</div>
        </div>
        <div class="post-options-dropdown">
            <div class="post-options-trigger"><svg><use xlink:href="./icons/symbol-defs.svg#icon-list2"></use></svg></div>
            <div class="post-options-menu">
                <a href="javascript:void(0)"
                   class="post-options-item edit-post-trigger"
                   data-post-id="<?= $pid ?>"
                   data-post-content="<?= htmlspecialchars($post['content'], ENT_QUOTES, 'UTF-8') ?>">Edytuj</a>
                <a href="javascript:void(0)" class="post-options-item danger" onclick="openModal('delete',<?= $pid ?>)">Usuń</a>
                <a href="javascript:void(0)" class="post-options-item" onclick="openModal('report',<?= $pid ?>)">Zgłoś</a>
            </div>
        </div>
    </div>

    <?php if (!empty($post['content'])): ?>
        <div class="post-feed-content"><?= $content ?></div>
    <?php endif; ?>

    <?= renderGallery($media) ?>

    <?php if (!empty($reactions)): ?>
        <div class="post-reactions-summary" id="react-summary-<?= $pid ?>"><?= $react_badge_html ?></div>
    <?php else: ?>
        <div class="post-reactions-summary" id="react-summary-<?= $pid ?>"></div>
    <?php endif; ?>

    <div class="post-actions-bar">
        <div class="action-button-wrapper reaction-container" data-post-id="<?= $pid ?>">
            <button class="<?= $btn_class ?>" id="react-btn-<?= $pid ?>"
                    data-post-id="<?= $pid ?>"
                    data-user-reaction="<?= htmlspecialchars($user_reaction ?? '') ?>"
                    onclick="toggleReaction(<?= $pid ?>, this.dataset.userReaction || 'like')">
                <svg class="action-icon"><use xlink:href="./icons/symbol-defs.svg#icon-star-empty"></use></svg>
                <span id="react-btn-text-<?= $pid ?>"><?= $btn_text ?></span>
            </button>
            <div class="reactions-popup">
                <?php foreach ($emoji_map as $type => $emoji): ?>
                    <span class="reaction-emoji" title="<?= $labels[$type] ?>"
                          data-type="<?= $type ?>" data-post-id="<?= $pid ?>"
                          onclick="event.stopPropagation();toggleReaction(<?= $pid ?>,'<?= $type ?>')"><?= $emoji ?></span>
                <?php endforeach; ?>
            </div>
        </div>

        <div class="action-button-wrapper">
            <button class="action-btn" onclick="toggleComments(<?= $pid ?>)">
                <svg class="action-icon"><use xlink:href="./icons/symbol-defs.svg#icon-bubbles4"></use></svg>
                <span id="cmt-count-<?= $pid ?>">Komentarz (<?= $total_cmt ?>)</span>
            </button>
        </div>
    </div>

    <div class="comments-section" id="comments-<?= $pid ?>" style="display:none;">
        <div class="comments-list" id="comments-list-<?= $pid ?>">
            <?php foreach ($comments as $c): ?>
                <?= renderComment($c, false) ?>
            <?php endforeach; ?>
        </div>

        <?php if ($total_cmt > 5): ?>
            <button class="load-more-comments-btn" data-post-id="<?= $pid ?>" data-offset="5" data-total="<?= $total_cmt ?>">
                Pokaż więcej komentarzy (<?= $total_cmt - min(5, $total_cmt) ?> pozostałych)
            </button>
        <?php endif; ?>

        <form class="comment-form" data-post-id="<?= $pid ?>">
            <input type="hidden" name="parent_comment_id" value="">
            <input type="hidden" name="reply_to_user_id" value="">
            <div class="cmt-form-row">
                <?php if (!empty($_SESSION['avatar_url'])): ?>
                    <img src="<?= htmlspecialchars($_SESSION['avatar_url']) ?>" alt="Ja" class="cmt-my-avatar">
                <?php else: ?>
                    <span class="cmt-av-ph">👤</span>
                <?php endif; ?>
                <div class="cmt-input-wrap">
                    <input type="text" name="comment_content" class="comment-input" placeholder="Napisz komentarz…" autocomplete="off" required>
                    <button type="submit" class="comment-submit-btn" title="Wyślij">➔</button>
                </div>
            </div>
            <div class="reply-indicator" style="display:none;">
                <span class="reply-label"></span>
                <button type="button" class="cancel-reply-btn" onclick="cancelReply(this.closest('.comment-form'))">✕</button>
            </div>
        </form>
    </div>
</div>
    <?php
    return ob_get_clean();
}

// ─────────────────────────────────────────────
// DANE DO STRONY
// ─────────────────────────────────────────────

$removed_ids = isset($_COOKIE['removed_suggestions']) ? array_map('intval', json_decode($_COOKIE['removed_suggestions'], true) ?? []) : [];

$sql_sugg = "
    SELECT u.user_id, u.first_name, u.last_name, u.avatar_url,
           COALESCE(u.city, MAX(addr.city)) AS display_city
    FROM users u
    LEFT JOIN addresses addr ON u.user_id = addr.user_id
    WHERE u.user_id != :uid
    AND u.user_id NOT IN (
        SELECT CASE WHEN requester_id=:uid2 THEN addressee_id ELSE requester_id END
        FROM friendships WHERE requester_id=:uid3 OR addressee_id=:uid4
    )
";
if (!empty($removed_ids)) {
    $ph = implode(',', array_map(fn($i) => ":r$i", array_keys($removed_ids)));
    $sql_sugg .= " AND u.user_id NOT IN ($ph)";
}
$sql_sugg .= " GROUP BY u.user_id ORDER BY RAND() LIMIT 12";

$stmt_sugg = db()->prepare($sql_sugg);
$params_sugg = [':uid'=>$current_user_id,':uid2'=>$current_user_id,':uid3'=>$current_user_id,':uid4'=>$current_user_id];
foreach ($removed_ids as $i => $id) $params_sugg[":r$i"] = $id;
$stmt_sugg->execute($params_sugg);
$suggestions = $stmt_sugg->fetchAll();

$pending_stmt = db()->prepare("SELECT COUNT(*) FROM friendships WHERE addressee_id=:uid AND status='pending'");
$pending_stmt->execute([':uid' => $current_user_id]);
$pending_count = (int)$pending_stmt->fetchColumn();

$initial_posts  = fetchFeedPosts($current_user_id, 0);
$initial_batch  = batchLoadPostData($initial_posts, $current_user_id);
$has_more_posts = count($initial_posts) === 20;
?>
<!doctype html>
<html lang="pl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>TwarzBlok – Feed</title>
    <link rel="stylesheet" href="assets/css/main.css">
    <link rel="stylesheet" href="assets/css/feed-layout.css">
</head>
<body>

<?php $nav_pending_count = $pending_count; $nav_active = 'feed'; require_once __DIR__ . '/includes/navbar.php'; ?>

<div class="fb-container">

    <!-- SIDEBAR LEFT -->
    <aside class="sidebar-left card2">
        <h3 class="m-bottom-10">Menu</h3>
        <ul class="menu-list">
            <li><div class="icon-wrapper"><svg><use xlink:href="./icons/symbol-defs.svg#icon-users"></use></svg></div><a href="#">Znajomi</a></li>
            <li><div class="icon-wrapper"><svg><use xlink:href="./icons/symbol-defs.svg#icon-star-empty"></use></svg></div><a href="#">Grupy</a></li>
            <li><div class="icon-wrapper"><svg><use xlink:href="./icons/symbol-defs.svg#icon-bubbles4"></use></svg></div><a href="games.php">Mini Gry</a></li>
            <li><div class="icon-wrapper"><svg><use xlink:href="./icons/symbol-defs.svg#icon-briefcase"></use></svg></div><a href="chat.php">Wiadomości</a></li>
        </ul>
    </aside>

    <!-- FEED -->
    <main class="feed">

        <!-- Formularz nowego posta -->
        <div class="post-form-card">
            <form method="POST" action="" enctype="multipart/form-data" id="post-form">
                <input type="hidden" name="csrf_token" value="<?= generate_csrf_token() ?>">
                <div class="post-create-top">
                    <div class="av-wrap">
                        <?php if (!empty($_SESSION['avatar_url'])): ?>
                            <img src="<?= htmlspecialchars($_SESSION['avatar_url']) ?>" alt="Ja">
                        <?php else: ?>
                            <div class="av-ph"><svg><use xlink:href="./icons/symbol-defs.svg#icon-user"></use></svg></div>
                        <?php endif; ?>
                    </div>
                    <?php $ph = !empty($_SESSION['first_name']) ? 'O czym teraz myślisz, ' . htmlspecialchars($_SESSION['first_name']) . '?' : 'O czym teraz myślisz?'; ?>
                    <input type="text" name="content" class="form-input" placeholder="<?= $ph ?>">
                </div>

                <div id="media-preview-container">
                    <div class="media-preview-grid" id="media-preview-grid"></div>
                </div>

                <div class="post-create-bottom">
                    <div class="post-create-actions">
                        <input type="file" name="post_media[]" id="post-media-upload" accept="image/jpeg,image/png,image/webp,image/gif,video/mp4,video/webm" multiple style="display:none;" onchange="previewMedia(event)">
                        <label for="post-media-upload">
                            <svg style="fill:var(--primary-color)"><use xlink:href="./icons/symbol-defs.svg#icon-film"></use></svg>
                            Zdjęcia/Wideo
                        </label>
                    </div>
                    <button type="submit" name="submit_post" class="btn-submit-post">Opublikuj</button>
                </div>
            </form>
        </div>

        <!-- Propozycje znajomych -->
        <?php if (!empty($suggestions)): ?>
        <div class="suggestions-section">
            <h4>Propozycje znajomych</h4>
            <div class="suggestions-carousel-wrapper">
                <button class="carousel-arrow carousel-arrow-left" onclick="scrollCarousel(-1)">
                    <svg viewBox="0 0 24 24"><path d="M15.41 7.41L14 6l-6 6 6 6 1.41-1.41L10.83 12z"/></svg>
                </button>
                <div class="suggestions-carousel" id="suggestionsCarousel">
                    <?php foreach ($suggestions as $s): $sid = (int)$s['user_id']; ?>
                        <div class="suggestion-card" data-user-id="<?= $sid ?>">
                            <div class="suggestion-avatar">
                                <?php if (!empty($s['avatar_url'])): ?>
                                    <img src="<?= htmlspecialchars($s['avatar_url']) ?>" alt="">
                                <?php else: ?>
                                    <div style="width:100%;height:100%;display:flex;align-items:center;justify-content:center;font-size:28px;">👤</div>
                                <?php endif; ?>
                            </div>
                            <div class="suggestion-name" title="<?= htmlspecialchars($s['first_name'].' '.$s['last_name']) ?>"><?= htmlspecialchars($s['first_name'].' '.$s['last_name']) ?></div>
                            <div class="suggestion-location">
                                <svg style="width:12px;height:12px;fill:currentColor" viewBox="0 0 24 24"><path d="M12 2C8.13 2 5 5.13 5 9c0 5.25 7 13 7 13s7-7.75 7-13c0-3.87-3.13-7-7-7zm0 9.5c-1.38 0-2.5-1.12-2.5-2.5s1.12-2.5 2.5-2.5 2.5 1.12 2.5 2.5-1.12 2.5-2.5 2.5z"/></svg>
                                <?= htmlspecialchars($s['display_city'] ?? 'Brak lokalizacji') ?>
                            </div>
                            <div class="suggestion-actions">
                                <button class="btn-add-friend" onclick="addFriend(<?= $sid ?>,this)">Dodaj</button>
                                <button class="btn-remove-suggestion" onclick="removeSuggestion(<?= $sid ?>,this)">Usuń</button>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
                <button class="carousel-arrow carousel-arrow-right" onclick="scrollCarousel(1)">
                    <svg viewBox="0 0 24 24"><path d="M8.59 16.59L10 18l6-6-6-6-1.41 1.41L13.17 12z"/></svg>
                </button>
            </div>
        </div>
        <?php endif; ?>

        <!-- FEED postów -->
        <div id="feed-container">
            <?php if (!empty($initial_posts)): ?>
                <?php foreach ($initial_posts as $post): ?>
                    <?= renderPost($post, $current_user_id, $initial_batch[$post['post_id']] ?? []) ?>
                <?php endforeach; ?>
            <?php else: ?>
                <p style="text-align:center;color:var(--text-muted);padding:30px 0">Brak postów do wyświetlenia. Dodaj znajomych lub opublikuj coś!</p>
            <?php endif; ?>
        </div>

        <div id="feed-spinner">⏳ Ładowanie…</div>
        <?php if ($has_more_posts): ?>
            <button id="load-more-posts-btn" data-offset="20">Załaduj więcej postów</button>
        <?php endif; ?>

    </main>

    <!-- SIDEBAR RIGHT -->
    <aside class="sidebar-right card">
        <h3 class="m-bottom-15">Kontakty</h3>
        <p style="color:var(--text-muted);font-size:13px;">Przejdź do <a href="chat.php">Czatu</a>, aby zobaczyć kontakty.</p>
    </aside>

</div>

<!-- LIGHTBOX -->
<div class="lightbox-overlay" id="lightbox" onclick="if(event.target===this)closeLightbox()">
    <div class="lightbox-inner" id="lightbox-inner">
        <button class="lightbox-btn lightbox-prev" onclick="lightboxNav(-1)">&#10094;</button>
        <img id="lb-img" src="" alt="" style="display:none">
        <video id="lb-video" controls style="display:none"></video>
        <button class="lightbox-btn lightbox-next" onclick="lightboxNav(1)">&#10095;</button>
        <button class="lightbox-btn lightbox-close" onclick="closeLightbox()">&#10005;</button>
        <div class="lightbox-counter" id="lb-counter"></div>
    </div>
</div>

<!-- MODALS -->
<div id="modal-edit" class="fb-modal-overlay">
    <div class="fb-modal-card">
        <div class="fb-modal-header"><h3>Edytuj post</h3><button class="fb-modal-close" onclick="closeModal('edit')">&times;</button></div>
        <form action="post_actions.php" method="POST">
            <div class="fb-modal-body">
                <input type="hidden" name="csrf_token" value="<?= generate_csrf_token() ?>">
                <input type="hidden" name="action" value="edit">
                <input type="hidden" name="post_id" id="edit-post-id">
                <textarea name="content" id="edit-post-content" class="fb-modal-textarea" required></textarea>
            </div>
            <div class="fb-modal-footer">
                <button type="button" class="fb-btn fb-btn-secondary" onclick="closeModal('edit')">Anuluj</button>
                <button type="submit" class="fb-btn fb-btn-primary">Zapisz</button>
            </div>
        </form>
    </div>
</div>

<div id="modal-delete" class="fb-modal-overlay">
    <div class="fb-modal-card">
        <div class="fb-modal-header"><h3>Usunąć post?</h3><button class="fb-modal-close" onclick="closeModal('delete')">&times;</button></div>
        <form action="post_actions.php" method="POST">
            <div class="fb-modal-body">
                <input type="hidden" name="csrf_token" value="<?= generate_csrf_token() ?>">
                <input type="hidden" name="action" value="delete">
                <input type="hidden" name="post_id" id="delete-post-id">
                <p>Czy na pewno chcesz usunąć ten post? Tej operacji nie można cofnąć.</p>
            </div>
            <div class="fb-modal-footer">
                <button type="button" class="fb-btn fb-btn-secondary" onclick="closeModal('delete')">Anuluj</button>
                <button type="submit" class="fb-btn fb-btn-danger">Usuń</button>
            </div>
        </form>
    </div>
</div>

<div id="modal-report" class="fb-modal-overlay">
    <div class="fb-modal-card">
        <div class="fb-modal-header"><h3>Zgłoś post</h3><button class="fb-modal-close" onclick="closeModal('report')">&times;</button></div>
        <form action="post_actions.php" method="POST">
            <div class="fb-modal-body">
                <input type="hidden" name="csrf_token" value="<?= generate_csrf_token() ?>">
                <input type="hidden" name="action" value="report">
                <input type="hidden" name="post_id" id="report-post-id">
                <p style="margin-bottom:12px;color:var(--text-muted)">Wybierz powód zgłoszenia:</p>
                <label class="report-reason"><input type="radio" name="reason" value="spam" checked> Spam</label>
                <label class="report-reason"><input type="radio" name="reason" value="harassment"> Nękanie lub obraźliwe treści</label>
                <label class="report-reason"><input type="radio" name="reason" value="hate_speech"> Mowa nienawiści</label>
                <label class="report-reason"><input type="radio" name="reason" value="other"> Inne</label>
            </div>
            <div class="fb-modal-footer">
                <button type="button" class="fb-btn fb-btn-secondary" onclick="closeModal('report')">Anuluj</button>
                <button type="submit" class="fb-btn fb-btn-primary">Wyślij zgłoszenie</button>
            </div>
        </form>
    </div>
</div>

<!-- Template komentarza – Problem 7 -->
<template id="comment-tpl">
    <div class="comment-item">
        <div class="cmt-avatar"></div>
        <div class="cmt-body">
            <div class="cmt-bubble">
                <span class="cmt-author"></span>
                <span class="cmt-text"></span>
            </div>
            <div class="cmt-meta">
                <div class="comment-reactions">
                    <div class="comment-reaction-picker"></div>
                </div>
                <button class="cmt-action-btn reply-btn">Odpowiedz</button>
                <span class="cmt-time"></span>
            </div>
            <div class="replies-container"></div>
        </div>
    </div>
</template>

<link rel="stylesheet" href="assets/css/toast.css">
<script src="assets/js/toast.js"></script>
<script>
const APP_CONFIG = { csrfToken: '<?= generate_csrf_token() ?>' };
</script>
<script src="assets/js/feed.js"></script>
</body>
</html>
