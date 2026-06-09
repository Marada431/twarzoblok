const { Server } = require('socket.io');
const mysql = require('mysql2/promise');
const crypto = require('crypto');

// Uruchamiaj: node --env-file=../.env server.js  (Node.js >= 20.6)
const SECRET = process.env.SOCKET_SECRET;
if (!SECRET) {
    console.error('Brak SOCKET_SECRET w zmiennych środowiskowych. Ustaw .env lub przekaż --env-file.');
    process.exit(1);
}

const DB_CONFIG = {
    host:             process.env.DB_HOST     || 'localhost',
    user:             process.env.DB_USER     || 'root',
    password:         process.env.DB_PASS     || '',
    database:         process.env.DB_NAME     || '',
    waitForConnections: true,
    connectionLimit:  10,
};

const pool = mysql.createPool(DB_CONFIG);

pool.on('error', (err) => {
    console.error('Błąd puli MySQL:', err);
});

const io = new Server(3000, {
    cors: { origin: '*', methods: ['GET', 'POST'] },
    maxHttpBufferSize: 1e7 // 10 MB (dla zdjęć w wiadomościach)
});

const onlineUsers = new Map(); // userId -> Set<socketId>
const userFriends = new Map(); // userId -> Set<friendId>

async function loadFriends(userId) {
    const [rows] = await pool.execute(
        `SELECT u.user_id FROM friendships f
         JOIN users u ON (f.requester_id = u.user_id OR f.addressee_id = u.user_id)
         WHERE f.status = 'accepted'
           AND ((f.requester_id = ? AND u.user_id = f.addressee_id)
             OR (f.addressee_id = ? AND u.user_id = f.requester_id))
           AND u.user_id != ?
         GROUP BY u.user_id`,
        [userId, userId, userId]
    );
    return new Set(rows.map(r => r.user_id));
}

function verifyToken(token) {
    try {
        const parts = token.split('.');
        if (parts.length !== 2) return false;

        const payloadStr = Buffer.from(parts[0], 'base64').toString('utf8');
        const sigBuffer = Buffer.from(parts[1], 'base64');
        const expectedSig = crypto.createHmac('sha256', SECRET).update(payloadStr).digest();

        if (!crypto.timingSafeEqual(sigBuffer, expectedSig)) return false;

        const data = JSON.parse(payloadStr);
        return (Date.now() / 1000 < data.exp) ? data : false;
    } catch (e) {
        console.error('Błąd parsowania tokena:', e);
        return false;
    }
}

async function saveMessage(chatId, senderId, content, type, attachmentUrl) {
    const [result] = await pool.execute(
        'INSERT INTO chat_messages (chat_id, sender_id, content, message_type, attachment_url) VALUES (?, ?, ?, ?, ?)',
        [chatId, senderId, content || '', type, attachmentUrl || null]
    );
    return result.insertId;
}

/**
 * Tworzy powiadomienie typu 'new_message' dla każdego odbiorcy w czacie.
 * Tabela notifications: user_id, type varchar(50), created_at, status enum('read','unread')
 * Brak kolumn sender_id / reference_id — pracujemy na istniejącej strukturze.
 */
async function createNotifications(chatId, senderId, content, attachmentUrl) {
    // Pobierz wszystkich uczestników czatu oprócz nadawcy
    const [participants] = await pool.execute(
        'SELECT user_id FROM chat_participants WHERE chat_id = ? AND user_id != ?',
        [chatId, senderId]
    );

    if (!participants.length) return;

    for (const p of participants) {
        const recipientId = p.user_id;

        // Anti-duplicate: pomijamy jeśli w ciągu 30 s istnieje już nieprzeczytane
        // powiadomienie 'new_message' dla tego odbiorcy
        const [existing] = await pool.execute(
            `SELECT COUNT(*) AS cnt
             FROM notifications
             WHERE user_id = ?
               AND type = 'new_message'
               AND status = 'unread'
               AND created_at > DATE_SUB(NOW(), INTERVAL 30 SECOND)`,
            [recipientId]
        );

        if (existing[0].cnt > 0) continue;

        await pool.execute(
            "INSERT INTO notifications (user_id, type, status) VALUES (?, 'new_message', 'unread')",
            [recipientId]
        );
    }
}

io.on('connection', async (socket) => {
    const user = verifyToken(socket.handshake.auth.token);
    if (!user) {
        socket.emit('error', 'Nieprawidłowy token');
        socket.disconnect();
        return;
    }

    const userId = user.user_id;
    const username = user.username;

    if (!onlineUsers.has(userId)) onlineUsers.set(userId, new Set());
    onlineUsers.get(userId).add(socket.id);

    socket.join(`user:${userId}`);

    if (!userFriends.has(userId)) {
        userFriends.set(userId, await loadFriends(userId));
    }
    const friends = userFriends.get(userId);

    // Powiadom znajomych o online
    for (const fid of friends) {
        io.to(`user:${fid}`).emit('user_online', { user_id: userId, username });
    }
    // Wyślij nowemu użytkownikowi listę online znajomych
    for (const fid of friends) {
        if (onlineUsers.has(fid) && onlineUsers.get(fid).size > 0) {
            socket.emit('user_online', { user_id: fid });
        }
    }

    socket.on('join_chat', (data) => {
        socket.join(`chat:${data.chat_id}`);
    });

    socket.on('leave_chat', (data) => {
        socket.leave(`chat:${data.chat_id}`);
    });

    socket.on('send_message', async (data) => {
        const { chat_id, content = '', message_type = 'text', attachment_url = null } = data;

        // Wymaga albo treści tekstowej, albo załącznika
        if (!chat_id || (!content.trim() && !attachment_url)) return;

        try {
            // Weryfikacja uczestnictwa w czacie
            const [rows] = await pool.execute(
                'SELECT 1 FROM chat_participants WHERE chat_id = ? AND user_id = ?',
                [chat_id, userId]
            );
            if (rows.length === 0) {
                socket.emit('error', 'Nie należysz do tego czatu');
                return;
            }

            const msgId = await saveMessage(chat_id, userId, content, message_type, attachment_url);

            // Zapisz powiadomienie dla każdego odbiorcy (poza nadawcą)
            createNotifications(chat_id, userId, content, attachment_url).catch(err => {
                console.error('Błąd createNotifications:', err);
            });

            io.to(`chat:${chat_id}`).emit('new_message', {
                message_id:     msgId,
                chat_id:        chat_id,
                user_id:        userId,
                username:       username,
                content:        content,
                attachment_url: attachment_url,
                message_type:   message_type,
                sent_at:        new Date().toISOString()
            });
        } catch (err) {
            console.error('Błąd send_message:', err);
            socket.emit('error', 'Błąd wysyłania wiadomości');
        }
    });

    socket.on('disconnect', () => {
        const userSockets = onlineUsers.get(userId);
        if (userSockets) {
            userSockets.delete(socket.id);
            if (userSockets.size === 0) {
                onlineUsers.delete(userId);
                for (const fid of friends) {
                    io.to(`user:${fid}`).emit('user_offline', { user_id: userId });
                }
            }
        }
    });
});

console.log('✅ Serwer Socket.io działa na porcie 3000');
