<?php
session_start();
if (!isset($_SESSION['user_id'])) {
    header('Location: index.php');
    exit;
}

require_once __DIR__ . '/config/database.php';

$userId   = (int)$_SESSION['user_id'];
$username = $_SESSION['username'] ?? 'Użytkownik';

// Pobranie znajomych
$stmt = db()->prepare("
    SELECT u.user_id, u.username, u.avatar_url, u.first_name, u.last_name
    FROM friendships f
    JOIN users u ON (f.requester_id = u.user_id OR f.addressee_id = u.user_id)
    LEFT JOIN user_blocks ub ON (ub.blocker_id = ? AND ub.blocked_id = u.user_id)
                               OR (ub.blocker_id = u.user_id AND ub.blocked_id = ?)
    WHERE f.status = 'accepted'
      AND (
           (f.requester_id = ? AND u.user_id = f.addressee_id)
        OR (f.addressee_id = ? AND u.user_id = f.requester_id)
      )
      AND u.user_id != ?
      AND ub.block_id IS NULL
    GROUP BY u.user_id
");
$stmt->execute([$userId, $userId, $userId, $userId, $userId]);
$friends = $stmt->fetchAll();

// Token z kodowaniem URL‑safe
$payload = json_encode([
        'user_id'  => $userId,
        'username' => $username,
        'exp'      => time() + 3600
]);
$signature = hash_hmac('sha256', $payload, SOCKET_SECRET, true);
$token = base64_encode($payload) . '.' . base64_encode($signature);
?>
<!DOCTYPE html>
<html lang="pl">
<head>
    <meta charset="UTF-8">
    <title>TwarzBlok - Wiadomości</title>
    <link rel="stylesheet" href="css/style.css">
    <style>
        .chats-sidebar { width: 280px; flex-shrink: 0; }
        .chat-main-zone { flex: 1; }
        .contact-item {
            display: flex; align-items: center; gap: 10px; padding: 10px;
            border-bottom: 1px solid #ddd; cursor: pointer;
        }
        .contact-item:hover { background: #f0f0f0; }
        .avatar {
            width: 40px; height: 40px; border-radius: 50%; background: #ccc;
            position: relative; overflow: hidden;
        }
        .avatar img { width: 100%; height: 100%; object-fit: cover; }
        .avatar.online::after {
            content: ''; position: absolute; bottom: 2px; right: 2px;
            width: 10px; height: 10px; background: #4caf50;
            border-radius: 50%; border: 2px solid white;
        }
        .chat-window { display: flex; flex-direction: column; height: 560px; }
        .chat-messages { flex: 1; overflow-y: auto; padding: 10px; background: #f9f9f9; }
        .message { margin-bottom: 10px; }
        .message-sender { font-weight: bold; display: block; }
        .message.received { text-align: left; }
        .message.sent { text-align: right; }
        .chat-footer { display: flex; padding: 10px; border-top: 1px solid #ddd; }
        .chat-footer input { flex: 1; margin-right: 10px; }
        .text-muted { color: #666; }
        .font-size-13 { font-size: 13px; }
    </style>
    <script src="http://localhost:3000/socket.io/socket.io.js"></script>
</head>
<body>
<nav class="navbar">
    <div class="navbar-brand">TwarzBlok 💬</div>
    <div class="navbar-links">
        <a href="index.php">🏠 Dom</a>
        <a href="games.php">🎮 Gry</a>
    </div>
    <div><a href="index.php" class="btn btn-secondary">Powrót do tablicy</a></div>
</nav>

<div class="fb-container game-layout">
    <aside class="chats-sidebar">
        <div class="card" style="height: 560px; overflow-y: auto;">
            <h3 class="m-bottom-15">Czaty</h3>
            <div class="form-group">
                <input type="text" class="form-input" id="friendSearch" placeholder="Szukaj...">
            </div>
            <div id="friendsList">
                <?php foreach ($friends as $friend): ?>
                    <div class="contact-item" data-userid="<?= $friend['user_id'] ?>"
                         data-username="<?= htmlspecialchars($friend['username']) ?>">
                        <div class="avatar" id="avatar-<?= $friend['user_id'] ?>">
                            <?php if ($friend['avatar_url']): ?>
                                <img src="<?= htmlspecialchars($friend['avatar_url']) ?>" alt="">
                            <?php endif; ?>
                        </div>
                        <div>
                            <div style="font-weight: 600;"><?= htmlspecialchars($friend['username']) ?></div>
                            <div class="text-muted font-size-13 last-msg" id="last-msg-<?= $friend['user_id'] ?>"></div>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
    </aside>

    <main class="chat-main-zone">
        <div class="card chat-window" id="chatWindow" style="display:none;">
            <div style="display: flex; align-items: center; gap: 10px; padding-bottom: 10px; border-bottom: 1px solid var(--border-color);">
                <div class="avatar" id="chatAvatar"></div>
                <div>
                    <div style="font-weight: bold;" id="chatUsername"></div>
                    <div class="text-muted font-size-13" id="chatStatus">Offline</div>
                </div>
            </div>
            <div class="chat-messages" id="messages"></div>
            <div class="chat-footer">
                <input type="text" class="form-input" id="messageInput" placeholder="Napisz wiadomość...">
                <button class="btn btn-primary" id="sendBtn">Wyślij</button>
            </div>
        </div>
        <div id="noChatSelected" class="card" style="height:560px;display:flex;align-items:center;justify-content:center;">
            <p>Wybierz znajomego, aby rozpocząć rozmowę.</p>
        </div>
    </main>
</div>

<script>
    const TOKEN = <?= json_encode($token) ?>;
    const USER_ID = <?= $userId ?>;

    const socket = io('http://localhost:3000', { auth: { token: TOKEN } });

    socket.on('connect', () => {
        console.log('✅ Połączono z Socket.io, ID:', socket.id);
    });
    socket.on('connect_error', (err) => {
        console.error('❌ Błąd połączenia Socket.io:', err.message);
    });
    socket.on('error', (msg) => {
        console.error('❌ Błąd serwera:', msg);
    });

    let currentChatId = null;
    let currentFriendId = null;

    document.querySelectorAll('.contact-item').forEach(item => {
        item.addEventListener('click', async () => {
            const friendId = item.dataset.userid;
            const friendName = item.dataset.username;
            openChat(friendId, friendName);
        });
    });

    async function openChat(friendId, friendName) {
        if (currentFriendId === friendId) return;
        currentFriendId = friendId;

        try {
            const res = await fetch(`get_or_create_chat.php?friend_id=${friendId}`);
            const data = await res.json();
            console.log('📬 Odpowiedź get_or_create_chat:', data);
            if (data.error) {
                alert('Błąd: ' + data.error);
                return;
            }
            currentChatId = data.chat_id;
            console.log('📌 chat_id:', currentChatId);

            socket.emit('join_chat', { chat_id: currentChatId });

            const histRes = await fetch(`get_history.php?chat_id=${currentChatId}`);
            const histData = await histRes.json();
            const messagesDiv = document.getElementById('messages');
            messagesDiv.innerHTML = '';
            if (histData.messages) {
                histData.messages.forEach(msg => {
                    appendMessage(msg.username, msg.content, msg.sender_id == USER_ID ? 'sent' : 'received');
                });
                messagesDiv.scrollTop = messagesDiv.scrollHeight;
            }

            document.getElementById('chatUsername').textContent = friendName;
            document.getElementById('chatStatus').textContent = 'Offline';
            document.getElementById('noChatSelected').style.display = 'none';
            document.getElementById('chatWindow').style.display = 'flex';
        } catch (err) {
            console.error('❌ Błąd openChat:', err);
        }
    }

    function appendMessage(sender, text, type) {
        const div = document.createElement('div');
        div.className = `message ${type}`;
        div.innerHTML = `<span class="message-sender">${sender}</span>${text}`;
        document.getElementById('messages').appendChild(div);
        document.getElementById('messages').scrollTop = document.getElementById('messages').scrollHeight;
    }

    document.getElementById('sendBtn').addEventListener('click', () => {
        const input = document.getElementById('messageInput');
        const content = input.value.trim();
        if (!content || !currentChatId) {
            console.warn('⚠ Nie można wysłać – brak chat_id lub treści');
            return;
        }
        console.log('✉ Wysyłam:', { chat_id: currentChatId, content });
        socket.emit('send_message', { chat_id: currentChatId, content, message_type: 'text' });
        input.value = '';
    });

    document.getElementById('messageInput').addEventListener('keypress', (e) => {
        if (e.key === 'Enter') document.getElementById('sendBtn').click();
    });

    socket.on('new_message', (msg) => {
        console.log('📩 Odebrano wiadomość:', msg);
        if (msg.chat_id === currentChatId) {
            appendMessage(msg.username, msg.content, msg.user_id == USER_ID ? 'sent' : 'received');
        }
        const last = document.getElementById(`last-msg-${msg.friend_id || msg.user_id}`);
        if (last) last.textContent = msg.content.substring(0, 30);
    });

    socket.on('user_online', (data) => {
        const av = document.getElementById(`avatar-${data.user_id}`);
        if (av) av.classList.add('online');
        if (data.user_id == currentFriendId) document.getElementById('chatStatus').textContent = 'Online';
    });

    socket.on('user_offline', (data) => {
        const av = document.getElementById(`avatar-${data.user_id}`);
        if (av) av.classList.remove('online');
        if (data.user_id == currentFriendId) document.getElementById('chatStatus').textContent = 'Offline';
    });

    document.getElementById('friendSearch').addEventListener('input', function () {
        const q = this.value.toLowerCase();
        document.querySelectorAll('.contact-item').forEach(el => {
            el.style.display = el.dataset.username.toLowerCase().includes(q) ? 'flex' : 'none';
        });
    });
</script>
</body>
</html>