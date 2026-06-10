<?php
session_start();
if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}

require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/csrf.php';
require_once __DIR__ . '/includes/auth_check.php';
require_once __DIR__ . '/app/models/GroupChatModel.php';

$userId   = (int)$_SESSION['user_id'];
check_user_status(db(), $userId);
$username = $_SESSION['username'] ?? 'Użytkownik';

// Dane do navbara
$userAvatarUrl = $_SESSION['avatar_url'] ?? null;
$userRole      = $_SESSION['role']       ?? 'user';

$pendingStmt = db()->prepare(
    "SELECT COUNT(*) FROM friendships WHERE addressee_id = ? AND status = 'pending'"
);
$pendingStmt->execute([$userId]);
$pendingCount = (int)$pendingStmt->fetchColumn();

// ── Znajomi (bez blokowanych) ─────────────────────────────
$stmt = db()->prepare("
    SELECT u.user_id, u.username, u.avatar_url, u.first_name, u.last_name
    FROM friendships f
    JOIN users u ON (
        (f.requester_id = ? AND u.user_id = f.addressee_id)
        OR (f.addressee_id = ? AND u.user_id = f.requester_id)
    )
    LEFT JOIN user_blocks ub ON (
        (ub.blocker_id = ? AND ub.blocked_id = u.user_id)
        OR (ub.blocker_id = u.user_id AND ub.blocked_id = ?)
    )
    WHERE f.status = 'accepted' AND ub.block_id IS NULL
    GROUP BY u.user_id
    ORDER BY u.username ASC
");
$stmt->execute([$userId, $userId, $userId, $userId]);
$friends = $stmt->fetchAll();

// Ostatnia wiadomość + liczba nieprzeczytanych per znajomy
foreach ($friends as &$f) {
    $s = db()->prepare("
        SELECT cm.content, cm.attachment_url, cm.sent_at
        FROM chats c
        JOIN chat_participants cp1 ON cp1.chat_id = c.chat_id AND cp1.user_id = ?
        JOIN chat_participants cp2 ON cp2.chat_id = c.chat_id AND cp2.user_id = ?
        JOIN chat_messages cm ON cm.chat_id = c.chat_id AND cm.status = 'active'
        WHERE c.chat_type = 'private'
        ORDER BY cm.sent_at DESC
        LIMIT 1
    ");
    $s->execute([$userId, $f['user_id']]);
    $last = $s->fetch();
    $f['last_msg']    = $last['content']        ?? null;
    $f['last_attach'] = $last['attachment_url'] ?? null;
    $f['last_at']     = $last['sent_at']        ?? null;

    $u = db()->prepare("
        SELECT COUNT(*) FROM chats c
        JOIN chat_participants cp1 ON cp1.chat_id = c.chat_id AND cp1.user_id = ?
        JOIN chat_participants cp2 ON cp2.chat_id = c.chat_id AND cp2.user_id = ?
        JOIN chat_messages cm ON cm.chat_id = c.chat_id
            AND cm.sender_id = ?
            AND cm.is_read = 0
            AND cm.status = 'active'
        WHERE c.chat_type = 'private'
    ");
    $u->execute([$userId, $f['user_id'], $f['user_id']]);
    $f['unread'] = (int)$u->fetchColumn();
}
unset($f);

usort($friends, function ($a, $b) {
    $ta = $a['last_at'] ? strtotime($a['last_at']) : 0;
    $tb = $b['last_at'] ? strtotime($b['last_at']) : 0;
    return $tb - $ta;
});

// ── Czaty grupowe ──────────────────────────────────────────
$groupModel = new GroupChatModel(db());
$groupChats = $groupModel->getUserGroups($userId);

// Token JWT dla Socket.io
$payload   = json_encode(['user_id' => $userId, 'username' => $username, 'exp' => time() + 3600]);
$signature = hash_hmac('sha256', $payload, SOCKET_SECRET, true);
$token     = base64_encode($payload) . '.' . base64_encode($signature);
?>
<!DOCTYPE html>
<html lang="pl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>TwarzBlok – Wiadomości</title>
    <link rel="stylesheet" href="assets/css/main.css?v=<?= filemtime(__DIR__.'/assets/css/main.css') ?>">
    <link rel="stylesheet" href="assets/css/chat-app.css?v=<?= filemtime(__DIR__.'/assets/css/chat-app.css') ?>">
</head>
<body>

<?php
$_SESSION['avatar_url'] = $userAvatarUrl;
$nav_pending_count = $pendingCount;
$nav_active = 'chat';
require_once __DIR__ . '/includes/navbar.php';
?>

<!-- ── Aplikacja czatu ──────────────────────────────────────── -->
<div class="chat-app">

    <!-- Lewa kolumna: lista rozmów -->
    <aside class="chat-sidebar" id="chatSidebar">
        <div class="sidebar-head">
            <div class="sidebar-head-top">
                <h3>Wiadomości</h3>
                <button class="btn-new-group" id="newGroupBtn" title="Nowa Grupa">
                    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 640 512" fill="currentColor">
                        <path d="M96 128a128 128 0 1 1 256 0A128 128 0 1 1 96 128zM0 482.3C0 383.8 79.8 304 178.3 304l91.4 0C368.2 304 448 383.8 448 482.3c0 16.4-13.3 29.7-29.7 29.7L29.7 512C13.3 512 0 498.7 0 482.3zM504 312l0-64-64 0c-13.3 0-24-10.7-24-24s10.7-24 24-24l64 0 0-64c0-13.3 10.7-24 24-24s24 10.7 24 24l0 64 64 0c13.3 0 24 10.7 24 24s-10.7 24-24 24l-64 0 0 64c0 13.3-10.7 24-24 24s-24-10.7-24-24z"/>
                    </svg>
                    <span class="btn-new-group-label">Nowa grupa</span>
                </button>
            </div>
            <input type="text" class="sidebar-search" id="searchInput" placeholder="Szukaj rozmów...">
        </div>

        <div class="convs-list" id="convsList">
            <?php if (empty($friends)): ?>
                <div class="no-friends">
                    Nie masz jeszcze żadnych znajomych.<br>
                    <a href="index.php">Wróć na tablicę</a>, aby dodać kogoś.
                </div>
            <?php else: ?>
                <?php foreach ($friends as $friend):
                    $displayName = htmlspecialchars($friend['first_name'] . ' ' . $friend['last_name']);
                    $uname       = htmlspecialchars($friend['username']);
                    $avatarUrl   = htmlspecialchars($friend['avatar_url'] ?? '');
                    $initials    = strtoupper(
                        mb_substr($friend['first_name'], 0, 1) .
                        mb_substr($friend['last_name'],  0, 1)
                    );
                    $hasUnread   = $friend['unread'] > 0;

                    if ($friend['last_attach'] && !$friend['last_msg']) {
                        $lastPreview = '📷 Zdjęcie';
                    } elseif ($friend['last_msg']) {
                        $lastPreview = mb_strimwidth(htmlspecialchars($friend['last_msg']), 0, 38, '…');
                    } else {
                        $lastPreview = '';
                    }
                ?>
                <div class="conv-item"
                     data-uid="<?= $friend['user_id'] ?>"
                     data-name="<?= $displayName ?>"
                     data-username="<?= $uname ?>"
                     data-avatar="<?= $avatarUrl ?>"
                     data-initials="<?= htmlspecialchars($initials) ?>">

                    <div class="av-wrap">
                        <?php if ($avatarUrl): ?>
                            <img src="<?= $avatarUrl ?>" alt="" class="av-img">
                        <?php else: ?>
                            <div class="av-placeholder"><?= $initials ?></div>
                        <?php endif; ?>
                        <span class="av-dot" data-uid="<?= $friend['user_id'] ?>"></span>
                    </div>

                    <div class="conv-info">
                        <div class="conv-info-top">
                            <div class="conv-name<?= $hasUnread ? ' has-unread' : '' ?>">
                                <?= $displayName ?>
                            </div>
                            <span class="unread-badge<?= $hasUnread ? ' visible' : '' ?>">
                                <?= $hasUnread ? min($friend['unread'], 99) : '' ?>
                            </span>
                        </div>
                        <div class="conv-last<?= $hasUnread ? ' has-unread' : '' ?>"
                             id="last-msg-<?= $friend['user_id'] ?>">
                            <?= $lastPreview ?>
                        </div>
                    </div>
                </div>
                <?php endforeach; ?>
            <?php endif; ?>

            <?php if (!empty($groupChats)): ?>
                <div class="conv-section-label">Grupy</div>
                <?php foreach ($groupChats as $g):
                    $gName      = htmlspecialchars($g['name']);
                    $gAvatar    = htmlspecialchars($g['avatar_url'] ?? '');
                    $gInitials  = htmlspecialchars(mb_strtoupper(mb_substr($g['name'], 0, 2)));
                    $gUnread    = (int)$g['unread'];
                    $gMembers   = (int)$g['member_count'];
                    if ($g['last_attach'] && !$g['last_msg']) {
                        $gPreview = '📷 Zdjęcie';
                    } elseif ($g['last_msg']) {
                        $gPreview = mb_strimwidth(htmlspecialchars($g['last_msg']), 0, 38, '…');
                    } else {
                        $gPreview = '';
                    }
                ?>
                <div class="conv-item"
                     data-type="group"
                     data-chat-id="<?= $g['chat_id'] ?>"
                     data-name="<?= $gName ?>"
                     data-avatar="<?= $gAvatar ?>"
                     data-initials="<?= $gInitials ?>"
                     data-member-count="<?= $gMembers ?>">

                    <div class="av-wrap">
                        <?php if ($gAvatar): ?>
                            <img src="<?= $gAvatar ?>" alt="" class="av-img">
                        <?php else: ?>
                            <div class="group-av-placeholder"><?= $gInitials ?></div>
                        <?php endif; ?>
                    </div>

                    <div class="conv-info">
                        <div class="conv-info-top">
                            <div class="conv-name<?= $gUnread ? ' has-unread' : '' ?>"><?= $gName ?></div>
                            <span class="unread-badge<?= $gUnread ? ' visible' : '' ?>">
                                <?= $gUnread ? min($gUnread, 99) : '' ?>
                            </span>
                        </div>
                        <div class="conv-last<?= $gUnread ? ' has-unread' : '' ?>"
                             id="last-msg-group-<?= $g['chat_id'] ?>">
                            <?= $gPreview ?>
                        </div>
                    </div>
                </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
    </aside>

    <!-- Prawa kolumna: okno rozmowy -->
    <main class="chat-main" id="chatMain">

        <!-- Pusty stan -->
        <div class="chat-empty" id="chatEmpty">
            <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 512 512" fill="currentColor">
                <path d="M256 448c141.4 0 256-93.1 256-208S397.4 32 256 32S0 125.1 0 240c0 45.1 17.7 86.8 47.7 120.9c-1.9 24.5-11.4 46.3-21.4 62.9c-5.5 9.2-11.1 16.6-15.2 21.6c-2.1 2.5-3.7 4.4-4.9 5.7c-.6 .6-1 1.1-1.3 1.4l-.3 .3c0 0 0 0 0 0s0 0 0 0c-4.6 4.6-5.9 11.4-3.4 17.4c2.5 6 8.3 9.9 14.8 9.9c28.7 0 57.6-8.9 81.6-19.3c22.9-10 42.4-21.9 54.3-30.6c31.8 11.5 67 17.9 104.1 17.9z"/>
            </svg>
            <p>Wybierz rozmowę, aby zacząć pisać</p>
        </div>

        <!-- Aktywna rozmowa -->
        <div class="chat-window" id="chatWindow">

            <div class="chat-header">
                <button class="btn-mobile-back" id="mobileBack">&#8592;</button>
                <div class="chat-header-av" id="chatHeaderAv"></div>
                <div class="chat-header-info">
                    <div class="chat-header-name"   id="chatHeaderName"></div>
                    <div class="chat-header-status" id="chatHeaderStatus">Offline</div>
                </div>
            </div>

            <div class="chat-messages" id="messages">
                <div class="load-more-wrap" id="loadMoreWrap" style="display:none;">
                    <button class="btn-load-more" id="loadMoreBtn">Załaduj wcześniejsze wiadomości</button>
                </div>
            </div>

            <div class="chat-input-area">
                <div class="img-preview-bar" id="imgPreviewBar">
                    <img id="previewImg" class="img-preview-thumb" src="" alt="Podgląd">
                    <button class="btn-remove-img" id="removeImgBtn">✕</button>
                    <span style="font-size:12px;color:var(--text-muted);">Zdjęcie zostanie wysłane z wiadomością</span>
                </div>

                <div class="chat-input-row">
                    <button class="btn-icon" id="attachBtn" title="Wyślij zdjęcie">
                        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 448 512" fill="currentColor">
                            <path d="M364.2 83.8c-24.4-24.4-64-24.4-88.4 0l-184 184c-42.1 42.1-42.1 110.3 0 152.4s110.3 42.1 152.4 0l152-152c10.9-10.9 28.7-10.9 39.6 0s10.9 28.7 0 39.6l-152 152c-64 64-167.6 64-231.6 0s-64-167.6 0-231.6l184-184c46.3-46.3 121.3-46.3 167.6 0s46.3 121.3 0 167.6l-176 176c-28.6 28.6-75 28.6-103.6 0s-28.6-75 0-103.6l144-144c10.9-10.9 28.7-10.9 39.6 0s10.9 28.7 0 39.6l-144 144c-6.7 6.7-6.7 17.7 0 24.4s17.7 6.7 24.4 0l176-176c24.4-24.4 24.4-64 0-88.4z"/>
                        </svg>
                    </button>
                    <input type="file" id="fileInput" accept="image/jpeg,image/png,image/webp,image/gif" style="display:none;">

                    <input type="text" class="chat-text-input" id="messageInput" placeholder="Napisz wiadomość...">

                    <button class="btn-send" id="sendBtn" title="Wyślij">
                        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 512 512" fill="white">
                            <path d="M498.1 5.6c10.1 7 15.4 19.1 13.5 31.2l-64 416c-1.5 9.7-7.4 18.2-16 23s-18.9 5.4-28 1.6L284 427.7l-68.5 74.1c-8.9 9.7-22.9 12.9-35.2 8.1S160 493.2 160 480l0-83.6c0-4 1.5-7.8 4.2-10.8L331.8 202.8c5.8-6.3 5.6-16-.4-22s-15.7-6.4-22-.7L106 360.8 17.7 316.6C7.1 311.3 .3 300.7 0 288.9s6.1-22.8 16.1-28.7l448-256c10.7-6.1 23.9-5.5 34 1.4z"/>
                        </svg>
                    </button>
                </div>
            </div>

        </div><!-- /.chat-window -->
    </main>

</div><!-- /.chat-app -->

<!-- ── Panel uczestników grupy ──────────────────────────── -->
<div class="group-members-panel" id="groupMembersPanel">
    <div class="group-members-header">
        <span>Uczestnicy</span>
        <button class="fb-modal-close" id="groupMembersPanelClose">&#10005;</button>
    </div>
    <div id="groupMembersList"></div>
</div>

<!-- ── Modal nowej grupy ───────────────────────────────── -->
<div class="group-modal" id="groupModalOverlay" style="display:none">
    <div class="fb-modal-card" style="max-width:480px;max-height:90vh;display:flex;flex-direction:column">
        <div class="fb-modal-header">
            <h3>Nowa Grupa</h3>
            <button class="fb-modal-close" id="groupModalClose">&#10005;</button>
        </div>
        <div class="fb-modal-body" style="overflow-y:auto;flex:1">
            <input type="text" id="groupNameInput" class="chat-text-input"
                   style="width:100%;box-sizing:border-box;margin-bottom:10px"
                   placeholder="Nazwa grupy *" maxlength="100">
            <input type="text" id="groupDescInput" class="chat-text-input"
                   style="width:100%;box-sizing:border-box;margin-bottom:14px"
                   placeholder="Opis (opcjonalnie)" maxlength="255">
            <p style="font-size:13px;color:var(--text-muted);margin-bottom:8px;font-weight:600">
                Wybierz uczestników:
            </p>
            <div class="group-friends-picker" id="groupFriendsPicker">
                <?php foreach ($friends as $f):
                    $fDisplay  = htmlspecialchars($f['first_name'] . ' ' . $f['last_name']);
                    $fAvatar   = htmlspecialchars($f['avatar_url'] ?? '');
                    $fInitials = strtoupper(mb_substr($f['first_name'],0,1).mb_substr($f['last_name'],0,1));
                ?>
                <label class="group-friend-row">
                    <input type="checkbox" class="group-member-cb" value="<?= $f['user_id'] ?>">
                    <div class="av-wrap" style="width:32px;height:32px;flex-shrink:0">
                        <?php if ($fAvatar): ?>
                            <img src="<?= $fAvatar ?>" class="av-img" style="width:32px;height:32px" alt="">
                        <?php else: ?>
                            <div class="av-placeholder" style="width:32px;height:32px;font-size:12px"><?= $fInitials ?></div>
                        <?php endif; ?>
                    </div>
                    <span><?= $fDisplay ?></span>
                </label>
                <?php endforeach; ?>
                <?php if (empty($friends)): ?>
                    <p style="padding:12px;color:var(--text-muted);font-size:13px">Brak znajomych do dodania.</p>
                <?php endif; ?>
            </div>
        </div>
        <div class="fb-modal-footer">
            <button id="groupModalCancelBtn" type="button"
                    style="background:none;border:1px solid var(--border-color);padding:8px 18px;border-radius:var(--radius-main);cursor:pointer;font-size:14px">
                Anuluj
            </button>
            <button id="groupModalCreateBtn" type="button"
                    style="background:var(--primary-color);color:#fff;border:none;padding:8px 18px;border-radius:var(--radius-main);cursor:pointer;font-size:14px;font-weight:600">
                Utwórz grupę
            </button>
        </div>
    </div>
</div>

<!-- Lightbox -->
<div class="lightbox" id="lightbox">
    <button class="btn-lightbox-close" id="lightboxClose">&#10005;</button>
    <img class="lightbox-img" id="lightboxImg" src="" alt="Podgląd zdjęcia">
</div>

<link rel="stylesheet" href="assets/css/toast.css?v=<?= filemtime(__DIR__.'/assets/css/toast.css') ?>">
<script src="http://localhost:3000/socket.io/socket.io.js"></script>
<script src="assets/js/toast.js?v=<?= filemtime(__DIR__.'/assets/js/toast.js') ?>"></script>
<script>
const APP_CONFIG = {
    token:     <?= json_encode($token) ?>,
    userId:    <?= $userId ?>,
    csrfToken: '<?= htmlspecialchars(generate_csrf_token(), ENT_QUOTES, 'UTF-8') ?>'
};
</script>
<script src="assets/js/chat.js?v=<?= filemtime(__DIR__.'/assets/js/chat.js') ?>"></script>
</body>
</html>
