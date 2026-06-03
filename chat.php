<?php
session_start();
if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}

require_once __DIR__ . '/config/database.php';

$userId   = (int)$_SESSION['user_id'];
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
    <link rel="stylesheet" href="css/style.css">
    <style>
        /* Nadpisz globalny rozmiar SVG (jak w index.php) */
        svg { max-width: 24px; max-height: 24px; }

        /* ── Układ ogólny ────────────────────────────────── */
        body { overflow: hidden; }

        .chat-app {
            display: flex;
            height: calc(100vh - 70px);
            margin-top: 70px;
            overflow: hidden;
        }

        /* ── Lewa kolumna ────────────────────────────────── */
        .chat-sidebar {
            width: 300px;
            flex-shrink: 0;
            background: var(--bg-surface);
            border-right: 1px solid var(--border-color);
            display: flex;
            flex-direction: column;
            overflow: hidden;
        }

        .sidebar-head {
            padding: 14px 16px 10px;
            border-bottom: 1px solid var(--border-color);
        }

        .sidebar-head h3 {
            font-size: 20px;
            font-weight: 700;
            color: var(--text-main);
            margin-bottom: 10px;
        }

        .sidebar-search {
            width: 100%;
            padding: 8px 12px 8px 36px;
            background: var(--bg-main);
            border: 1px solid var(--border-color);
            border-radius: 20px;
            font-size: 14px;
            color: var(--text-main);
            outline: none;
            background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 512 512'%3E%3Cpath fill='%239aa79c' d='M416 208c0 45.9-14.9 88.3-40 122.7L502.6 457.4c12.5 12.5 12.5 32.8 0 45.3s-32.8 12.5-45.3 0L330.7 376c-34.4 25.2-76.8 40-122.7 40C93.1 416 0 322.9 0 208S93.1 0 208 0s208 93.1 208 208zM208 352a144 144 0 1 0 0-288 144 144 0 1 0 0 288z'/%3E%3C/svg%3E");
            background-repeat: no-repeat;
            background-position: 12px center;
            background-size: 14px;
            transition: var(--transition);
        }

        .sidebar-search:focus {
            border-color: var(--primary-color);
            box-shadow: 0 0 0 2px rgba(51, 131, 54, 0.2);
        }

        .convs-list { flex: 1; overflow-y: auto; }

        .conv-item {
            display: flex;
            align-items: center;
            gap: 10px;
            padding: 10px 14px;
            cursor: pointer;
            border-bottom: 1px solid var(--border-color);
            transition: background 0.15s;
        }

        .conv-item:hover  { background: var(--bg-hover); }
        .conv-item.active { background: var(--bg-hover); }

        .conv-info { flex: 1; min-width: 0; }

        .conv-info-top {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 4px;
        }

        .conv-name {
            font-weight: 600;
            font-size: 14px;
            color: var(--text-main);
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }

        .conv-name.has-unread { font-weight: 700; }

        .unread-badge {
            background: var(--primary-color);
            color: #fff;
            border-radius: 50%;
            min-width: 18px;
            height: 18px;
            font-size: 11px;
            font-weight: 700;
            display: none;
            align-items: center;
            justify-content: center;
            padding: 0 4px;
            flex-shrink: 0;
            line-height: 1;
        }

        .unread-badge.visible { display: flex; }

        .conv-last {
            font-size: 12px;
            color: var(--text-muted);
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
            margin-top: 2px;
        }

        .conv-last.has-unread {
            color: var(--text-main);
            font-weight: 600;
        }

        /* ── Avatar z kropką online ──────────────────────── */
        .av-wrap {
            position: relative;
            flex-shrink: 0;
            width: 44px;
            height: 44px;
        }

        .av-wrap .av-img,
        .av-wrap .av-placeholder {
            width: 44px;
            height: 44px;
            border-radius: 50%;
            object-fit: cover;
            background: var(--border-color);
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: 700;
            font-size: 16px;
            color: var(--text-muted);
        }

        .av-dot {
            position: absolute;
            bottom: 1px;
            right: 1px;
            width: 12px;
            height: 12px;
            border-radius: 50%;
            background: #9e9e9e;
            border: 2px solid var(--bg-surface);
            transition: background 0.3s;
        }

        .av-dot.online { background: var(--success); }

        /* ── Prawa kolumna ───────────────────────────────── */
        .chat-main {
            flex: 1;
            display: flex;
            flex-direction: column;
            overflow: hidden;
            background: var(--bg-main);
        }

        .chat-empty {
            flex: 1;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            color: var(--text-muted);
            gap: 14px;
        }

        .chat-empty svg { width: 64px; height: 64px; max-width: 64px; max-height: 64px; opacity: 0.3; }
        .chat-empty p { font-size: 16px; }

        .chat-window {
            display: none;
            flex-direction: column;
            height: 100%;
        }

        /* ── Nagłówek rozmowy ────────────────────────────── */
        .chat-header {
            display: flex;
            align-items: center;
            gap: 12px;
            padding: 10px 16px;
            background: var(--bg-surface);
            border-bottom: 1px solid var(--border-color);
            flex-shrink: 0;
        }

        .btn-mobile-back {
            display: none;
            background: none;
            border: none;
            font-size: 20px;
            cursor: pointer;
            color: var(--primary-color);
            padding: 4px 8px;
            border-radius: 6px;
        }

        .btn-mobile-back:hover { background: var(--bg-hover); }

        .chat-header-av {
            width: 40px;
            height: 40px;
            border-radius: 50%;
            object-fit: cover;
            background: var(--border-color);
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: 700;
            font-size: 16px;
            color: var(--text-muted);
            flex-shrink: 0;
            overflow: hidden;
        }

        .chat-header-info { flex: 1; }

        .chat-header-name {
            font-weight: 700;
            font-size: 15px;
            color: var(--text-main);
        }

        .chat-header-status {
            font-size: 12px;
            color: var(--text-muted);
        }

        .chat-header-status.is-online { color: var(--success); }

        /* ── Obszar wiadomości ───────────────────────────── */
        .chat-messages {
            flex: 1;
            overflow-y: auto;
            padding: 16px;
            display: flex;
            flex-direction: column;
            gap: 2px;
        }

        .date-sep {
            display: flex;
            align-items: center;
            gap: 10px;
            color: var(--text-muted);
            font-size: 12px;
            margin: 12px 0 8px;
        }

        .date-sep::before,
        .date-sep::after {
            content: '';
            flex: 1;
            height: 1px;
            background: var(--border-color);
        }

        .load-more-wrap { text-align: center; padding: 8px 0 4px; }

        .btn-load-more {
            background: none;
            border: 1px solid var(--border-color);
            border-radius: 20px;
            padding: 5px 18px;
            font-size: 12px;
            color: var(--text-muted);
            cursor: pointer;
            transition: var(--transition);
        }

        .btn-load-more:hover { background: var(--bg-hover); color: var(--text-main); }

        /* ── Dymki wiadomości ────────────────────────────── */
        .msg-wrap {
            display: flex;
            flex-direction: column;
            max-width: 65%;
            margin-bottom: 2px;
        }

        .msg-wrap.sent     { align-self: flex-end;   align-items: flex-end; }
        .msg-wrap.received { align-self: flex-start; align-items: flex-start; }

        .msg-bubble {
            padding: 8px 12px;
            border-radius: 18px;
            font-size: 14px;
            line-height: 1.4;
            word-break: break-word;
        }

        .msg-wrap.sent .msg-bubble {
            background: var(--primary-color);
            color: #fff;
            border-bottom-right-radius: 4px;
        }

        .msg-wrap.received .msg-bubble {
            background: var(--bg-hover);
            color: var(--text-main);
            border-bottom-left-radius: 4px;
        }

        .msg-img {
            max-width: 280px;
            width: auto !important;
            height: auto !important;
            border-radius: 12px;
            cursor: pointer;
            display: block;
            margin-top: 4px;
            border: 1px solid var(--border-color);
            transition: opacity 0.15s;
        }

        .msg-img:hover { opacity: 0.9; }

        /* Metadane wiadomości: czas + znacznik odczytania */
        .msg-meta {
            display: flex;
            align-items: center;
            justify-content: flex-end;
            gap: 4px;
            margin-top: 3px;
            padding: 0 2px;
            min-height: 18px;
        }

        .msg-wrap.received .msg-meta { justify-content: flex-start; }

        .msg-time {
            font-size: 11px;
            color: var(--text-muted);
        }

        /* Avatar odczytania (styl Messenger) */
        .read-receipt-av {
            width: 16px !important;
            height: 16px !important;
            border-radius: 50%;
            object-fit: cover;
            border: 1.5px solid var(--bg-surface);
            opacity: 0;
            transition: opacity 0.3s ease;
            flex-shrink: 0;
            display: block;
        }

        .read-receipt-av.visible { opacity: 1; }

        /* ── Obszar wpisywania ───────────────────────────── */
        .chat-input-area {
            padding: 8px 12px 10px;
            background: var(--bg-surface);
            border-top: 1px solid var(--border-color);
            flex-shrink: 0;
        }

        .img-preview-bar {
            display: none;
            align-items: center;
            gap: 8px;
            padding-bottom: 8px;
        }

        .img-preview-thumb {
            height: 56px !important;
            width: auto !important;
            max-width: 200px;
            object-fit: cover;
            border-radius: 8px;
            border: 2px solid var(--primary-color);
        }

        .btn-remove-img {
            width: 22px;
            height: 22px;
            background: #e53935;
            color: #fff;
            border: none;
            border-radius: 50%;
            cursor: pointer;
            font-size: 13px;
            line-height: 1;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 0;
            flex-shrink: 0;
        }

        .chat-input-row {
            display: flex;
            align-items: center;
            gap: 6px;
        }

        .btn-icon {
            background: none;
            border: none;
            padding: 8px;
            cursor: pointer;
            color: var(--text-muted);
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            flex-shrink: 0;
            transition: var(--transition);
        }

        .btn-icon:hover { background: var(--bg-hover); color: var(--primary-color); }
        .btn-icon svg { width: 20px; height: 20px; max-width: 20px; max-height: 20px; }

        .chat-text-input {
            flex: 1;
            padding: 9px 14px;
            background: var(--bg-main);
            border: 1px solid var(--border-color);
            border-radius: 20px;
            font-size: 14px;
            color: var(--text-main);
            outline: none;
            transition: var(--transition);
            width: auto;
        }

        .chat-text-input:focus {
            border-color: var(--primary-color);
            box-shadow: 0 0 0 2px rgba(51, 131, 54, 0.15);
        }

        .btn-send {
            background: var(--primary-color);
            color: #fff;
            border: none;
            border-radius: 50%;
            width: 38px;
            height: 38px;
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: center;
            flex-shrink: 0;
            transition: var(--transition);
        }

        .btn-send:hover { background: var(--primary-hover); }
        .btn-send svg { width: 18px; height: 18px; max-width: 18px; max-height: 18px; }

        /* ── Lightbox ─────────────────────────────────────── */
        .lightbox {
            display: none;
            position: fixed;
            inset: 0;
            background: rgba(0, 0, 0, 0.88);
            z-index: 9999;
            align-items: center;
            justify-content: center;
        }

        .lightbox.active { display: flex; }

        .lightbox-img {
            max-width: 90vw;
            max-height: 90vh;
            border-radius: 8px;
            object-fit: contain;
            width: auto !important;
            height: auto !important;
        }

        .btn-lightbox-close {
            position: absolute;
            top: 16px;
            right: 24px;
            color: #fff;
            font-size: 36px;
            cursor: pointer;
            background: none;
            border: none;
            line-height: 1;
            opacity: 0.85;
            transition: opacity 0.15s;
        }

        .btn-lightbox-close:hover { opacity: 1; }

        /* ── Responsywność ───────────────────────────────── */
        @media (max-width: 768px) {
            .chat-sidebar                { width: 100%; }
            .chat-main                   { display: none; }
            .chat-sidebar.mob-hidden     { display: none; }
            .chat-main.mob-visible       { display: flex; width: 100%; }
            .btn-mobile-back             { display: flex; }
        }

        .no-friends {
            padding: 20px;
            text-align: center;
            color: var(--text-muted);
            font-size: 14px;
        }

        /* Badge znajomych w navbarze */
        .friend-badge {
            position: absolute;
            top: -8px;
            right: -10px;
            background: #e63946;
            color: white;
            border-radius: 50%;
            min-width: 20px;
            height: 20px;
            font-size: 12px;
            font-weight: bold;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 0 4px;
            box-shadow: 0 0 0 2px var(--bg-surface);
        }
    </style>
