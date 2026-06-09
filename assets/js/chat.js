/* chat.js – logika czatu TwarzBlok
 * Wymaga globalnego APP_CONFIG = { token, userId } ustawionego w PHP przed załadowaniem tego pliku.
 * Wymaga załadowanego socket.io.
 */

const ChatApp = {
    token:           APP_CONFIG.token,
    userId:          APP_CONFIG.userId,
    socket:          null,
    currentChatId:   null,
    currentFriendId: null,
    oldestMsgId:     null,
    selectedFile:    null,
    lastMsgDateKey:  null,
    currentReadMsgId: null,
    pollInterval:    null,

    // ── Inicjalizacja ────────────────────────────────────────
    init() {
        this.socket = io('http://localhost:3000', { auth: { token: this.token } });
        this.socket.on('connect',       () => console.log('✅ Socket.io ok'));
        this.socket.on('connect_error', e  => console.error('❌ Socket:', e.message));
        this.socket.on('error',         m  => console.error('❌ Server:', m));

        this.socket.on('new_message',  msg => this.onNewMessage(msg));
        this.socket.on('user_online',  d   => this.setUserOnline(d.user_id, true));
        this.socket.on('user_offline', d   => this.setUserOnline(d.user_id, false));

        this.bindEvents();
        this.refreshUnreadBadges();
        setInterval(() => this.updatePendingFriendsCount(), 15000);
    },

    // ── Wiązanie zdarzeń ─────────────────────────────────────
    bindEvents() {
        document.querySelectorAll('.conv-item').forEach(item => {
            item.addEventListener('click', () => {
                this.openChat(item.dataset.uid, item.dataset.name,
                              item.dataset.avatar, item.dataset.initials);
            });
        });

        document.getElementById('sendBtn').addEventListener('click', () => this.sendMessage());
        document.getElementById('messageInput').addEventListener('keypress', e => {
            if (e.key === 'Enter' && !e.shiftKey) { e.preventDefault(); this.sendMessage(); }
        });

        document.getElementById('attachBtn').addEventListener('click', () => {
            document.getElementById('fileInput').click();
        });
        document.getElementById('fileInput').addEventListener('change', e => this.onFileChange(e));
        document.getElementById('removeImgBtn').addEventListener('click', () => this.clearImagePreview());

        document.getElementById('lightboxClose').addEventListener('click', () => this.closeLightbox());
        document.getElementById('lightbox').addEventListener('click', e => {
            if (e.target === e.currentTarget) this.closeLightbox();
        });

        document.addEventListener('keydown', e => { if (e.key === 'Escape') this.closeLightbox(); });

        document.getElementById('mobileBack').addEventListener('click', () => {
            document.getElementById('chatSidebar').classList.remove('mob-hidden');
            document.getElementById('chatMain').classList.remove('mob-visible');
            if (this.currentChatId) this.socket.emit('leave_chat', { chat_id: this.currentChatId });
            this.stopPolling();
            this.currentChatId   = null;
            this.currentFriendId = null;
            document.getElementById('chatWindow').style.display = 'none';
            document.getElementById('chatEmpty').style.display  = 'flex';
            document.querySelectorAll('.conv-item').forEach(el => el.classList.remove('active'));
        });

        document.getElementById('searchInput').addEventListener('input', function () {
            const q = this.value.toLowerCase();
            document.querySelectorAll('.conv-item').forEach(el => {
                const match = el.dataset.name.toLowerCase().includes(q) ||
                              el.dataset.username.toLowerCase().includes(q);
                el.style.display = match ? 'flex' : 'none';
            });
        });
    },

    // ── Otwarcie czatu ───────────────────────────────────────
    async openChat(friendId, friendName, avatarUrl, initials) {
        if (this.currentFriendId === friendId) return;

        this.stopPolling();
        this.currentFriendId  = friendId;
        this.lastMsgDateKey   = null;
        this.oldestMsgId      = null;
        this.currentReadMsgId = null;

        document.querySelectorAll('.conv-item').forEach(el => el.classList.remove('active'));
        const item = document.querySelector(`.conv-item[data-uid="${friendId}"]`);
        if (item) item.classList.add('active');

        document.getElementById('chatSidebar').classList.add('mob-hidden');
        document.getElementById('chatMain').classList.add('mob-visible');
        document.getElementById('chatEmpty').style.display  = 'none';
        document.getElementById('chatWindow').style.display = 'flex';

        const avEl = document.getElementById('chatHeaderAv');
        if (avatarUrl) {
            avEl.innerHTML = `<img src="${esc(avatarUrl)}" alt=""
                style="width:40px;height:40px;border-radius:50%;object-fit:cover;display:block;">`;
        } else {
            avEl.textContent = initials;
            avEl.style.background = 'var(--border-color)';
        }
        document.getElementById('chatHeaderName').textContent = friendName;
        this.setStatus('Offline', false);

        const msgsDiv = document.getElementById('messages');
        msgsDiv.innerHTML =
            '<div class="load-more-wrap" id="loadMoreWrap" style="display:none;">' +
            '<button class="btn-load-more" id="loadMoreBtn">Załaduj wcześniejsze wiadomości</button></div>';
        document.getElementById('loadMoreBtn').addEventListener('click', () => this.loadMore());

        try {
            const res  = await fetch(`get_or_create_chat.php?friend_id=${friendId}`);
            const data = await res.json();
            if (data.error) { showToast('Błąd: ' + data.error, 'error'); return; }

            this.currentChatId = data.chat_id;
            this.socket.emit('join_chat', { chat_id: this.currentChatId });
            await this.loadHistory();
            this.markAsRead(this.currentChatId, friendId);
            this.startPolling();
        } catch (err) {
            console.error('openChat error:', err);
        }
    },

    // ── Historia wiadomości ──────────────────────────────────
    async loadHistory() {
        try {
            const res  = await fetch(`get_history.php?chat_id=${this.currentChatId}`);
            const data = await res.json();
            if (!data.messages) return;

            data.messages.forEach(msg => {
                this.appendMessage(msg, msg.sender_id == this.userId ? 'sent' : 'received', false);
            });
            if (data.messages.length > 0) {
                this.oldestMsgId = data.messages[0].message_id;
            }
            document.getElementById('loadMoreWrap').style.display = data.has_more ? 'block' : 'none';
            this.scrollToBottom();
        } catch (err) {
            console.error('loadHistory error:', err);
        }
    },

    // ── Starsze wiadomości ───────────────────────────────────
    async loadMore() {
        if (!this.oldestMsgId) return;
        try {
            const scrollEl = document.getElementById('messages');
            const prevH    = scrollEl.scrollHeight;

            const res  = await fetch(`get_history.php?chat_id=${this.currentChatId}&before_id=${this.oldestMsgId}`);
            const data = await res.json();
            if (!data.messages || !data.messages.length) {
                document.getElementById('loadMoreWrap').style.display = 'none';
                return;
            }

            const anchor = document.getElementById('loadMoreWrap');
            for (let i = data.messages.length - 1; i >= 0; i--) {
                const msg = data.messages[i];
                this.prependMessage(msg, msg.sender_id == this.userId ? 'sent' : 'received', anchor.nextSibling);
            }
            this.oldestMsgId = data.messages[0].message_id;
            document.getElementById('loadMoreWrap').style.display = data.has_more ? 'block' : 'none';
            scrollEl.scrollTop += scrollEl.scrollHeight - prevH;
        } catch (err) {
            console.error('loadMore error:', err);
        }
    },

    // ── Polling ──────────────────────────────────────────────
    startPolling() {
        this.stopPolling();
        this.pollInterval = setInterval(async () => {
            if (!this.currentChatId || !this.currentFriendId) return;
            try {
                const res  = await fetch(
                    `get_read_status.php?chat_id=${this.currentChatId}&friend_id=${this.currentFriendId}`
                );
                const data = await res.json();
                if (!data.error) {
                    this.updateReadReceipt(data.last_read_id, data.friend_avatar, data.read_at);
                }
            } catch (e) {}
            this.refreshUnreadBadges();
        }, 3000);
    },

    stopPolling() {
        if (this.pollInterval) { clearInterval(this.pollInterval); this.pollInterval = null; }
    },

    // ── Badges nieprzeczytanych ──────────────────────────────
    async refreshUnreadBadges() {
        try {
            const res    = await fetch('get_unread_counts.php');
            const counts = await res.json();
            this.updateUnreadBadges(counts);
        } catch (e) {}
    },

    updateUnreadBadges(counts) {
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
    },

    // ── Oznacz jako przeczytane ──────────────────────────────
    markAsRead(chatId, friendId) {
        fetch('mark_read.php', {
            method:  'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body:    `chat_id=${chatId}`
        }).then(() => {
            const item = document.querySelector(`.conv-item[data-uid="${friendId}"]`);
            if (!item) return;
            const badge  = item.querySelector('.unread-badge');
            const nameEl = item.querySelector('.conv-name');
            const lastEl = item.querySelector('.conv-last');
            if (badge)  { badge.textContent = ''; badge.classList.remove('visible'); }
            if (nameEl) nameEl.classList.remove('has-unread');
            if (lastEl) lastEl.classList.remove('has-unread');
        }).catch(() => {});
    },

    // ── Read receipt ─────────────────────────────────────────
    updateReadReceipt(lastReadId, friendAvatar, readAt) {
        if (!lastReadId) return;
        if (lastReadId === this.currentReadMsgId) return;

        if (this.currentReadMsgId) {
            const old = document.querySelector(`.msg-wrap.sent[data-msg-id="${this.currentReadMsgId}"]`);
            if (old) { const ov = old.querySelector('.read-receipt-av'); if (ov) ov.classList.remove('visible'); }
        }

        const wrap = document.querySelector(`.msg-wrap.sent[data-msg-id="${lastReadId}"]`);
        if (!wrap) return;
        const rcAv = wrap.querySelector('.read-receipt-av');
        if (!rcAv) return;

        if (friendAvatar) {
            rcAv.src = friendAvatar;
        } else {
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
        this.currentReadMsgId = lastReadId;
    },

    // ── Budowanie wiadomości DOM ─────────────────────────────
    buildMsgWrap(msg, type) {
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
            img.addEventListener('click', () => this.openLightbox(msg.attachment_url));
            wrap.appendChild(img);
        }

        const meta   = document.createElement('div');
        meta.className = 'msg-meta';
        const timeEl = document.createElement('span');
        timeEl.className = 'msg-time';
        timeEl.textContent = fmtTime(sentAt);
        meta.appendChild(timeEl);

        if (type === 'sent' && msg.message_id) {
            const rcAv = document.createElement('img');
            rcAv.className = 'read-receipt-av';
            rcAv.alt = 'Odczytano';
            meta.appendChild(rcAv);
        }
        wrap.appendChild(meta);
        return { wrap, dateK: dateKey(sentAt), dateLabel: fmtDate(sentAt) };
    },

    appendMessage(msg, type, scroll = true) {
        const msgsDiv = document.getElementById('messages');
        const { wrap, dateK, dateLabel } = this.buildMsgWrap(msg, type);
        if (dateK !== this.lastMsgDateKey) {
            this.lastMsgDateKey = dateK;
            const sep = document.createElement('div');
            sep.className = 'date-sep';
            sep.textContent = dateLabel;
            msgsDiv.appendChild(sep);
        }
        msgsDiv.appendChild(wrap);
        if (scroll) this.scrollToBottom();
    },

    prependMessage(msg, type, beforeEl) {
        const msgsDiv = document.getElementById('messages');
        const { wrap, dateLabel } = this.buildMsgWrap(msg, type);
        const sep = document.createElement('div');
        sep.className = 'date-sep';
        sep.textContent = dateLabel;
        msgsDiv.insertBefore(sep, beforeEl);
        msgsDiv.insertBefore(wrap, sep.nextSibling);
    },

    scrollToBottom() {
        const m = document.getElementById('messages');
        m.scrollTop = m.scrollHeight;
    },

    // ── Wysyłanie ────────────────────────────────────────────
    async sendMessage() {
        if (!this.currentChatId) return;
        const input   = document.getElementById('messageInput');
        const content = input.value.trim();
        if (!content && !this.selectedFile) return;

        let attachmentUrl = null;
        if (this.selectedFile) {
            try {
                const fd = new FormData();
                fd.append('image', this.selectedFile);
                const res  = await fetch('upload_chat_image.php', {
                    method: 'POST',
                    headers: { 'X-CSRF-Token': APP_CONFIG.csrfToken },
                    body: fd
                });
                const data = await res.json();
                if (data.error) { showToast('Błąd uploadu: ' + data.error, 'error'); return; }
                attachmentUrl = data.url;
            } catch {
                showToast('Błąd przesyłania zdjęcia.', 'error');
                return;
            }
        }

        this.socket.emit('send_message', {
            chat_id:        this.currentChatId,
            content:        content,
            message_type:   attachmentUrl ? 'image' : 'text',
            attachment_url: attachmentUrl
        });
        input.value = '';
        this.clearImagePreview();
    },

    // ── Nowa wiadomość z Socket.io ───────────────────────────
    onNewMessage(msg) {
        if (msg.chat_id === this.currentChatId) {
            const type = msg.user_id == this.userId ? 'sent' : 'received';
            this.appendMessage(msg, type);
            if (msg.user_id != this.userId) {
                this.markAsRead(this.currentChatId, this.currentFriendId);
            }
        }
        const targetFriendId = msg.user_id == this.userId ? this.currentFriendId : msg.user_id;
        if (targetFriendId) {
            const lastEl = document.getElementById(`last-msg-${targetFriendId}`);
            if (lastEl) {
                lastEl.textContent = msg.attachment_url ? '📷 Zdjęcie' : (msg.content || '').substring(0, 38);
            }
        }
    },

    // ── Status online ────────────────────────────────────────
    setUserOnline(uid, online) {
        document.querySelectorAll(`.av-dot[data-uid="${uid}"]`).forEach(dot => {
            dot.classList.toggle('online', online);
        });
        if (uid == this.currentFriendId) this.setStatus(online ? 'Online' : 'Offline', online);
    },

    setStatus(text, online) {
        const el = document.getElementById('chatHeaderStatus');
        if (!el) return;
        el.textContent = online ? '● ' + text : text;
        el.className   = 'chat-header-status' + (online ? ' is-online' : '');
    },

    // ── Plik ─────────────────────────────────────────────────
    onFileChange(e) {
        const file    = e.target.files[0];
        if (!file) return;
        const allowed = ['image/jpeg', 'image/png', 'image/webp', 'image/gif'];
        if (!allowed.includes(file.type)) { showToast('Dozwolone: JPG, PNG, WebP, GIF', 'error'); return; }
        if (file.size > 10 * 1024 * 1024) { showToast('Plik za duży (max 10 MB)', 'error'); return; }
        this.selectedFile = file;
        const reader = new FileReader();
        reader.onload = ev => {
            document.getElementById('previewImg').src = ev.target.result;
            document.getElementById('imgPreviewBar').style.display = 'flex';
        };
        reader.readAsDataURL(file);
        e.target.value = '';
    },

    clearImagePreview() {
        this.selectedFile = null;
        document.getElementById('imgPreviewBar').style.display = 'none';
        document.getElementById('previewImg').src = '';
    },

    // ── Lightbox ─────────────────────────────────────────────
    openLightbox(src) {
        document.getElementById('lightboxImg').src = src;
        document.getElementById('lightbox').classList.add('active');
    },

    closeLightbox() {
        document.getElementById('lightbox').classList.remove('active');
        document.getElementById('lightboxImg').src = '';
    },

    // ── Licznik zaproszeń ────────────────────────────────────
    updatePendingFriendsCount() {
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
            badge.textContent   = data.count;
            badge.style.display = data.count > 0 ? 'flex' : 'none';
        })
        .catch(() => {});
    },
};

/* ── Formatowanie (moduł-poziom) ─────────────────────────── */
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

document.addEventListener('DOMContentLoaded', () => ChatApp.init());
