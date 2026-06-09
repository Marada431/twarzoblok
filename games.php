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
$pdo = db();

// ─────────────────────────────────────────────
// AJAX: Polubienia, Zapisywanie, Komentarze
// ─────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['ajax_action'])) {
    header('Content-Type: application/json');
    $post_id = (int)$_POST['post_id'];
    $action = $_POST['ajax_action'];

    if ($action === 'toggle_reaction' || $action === 'toggle_save') {
        $rtype = ($action === 'toggle_save') ? 'save' : 'like';

        // Sprawdź czy już istnieje
        $stmt = $pdo->prepare("SELECT reaction_id FROM post_reactions WHERE post_id = ? AND user_id = ? AND reaction_type = ?");
        $stmt->execute([$post_id, $current_user_id, $rtype]);
        $exists = $stmt->fetch();

        if ($exists) {
            $pdo->prepare("DELETE FROM post_reactions WHERE reaction_id = ?")->execute([$exists['reaction_id']]);
            echo json_encode(['status' => 'removed']);
        } else {
            $pdo->prepare("INSERT INTO post_reactions (user_id, post_id, reaction_type) VALUES (?, ?, ?)")->execute([$current_user_id, $post_id, $rtype]);
            echo json_encode(['status' => 'added']);
        }
        exit;
    }

    if ($action === 'add_comment') {
        $content = trim($_POST['content']);
        if (!empty($content)) {
            $stmt = $pdo->prepare("INSERT INTO comments (author_id, post_id, content) VALUES (?, ?, ?)");
            $stmt->execute([$current_user_id, $post_id, $content]);
            echo json_encode(['status' => 'success']);
        }
        exit;
    }
}

// ─────────────────────────────────────────────
// UPLOAD NOWEJ ROLKI
// ─────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['upload_reel'])) {
    $content = trim($_POST['content'] ?? '');

    if (isset($_FILES['reel_video']) && $_FILES['reel_video']['error'] === UPLOAD_ERR_OK) {
        $finfo = new finfo(FILEINFO_MIME_TYPE);
        $mime = $finfo->file($_FILES['reel_video']['tmp_name']);

        if (str_starts_with($mime, 'video/')) {
            // 1. Stwórz post
            $stmt = $pdo->prepare("INSERT INTO posts (author_id, content, created_at) VALUES (?, ?, NOW())");
            $stmt->execute([$current_user_id, $content]);
            $new_post_id = (int)$pdo->lastInsertId();

            // 2. Zapisz plik
            $ext = pathinfo($_FILES['reel_video']['name'], PATHINFO_EXTENSION);
            $dir = "uploads/posts/{$current_user_id}/{$new_post_id}/";
            if (!is_dir($dir)) mkdir($dir, 0775, true);

            $filename = uniqid('reel_', true) . '.' . $ext;
            $dest = $dir . $filename;

            if (move_uploaded_file($_FILES['reel_video']['tmp_name'], $dest)) {
                $pdo->prepare("INSERT INTO post_media (post_id, media_type, file_url) VALUES (?, 'video', ?)")
                        ->execute([$new_post_id, $dest]);
            }
        }
    }
    header('Location: reels.php');
    exit;
}