</head>
<body>

<!-- ── Navbar (identyczny z index.php) ──────────────────────── -->
<nav class="navbar">
    <div class="navbar-brand">TwarzBlok
        <input class="suchemashine" type="text" placeholder="Szukaj na TwarzBlok">
    </div>

    <div class="navbar-links">
        <a href="index.php" title="Tablica">
            <svg><use xlink:href="./icons/symbol-defs.svg#icon-home"></use></svg>
        </a>
        <a href="games.php" title="Gry">
            <svg><use xlink:href="./icons/symbol-defs.svg#icon-film"></use></svg>
        </a>

        <a href="friend_requests.php" title="Znajomi" style="position:relative;display:inline-flex;align-items:center;">
            <svg><use xlink:href="./icons/symbol-defs.svg#icon-users"></use></svg>
            <span id="pending-friends-count" class="friend-badge"
                  style="<?= ($pendingCount === 0) ? 'display:none;' : '' ?>">
                <?= $pendingCount ?>
            </span>
        </a>

        <a href="#" title="Marketplace">
            <svg><use xlink:href="./icons/symbol-defs.svg#icon-briefcase"></use></svg>
        </a>

        <a href="chat.php" title="Wiadomości" style="color:var(--primary-color);">
            <svg><use xlink:href="./icons/symbol-defs.svg#icon-bubbles4"></use></svg>
        </a>
    </div>

    <div class="navbar-links2">
        <?php if ($userRole === 'admin'): ?>
            <a href="admin_panel.php" title="Panel Administratora">
                <div class="icon-wrapper" style="background-color:rgba(230,57,70,0.1);color:#e63946;">
                    <svg style="fill:currentColor;"><use xlink:href="./icons/symbol-defs.svg#icon-list2"></use></svg>
                </div>
            </a>
        <?php endif; ?>

        <a href="#"><div class="icon-wrapper"><svg><use xlink:href="./icons/symbol-defs.svg#icon-list2"></use></svg></div></a>
        <a href="#"><div class="icon-wrapper"><svg><use xlink:href="./icons/symbol-defs.svg#icon-bubbles2"></use></svg></div></a>
        <a href="#"><div class="icon-wrapper"><svg><use xlink:href="./icons/symbol-defs.svg#icon-bell"></use></svg></div></a>

        <div class="user-profile-dropdown">
            <div class="avatar-navbar-wrapper">
                <?php if ($userAvatarUrl): ?>
                    <img src="<?= htmlspecialchars($userAvatarUrl) ?>" alt="Profil"
                         style="width:100%;height:100%;object-fit:cover;display:block;">
                <?php else: ?>
                    <svg style="width:24px;height:24px;">
                        <use xlink:href="./icons/symbol-defs.svg#icon-user"></use>
                    </svg>
                <?php endif; ?>
            </div>

            <div class="dropdown-menu-content">
                <a href="settings.php" class="dropdown-item settings-btn">
                    <svg><use xlink:href="./icons/symbol-defs.svg#icon-user"></use></svg>
                    Ustawienia i prywatność
                </a>
                <a href="logout.php" class="dropdown-item logout-btn">
                    <svg><use xlink:href="./icons/symbol-defs.svg#icon-user"></use></svg>
                    Wyloguj się
                </a>
            </div>
        </div>
    </div>
