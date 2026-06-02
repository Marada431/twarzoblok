const { Server } = require('socket.io');
const mysql = require('mysql2/promise');
const crypto = require('crypto');

const SECRET = 'bardzo_tajny_klucz_zmien_go'; // ten sam co w config/database.php
const DB_CONFIG = {
    host: 'localhost',
    user: 'root',
    password: '',
    database: 'twarzobok',
    waitForConnections: true,
    connectionLimit: 10
};

// Utworzenie puli (synchroniczne)
const pool = mysql.createPool(DB_CONFIG);

// Opcjonalna obsługa błędów puli
pool.on('error', (err) => {
    console.error('Błąd puli MySQL:', err);
});

// Socket.io
const io = new Server(3000, {
    cors: { origin: "http://localhost", methods: ["GET", "POST"] }
});

const onlineUsers = new Map();           // userId -> Set<socketId>
const userFriends = new Map();           // userId -> Set<friendId>

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
        if (parts.length !== 2) {
            console.log('🔐 Nieprawidłowy format tokena (brak kropki)');
            return false;
        }
        const payloadStr = Buffer.from(parts[0], 'base64').toString('utf8');
        const sigBuffer = Buffer.from(parts[1], 'base64');

        const expectedSig = crypto.createHmac('sha256', SECRET).update(payloadStr).digest();
        if (!crypto.timingSafeEqual(sigBuffer, expectedSig)) {
            console.log('🔐 Niezgodność podpisu');
            return false;
        }
        const data = JSON.parse(payloadStr);
        console.log('🕒 Token ważny do:', new Date(data.exp * 1000), 'teraz:', new Date());
        return (Date.now() / 1000 < data.exp) ? data : false;
    } catch (e) {
        console.error('❌ Błąd parsowania tokena:', e);
        return false;
    }
}

async function saveMessage(chatId, senderId, content, type) {
    await pool.execute(
        'INSERT INTO chat_messages (chat_id, sender_id, content, message_type) VALUES (?, ?, ?, ?)',
        [chatId, senderId, content, type]
    );
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
    for (let fid of friends) {
        io.to(`user:${fid}`).emit('user_online', { user_id: userId, username });
    }
    // Wyślij nowemu listę online znajomych
    for (let fid of friends) {
        if (onlineUsers.has(fid) && onlineUsers.get(fid).size > 0) {
            socket.emit('user_online', { user_id: fid });
        }
    }

    socket.on('join_chat', (data) => {
        socket.join(`chat:${data.chat_id}`);
    });

    socket.on('send_message', async (data) => {
        const { chat_id, content, message_type = 'text' } = data;
        if (!chat_id || !content.trim()) return;

        const [rows] = await pool.execute(
            'SELECT 1 FROM chat_participants WHERE chat_id = ? AND user_id = ?',
            [chat_id, userId]
        );
        if (rows.length === 0) {
            socket.emit('error', 'Nie należysz do czatu');
            return;
        }

        try {
            await saveMessage(chat_id, userId, content, message_type);
            io.to(`chat:${chat_id}`).emit('new_message', {
                chat_id,
                user_id: userId,
                username,
                content,
                sent_at: new Date().toISOString()
            });
        } catch (err) {
            console.error(err);
            socket.emit('error', 'Błąd wysyłania');
        }
    });

    socket.on('disconnect', () => {
        const userSockets = onlineUsers.get(userId);
        if (userSockets) {
            userSockets.delete(socket.id);
            if (userSockets.size === 0) {
                onlineUsers.delete(userId);
                for (let fid of friends) {
                    io.to(`user:${fid}`).emit('user_offline', { user_id: userId });
                }
            }
        }
    });
});

console.log('Serwer Socket.io działa na porcie 3000');