// ─────────────────────────────────────────────
// POBIERANIE ROLEK (Posty zawierające wideo)
// ─────────────────────────────────────────────
$stmt = $pdo->prepare("
    SELECT p.post_id, p.content, p.created_at, pm.file_url, 
           u.first_name, u.last_name, u.avatar_url,
           (SELECT COUNT(*) FROM post_reactions WHERE post_id = p.post_id AND reaction_type = 'like') as likes_count,
           (SELECT COUNT(*) FROM comments WHERE post_id = p.post_id AND parent_comment_id IS NULL) as comments_count,
           (SELECT COUNT(*) FROM post_reactions WHERE post_id = p.post_id AND reaction_type = 'like' AND user_id = :uid) as is_liked,
           (SELECT COUNT(*) FROM post_reactions WHERE post_id = p.post_id AND reaction_type = 'save' AND user_id = :uid) as is_saved
    FROM posts p
    JOIN post_media pm ON p.post_id = pm.post_id AND pm.media_type = 'video'
    JOIN users u ON p.author_id = u.user_id
    ORDER BY RAND() -- Losowa kolejność jak na TikToku/Reelsach (możesz zmienić na p.created_at DESC)
    LIMIT 20
");
$stmt->execute([':uid' => $current_user_id]);
$reels = $stmt->fetchAll();

?>
<!doctype html>
<html lang="pl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>TwarzBlok – Rolki</title>
    <link rel="stylesheet" href="assets/css/main.css">
    <style>
        body { background: #18191A; color: white; margin: 0; padding: 0; overflow: hidden; }

        /* Kontener na rolki */
        .reels-container {
            height: calc(100vh - 60px); /* Odejmujemy wysokość Twojego navbaru */
            scroll-snap-type: y mandatory;
            overflow-y: scroll;
            overflow-x: hidden;
            scroll-behavior: smooth;
        }
        .reels-container::-webkit-scrollbar { display: none; }

        /* Pojedyncza rolka */
        .reel-wrapper {
            height: 100%;
            width: 100%;
            display: flex;
            justify-content: center;
            align-items: center;
            scroll-snap-align: start;
            position: relative;
        }

        .reel-video-container {
            position: relative;
            height: 95%;
            width: 100%;
            max-width: 450px;
            background: #000;
            border-radius: 12px;
            overflow: hidden;
            box-shadow: 0 4px 15px rgba(0,0,0,0.5);
        }

        .reel-video-container video {
            width: 100%;
            height: 100%;
            object-fit: cover;
            cursor: pointer;
        }

        /* Overlay (Opis i Przyciski) */
        .reel-overlay {
            position: absolute;
            bottom: 0; left: 0; right: 0;
            padding: 20px;
            background: linear-gradient(to top, rgba(0,0,0,0.8), transparent);
            display: flex;
            justify-content: space-between;
            align-items: flex-end;
            pointer-events: none; /* Żeby kliknięcie w tło pauzowało wideo */
        }

        .reel-info { pointer-events: auto; max-width: 75%; }
        .reel-author { display: flex; align-items: center; gap: 10px; margin-bottom: 8px; }
        .reel-author img { width: 40px; height: 40px; border-radius: 50%; object-fit: cover; border: 2px solid white; }
        .reel-author h3 { margin: 0; font-size: 16px; text-shadow: 1px 1px 2px black; }
        .reel-desc { font-size: 14px; margin: 0; text-shadow: 1px 1px 2px black; }

        /* Pasek akcji po prawej */
        .reel-actions { display: flex; flex-direction: column; gap: 20px; pointer-events: auto; }
        .action-btn {
            background: rgba(0,0,0,0.4); border: none; color: white;
            border-radius: 50%; width: 50px; height: 50px;
            display: flex; flex-direction: column; align-items: center; justify-content: center;
            cursor: pointer; transition: 0.2s;
        }
        .action-btn:hover { background: rgba(255,255,255,0.2); }
        .action-btn.active { color: #e41e3f; } /* Czerwony polubiony */
        .action-btn.saved { color: #f1c40f; } /* Żółty zapisany */
        .action-text { font-size: 12px; margin-top: 4px; font-weight: bold; text-shadow: 1px 1px 2px black;}

        /* Przycisk dodawania rolki na ekranie */
        .add-reel-float {
            position: fixed; top: 80px; right: 20px;
            background: var(--primary-color, #1877f2); color: white;
            padding: 10px 20px; border-radius: 20px; font-weight: bold;
            cursor: pointer; z-index: 100; box-shadow: 0 4px 10px rgba(0,0,0,0.5);
            border: none;
        }

        /* Modal Dodawania i Komentarzy */
        .modal-overlay { display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.8); z-index: 999; justify-content: center; align-items: center;}
        .modal-box { background: #242526; padding: 20px; border-radius: 12px; width: 90%; max-width: 400px; color: white; }
        .modal-box input, .modal-box textarea { width: 100%; padding: 10px; margin-top: 10px; border-radius: 6px; border: none; background: #3a3b3c; color: white; box-sizing: border-box;}
        .modal-box button { margin-top: 15px; width: 100%; padding: 10px; background: #1877f2; color: white; border: none; border-radius: 6px; cursor: pointer; }
        .close-modal { float: right; cursor: pointer; font-size: 20px; }
    </style>
</head>
<body>

<?php $nav_active = 'reels'; require_once __DIR__ . '/includes/navbar.php'; ?>

<button class="add-reel-float" onclick="document.getElementById('uploadModal').style.display='flex'">+ Dodaj Rolkę</button>

<div class="reels-container" id="reelsContainer">
    <?php if(empty($reels)): ?>
        <h2 style="text-align:center; margin-top:50px;">Brak dostępnych rolek. Bądź pierwszy!</h2>
    <?php endif; ?>

    <?php foreach($reels as $reel): ?>
        <div class="reel-wrapper">
            <div class="reel-video-container">
                <video src="<?= htmlspecialchars($reel['file_url']) ?>" loop playsinline></video>

                <div class="reel-overlay">
                    <div class="reel-info">
                        <div class="reel-author">
                            <img src="<?= htmlspecialchars($reel['avatar_url'] ?: './icons/default-avatar.png') ?>" alt="Avatar">
                            <h3><?= htmlspecialchars($reel['first_name'] . ' ' . $reel['last_name']) ?></h3>
                        </div>
                        <p class="reel-desc"><?= nl2br(htmlspecialchars($reel['content'])) ?></p>
                    </div>

                    <div class="reel-actions">
                        <button class="action-btn <?= $reel['is_liked'] ? 'active' : '' ?>" onclick="toggleAction('toggle_reaction', <?= $reel['post_id'] ?>, this, 'likes_count')">
                            ❤️
                            <span class="action-text" id="likes_count_<?= $reel['post_id'] ?>"><?= $reel['likes_count'] ?></span>
                        </button>

                        <button class="action-btn" onclick="openComments(<?= $reel['post_id'] ?>)">
                            💬
                            <span class="action-text"><?= $reel['comments_count'] ?></span>
                        </button>

                        <button class="action-btn <?= $reel['is_saved'] ? 'saved' : '' ?>" onclick="toggleAction('toggle_save', <?= $reel['post_id'] ?>, this, null)">
                            🔖
                            <span class="action-text">Zapisz</span>
                        </button>
                    </div>
                </div>
            </div>
        </div>
    <?php endforeach; ?>
</div>

<div id="uploadModal" class="modal-overlay">
    <div class="modal-box">
        <span class="close-modal" onclick="document.getElementById('uploadModal').style.display='none'">&times;</span>
        <h3>Dodaj nową rolkę</h3>
        <form method="POST" enctype="multipart/form-data">
            <input type="text" name="content" placeholder="Opisz swoją rolkę..." autocomplete="off">
            <input type="file" name="reel_video" accept="video/mp4,video/webm" required>
            <button type="submit" name="upload_reel">Opublikuj</button>
        </form>
    </div>
</div>

<div id="commentModal" class="modal-overlay">
    <div class="modal-box">
        <span class="close-modal" onclick="document.getElementById('commentModal').style.display='none'">&times;</span>
        <h3>Napisz komentarz</h3>
        <textarea id="commentText" rows="3" placeholder="Twój komentarz..."></textarea>
        <input type="hidden" id="commentPostId">
        <button onclick="submitComment()">Wyślij komentarz</button>
    </div>
</div>

<script>
    // 1. Odtwarzanie wideo po przewinięciu (Intersection Observer)
    const videos = document.querySelectorAll('video');
    const observer = new IntersectionObserver((entries) => {
        entries.forEach(entry => {
            if (entry.isIntersecting) {
                entry.target.play().catch(e => console.log("Czeka na interakcję"));
            } else {
                entry.target.pause();
                entry.target.currentTime = 0;
            }
        });
    }, { threshold: 0.6 });

    videos.forEach(video => {
        observer.observe(video);
        // Kliknij, by zapauzować
        video.addEventListener('click', () => {
            if(video.paused) video.play(); else video.pause();
        });
    });

    // 2. AJAX - Lajki i Zapisywanie
    function toggleAction(action, postId, btnElement, countId) {
        const formData = new FormData();
        formData.append('ajax_action', action);
        formData.append('post_id', postId);

        fetch('reels.php', { method: 'POST', body: formData })
            .then(res => res.json())
            .then(data => {
                if(action === 'toggle_reaction') {
                    btnElement.classList.toggle('active');
                    let countSpan = document.getElementById(countId + '_' + postId);
                    let count = parseInt(countSpan.innerText);
                    countSpan.innerText = data.status === 'added' ? count + 1 : count - 1;
                } else if(action === 'toggle_save') {
                    btnElement.classList.toggle('saved');
                }
            });
    }

    // 3. Obsługa Komentarzy
    function openComments(postId) {
        document.getElementById('commentPostId').value = postId;
        document.getElementById('commentText').value = '';
        document.getElementById('commentModal').style.display = 'flex';
    }

    function submitComment() {
        const postId = document.getElementById('commentPostId').value;
        const content = document.getElementById('commentText').value;

        const formData = new FormData();
        formData.append('ajax_action', 'add_comment');
        formData.append('post_id', postId);
        formData.append('content', content);

        fetch('reels.php', { method: 'POST', body: formData })
            .then(res => res.json())
            .then(data => {
                if(data.status === 'success') {
                    document.getElementById('commentModal').style.display = 'none';
                    alert('Komentarz został dodany!');
                    // Opcjonalnie: odświeżenie strony żeby zaktualizować licznik
                }
            });
    }
</script>
<link rel="stylesheet" href="assets/css/toast.css">
<script src="assets/js/toast.js"></script>
<script>
    const APP_CONFIG = { csrfToken: '<?= generate_csrf_token() ?>' };
</script>
<script src="assets/js/feed.js"></script>
</body>
</html>