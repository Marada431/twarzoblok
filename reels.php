<?php
session_start();
require_once 'config/database.php';
require_once 'includes/csrf.php';
require_once 'includes/auth_check.php';

if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
    header('Location: login.php');
    exit;
}

$current_user_id = (int)$_SESSION['user_id'];
$pdo = db();
check_user_status($pdo, $current_user_id);

// ─── Upload nowej rolki ───────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['upload_reel'])) {
    verify_csrf_token($_POST['csrf_token'] ?? '');

    $content       = trim($_POST['content'] ?? '');
    $upload_error  = '';
    $allowed_mimes = ['video/mp4', 'video/webm'];
    $max_size      = 100 * 1024 * 1024; // 100 MB

    if (!isset($_FILES['reel_video']) || $_FILES['reel_video']['error'] !== UPLOAD_ERR_OK) {
        $upload_error = 'Błąd przesyłania pliku.';
    } elseif ($_FILES['reel_video']['size'] > $max_size) {
        $upload_error = 'Plik przekracza limit 100 MB.';
    } else {
        $finfo = new finfo(FILEINFO_MIME_TYPE);
        $mime  = $finfo->file($_FILES['reel_video']['tmp_name']);

        if (!in_array($mime, $allowed_mimes, true)) {
            $upload_error = 'Dozwolone formaty: MP4, WebM.';
        } else {
            $stmt = $pdo->prepare("INSERT INTO posts (author_id, content, created_at) VALUES (?, ?, NOW())");
            $stmt->execute([$current_user_id, $content]);
            $new_post_id = (int)$pdo->lastInsertId();

            $ext = strtolower(pathinfo($_FILES['reel_video']['name'], PATHINFO_EXTENSION));
            $dir = "uploads/posts/{$current_user_id}/{$new_post_id}/";
            if (!is_dir($dir)) mkdir($dir, 0775, true);

            $filename = uniqid('reel_', true) . '.' . $ext;
            $dest     = $dir . $filename;

            if (move_uploaded_file($_FILES['reel_video']['tmp_name'], $dest)) {
                $pdo->prepare("INSERT INTO post_media (post_id, media_type, file_url, position) VALUES (?, 'video', ?, 0)")
                    ->execute([$new_post_id, $dest]);
            } else {
                $pdo->prepare("DELETE FROM posts WHERE post_id = ?")->execute([$new_post_id]);
                $upload_error = 'Błąd zapisu pliku.';
            }
        }
    }

    header('Location: reels.php' . ($upload_error ? '?error=' . urlencode($upload_error) : ''));
    exit;
}