</nav>

<!-- ── Aplikacja czatu ──────────────────────────────────────── -->
<div class="chat-app">

    <!-- Lewa kolumna: lista rozmów -->
    <aside class="chat-sidebar" id="chatSidebar">
        <div class="sidebar-head">
            <h3>Wiadomości</h3>
            <input type="text" class="sidebar-search" id="searchInput" placeholder="Szukaj znajomych...">
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

<!-- Lightbox -->
<div class="lightbox" id="lightbox">
    <button class="btn-lightbox-close" id="lightboxClose">&#10005;</button>
    <img class="lightbox-img" id="lightboxImg" src="" alt="Podgląd zdjęcia">
</div>

<script src="http://localhost:3000/socket.io/socket.io.js"></script>
<script>
/* ── Stałe ──────────────────────────────────────────────── */
const TOKEN   = <?= json_encode($token) ?>;
const USER_ID = <?= $userId ?>;

/* ── Socket.io ──────────────────────────────────────────── */
const socket = io('http://localhost:3000', { auth: { token: TOKEN } });
socket.on('connect',       () => console.log('✅ Socket.io ok'));
socket.on('connect_error', e  => console.error('❌ Socket:', e.message));
socket.on('error',         m  => console.error('❌ Server:', m));

/* ── Stan czatu ─────────────────────────────────────────── */
let currentChatId    = null;
let currentFriendId  = null;
let oldestMsgId      = null;
let selectedFile     = null;
let lastMsgDateKey   = null;
let currentReadMsgId = null;   // ID wiadomości z aktualnie wyświetlonym receiptem
let pollInterval     = null;

