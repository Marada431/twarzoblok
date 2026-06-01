<?php
session_start();
if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}

require_once 'config/database.php';

$user_id = $_SESSION['user_id'];
$username = $_SESSION['username'] ?? '';

$chat_id = isset($_GET['chat_id']) ? (int)$_GET['chat_id'] : 0;

// Funkcja pobierająca czaty użytkownika
function getUserChats(PDO $pdo, int $user_id): array {
    $stmt = $pdo->prepare("
        SELECT c.chat_id, c.name, c.chat_type, c.avatar_url,
               (SELECT COUNT(*) FROM chat_participants WHERE chat_id = c.chat_id) AS members_count
        FROM chat_participants cp
        JOIN chats c ON cp.chat_id = c.chat_id
        WHERE cp.user_id = :uid
        ORDER BY c.chat_id DESC
    ");
    $stmt->execute(['uid' => $user_id]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

$pdo = db(); // <-- użycie funkcji z database.php
$chats = getUserChats($pdo, $user_id);

if ($chat_id === 0) {
    if (!empty($chats)) {
        header('Location: chat.php?chat_id=' . $chats[0]['chat_id']);
        exit;
    } else {
        echo "<p>Nie masz jeszcze żadnych czatów.</p>";
        exit;
    }
}

// Pobranie bieżącego czatu
$stmt = $pdo->prepare("SELECT * FROM chats WHERE chat_id = :cid");
$stmt->execute(['cid' => $chat_id]);
$currentChat = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$currentChat) {
    echo "<p>Czat nie istnieje.</p>";
    exit;
}

// Sprawdzenie dostępu
$stmt = $pdo->prepare("SELECT 1 FROM chat_participants WHERE chat_id = :cid AND user_id = :uid");
$stmt->execute(['cid' => $chat_id, 'uid' => $user_id]);
if ($stmt->rowCount() === 0) {
    echo "<p>Brak dostępu do tego czatu.</p>";
    exit;
}

// Pobranie wstępnych wiadomości
$stmt = $pdo->prepare("
    SELECT m.message_id, m.sender_id, u.username, m.content, m.message_type, m.sent_at
    FROM chat_messages m
    JOIN users u ON m.sender_id = u.user_id
    WHERE m.chat_id = :cid AND m.status = 'active'
    ORDER BY m.sent_at ASC
    LIMIT 30
");
$stmt->execute(['cid' => $chat_id]);
$initialMessages = $stmt->fetchAll(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html lang="pl">
<head>
    <meta charset="UTF-8">
    <title>Czat - <?php echo htmlspecialchars($currentChat['name'] ?? 'Bez nazwy'); ?></title>
    <link rel="stylesheet" href="css/style.css">
    <style>
        .chat-container { display: flex; height: 90vh; }
        .chat-list { width: 250px; border-right: 1px solid #ccc; padding: 10px; overflow-y: auto; }
        .chat-list a { display: block; padding: 8px; margin: 2px 0; text-decoration: none; color: #333; border-radius: 4px; }
        .chat-list a.active { background-color: #ddd; font-weight: bold; }
        .chat-window { flex: 1; display: flex; flex-direction: column; }
        .messages { flex: 1; overflow-y: auto; padding: 10px; background: #f9f9f9; }
        .message { margin-bottom: 10px; }
        .message .username { font-weight: bold; }
        .message .time { font-size: 0.8em; color: #666; }
        .input-area { display: flex; padding: 10px; border-top: 1px solid #ccc; }
        .input-area input { flex: 1; padding: 8px; }
        .input-area button { padding: 8px 16px; }
        .online-users { width: 200px; border-left: 1px solid #ccc; padding: 10px; overflow-y: auto; }
        .online-users ul { list-style: none; padding: 0; }
        .online-users li { padding: 4px 0; }
    </style>
</head>
<body>
<div class="chat-container">
    <div class="chat-list">
        <h3>Rozmowy</h3>
        <?php foreach ($chats as $chat): ?>
            <a href="?chat_id=<?php echo $chat['chat_id']; ?>"
               class="<?php echo ($chat['chat_id'] == $chat_id) ? 'active' : ''; ?>">
                <?php echo htmlspecialchars($chat['name'] ?? 'Czat #'.$chat['chat_id']); ?>
                <small>(<?php echo $chat['chat_type']; ?>)</small>
            </a>
        <?php endforeach; ?>
    </div>

    <div class="chat-window">
        <div class="chat-header">
            <h2><?php echo htmlspecialchars($currentChat['name'] ?? 'Czat #'.$chat_id); ?></h2>
        </div>
        <div class="messages" id="messages">
            <?php foreach ($initialMessages as $msg): ?>
                <div class="message" data-id="<?php echo $msg['message_id']; ?>">
                    <span class="username"><?php echo htmlspecialchars($msg['username']); ?>:</span>
                    <span class="content"><?php echo htmlspecialchars($msg['content']); ?></span>
                    <span class="time"><?php echo date('H:i', strtotime($msg['sent_at'])); ?></span>
                </div>
            <?php endforeach; ?>
        </div>
        <div class="input-area">
            <input type="text" id="messageInput" placeholder="Wpisz wiadomość..." />
            <button id="sendBtn">Wyślij</button>
        </div>
    </div>

    <div class="online-users">
        <h4>Online</h4>
        <ul id="onlineList"></ul>
    </div>
</div>

<script src="https://cdn.socket.io/4.5.4/socket.io.min.js"></script>
<script>
    const userId = <?php echo $user_id; ?>;
    const username = "<?php echo addslashes($username); ?>";
    const chatId = <?php echo $chat_id; ?>;

    const socket = io('http://localhost:3000', {
        auth: { userId: userId, chatId: chatId }
    });

    const messagesDiv = document.getElementById('messages');
    const messageInput = document.getElementById('messageInput');
    const sendBtn = document.getElementById('sendBtn');
    const onlineList = document.getElementById('onlineList');

    function appendMessage(data) {
        const div = document.createElement('div');
        div.className = 'message';
        div.dataset.id = data.message_id;
        const time = new Date(data.sent_at).toLocaleTimeString('pl-PL', { hour: '2-digit', minute: '2-digit' });
        div.innerHTML = `
                <span class="username">${escapeHtml(data.username)}:</span>
                <span class="content">${escapeHtml(data.content)}</span>
                <span class="time">${time}</span>
            `;
        messagesDiv.appendChild(div);
        messagesDiv.scrollTop = messagesDiv.scrollHeight;
    }

    function escapeHtml(text) {
        const map = { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#039;' };
        return text.replace(/[&<>"']/g, m => map[m]);
    }

    socket.on('new_message', (data) => appendMessage(data));

    socket.on('chat_history', (messages) => {
        messagesDiv.innerHTML = '';
        messages.forEach(msg => appendMessage(msg));
    });

    socket.on('online_users', (users) => {
        onlineList.innerHTML = '';
        users.forEach(u => {
            const li = document.createElement('li');
            li.textContent = u.username;
            onlineList.appendChild(li);
        });
    });

    function sendMessage() {
        const content = messageInput.value.trim();
        if (content === '') return;
        socket.emit('send_message', { chatId: chatId, content: content });
        messageInput.value = '';
    }

    sendBtn.addEventListener('click', sendMessage);
    messageInput.addEventListener('keypress', (e) => {
        if (e.key === 'Enter') sendMessage();
    });

    window.addEventListener('beforeunload', () => {
        socket.emit('leave_chat', chatId);
    });

    socket.emit('load_history', { chatId: chatId });
</script>
</body>
</html>