// ─── Pierwsze 10 rolek ────────────────────────────────────────────────────────
$stmt = $pdo->prepare("
    SELECT p.post_id, p.content, p.created_at, pm.file_url,
           p.author_id,
           u.first_name, u.last_name, u.avatar_url,
           (SELECT COUNT(*) FROM post_reactions WHERE post_id = p.post_id AND reaction_type = 'like') AS likes_count,
           (SELECT COUNT(*) FROM comments        WHERE post_id = p.post_id AND parent_comment_id IS NULL) AS comments_count,
           (SELECT COUNT(*) FROM post_reactions  WHERE post_id = p.post_id AND reaction_type = 'like' AND user_id = :uid1) AS is_liked,
           (SELECT COUNT(*) FROM post_reactions  WHERE post_id = p.post_id AND reaction_type = 'save' AND user_id = :uid2) AS is_saved
    FROM posts p
    JOIN post_media pm ON p.post_id = pm.post_id AND pm.media_type = 'video'
    JOIN users u ON p.author_id = u.user_id
    ORDER BY p.created_at DESC
    LIMIT 10
");
$stmt->execute([':uid1' => $current_user_id, ':uid2' => $current_user_id]);
$reels = $stmt->fetchAll();

$csrf_token = generate_csrf_token();
$upload_error_msg = htmlspecialchars($_GET['error'] ?? '');
?>
<!doctype html>
<html lang="pl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="<?= $csrf_token ?>">
    <title>TwarzBlok – Rolki</title>
    <link rel="stylesheet" href="assets/css/main.css">
    <link rel="stylesheet" href="assets/css/reels.css">
</head>
<body>

<?php $nav_active = 'reels'; require_once __DIR__ . '/includes/navbar.php'; ?>

<button class="add-reel-float" id="openUploadModal">+ Dodaj Rolkę</button>

<div class="reels-container" id="reelsContainer">
    <?php if (empty($reels)): ?>
        <div class="reels-empty">
            <h2>Brak dostępnych rolek. Bądź pierwszy!</h2>
        </div>
    <?php endif; ?>

    <?php foreach ($reels as $reel):
        $is_own    = ((int)$reel['author_id'] === $current_user_id);
        $avatar    = htmlspecialchars($reel['avatar_url'] ?: './icons/default-avatar.png');
        $author    = htmlspecialchars($reel['first_name'] . ' ' . $reel['last_name']);
        $file_url  = htmlspecialchars($reel['file_url']);
        $desc      = nl2br(htmlspecialchars($reel['content']));
    ?>
        <div class="reel-wrapper" data-post-id="<?= (int)$reel['post_id'] ?>">
            <div class="reel-video-container">
                <div class="reel-progress"><div class="reel-progress-bar"></div></div>

                <video src="<?= $file_url ?>" loop playsinline></video>

                <button class="mute-btn" title="Wycisz/Włącz dźwięk">🔊</button>

                <div class="reel-overlay">
                    <div class="reel-info">
                        <div class="reel-author">
                            <img src="<?= $avatar ?>" alt="Avatar">
                            <h3><?= $author ?></h3>
                        </div>
                        <?php if ($reel['content']): ?>
                            <p class="reel-desc"><?= $desc ?></p>
                        <?php endif; ?>
                    </div>

                    <div class="reel-actions">
                        <button class="action-btn <?= $reel['is_liked'] ? 'active' : '' ?>"
                                data-action="toggle_reaction"
                                title="Lubię to">
                            ❤️
                            <span class="action-text"><?= (int)$reel['likes_count'] ?></span>
                        </button>

                        <button class="action-btn"
                                data-action="open_comments"
                                title="Komentarze">
                            💬
                            <span class="action-text"><?= (int)$reel['comments_count'] ?></span>
                        </button>

                        <button class="action-btn <?= $reel['is_saved'] ? 'saved' : '' ?>"
                                data-action="toggle_save"
                                title="Zapisz">
                            🔖
                            <span class="action-text">Zapisz</span>
                        </button>

                        <button class="action-btn"
                                data-action="share"
                                data-title="<?= $author ?>"
                                title="Udostępnij">
                            🔗
                            <span class="action-text">Udostępnij</span>
                        </button>

                        <?php if ($is_own): ?>
                            <button class="action-btn delete-btn"
                                    data-action="delete_reel"
                                    title="Usuń rolkę">
                                🗑️
                                <span class="action-text">Usuń</span>
                            </button>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
    <?php endforeach; ?>

    <div id="sentinel"></div>
</div>

<!-- Panel komentarzy -->
<div class="comments-panel" id="commentsPanel">
    <div class="comments-panel-header">
        <h3>Komentarze</h3>
        <button class="comments-close-btn" id="closeCommentsPanel">&times;</button>
    </div>
    <div class="comments-list" id="commentsList">
        <p class="comments-loading">Ładowanie...</p>
    </div>
    <div class="comments-form">
        <input type="hidden" id="commentPostId">
        <textarea id="commentText" rows="2" placeholder="Dodaj komentarz..."></textarea>
        <button class="comments-submit-btn" id="submitCommentBtn">Wyślij</button>
    </div>
</div>
<div class="comments-overlay" id="commentsOverlay"></div>

<!-- Modal: Dodaj rolkę -->
<div id="uploadModal" class="modal-overlay">
    <div class="modal-box">
        <span class="close-modal" id="closeUploadModal">&times;</span>
        <h3>Dodaj nową rolkę</h3>
        <?php if ($upload_error_msg): ?>
            <p class="upload-error"><?= $upload_error_msg ?></p>
        <?php endif; ?>
        <form method="POST" enctype="multipart/form-data" action="reels.php">
            <input type="hidden" name="csrf_token" value="<?= $csrf_token ?>">
            <input type="text" name="content" placeholder="Opisz swoją rolkę..." autocomplete="off">
            <input type="file" name="reel_video" accept="video/mp4,video/webm" required>
            <p class="upload-hint">Maks. 100 MB · MP4 lub WebM</p>
            <button type="submit" name="upload_reel">Opublikuj</button>
        </form>
    </div>
</div>

<script src="assets/js/reels.js"></script>
<script>
    window.REELS_CONFIG = {
        currentUserId: <?= $current_user_id ?>,
        initialOffset: <?= count($reels) ?>
    };
    document.addEventListener('DOMContentLoaded', () => { window.reelsApp = new ReelsApp(); });
</script>
</body>
</html>