/* ── Otwarcie czatu ─────────────────────────────────────── */
async function openChat(friendId, friendName, avatarUrl, initials) {
    if (currentFriendId === friendId) return;

    stopPolling();
    currentFriendId  = friendId;
    lastMsgDateKey   = null;
    oldestMsgId      = null;
    currentReadMsgId = null;

    document.querySelectorAll('.conv-item').forEach(el => el.classList.remove('active'));
    const item = document.querySelector(`.conv-item[data-uid="${friendId}"]`);
    if (item) item.classList.add('active');

    // Mobile toggle
    document.getElementById('chatSidebar').classList.add('mob-hidden');
    document.getElementById('chatMain').classList.add('mob-visible');

    document.getElementById('chatEmpty').style.display  = 'none';
    document.getElementById('chatWindow').style.display = 'flex';

    // Nagłówek
    const avEl = document.getElementById('chatHeaderAv');
    if (avatarUrl) {
        avEl.innerHTML = `<img src="${esc(avatarUrl)}" alt=""
            style="width:40px;height:40px;border-radius:50%;object-fit:cover;display:block;">`;
    } else {
        avEl.textContent = initials;
        avEl.style.background = 'var(--border-color)';
    }
    document.getElementById('chatHeaderName').textContent = friendName;
    setStatus('Offline', false);

    // Wyczyść wiadomości
    const msgsDiv = document.getElementById('messages');
    msgsDiv.innerHTML =
        '<div class="load-more-wrap" id="loadMoreWrap" style="display:none;">' +
        '<button class="btn-load-more" id="loadMoreBtn">Załaduj wcześniejsze wiadomości</button></div>';
    document.getElementById('loadMoreBtn').addEventListener('click', loadMore);

    try {
        const res  = await fetch(`get_or_create_chat.php?friend_id=${friendId}`);
        const data = await res.json();
        if (data.error) { alert('Błąd: ' + data.error); return; }

        currentChatId = data.chat_id;
        socket.emit('join_chat', { chat_id: currentChatId });

        await loadHistory();

        // Oznacz jako przeczytane i wyczyść badge
        markAsRead(currentChatId, friendId);

        // Zacznij polling
        startPolling();
    } catch (err) {
        console.error('openChat error:', err);
    }
}

