<?php
session_start();
require_once 'config/database.php';

if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
    header('Location: login.php');
    exit;
}

$current_user_id = (int) $_SESSION['user_id'];

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
        $html   = '';
        foreach ($posts as $post) $html .= renderPost($post, $current_user_id);
        echo json_encode(['success'=>true,'html'=>$html,'has_more'=>count($posts)===20]);
        exit;
    }

    echo json_encode(['success'=>false,'message'=>'Nieznana akcja.']); exit;
}

// ─────────────────────────────────────────────
// DANE: feed + sugestie + licznik zaproszeń
// ─────────────────────────────────────────────

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
    $cid    = (int)$c['comment_id'];
    $name   = htmlspecialchars($c['first_name'] . ' ' . $c['last_name']);
    $time   = relativeTime($c['created_at']);
    $text   = nl2br(htmlspecialchars($c['content']));
    $avatar = !empty($c['avatar_url'])
        ? "<img src=\"" . htmlspecialchars($c['avatar_url']) . "\" alt=\"Avatar\" loading=\"lazy\">"
        : '<span class="cmt-av-ph">👤</span>';
    $reply_count = (int)($c['reply_count'] ?? 0);

    $reply_mention = '';
    if (!empty($c['reply_to_username'])) {
        $ru = htmlspecialchars($c['reply_to_username']);
        $reply_mention = "<a class=\"reply-mention\" href=\"#\">@$ru</a> ";
    }

    $reactions_html = isset($c['reactions']) ? renderCommentReactions($c['reactions'], $cid) : '';
    $indent = $is_reply ? ' reply-comment' : '';
    $author_id = (int)$c['author_id'];

    $html  = "<div class=\"comment-item$indent\" id=\"comment-$cid\" data-comment-id=\"$cid\" data-author-id=\"$author_id\">";
    $html .= "<div class=\"cmt-avatar\">$avatar</div>";
    $html .= "<div class=\"cmt-body\">";
    $html .= "<div class=\"cmt-bubble\">";
    $html .= "<span class=\"cmt-author\">$name</span>";
    $html .= "<span class=\"cmt-text\">$reply_mention$text</span>";
    $html .= "</div>";
    $html .= "<div class=\"cmt-meta\">";
    $html .= $reactions_html;
    $html .= "<button class=\"cmt-action-btn reply-btn\" data-comment-id=\"$cid\" data-author-id=\"$author_id\" data-author-name=\"" . htmlspecialchars($c['first_name'] . ' ' . $c['last_name']) . "\">Odpowiedz</button>";
    $html .= "<span class=\"cmt-time\">$time</span>";
    $html .= "</div>";

    if (!$is_reply && $reply_count > 0) {
        $html .= "<div class=\"replies-container\" id=\"replies-$cid\" data-loaded=\"0\">";
        $html .= "<button class=\"load-replies-btn\" data-comment-id=\"$cid\" data-count=\"$reply_count\">Zobacz $reply_count odpowiedzi</button>";
        $html .= "</div>";
    } elseif (!$is_reply) {
        $html .= "<div class=\"replies-container\" id=\"replies-$cid\" data-loaded=\"0\"></div>";
    }

    $html .= "</div></div>";
    return $html;
}

