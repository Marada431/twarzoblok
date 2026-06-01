const express = require('express');
const http = require('http');
const { Server } = require('socket.io');
const mysql = require('mysql2/promise');

// Konfiguracja połączenia z MySQL – dane takie same jak w config/database.php
const dbConfig = {
    host: 'localhost',
    user: 'root',
    password: '',
    database: 'twarzoblok',  // nazwa bazy – sprawdź, czy dokładnie taka
    waitForConnections: true,
    connectionLimit: 10,
    queueLimit: 0
};

const pool = mysql.createPool(dbConfig);

const app = express();
const server = http.createServer(app);
const io = new Server(server, {
    cors: {
        origin: "*",  // na potrzeby deweloperskie, później ogranicz do domeny
        methods: ["GET", "POST"]
    }
});

// Przechowuje informacje o zalogowanych użytkownikach w kontekście Socket.io
const onlineUsers = new Map();  // socket.id => { userId, username, chatId }

io.on('connection', async (socket) => {
    const { userId, chatId } = socket.handshake.auth;
    if (!userId) {
        socket.emit('error', 'Brak identyfikatora użytkownika');
        socket.disconnect();
        return;
    }

    // Pobranie danych użytkownika z bazy
    let user;
    try {
        const [rows] = await pool.query('SELECT user_id, username FROM users WHERE user_id = ?', [userId]);
        if (rows.length === 0) {
            socket.emit('error', 'Nieprawidłowy użytkownik');
            socket.disconnect();
            return;
        }
        user = rows[0];
    } catch (err) {
        console.error(err);
        socket.disconnect();
        return;
    }

    // Jeśli podano chatId, sprawdź czy użytkownik jest uczestnikiem
    if (chatId) {
        try {
            const [participant] = await pool.query(
                'SELECT 1 FROM chat_participants WHERE chat_id = ? AND user_id = ?',
                [chatId, userId]
            );
            if (participant.length === 0) {
                socket.emit('error', 'Nie masz dostępu do tego czatu');
                socket.disconnect();
                return;
            }
            // Dołącz do pokoju Socket.io dla tego czatu
            socket.join(`chat_${chatId}`);
            // Zapamiętaj kontekst
            onlineUsers.set(socket.id, { userId: user.user_id, username: user.username, chatId });
        } catch (err) {
            console.error(err);
            socket.disconnect();
            return;
        }
    }

    // Powiadom pozostałych w pokoju o wejściu użytkownika
    if (chatId) {
        io.to(`chat_${chatId}`).emit('user_online', {
            userId: user.user_id,
            username: user.username
        });
        // Wyślij aktualną listę online
        emitOnlineUsers(chatId);
    }

    // Obsługa pobierania historii wiadomości
    socket.on('load_history', async (data) => {
        const cid = data.chatId || chatId;
        if (!cid) return;
        try {
            const [messages] = await pool.query(
                `SELECT m.message_id, m.sender_id, u.username, m.content, m.message_type, m.sent_at
                 FROM chat_messages m
                 JOIN users u ON m.sender_id = u.user_id
                 WHERE m.chat_id = ? AND m.status = 'active'
                 ORDER BY m.sent_at ASC
                 LIMIT 50`,  // ostatnie 50 wiadomości
                [cid]
            );
            socket.emit('chat_history', messages);
        } catch (err) {
            console.error(err);
        }
    });

    // Wysyłanie nowej wiadomości
    socket.on('send_message', async (data) => {
        const cid = data.chatId || chatId;
        if (!cid || !data.content) return;

        const content = data.content.trim();
        if (content === '') return;

        try {
            // Zapis do bazy
            const [result] = await pool.query(
                `INSERT INTO chat_messages (chat_id, sender_id, content, message_type, sent_at)
                 VALUES (?, ?, ?, 'text', NOW())`,
                [cid, user.user_id, content]
            );
            const messageId = result.insertId;

            // Pobranie znacznika czasu z bazy (dla spójności)
            const [timestamps] = await pool.query(
                'SELECT sent_at FROM chat_messages WHERE message_id = ?', [messageId]
            );
            const sentAt = timestamps[0].sent_at;

            const messageData = {
                message_id: messageId,
                chat_id: cid,
                sender_id: user.user_id,
                username: user.username,
                content: content,
                message_type: 'text',
                sent_at: sentAt
            };

            // Rozsyłanie do wszystkich w pokoju
            io.to(`chat_${cid}`).emit('new_message', messageData);
        } catch (err) {
            console.error(err);
        }
    });

    // Opuszczanie czatu (gdy użytkownik zmienia pokój)
    socket.on('leave_chat', (cid) => {
        if (cid) {
            socket.leave(`chat_${cid}`);
            // Usunięcie z mapy (jeśli był przypisany do tego czatu)
            const info = onlineUsers.get(socket.id);
            if (info && info.chatId == cid) {
                onlineUsers.delete(socket.id);
                emitOnlineUsers(cid);
            }
        }
    });

    socket.on('disconnect', () => {
        const info = onlineUsers.get(socket.id);
        if (info && info.chatId) {
            io.to(`chat_${info.chatId}`).emit('user_offline', {
                userId: info.userId,
                username: info.username
            });
            emitOnlineUsers(info.chatId);
        }
        onlineUsers.delete(socket.id);
    });
});

// Pomocnicza funkcja – wysyła listę online do konkretnego pokoju
function emitOnlineUsers(chatId) {
    const usersInRoom = [];
    for (const [_, user] of onlineUsers) {
        if (user.chatId == chatId) {
            usersInRoom.push({ userId: user.userId, username: user.username });
        }
    }
    io.to(`chat_${chatId}`).emit('online_users', usersInRoom);
}

const PORT = 3000;
server.listen(PORT, () => {
    console.log(`Serwer czatu działa na porcie ${PORT}`);
});