/* ── Wczytaj historię ───────────────────────────────────── */
async function loadHistory() {
    try {
        const res  = await fetch(`get_history.php?chat_id=${currentChatId}`);
        const data = await res.json();
        if (!data.messages) return;

        data.messages.forEach(msg => {
            appendMessage(msg, msg.sender_id == USER_ID ? 'sent' : 'received', false);
        });

        if (data.messages.length > 0) {
            oldestMsgId = data.messages[0].message_id;
        }

        document.getElementById('loadMoreWrap').style.display = data.has_more ? 'block' : 'none';
        scrollToBottom();
    } catch (err) {
        console.error('loadHistory error:', err);
    }
}

/* ── Załaduj starsze wiadomości ─────────────────────────── */
async function loadMore() {
    if (!oldestMsgId) return;
    try {
        const scrollEl = document.getElementById('messages');
        const prevH    = scrollEl.scrollHeight;

        const res  = await fetch(`get_history.php?chat_id=${currentChatId}&before_id=${oldestMsgId}`);
        const data = await res.json();
        if (!data.messages || !data.messages.length) {
            document.getElementById('loadMoreWrap').style.display = 'none';
            return;
        }

        const anchor = document.getElementById('loadMoreWrap');
        for (let i = data.messages.length - 1; i >= 0; i--) {
            const msg  = data.messages[i];
            prependMessage(msg, msg.sender_id == USER_ID ? 'sent' : 'received', anchor.nextSibling);
        }

        oldestMsgId = data.messages[0].message_id;
        document.getElementById('loadMoreWrap').style.display = data.has_more ? 'block' : 'none';
        scrollEl.scrollTop += scrollEl.scrollHeight - prevH;
    } catch (err) {
        console.error('loadMore error:', err);
    }
}

/* ── Polling: read receipt + unread counts ──────────────── */
function startPolling() {
    stopPolling();
    pollInterval = setInterval(async () => {
        if (!currentChatId || !currentFriendId) return;

        // 1. Status odczytania moich wiadomości przez znajomego
        try {
            const res  = await fetch(
                `get_read_status.php?chat_id=${currentChatId}&friend_id=${currentFriendId}`
            );
            const data = await res.json();
            if (!data.error) {
                updateReadReceipt(data.last_read_id, data.friend_avatar, data.read_at);
            }
        } catch (e) { /* sieć */ }

        // 2. Liczniki nieprzeczytanych w całym sidebarze
        refreshUnreadBadges();
    }, 3000);
}

function stopPolling() {
    if (pollInterval) { clearInterval(pollInterval); pollInterval = null; }
}

/* ── Odśwież badges nieprzeczytanych ────────────────────── */
async function refreshUnreadBadges() {
    try {
        const res    = await fetch('get_unread_counts.php');
        const counts = await res.json();
        updateUnreadBadges(counts);
    } catch (e) { /* sieć */ }
}