function renderPost(array $post, int $uid): string {
    $pid        = (int)$post['post_id'];
    $name       = htmlspecialchars($post['first_name'] . ' ' . $post['last_name']);
    $time       = relativeTime($post['created_at']);
    $content    = nl2br(htmlspecialchars($post['content']));
    $avatar_url = $post['avatar_url'] ?? '';

    $avatar = $avatar_url
        ? "<img src=\"" . htmlspecialchars($avatar_url) . "\" alt=\"Avatar\">"
        : '<div class="placeholder-svg"><svg><use xlink:href="./icons/symbol-defs.svg#icon-user"></use></svg></div>';

    $media      = fetchPostMedia($pid);
    $reactions  = fetchPostReactions($pid, $uid);
    $comments   = fetchInitialComments($pid, $uid);
    $total_cmt  = fetchTotalComments($pid);

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
                <a href="javascript:void(0)" class="post-options-item" onclick="openModal('edit',<?= $pid ?>,'<?= urlencode(html_entity_decode($post['content'])) ?>')">Edytuj</a>
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

$initial_posts = fetchFeedPosts($current_user_id, 0);
$has_more_posts = count($initial_posts) === 20;
?>
<!doctype html>
<html lang="pl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>TwarzBlok – Feed</title>
    <link rel="stylesheet" href="css/style.css">
    <style>
        svg { max-width: 24px; max-height: 24px; }

        /* ── Layout ── */
        .navbar { display:flex; justify-content:space-between; align-items:center; padding:0 15px; box-sizing:border-box; position:fixed; top:0; left:0; width:100%; z-index:1000; }
        .fb-container { display:flex; justify-content:space-between; align-items:flex-start; max-width:1200px; margin:70px auto 20px; padding:0 15px; gap:20px; }
        .sidebar-left, .sidebar-right { width:250px; flex-shrink:0; position:sticky; top:80px; }
        .feed { flex-grow:1; max-width:600px; margin:0 auto; }

        /* ── Post create ── */
        .post-form-card { background:var(--bg-surface); border-radius:10px; padding:12px 16px; box-shadow:0 1px 2px rgba(0,0,0,.1); margin-bottom:15px; }
        .post-create-top { display:flex; gap:8px; padding-bottom:12px; }
        .post-create-top .av-wrap { width:40px; height:40px; flex-shrink:0; }
        .post-create-top .av-wrap img { width:100%; height:100%; object-fit:cover; border-radius:50%; }
        .post-create-top .av-wrap .av-ph { width:100%; height:100%; display:flex; align-items:center; justify-content:center; background:var(--border-color); border-radius:50%; }
        .post-create-top .form-input { flex-grow:1; height:40px; padding:0 16px; border:1px solid var(--border-color); border-radius:20px; background:var(--bg-main); outline:none; cursor:pointer; }
        .post-create-top .form-input:focus { border-color:var(--primary-color); background:#fff; cursor:text; }
        .post-create-bottom { display:flex; justify-content:space-between; align-items:center; padding-top:10px; flex-wrap:wrap; gap:10px; }
        .post-create-actions { display:flex; gap:15px; }
        .post-create-actions label, .post-create-actions .action-item { display:flex; align-items:center; gap:8px; color:var(--text-muted); font-size:14px; font-weight:600; cursor:pointer; padding:6px 8px; border-radius:6px; transition:background .2s; }
        .post-create-actions label:hover, .post-create-actions .action-item:hover { background:var(--bg-hover); }
        .btn-submit-post { border:none; background:var(--primary-color); color:#fff; padding:6px 20px; font-weight:600; border-radius:20px; cursor:pointer; transition:background .2s; }
        .btn-submit-post:hover { background:var(--primary-hover); }
        #media-preview-container { display:none; margin-top:12px; }
        .media-preview-grid { display:flex; flex-wrap:wrap; gap:8px; }
        .media-preview-item { position:relative; width:80px; height:80px; border-radius:8px; overflow:hidden; background:var(--border-color); }
        .media-preview-item img, .media-preview-item video { width:100%; height:100%; object-fit:cover; }
        .media-preview-item .rm-preview { position:absolute; top:2px; right:2px; background:rgba(0,0,0,.6); color:#fff; border:none; border-radius:50%; width:20px; height:20px; font-size:12px; cursor:pointer; display:flex; align-items:center; justify-content:center; }

        /* ── Friend badge ── */
        .friend-badge { position:absolute; top:-8px; right:-10px; background:#e63946; color:#fff; border-radius:50%; min-width:20px; height:20px; font-size:12px; font-weight:700; display:flex; align-items:center; justify-content:center; padding:0 4px; box-shadow:0 0 0 2px var(--bg-main,#fff); }

        /* ── Post cards ── */
        .post-feed-card { background:var(--bg-surface); border-radius:10px; padding:15px; margin-bottom:15px; box-shadow:0 1px 2px rgba(0,0,0,.1); }
        .post-feed-header { display:flex; align-items:center; gap:10px; margin-bottom:10px; }
        .avatar-box { width:40px; height:40px; border-radius:50%; overflow:hidden; flex-shrink:0; }
        .avatar-box img { width:100%; height:100%; object-fit:cover; }
        .placeholder-svg { width:100%; height:100%; background:var(--border-color); display:flex; align-items:center; justify-content:center; }
        .post-feed-author { font-weight:600; font-size:15px; color:var(--text-main); }
        .post-feed-time { font-size:12px; color:var(--text-muted); }
        .post-feed-content { font-size:15px; color:var(--text-main); margin-bottom:10px; line-height:1.5; }
        .relative-header { position:relative!important; }

        /* ── Post options ── */
        .post-options-dropdown { position:absolute; right:0; top:50%; transform:translateY(-50%); z-index:10; }
        .post-options-trigger { width:36px; height:36px; border-radius:50%; display:flex; align-items:center; justify-content:center; cursor:pointer; }
        .post-options-trigger:hover { background:var(--bg-hover); }
        .post-options-menu { display:none; position:absolute; right:0; top:100%; background:#fff; min-width:140px; border:1px solid var(--border-color); border-radius:var(--radius-main); box-shadow:0 4px 12px rgba(0,0,0,.15); padding:5px 0; z-index:100; }
        .post-options-dropdown:hover .post-options-menu { display:block; }
        .post-options-item { display:block; padding:8px 16px; color:#1c1e21!important; font-size:14px; font-weight:500; text-decoration:none!important; }
        .post-options-item:hover { background:var(--bg-hover); }
        .post-options-item.danger:hover { background:#ffebe9; color:#e41e3f!important; }
        .post-feed-card:hover { z-index:999; position:relative; }

        /* ── Media gallery ── */
        .media-gallery { border-radius:8px; overflow:hidden; margin-top:10px; cursor:pointer; }
        .media-gallery.gallery-1 .media-tile img,
        .media-gallery.gallery-1 .media-tile video { max-height:500px; object-fit:contain; width:100%; background:#000; }
        .media-gallery.gallery-2 { display:grid; grid-template-columns:1fr 1fr; gap:2px; max-height:300px; }
        .media-gallery.gallery-3 { display:grid; grid-template-columns:1fr 1fr; grid-template-rows:150px 150px; gap:2px; }
        .media-gallery.gallery-3 .media-tile:first-child { grid-row:span 2; }
        .media-gallery.gallery-4plus { display:grid; grid-template-columns:1fr 1fr; grid-template-rows:200px 200px; gap:2px; }
        .media-tile { overflow:hidden; position:relative; }
        .media-tile img, .media-tile video { width:100%; height:100%; object-fit:cover; display:block; }
        .media-tile-more { display:flex; align-items:center; justify-content:center; width:100%; height:100%; background-size:cover; background-position:center; }
        .media-tile-more::before { content:''; position:absolute; inset:0; background:rgba(0,0,0,.45); }
        .media-tile-more span { position:relative; color:#fff; font-size:28px; font-weight:700; }

        /* ── Lightbox ── */
        .lightbox-overlay { display:none; position:fixed; inset:0; background:rgba(0,0,0,.92); z-index:9999; align-items:center; justify-content:center; }
        .lightbox-overlay.active { display:flex; }
        .lightbox-inner { position:relative; max-width:90vw; max-height:90vh; display:flex; align-items:center; justify-content:center; }
        .lightbox-inner img, .lightbox-inner video { max-width:90vw; max-height:88vh; border-radius:4px; display:block; }
        .lightbox-btn { position:absolute; background:rgba(255,255,255,.15); border:none; color:#fff; font-size:28px; width:48px; height:48px; border-radius:50%; cursor:pointer; display:flex; align-items:center; justify-content:center; transition:background .2s; z-index:2; }
        .lightbox-btn:hover { background:rgba(255,255,255,.3); }
        .lightbox-prev { left:-64px; }
        .lightbox-next { right:-64px; }
        .lightbox-close { top:-16px; right:-16px; position:absolute; }
        .lightbox-counter { position:absolute; bottom:-36px; left:50%; transform:translateX(-50%); color:#fff; font-size:14px; }

        /* ── Reactions ── */
        .post-reactions-summary { display:flex; flex-wrap:wrap; gap:6px; padding:6px 4px; min-height:28px; }
        .reaction-badge { display:inline-flex; align-items:center; gap:3px; font-size:13px; color:var(--text-muted); background:var(--bg-hover); border-radius:12px; padding:2px 8px; }
        .reaction-badge.mine { background:rgba(51,131,54,.12); color:var(--primary-color); font-weight:700; }
        .post-actions-bar { display:flex; justify-content:space-between; border-top:1px solid var(--border-color); margin-top:4px; padding-top:4px; }
        .action-button-wrapper { flex:1; position:relative; display:flex; justify-content:center; }
        .action-btn { width:100%; background:none; border:none; padding:10px; display:flex; align-items:center; justify-content:center; gap:8px; color:var(--text-muted); font-size:14px; font-weight:600; cursor:pointer; border-radius:6px; transition:background .2s,color .2s; }
        .action-btn:hover { background:var(--bg-hover); color:var(--primary-color); }
        .action-icon { width:20px!important; height:20px!important; fill:currentColor; }
        .active-reacted { color:var(--primary-color)!important; }

        /* ── Reaction popup ── */
        .reaction-container { position:relative; }
        .reactions-popup { display:flex; gap:10px; position:absolute; bottom:calc(100% + 4px); left:0; background:#fff; padding:8px 14px; border-radius:30px; box-shadow:0 4px 16px rgba(0,0,0,.18); border:1px solid var(--border-color); opacity:0; visibility:hidden; transform:translateY(8px); transition:opacity .2s,transform .2s,visibility .2s; z-index:10001; }
        .reaction-container:hover .reactions-popup { opacity:1; visibility:visible; transform:translateY(0); }
        .reaction-emoji { font-size:24px; cursor:pointer; transition:transform .15s; user-select:none; }
        .reaction-emoji:hover { transform:scale(1.4); }

        /* ── Comments ── */
        .comments-section { border-top:1px solid var(--border-color); padding-top:10px; margin-top:8px; }
        .comments-list { margin-bottom:10px; }
        .comment-item { display:flex; gap:8px; margin-bottom:10px; align-items:flex-start; }
        .reply-comment { padding-left:40px; }
        .cmt-avatar img, .cmt-av-ph { width:32px; height:32px; border-radius:50%; object-fit:cover; flex-shrink:0; background:var(--bg-hover); display:flex; align-items:center; justify-content:center; font-size:16px; }
        .cmt-body { flex:1; min-width:0; }
        .cmt-bubble { background:var(--bg-main); padding:8px 12px; border-radius:18px; display:inline-block; max-width:100%; word-break:break-word; }
        .cmt-author { font-size:13px; font-weight:700; color:#050505; display:block; margin-bottom:2px; }
        .cmt-text { font-size:14px; color:#050505; }
        .reply-mention { color:var(--primary-color); font-weight:600; text-decoration:none; }
        .cmt-meta { display:flex; align-items:center; gap:8px; flex-wrap:wrap; padding:4px 4px 0; }
        .cmt-time { font-size:12px; color:var(--text-muted); }
        .cmt-action-btn { background:none; border:none; font-size:12px; font-weight:700; color:var(--text-muted); cursor:pointer; padding:0 2px; }
        .cmt-action-btn:hover { color:var(--primary-color); }

        /* ── Comment reactions ── */
        .comment-reactions { position:relative; display:inline-flex; align-items:center; gap:4px; }
        .comment-reaction-badge { font-size:12px; cursor:pointer; padding:1px 5px; border-radius:10px; background:var(--bg-hover); color:var(--text-muted); display:inline-flex; align-items:center; gap:2px; }
        .comment-reaction-badge.mine { background:rgba(51,131,54,.1); color:var(--primary-color); font-weight:700; }
        .comment-reaction-badge:hover { background:var(--border-color); }
        .comment-reaction-picker { display:none; position:absolute; bottom:calc(100% + 4px); left:0; background:#fff; border:1px solid var(--border-color); border-radius:24px; padding:6px 10px; box-shadow:0 4px 12px rgba(0,0,0,.15); gap:8px; z-index:500; }
        .comment-reactions:hover .comment-reaction-picker { display:flex; }
        .comment-pick-emoji { font-size:20px; cursor:pointer; transition:transform .15s; }
        .comment-pick-emoji:hover { transform:scale(1.35); }

        /* ── Load replies ── */
        .load-replies-btn, .load-more-comments-btn { background:none; border:none; color:var(--primary-color); font-size:13px; font-weight:700; cursor:pointer; padding:4px 0; display:block; margin-top:4px; }
        .load-replies-btn:hover, .load-more-comments-btn:hover { text-decoration:underline; }
        .replies-container { padding-top:4px; }

        /* ── Comment form ── */
        .comment-form { margin-top:8px; }
        .cmt-form-row { display:flex; align-items:center; gap:8px; background:var(--bg-main); border-radius:20px; padding:4px 12px; }
        .cmt-my-avatar { width:32px; height:32px; border-radius:50%; object-fit:cover; flex-shrink:0; }
        .cmt-input-wrap { display:flex; flex:1; align-items:center; gap:4px; }
        .comment-input { flex:1; background:none; border:none; outline:none; font-size:14px; color:#050505; padding:8px 4px; }
        .comment-submit-btn { background:none; border:none; color:var(--primary-color); font-size:18px; cursor:pointer; padding:0 4px; }
        .reply-indicator { display:flex; align-items:center; gap:6px; padding:4px 12px; font-size:13px; color:var(--text-muted); background:rgba(51,131,54,.07); border-radius:8px; margin-top:4px; }
        .reply-label { flex:1; }
        .cancel-reply-btn { background:none; border:none; font-size:16px; cursor:pointer; color:var(--text-muted); }

        /* ── Load more ── */
        #load-more-posts-btn { display:block; width:100%; padding:12px; background:var(--bg-surface); border:1px solid var(--border-color); border-radius:10px; font-size:14px; font-weight:600; color:var(--primary-color); cursor:pointer; text-align:center; margin-bottom:20px; transition:background .2s; }
        #load-more-posts-btn:hover { background:var(--bg-hover); }
        #feed-spinner { text-align:center; padding:20px; color:var(--text-muted); display:none; }

        /* ── Suggestions ── */
        .suggestions-section { background:var(--bg-surface); border-radius:10px; padding:16px; box-shadow:0 1px 2px rgba(0,0,0,.1); margin-bottom:15px; }
        .suggestions-section h4 { font-size:16px; font-weight:600; color:var(--text-main); margin-bottom:12px; }
        .suggestions-carousel-wrapper { position:relative; display:flex; align-items:center; }
        .suggestions-carousel { display:flex; gap:12px; overflow-x:auto; scroll-behavior:smooth; scroll-snap-type:x mandatory; padding:4px 2px 8px; scrollbar-width:thin; scrollbar-color:var(--border-color) transparent; }
        .suggestions-carousel::-webkit-scrollbar { height:6px; }
        .suggestions-carousel::-webkit-scrollbar-thumb { background:var(--border-color); border-radius:10px; }
        .suggestion-card { flex:0 0 auto; width:180px; background:var(--bg-surface); border-radius:12px; box-shadow:0 1px 3px rgba(0,0,0,.1); padding:16px 12px; text-align:center; scroll-snap-align:start; position:relative; display:flex; flex-direction:column; align-items:center; gap:8px; transition:transform .2s,box-shadow .2s; }
        .suggestion-card:hover { transform:translateY(-2px); box-shadow:0 4px 12px rgba(0,0,0,.15); }
        .suggestion-avatar { width:72px; height:72px; border-radius:50%; overflow:hidden; background:var(--border-color); }
        .suggestion-avatar img { width:100%; height:100%; object-fit:cover; }
        .suggestion-name { font-weight:600; font-size:14px; color:var(--text-main); overflow:hidden; text-overflow:ellipsis; white-space:nowrap; max-width:100%; }
        .suggestion-location { font-size:12px; color:var(--text-muted); display:flex; align-items:center; gap:4px; justify-content:center; }
        .suggestion-actions { display:flex; gap:8px; width:100%; margin-top:4px; }
        .btn-add-friend { flex:1; padding:7px 10px; font-size:13px; font-weight:600; border:none; border-radius:6px; cursor:pointer; background:var(--primary-color); color:#fff; transition:background .2s; }
        .btn-add-friend:hover { background:var(--primary-hover); }
        .btn-add-friend:disabled { background:#42b72a; cursor:default; }
        .btn-remove-suggestion { padding:7px 10px; font-size:13px; font-weight:600; border:1px solid var(--border-color); border-radius:6px; cursor:pointer; background:transparent; color:var(--text-muted); transition:background .2s; white-space:nowrap; }
        .btn-remove-suggestion:hover { background:var(--bg-hover); }
        .carousel-arrow { position:absolute; top:50%; transform:translateY(-50%); z-index:10; width:36px; height:36px; border-radius:50%; border:1px solid var(--border-color); background:rgba(255,255,255,.95); cursor:pointer; display:flex; align-items:center; justify-content:center; box-shadow:0 2px 8px rgba(0,0,0,.2); transition:box-shadow .2s; }
        .carousel-arrow:hover { box-shadow:0 4px 12px rgba(0,0,0,.25); }
        .carousel-arrow-left { left:-12px; }
        .carousel-arrow-right { right:-12px; }
        .carousel-arrow svg { width:18px; height:18px; fill:var(--text-main); }
        .suggestions-empty { text-align:center; padding:20px; color:var(--text-muted); font-size:14px; }

        /* ── Modals ── */
        .fb-modal-overlay { display:none; position:fixed; inset:0; background:rgba(0,0,0,.5); z-index:9999; align-items:center; justify-content:center; }
        .fb-modal-card { background:#fff; width:100%; max-width:500px; border-radius:var(--radius-main); box-shadow:0 12px 28px rgba(0,0,0,.2); overflow:hidden; animation:modalIn .2s ease-out; }
        @keyframes modalIn { from{transform:scale(.95);opacity:0} to{transform:scale(1);opacity:1} }
        .fb-modal-header { display:flex; align-items:center; justify-content:space-between; padding:16px; border-bottom:1px solid var(--border-color); }
        .fb-modal-header h3 { margin:0; font-size:20px; font-weight:700; color:var(--text-main); }
        .fb-modal-close { background:none; border:none; font-size:24px; cursor:pointer; color:var(--text-muted); }
        .fb-modal-body { padding:16px; color:var(--text-main); }
        .fb-modal-textarea { width:100%; height:120px; padding:12px; border:1px solid var(--border-color); border-radius:var(--radius-main); resize:none; font-size:15px; outline:none; box-sizing:border-box; }
        .fb-modal-textarea:focus { border-color:var(--primary-color); }
        .fb-modal-footer { padding:12px 16px; background:#f0f2f5; display:flex; justify-content:flex-end; gap:8px; }
        .fb-btn { padding:8px 16px; border:none; border-radius:6px; font-size:14px; font-weight:600; cursor:pointer; }
        .fb-btn-secondary { background:#e4e6eb; color:#050505; }
        .fb-btn-secondary:hover { background:#d8dadf; }
        .fb-btn-primary { background:var(--primary-color); color:#fff; }
        .fb-btn-primary:hover { background:var(--primary-hover); }
        .fb-btn-danger { background:#e41e3f; color:#fff; }
        .fb-btn-danger:hover { background:#c91a37; }
        .report-reason { display:flex; align-items:center; gap:10px; padding:8px; cursor:pointer; border-radius:6px; }
        .report-reason:hover { background:var(--bg-hover); }

        /* ── Feed separator ── */
        .feed-separator { text-align:center; font-size:13px; color:var(--text-muted); padding:10px 0 14px; display:flex; align-items:center; gap:10px; }
        .feed-separator::before, .feed-separator::after { content:''; flex:1; border-top:1px solid var(--border-color); }
    </style>
</head>
<body>

<!-- NAVBAR -->
<nav class="navbar">
    <div class="navbar-brand">TwarzBlok
        <input class="suchemashine" type="text" placeholder="Szukaj na TwarzBlok">
    </div>
    <div class="navbar-links">
        <a href="index.php"><svg><use xlink:href="./icons/symbol-defs.svg#icon-home"></use></svg></a>
        <a href="games.php"><svg><use xlink:href="./icons/symbol-defs.svg#icon-film"></use></svg></a>
        <a href="friend_requests.php" style="position:relative;display:inline-flex;align-items:center;">
            <svg><use xlink:href="./icons/symbol-defs.svg#icon-users"></use></svg>
            <span id="pending-friends-count" class="friend-badge" style="<?= $pending_count === 0 ? 'display:none' : '' ?>"><?= $pending_count ?></span>
        </a>
        <a href="#"><svg><use xlink:href="./icons/symbol-defs.svg#icon-briefcase"></use></svg></a>
        <a href="chat.php"><svg><use xlink:href="./icons/symbol-defs.svg#icon-bubbles4"></use></svg></a>
    </div>
    <div class="navbar-links2">
        <?php if (isset($_SESSION['role']) && $_SESSION['role'] === 'admin'): ?>
            <a href="admin_panel.php" title="Panel Administratora">
                <div class="icon-wrapper" style="background:rgba(230,57,70,.1);color:#e63946;">
                    <svg style="fill:currentColor"><use xlink:href="./icons/symbol-defs.svg#icon-list2"></use></svg>
                </div>
            </a>
        <?php endif; ?>
        <a href="#"><div class="icon-wrapper"><svg><use xlink:href="./icons/symbol-defs.svg#icon-list2"></use></svg></div></a>
        <a href="#"><div class="icon-wrapper"><svg><use xlink:href="./icons/symbol-defs.svg#icon-bubbles2"></use></svg></div></a>
        <a href="#"><div class="icon-wrapper"><svg><use xlink:href="./icons/symbol-defs.svg#icon-bell"></use></svg></div></a>
        <div class="user-profile-dropdown">
            <div class="avatar-navbar-wrapper">
                <?php if (!empty($_SESSION['avatar_url'])): ?>
                    <img src="<?= htmlspecialchars($_SESSION['avatar_url']) ?>" alt="Profil" style="width:100%;height:100%;object-fit:cover;">
                <?php else: ?>
                    <svg style="width:24px;height:24px;"><use xlink:href="./icons/symbol-defs.svg#icon-user"></use></svg>
                <?php endif; ?>
            </div>
            <div class="dropdown-menu-content">
                <a href="settings.php" class="dropdown-item settings-btn"><svg><use xlink:href="./icons/symbol-defs.svg#icon-user"></use></svg> Ustawienia</a>
                <a href="logout.php" class="dropdown-item logout-btn"><svg><use xlink:href="./icons/symbol-defs.svg#icon-user"></use></svg> Wyloguj się</a>
            </div>
        </div>
    </div>
</nav>

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
                    <?= renderPost($post, $current_user_id) ?>
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

<script>
// ── Emoji map ──
const EMOJI_MAP = {like:'👍',love:'❤️',hug:'🤗',haha:'😆',wow:'😮',sad:'😢',angry:'😡'};
const EMOJI_LABEL = {like:'Lubię to',love:'Super',hug:'Trzymaj się',haha:'Haha',wow:'Wow',sad:'Smutne',angry:'Złość'};

// ── Media preview (multi-file) ──
function previewMedia(event) {
    const container = document.getElementById('media-preview-container');
    const grid = document.getElementById('media-preview-grid');
    grid.innerHTML = '';
    const files = event.target.files;
    if (!files || files.length === 0) { container.style.display = 'none'; return; }
    container.style.display = 'block';
    Array.from(files).slice(0, 10).forEach((file, i) => {
        const item = document.createElement('div');
        item.className = 'media-preview-item';
        const rm = document.createElement('button');
        rm.type = 'button'; rm.className = 'rm-preview'; rm.textContent = '✕';
        rm.onclick = () => item.remove();
        if (file.type.startsWith('video/')) {
            const v = document.createElement('video');
            v.src = URL.createObjectURL(file); v.muted = true;
            item.appendChild(v);
        } else {
            const img = document.createElement('img');
            img.src = URL.createObjectURL(file);
            item.appendChild(img);
        }
        item.appendChild(rm);
        grid.appendChild(item);
    });
}

// ── Lightbox ──
let lbMedia = [], lbIdx = 0;
function openLightbox(galleryEl, startIdx) {
    try { lbMedia = JSON.parse(galleryEl.dataset.media); } catch(e) { return; }
    lbIdx = startIdx;
    document.getElementById('lightbox').classList.add('active');
    showLbMedia();
}
function showLbMedia() {
    const m = lbMedia[lbIdx];
    const img = document.getElementById('lb-img');
    const vid = document.getElementById('lb-video');
    const ctr = document.getElementById('lb-counter');
    img.style.display = 'none'; vid.style.display = 'none';
    if (m.type === 'video') { vid.src = m.url; vid.style.display = 'block'; }
    else { img.src = m.url; img.style.display = 'block'; }
    ctr.textContent = lbMedia.length > 1 ? `${lbIdx+1} / ${lbMedia.length}` : '';
    document.querySelector('.lightbox-prev').style.display = lbMedia.length > 1 ? 'flex' : 'none';
    document.querySelector('.lightbox-next').style.display = lbMedia.length > 1 ? 'flex' : 'none';
}
function lightboxNav(dir) {
    lbIdx = (lbIdx + dir + lbMedia.length) % lbMedia.length;
    showLbMedia();
}
function closeLightbox() {
    document.getElementById('lightbox').classList.remove('active');
    const vid = document.getElementById('lb-video');
    vid.pause(); vid.src = '';
}
document.addEventListener('keydown', e => {
    if (!document.getElementById('lightbox').classList.contains('active')) return;
    if (e.key === 'ArrowLeft') lightboxNav(-1);
    else if (e.key === 'ArrowRight') lightboxNav(1);
    else if (e.key === 'Escape') closeLightbox();
});

// ── Reakcje na posty ──
async function toggleReaction(postId, type) {
    const btn   = document.getElementById('react-btn-' + postId);
    const current = btn.dataset.userReaction;
    const newType = (current === type) ? null : type;

    const res = await fetch('reactions/toggle.php', {
        method: 'POST',
        headers: {'Content-Type':'application/x-www-form-urlencoded'},
        body: `type=${encodeURIComponent(type)}&post_id=${postId}`
    });
    const data = await res.json();
    if (!data.success) return;

    // Aktualizuj stan przycisku
    const userReaction = data.user_reaction;
    btn.dataset.userReaction = userReaction || '';
    btn.className = userReaction ? 'action-btn active-reacted' : 'action-btn';
    document.getElementById('react-btn-text-' + postId).textContent =
        userReaction ? (EMOJI_MAP[userReaction] + ' ' + EMOJI_LABEL[userReaction]) : 'Reakcja';

    // Aktualizuj badges
    const summary = document.getElementById('react-summary-' + postId);
    let html = '';
    for (const [t, cnt] of Object.entries(data.counts)) {
        if (cnt > 0) {
            const mine = (t === userReaction) ? ' mine' : '';
            html += `<span class="reaction-badge${mine}" title="${EMOJI_LABEL[t]}">${EMOJI_MAP[t]} <b>${cnt}</b></span>`;
        }
    }
    summary.innerHTML = html;
}

// ── Reakcje na komentarze ──
document.addEventListener('click', async function(e) {
    const btn = e.target.closest('.comment-pick-emoji');
    if (!btn) return;
    const type = btn.dataset.type;
    const cid  = btn.dataset.commentId;

    const res = await fetch('reactions/toggle.php', {
        method: 'POST',
        headers: {'Content-Type':'application/x-www-form-urlencoded'},
        body: `type=${encodeURIComponent(type)}&comment_id=${cid}`
    });
    const data = await res.json();
    if (!data.success) return;

    const container = document.querySelector(`.comment-reactions[data-comment-id="${cid}"]`);
    if (!container) return;

    // Odśwież badges (zostawiamy picker)
    container.querySelectorAll('.comment-reaction-badge').forEach(el => el.remove());
    const picker = container.querySelector('.comment-reaction-picker');
    const frag = [];
    for (const [t, cnt] of Object.entries(data.counts)) {
        if (cnt > 0) {
            const mine = (t === data.user_reaction) ? ' mine' : '';
            frag.push(`<span class="comment-reaction-badge${mine}" data-type="${t}" title="${EMOJI_LABEL[t]}">${EMOJI_MAP[t]} ${cnt}</span>`);
        }
    }
    picker.insertAdjacentHTML('beforebegin', frag.join(''));
});

// ── Komentarze ──
function toggleComments(postId) {
    const sec = document.getElementById('comments-' + postId);
    sec.style.display = sec.style.display === 'none' ? 'block' : 'none';
}

// Złóż komentarz / odpowiedź
document.addEventListener('submit', async function(e) {
    const form = e.target.closest('.comment-form');
    if (!form) return;
    e.preventDefault();

    const postId  = form.dataset.postId;
    const input   = form.querySelector('.comment-input');
    const content = input.value.trim();
    if (!content) return;

    const parentId    = form.querySelector('[name=parent_comment_id]').value;
    const replyToId   = form.querySelector('[name=reply_to_user_id]').value;

    const fd = new FormData();
    fd.append('action', 'comment');
    fd.append('post_id', postId);
    fd.append('comment_content', content);
    fd.append('parent_comment_id', parentId);
    fd.append('reply_to_user_id', replyToId);

    const res  = await fetch('post_actions.php', { method:'POST', body: fd });
    const data = await res.json();
    if (!data.success) { alert(data.message || 'Błąd.'); return; }

    input.value = '';
    cancelReply(form);

    const avatarHtml = data.user_avatar
        ? `<img src="${data.user_avatar}" alt="Avatar" loading="lazy">`
        : `<span class="cmt-av-ph">👤</span>`;

    const replyMention = data.reply_to_username
        ? `<a class="reply-mention" href="#">@${data.reply_to_username}</a> `
        : '';

    const newCmt = `
        <div class="comment-item ${data.parent_comment_id ? 'reply-comment' : ''}" id="comment-${data.comment_id}" data-comment-id="${data.comment_id}">
            <div class="cmt-avatar">${avatarHtml}</div>
            <div class="cmt-body">
                <div class="cmt-bubble">
                    <span class="cmt-author">${data.user_name}</span>
                    <span class="cmt-text">${replyMention}${escHtml(data.content)}</span>
                </div>
                <div class="cmt-meta">
                    <div class="comment-reactions" data-comment-id="${data.comment_id}">
                        <div class="comment-reaction-picker" data-comment-id="${data.comment_id}">
                            ${Object.entries(EMOJI_MAP).map(([t,e])=>`<span class="comment-pick-emoji" data-type="${t}" data-comment-id="${data.comment_id}" title="${EMOJI_LABEL[t]}">${e}</span>`).join('')}
                        </div>
                    </div>
                    <button class="cmt-action-btn reply-btn"
                            data-comment-id="${data.comment_id}"
                            data-author-id="${data.author_id || ''}"
                            data-author-name="${data.user_name}">Odpowiedz</button>
                    <span class="cmt-time">przed chwilą</span>
                </div>
                <div class="replies-container" id="replies-${data.comment_id}" data-loaded="0"></div>
            </div>
        </div>`;

    if (data.parent_comment_id) {
        const repliesBox = document.getElementById('replies-' + data.parent_comment_id);
        if (repliesBox) repliesBox.insertAdjacentHTML('beforeend', newCmt);
    } else {
        const list = document.getElementById('comments-list-' + postId);
        if (list) list.insertAdjacentHTML('afterbegin', newCmt);
        // Zaktualizuj licznik
        const cntEl = document.getElementById('cmt-count-' + postId);
        if (cntEl) {
            const m = cntEl.textContent.match(/\d+/);
            const n = m ? parseInt(m[0]) + 1 : 1;
            cntEl.textContent = `Komentarz (${n})`;
        }
    }
});

// Kliknięcie "Odpowiedz"
document.addEventListener('click', function(e) {
    const btn = e.target.closest('.reply-btn');
    if (!btn) return;

    const commentId  = btn.dataset.commentId;
    const authorId   = btn.dataset.authorId;
    const authorName = btn.dataset.authorName;

    // Znajdź formularz w tym samym posts card
    const card = btn.closest('.post-feed-card');
    if (!card) return;
    const form = card.querySelector('.comment-form');
    if (!form) return;

    form.querySelector('[name=parent_comment_id]').value = commentId;
    form.querySelector('[name=reply_to_user_id]').value  = authorId;

    const indicator = form.querySelector('.reply-indicator');
    form.querySelector('.reply-label').textContent = 'Odpowiadasz: @' + authorName;
    indicator.style.display = 'flex';

    const input = form.querySelector('.comment-input');
    input.placeholder = '@' + authorName + ' ';
    input.focus();

    // Pokaż sekcję komentarzy jeśli ukryta
    const section = form.closest('.comments-section');
    if (section) section.style.display = 'block';
});

function cancelReply(form) {
    form.querySelector('[name=parent_comment_id]').value = '';
    form.querySelector('[name=reply_to_user_id]').value  = '';
    form.querySelector('.reply-indicator').style.display = 'none';
    form.querySelector('.comment-input').placeholder = 'Napisz komentarz…';
}

// Ładuj odpowiedzi
document.addEventListener('click', async function(e) {
    const btn = e.target.closest('.load-replies-btn');
    if (!btn) return;
    btn.disabled = true;
    btn.textContent = 'Ładowanie…';

    const commentId = btn.dataset.commentId;
    const postId    = btn.closest('.post-feed-card').id.replace('post-', '');

    const res  = await fetch(`comments_load.php?post_id=${postId}&parent_id=${commentId}`);
    const data = await res.json();
    if (!data.success) { btn.disabled = false; return; }

    const container = document.getElementById('replies-' + commentId);
    container.innerHTML = '';
    data.comments.forEach(c => { container.insertAdjacentHTML('beforeend', buildCommentHTML(c)); });
    btn.remove();
    container.dataset.loaded = '1';
});

// Załaduj więcej komentarzy głównych
document.addEventListener('click', async function(e) {
    const btn = e.target.closest('.load-more-comments-btn');
    if (!btn) return;
    btn.disabled = true;
    btn.textContent = 'Ładowanie…';

    const postId = btn.dataset.postId;
    const offset = parseInt(btn.dataset.offset);
    const total  = parseInt(btn.dataset.total);

    const res  = await fetch(`comments_load.php?post_id=${postId}&offset=${offset}`);
    const data = await res.json();
    if (!data.success) { btn.disabled = false; return; }

    const list = document.getElementById('comments-list-' + postId);
    data.comments.forEach(c => { list.insertAdjacentHTML('beforeend', buildCommentHTML(c)); });

    const newOffset = offset + data.comments.length;
    if (data.has_more) {
        btn.dataset.offset = newOffset;
        btn.disabled = false;
        btn.textContent = `Pokaż więcej komentarzy (${total - newOffset} pozostałych)`;
    } else {
        btn.remove();
    }
});

// Buduj HTML komentarza z danych JSON
function buildCommentHTML(c) {
    const avatarHtml = c.author_avatar
        ? `<img src="${escHtml(c.author_avatar)}" alt="Avatar" loading="lazy">`
        : `<span class="cmt-av-ph">👤</span>`;

    const replyMention = c.reply_to_username
        ? `<a class="reply-mention" href="#">@${escHtml(c.reply_to_username)}</a> `
        : '';

    const replyClass = c.is_reply ? ' reply-comment' : '';

    let reactBadges = '';
    (c.reactions || []).forEach(r => {
        if (r.cnt > 0) {
            const mine = r.is_mine ? ' mine' : '';
            reactBadges += `<span class="comment-reaction-badge${mine}" data-type="${r.reaction_type}" title="${EMOJI_LABEL[r.reaction_type]}">${EMOJI_MAP[r.reaction_type]} ${r.cnt}</span>`;
        }
    });

    const pickerEmojis = Object.entries(EMOJI_MAP).map(([t,e]) =>
        `<span class="comment-pick-emoji" data-type="${t}" data-comment-id="${c.comment_id}" title="${EMOJI_LABEL[t]}">${e}</span>`
    ).join('');

    let repliesHTML = '';
    if (!c.is_reply && c.reply_count > 0) {
        repliesHTML = `<div class="replies-container" id="replies-${c.comment_id}" data-loaded="0">
            <button class="load-replies-btn" data-comment-id="${c.comment_id}" data-count="${c.reply_count}">Zobacz ${c.reply_count} odpowiedzi</button>
        </div>`;
    } else if (!c.is_reply) {
        repliesHTML = `<div class="replies-container" id="replies-${c.comment_id}" data-loaded="0"></div>`;
    }

    return `<div class="comment-item${replyClass}" id="comment-${c.comment_id}" data-comment-id="${c.comment_id}">
        <div class="cmt-avatar">${avatarHtml}</div>
        <div class="cmt-body">
            <div class="cmt-bubble">
                <span class="cmt-author">${escHtml(c.author_name)}</span>
                <span class="cmt-text">${replyMention}${escHtml(c.content).replace(/\n/g,'<br>')}</span>
            </div>
            <div class="cmt-meta">
                <div class="comment-reactions" data-comment-id="${c.comment_id}">
                    ${reactBadges}
                    <div class="comment-reaction-picker" data-comment-id="${c.comment_id}">${pickerEmojis}</div>
                </div>
                <button class="cmt-action-btn reply-btn"
                        data-comment-id="${c.comment_id}"
                        data-author-id="${c.author_id}"
                        data-author-name="${escHtml(c.author_name)}">Odpowiedz</button>
                <span class="cmt-time">${escHtml(c.created_at_rel)}</span>
            </div>
            ${repliesHTML}
        </div>
    </div>`;
}

// ── Load more posts ──
const loadMoreBtn = document.getElementById('load-more-posts-btn');
if (loadMoreBtn) {
    loadMoreBtn.addEventListener('click', async function() {
        this.disabled = true;
        document.getElementById('feed-spinner').style.display = 'block';

        const res = await fetch('index.php', {
            method: 'POST',
            headers: {'Content-Type':'application/x-www-form-urlencoded'},
            body: 'action=load_posts&offset=' + this.dataset.offset
        });
        const data = await res.json();

        document.getElementById('feed-spinner').style.display = 'none';
        if (data.html) {
            document.getElementById('feed-container').insertAdjacentHTML('beforeend', data.html);
            this.dataset.offset = parseInt(this.dataset.offset) + 20;
        }
        if (!data.has_more) { this.remove(); return; }
        this.disabled = false;
    });
}

// ── Modals ──
function openModal(type, postId, encodedContent) {
    document.getElementById(type + '-post-id').value = postId;
    if (type === 'edit' && encodedContent) {
        document.getElementById('edit-post-content').value = decodeURIComponent(encodedContent.replace(/\+/g,' '));
    }
    document.getElementById('modal-' + type).style.display = 'flex';
}
function closeModal(type) {
    document.getElementById('modal-' + type).style.display = 'none';
}
window.onclick = function(e) {
    if (e.target.classList.contains('fb-modal-overlay')) e.target.style.display = 'none';
};

// ── Karuzela ──
function scrollCarousel(dir) {
    document.getElementById('suggestionsCarousel')?.scrollBy({left: dir * 200, behavior:'smooth'});
}

// ── Znajomi ──
async function addFriend(id, btn) {
    if (btn.disabled) return;
    btn.disabled = true; btn.textContent = '…';
    const res  = await fetch('index.php', {method:'POST',headers:{'Content-Type':'application/x-www-form-urlencoded'},body:`action=add_friend&target_user_id=${id}`});
    const data = await res.json();
    if (data.success) { btn.textContent = '✓ Wysłano'; btn.style.background = '#42b72a'; }
    else { alert(data.message); btn.disabled = false; btn.textContent = 'Dodaj'; }
}
async function removeSuggestion(id, btn) {
    const card = btn.closest('.suggestion-card');
    await fetch('index.php', {method:'POST',headers:{'Content-Type':'application/x-www-form-urlencoded'},body:`action=remove_suggestion&target_user_id=${id}`});
    if (card) { card.style.opacity = '0'; card.style.transition = 'opacity .3s'; setTimeout(()=>card.remove(), 300); }
}

// ── Licznik zaproszeń ──
async function updatePendingCount() {
    const res  = await fetch('index.php', {method:'POST',headers:{'Content-Type':'application/x-www-form-urlencoded'},body:'action=get_pending_count'});
    const data = await res.json();
    if (!data.success) return;
    const badge = document.getElementById('pending-friends-count');
    if (!badge) return;
    badge.textContent = data.count;
    badge.style.display = data.count > 0 ? 'flex' : 'none';
}
setInterval(updatePendingCount, 15000);

// ── Helpers ──
function escHtml(str) {
    if (!str) return '';
    return String(str).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;').replace(/'/g,'&#39;');
}
</script>
</body>
</html>
