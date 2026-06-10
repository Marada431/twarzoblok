<?php
session_start();
// Zabezpieczenie przed niezalogowanymi użytkownikami (dostosuj jeśli masz inną zmienną sesyjną)
if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit;
}
$current_user_id = $_SESSION['user_id'];
?>
<!DOCTYPE html>
<html lang="pl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>TwarzBlok - Rolki</title>
    <link rel="stylesheet" href="assets/css/reels.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
</head>
<body>

<button class="upload-trigger-btn" onclick="toggleUploadModal(true)">
    <i class="fa-solid2 fa-plus"></i> Dodaj Rolkę
</button>

<div class="reels-container" id="reelsContainer" data-user-id="<?php echo $current_user_id; ?>">
</div>

<div id="uploadModal" class="modal">
    <div class="modal-content">
        <span class="close-btn" onclick="toggleUploadModal(false)">&times;</span>
        <h2>Dodaj nową rolkę</h2>
        <form id="uploadReelForm" enctype="multipart/form-data">
            <div class="form-group">
                <label for="videoFile">Wybierz plik wideo:</label>
                <input type="file" id="videoFile" name="video" accept="video/*" required>
            </div>
            <div class="form-group">
                <label for="description">Opis:</label>
                <textarea id="description" name="description" rows="3" placeholder="Dodaj ciekawy opis..."></textarea>
            </div>
            <button type="submit" class="submit-btn">Opublikuj</button>
        </form>
    </div>
</div>

<div id="commentsModal" class="modal">
    <div class="modal-content comments-content">
        <span class="close-btn" onclick="toggleCommentsModal(false)">&times;</span>
        <h2>Komentarze</h2>
        <div class="comments-list" id="commentsList">
        </div>
        <form id="addCommentForm" class="comment-input-area">
            <input type="hidden" id="activeReelId" value="">
            <input type="text" id="commentContent" placeholder="Napisz komentarz..." required>
            <button type="submit"><i class="fa-solid fa-paper-plane"></i></button>
        </form>
    </div>
</div>

<script src="assets/js/reels.js"></script>
<div class="scroll-nav-container">
    <button class="nav-arrow-btn up" onclick="scrollReels('up')" title="Poprzednia rolka">
        <i class="fa-solid fa-chevron-up"></i>
    </button>
    <button class="nav-arrow-btn down" onclick="scrollReels('down')" title="Następna rolka">
        <i class="fa-solid fa-chevron-down"></i>
    </button>
</div>
</body>
</html>