/* ── Aktualizacja UI badges nieprzeczytanych ────────────── */
function updateUnreadBadges(counts) {
    document.querySelectorAll('.conv-item').forEach(item => {
        const uid    = item.dataset.uid;
        const count  = counts[uid] || 0;
        const badge  = item.querySelector('.unread-badge');
        const nameEl = item.querySelector('.conv-name');
        const lastEl = item.querySelector('.conv-last');

        if (count > 0) {
            badge.textContent = count > 99 ? '99+' : count;
            badge.classList.add('visible');
            nameEl.classList.add('has-unread');
            if (lastEl) lastEl.classList.add('has-unread');
        } else {
            badge.textContent = '';
            badge.classList.remove('visible');
            nameEl.classList.remove('has-unread');
            if (lastEl) lastEl.classList.remove('has-unread');
        }
    });
}

/* ── Oznacz jako przeczytane ────────────────────────────── */
function markAsRead(chatId, friendId) {
    fetch('mark_read.php', {
        method:  'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body:    `chat_id=${chatId}`
    }).then(() => {
        // Natychmiast wyczyść badge dla tego znajomego
        const item = document.querySelector(`.conv-item[data-uid="${friendId}"]`);
        if (!item) return;
        const badge  = item.querySelector('.unread-badge');
        const nameEl = item.querySelector('.conv-name');
        const lastEl = item.querySelector('.conv-last');
        if (badge)  { badge.textContent = ''; badge.classList.remove('visible'); }
        if (nameEl) nameEl.classList.remove('has-unread');
        if (lastEl) lastEl.classList.remove('has-unread');
    }).catch(() => {});
}

/* ── Aktualizacja read receipt ──────────────────────────── */
function updateReadReceipt(lastReadId, friendAvatar, readAt) {
    if (!lastReadId) return;
    if (lastReadId === currentReadMsgId) return; // brak zmian

    // Ukryj stary receipt
    if (currentReadMsgId) {
        const old = document.querySelector(`.msg-wrap.sent[data-msg-id="${currentReadMsgId}"]`);
        if (old) {
            const oldAv = old.querySelector('.read-receipt-av');
            if (oldAv) oldAv.classList.remove('visible');
        }
    }

    // Pokaż nowy
    const wrap = document.querySelector(`.msg-wrap.sent[data-msg-id="${lastReadId}"]`);
    if (!wrap) return;

    const rcAv = wrap.querySelector('.read-receipt-av');
    if (!rcAv) return;

    if (friendAvatar) {
        rcAv.src = friendAvatar;
    } else {
        // Brak avatara – pokaż zielone kółko
        rcAv.src = 'data:image/svg+xml,' + encodeURIComponent(
            '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 16 16">' +
            '<circle cx="8" cy="8" r="8" fill="#338336"/>' +
            '<path fill="white" d="M4 8l3 3 5-5" stroke="white" stroke-width="1.5" fill="none" stroke-linecap="round"/>' +
            '</svg>'
        );
    }

    if (readAt) {
        const t = new Date(readAt).toLocaleTimeString('pl-PL', { hour: '2-digit', minute: '2-digit' });
        rcAv.title = `Odczytano o ${t}`;
    } else {
        rcAv.title = 'Odczytano';
    }

    rcAv.classList.add('visible');
    currentReadMsgId = lastReadId;
}

/* ── Formatowanie ───────────────────────────────────────── */
function fmtTime(sentAt) {
    const d = new Date(String(sentAt).replace(' ', 'T'));
    return d.toLocaleTimeString('pl-PL', { hour: '2-digit', minute: '2-digit' });
}

function fmtDate(sentAt) {
    const d = new Date(String(sentAt).replace(' ', 'T'));
    return d.toLocaleDateString('pl-PL', { day: 'numeric', month: 'long', year: 'numeric' });
}

function dateKey(sentAt) {
    const d = new Date(String(sentAt).replace(' ', 'T'));
    return `${d.getFullYear()}-${d.getMonth()}-${d.getDate()}`;
}

function esc(str) {
    const d = document.createElement('div');
    d.textContent = String(str);
    return d.innerHTML;
}

/* ── Budowanie elementu wiadomości ──────────────────────── */
function buildMsgWrap(msg, type) {
    const sentAt = msg.sent_at || new Date().toISOString();
    const wrap   = document.createElement('div');
    wrap.className = `msg-wrap ${type}`;

    if (msg.message_id) wrap.dataset.msgId = msg.message_id;

    if (msg.content && msg.content.trim()) {
        const bubble = document.createElement('div');
        bubble.className = 'msg-bubble';
        bubble.textContent = msg.content;
        wrap.appendChild(bubble);
    }

    if (msg.attachment_url) {
        const img = document.createElement('img');
        img.className = 'msg-img';
        img.src = esc(msg.attachment_url);
        img.alt = 'Zdjęcie';
        img.addEventListener('click', () => openLightbox(msg.attachment_url));
        wrap.appendChild(img);
    }

    // Meta: czas + read receipt
    const meta = document.createElement('div');
    meta.className = 'msg-meta';

    const timeEl = document.createElement('span');
    timeEl.className = 'msg-time';
    timeEl.textContent = fmtTime(sentAt);
    meta.appendChild(timeEl);

    // Avatar odczytania tylko dla własnych wiadomości (sent)
    if (type === 'sent' && msg.message_id) {
        const rcAv = document.createElement('img');
        rcAv.className = 'read-receipt-av';
        rcAv.alt = 'Odczytano';
        meta.appendChild(rcAv);
    }

    wrap.appendChild(meta);

    return { wrap, dateK: dateKey(sentAt), dateLabel: fmtDate(sentAt) };
}

/* ── Dołącz wiadomość na dole ───────────────────────────── */
function appendMessage(msg, type, scroll = true) {
    const msgsDiv = document.getElementById('messages');
    const { wrap, dateK, dateLabel } = buildMsgWrap(msg, type);

    if (dateK !== lastMsgDateKey) {
        lastMsgDateKey = dateK;
        const sep = document.createElement('div');
        sep.className = 'date-sep';
        sep.textContent = dateLabel;
        msgsDiv.appendChild(sep);
    }

    msgsDiv.appendChild(wrap);
    if (scroll) scrollToBottom();
}

/* ── Wstaw wiadomość na górze (load more) ───────────────── */
function prependMessage(msg, type, beforeEl) {
    const msgsDiv = document.getElementById('messages');
    const { wrap, dateLabel } = buildMsgWrap(msg, type);

    const sep = document.createElement('div');
    sep.className = 'date-sep';
    sep.textContent = dateLabel;
    msgsDiv.insertBefore(sep, beforeEl);
    msgsDiv.insertBefore(wrap, sep.nextSibling);
}

function scrollToBottom() {
    const m = document.getElementById('messages');
    m.scrollTop = m.scrollHeight;
}

/* ── Wysyłanie wiadomości ───────────────────────────────── */
async function sendMessage() {
    if (!currentChatId) return;

    const input   = document.getElementById('messageInput');
    const content = input.value.trim();
    if (!content && !selectedFile) return;

    let attachmentUrl = null;

    if (selectedFile) {
        try {
            const fd = new FormData();
            fd.append('image', selectedFile);
            const res  = await fetch('upload_chat_image.php', { method: 'POST', body: fd });
            const data = await res.json();
            if (data.error) { alert('Błąd uploadu: ' + data.error); return; }
            attachmentUrl = data.url;
        } catch {
            alert('Błąd przesyłania zdjęcia.');
            return;
        }
    }

    socket.emit('send_message', {
        chat_id:        currentChatId,
        content:        content,
        message_type:   attachmentUrl ? 'image' : 'text',
        attachment_url: attachmentUrl
    });

    input.value = '';
    clearImagePreview();
}

/* ── Nowe wiadomości z Socket.io ────────────────────────── */
socket.on('new_message', (msg) => {
    if (msg.chat_id === currentChatId) {
        const type = msg.user_id == USER_ID ? 'sent' : 'received';
        appendMessage(msg, type);

        // Jeśli odebraliśmy wiadomość od innego i czat jest otwarty — oznacz jako przeczytaną
        if (msg.user_id != USER_ID) {
            markAsRead(currentChatId, currentFriendId);
        }
    }

    // Aktualizuj podgląd ostatniej wiadomości w sidebarze
    const targetFriendId = msg.user_id == USER_ID ? currentFriendId : msg.user_id;
    if (targetFriendId) {
        const lastEl = document.getElementById(`last-msg-${targetFriendId}`);
        if (lastEl) {
            lastEl.textContent = msg.attachment_url
                ? '📷 Zdjęcie'
                : (msg.content || '').substring(0, 38);
        }
    }
});

/* ── Status online/offline ──────────────────────────────── */
socket.on('user_online',  d => setUserOnline(d.user_id, true));
socket.on('user_offline', d => setUserOnline(d.user_id, false));

function setUserOnline(uid, online) {
    document.querySelectorAll(`.av-dot[data-uid="${uid}"]`).forEach(dot => {
        dot.classList.toggle('online', online);
    });
    if (uid == currentFriendId) setStatus(online ? 'Online' : 'Offline', online);
}

function setStatus(text, online) {
    const el = document.getElementById('chatHeaderStatus');
    if (!el) return;
    el.textContent = online ? '● ' + text : text;
    el.className   = 'chat-header-status' + (online ? ' is-online' : '');
}

/* ── Plik (zdjęcie) ─────────────────────────────────────── */
document.getElementById('attachBtn').addEventListener('click', () => {
    document.getElementById('fileInput').click();
});

document.getElementById('fileInput').addEventListener('change', (e) => {
    const file    = e.target.files[0];
    if (!file) return;

    const allowed = ['image/jpeg', 'image/png', 'image/webp', 'image/gif'];
    if (!allowed.includes(file.type)) { alert('Dozwolone: JPG, PNG, WebP, GIF'); return; }
    if (file.size > 10 * 1024 * 1024) { alert('Plik za duży (max 10 MB)'); return; }

    selectedFile = file;
    const reader = new FileReader();
    reader.onload = ev => {
        document.getElementById('previewImg').src = ev.target.result;
        document.getElementById('imgPreviewBar').style.display = 'flex';
    };
    reader.readAsDataURL(file);
    e.target.value = '';
});

document.getElementById('removeImgBtn').addEventListener('click', clearImagePreview);

function clearImagePreview() {
    selectedFile = null;
    document.getElementById('imgPreviewBar').style.display = 'none';
    document.getElementById('previewImg').src = '';
}

/* ── Lightbox ───────────────────────────────────────────── */
function openLightbox(src) {
    document.getElementById('lightboxImg').src = src;
    document.getElementById('lightbox').classList.add('active');
}

document.getElementById('lightboxClose').addEventListener('click', closeLightbox);
document.getElementById('lightbox').addEventListener('click', e => {
    if (e.target === e.currentTarget) closeLightbox();
});

function closeLightbox() {
    document.getElementById('lightbox').classList.remove('active');
    document.getElementById('lightboxImg').src = '';
}

document.addEventListener('keydown', e => { if (e.key === 'Escape') closeLightbox(); });

/* ── Wysyłanie ──────────────────────────────────────────── */
document.getElementById('sendBtn').addEventListener('click', sendMessage);
document.getElementById('messageInput').addEventListener('keypress', e => {
    if (e.key === 'Enter' && !e.shiftKey) { e.preventDefault(); sendMessage(); }
});

/* ── Kliknięcia w listę rozmów ──────────────────────────── */
document.querySelectorAll('.conv-item').forEach(item => {
    item.addEventListener('click', () => {
        openChat(
            item.dataset.uid,
            item.dataset.name,
            item.dataset.avatar,
            item.dataset.initials
        );
    });
});

/* ── Powrót (mobile) ────────────────────────────────────── */
document.getElementById('mobileBack').addEventListener('click', () => {
    document.getElementById('chatSidebar').classList.remove('mob-hidden');
    document.getElementById('chatMain').classList.remove('mob-visible');
    if (currentChatId) socket.emit('leave_chat', { chat_id: currentChatId });
    stopPolling();
    currentChatId   = null;
    currentFriendId = null;
    document.getElementById('chatWindow').style.display = 'none';
    document.getElementById('chatEmpty').style.display  = 'flex';
    document.querySelectorAll('.conv-item').forEach(el => el.classList.remove('active'));
});

/* ── Wyszukiwanie ───────────────────────────────────────── */
document.getElementById('searchInput').addEventListener('input', function () {
    const q = this.value.toLowerCase();
    document.querySelectorAll('.conv-item').forEach(el => {
        const match = el.dataset.name.toLowerCase().includes(q) ||
                      el.dataset.username.toLowerCase().includes(q);
        el.style.display = match ? 'flex' : 'none';
    });
});

/* ── Inicjalizacja: załaduj badges na starcie ───────────── */
document.addEventListener('DOMContentLoaded', () => {
    refreshUnreadBadges();
});

/* ── Licznik zaproszeń do znajomych (navbar) ────────────── */
function updatePendingFriendsCount() {
    fetch('index.php', {
        method:  'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body:    'action=get_pending_count'
    })
    .then(r => r.json())
    .then(data => {
        if (!data.success) return;
        const badge = document.getElementById('pending-friends-count');
        if (!badge) return;
        if (data.count > 0) {
            badge.textContent  = data.count;
            badge.style.display = 'flex';
        } else {
            badge.style.display = 'none';
        }
    })
    .catch(() => {});
}

setInterval(updatePendingFriendsCount, 15000);
</script>
</body>
</html>
