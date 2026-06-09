/* chat.js – logika czatu TwarzBlok
 * Wymaga globalnego APP_CONFIG = { token, userId, csrfToken } ustawionego w PHP przed załadowaniem tego pliku.
 * Wymaga załadowanego socket.io.
 */

const ChatApp = {
    token:           APP_CONFIG.token,
    userId:          APP_CONFIG.userId,
    socket:          null,
    currentChatId:   null,
    currentFriendId: null,
    currentChatType: null,   // 'private' | 'group'
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
                if (item.dataset.type === 'group') {
                    this.openGroupChat(
                        item.dataset.chatId,
                        item.dataset.name,
                        item.dataset.avatar,
                        item.dataset.initials,
                        parseInt(item.dataset.memberCount) || 0
                    );
                } else {
                    this.openChat(item.dataset.uid, item.dataset.name,
                                  item.dataset.avatar, item.dataset.initials);
                }
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
            this.currentChatType = null;
            document.getElementById('groupMembersPanel').classList.remove('open');
            document.getElementById('chatWindow').style.display = 'none';
            document.getElementById('chatEmpty').style.display  = 'flex';
            document.querySelectorAll('.conv-item').forEach(el => el.classList.remove('active'));
        });

        document.getElementById('searchInput').addEventListener('input', function () {
            const q = this.value.toLowerCase();
            document.querySelectorAll('.conv-item').forEach(el => {
                const match = el.dataset.name.toLowerCase().includes(q) ||
                              (el.dataset.username || '').toLowerCase().includes(q);
                el.style.display = match ? 'flex' : 'none';
            });
            // Ukryj etykietę sekcji jeśli wszystkie grupy są ukryte
            const label = document.querySelector('.conv-section-label');
            if (label) {
                const anyGroupVisible = [...document.querySelectorAll('.conv-item[data-type="group"]')]
                    .some(el => el.style.display !== 'none');
                label.style.display = anyGroupVisible ? '' : 'none';
            }
        });

        // ── Nowa Grupa ──
        const newGroupBtn = document.getElementById('newGroupBtn');
        if (newGroupBtn) {
            newGroupBtn.addEventListener('click', () => this.openGroupModal());
        }
        document.getElementById('groupModalClose').addEventListener('click',  () => this.closeGroupModal());
        document.getElementById('groupModalCancelBtn').addEventListener('click', () => this.closeGroupModal());
        document.getElementById('groupModalCreateBtn').addEventListener('click', () => this.submitCreateGroup());
        document.getElementById('groupModalOverlay').addEventListener('click', e => {
            if (e.target === e.currentTarget) this.closeGroupModal();
        });

        // ── Panel uczestników ──
        document.getElementById('groupMembersPanelClose').addEventListener('click', () => {
            document.getElementById('groupMembersPanel').classList.remove('open');
        });
    },

    // ── Otwarcie czatu prywatnego ────────────────────────────
    async openChat(friendId, friendName, avatarUrl, initials) {
        if (this.currentFriendId === friendId && this.currentChatType === 'private') return;

        this.stopPolling();
        this.currentFriendId  = friendId;
        this.currentChatType  = 'private';
        this.lastMsgDateKey   = null;
        this.oldestMsgId      = null;
        this.currentReadMsgId = null;

        document.getElementById('groupMembersPanel').classList.remove('open');
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
        document.getElementById('chatHeaderStatus').style.cursor = '';
        document.getElementById('chatHeaderStatus').onclick = null;
        this.setStatus('Offline', false);

        const msgsDiv = document.getElementById('messages');
        msgsDiv.innerHTML =
            '<div class="load-more-wrap" id="loadMoreWrap" style="display:none;">' +
            '<button class="btn-load-more" id="loadMoreBtn">Załaduj wcześniejsze wiadomości</button></div>';
        document.getElementById('loadMoreBtn').addEventListener('click', () => this.loadMore());

        if (this.currentChatId) this.socket.emit('leave_chat', { chat_id: this.currentChatId });

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

    // ── Otwarcie czatu grupowego ─────────────────────────────
    async openGroupChat(chatId, groupName, avatarUrl, initials, memberCount) {
        chatId = parseInt(chatId);
        if (this.currentChatId === chatId && this.currentChatType === 'group') return;

        this.stopPolling();
        this.currentChatId   = chatId;
        this.currentFriendId = null;
        this.currentChatType = 'group';
        this.lastMsgDateKey  = null;
        this.oldestMsgId     = null;
        this.currentReadMsgId = null;

        document.getElementById('groupMembersPanel').classList.remove('open');
        document.querySelectorAll('.conv-item').forEach(el => el.classList.remove('active'));
        const item = document.querySelector(`.conv-item[data-chat-id="${chatId}"]`);
        if (item) item.classList.add('active');

        document.getElementById('chatSidebar').classList.add('mob-hidden');
        document.getElementById('chatMain').classList.add('mob-visible');
        document.getElementById('chatEmpty').style.display  = 'none';
        document.getElementById('chatWindow').style.display = 'flex';

        const avEl = document.getElementById('chatHeaderAv');
        if (avatarUrl) {
            avEl.innerHTML = `<img src="${esc(avatarUrl)}" alt=""
                style="width:40px;height:40px;border-radius:50%;object-fit:cover;display:block;">`;
            avEl.style.background = '';
        } else {
            avEl.textContent  = initials;
            avEl.style.background = 'var(--primary-color)';
            avEl.style.color  = '#fff';
        }
        document.getElementById('chatHeaderName').textContent = groupName;

        const statusEl = document.getElementById('chatHeaderStatus');
        statusEl.textContent = `${memberCount} uczestników – kliknij, aby zobaczyć`;
        statusEl.className   = 'chat-header-status group-header-members';
        statusEl.onclick     = () => this.toggleMembersPanel(chatId);

        const msgsDiv = document.getElementById('messages');
        msgsDiv.innerHTML =
            '<div class="load-more-wrap" id="loadMoreWrap" style="display:none;">' +
            '<button class="btn-load-more" id="loadMoreBtn">Załaduj wcześniejsze wiadomości</button></div>';
        document.getElementById('loadMoreBtn').addEventListener('click', () => this.loadMore());

        this.socket.emit('join_chat', { chat_id: chatId });

        try {
            await this.loadHistory();
            this.markGroupAsRead(chatId);
            this.startPolling();
        } catch (err) {
            console.error('openGroupChat error:', err);
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
            if (!this.currentChatId) return;

            // Read receipt tylko dla prywatnych czatów
            if (this.currentChatType === 'private' && this.currentFriendId) {
                try {
                    const res  = await fetch(
                        `get_read_status.php?chat_id=${this.currentChatId}&friend_id=${this.currentFriendId}`
                    );
                    const data = await res.json();
                    if (!data.error) {
                        this.updateReadReceipt(data.last_read_id, data.friend_avatar, data.read_at);
                    }
                } catch (e) {}
            }
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
        try {
            const res    = await fetch('get_group_unread_counts.php');
            const counts = await res.json();
            this.updateGroupUnreadBadges(counts);
        } catch (e) {}
    },

    updateUnreadBadges(counts) {
        document.querySelectorAll('.conv-item[data-uid]').forEach(item => {
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

    updateGroupUnreadBadges(counts) {
        document.querySelectorAll('.conv-item[data-type="group"]').forEach(item => {
            const chatId = item.dataset.chatId;
            const count  = counts[chatId] || 0;
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

    // ── Oznacz jako przeczytane (prywatne) ───────────────────
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

    // ── Oznacz jako przeczytane (grupowe) ────────────────────
    markGroupAsRead(chatId) {
        fetch('mark_read.php', {
            method:  'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body:    `chat_id=${chatId}`
        }).then(() => {
            const item = document.querySelector(`.conv-item[data-chat-id="${chatId}"]`);
            if (!item) return;
            const badge  = item.querySelector('.unread-badge');
            const nameEl = item.querySelector('.conv-name');
            const lastEl = item.querySelector('.conv-last');
            if (badge)  { badge.textContent = ''; badge.classList.remove('visible'); }
            if (nameEl) nameEl.classList.remove('has-unread');
            if (lastEl) lastEl.classList.remove('has-unread');
        }).catch(() => {});
    },

    // ── Read receipt (tylko prywatne) ────────────────────────
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

        // Nazwa nadawcy w czacie grupowym (tylko dla otrzymanych)
        if (type === 'received' && this.currentChatType === 'group' && msg.username) {
            const senderEl = document.createElement('div');
            senderEl.className   = 'msg-sender-name';
            senderEl.textContent = msg.username;
            wrap.appendChild(senderEl);
        }

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

        // Read receipt tylko w prywatnych czatach
        if (type === 'sent' && msg.message_id && this.currentChatType !== 'group') {
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
                if (this.currentChatType === 'group') {
                    this.markGroupAsRead(this.currentChatId);
                } else {
                    this.markAsRead(this.currentChatId, this.currentFriendId);
                }
            }
        }

        // Aktualizuj podgląd ostatniej wiadomości w sidebarze
        if (this.currentChatType === 'group') {
            const lastEl = document.getElementById(`last-msg-group-${msg.chat_id}`);
            if (lastEl) {
                lastEl.textContent = msg.attachment_url ? '📷 Zdjęcie' : (msg.content || '').substring(0, 38);
            }
        } else {
            const targetFriendId = msg.user_id == this.userId ? this.currentFriendId : msg.user_id;
            if (targetFriendId) {
                const lastEl = document.getElementById(`last-msg-${targetFriendId}`);
                if (lastEl) {
                    lastEl.textContent = msg.attachment_url ? '📷 Zdjęcie' : (msg.content || '').substring(0, 38);
                }
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

    // ── Panel uczestników grupy ──────────────────────────────
    async toggleMembersPanel(chatId) {
        const panel = document.getElementById('groupMembersPanel');
        if (panel.classList.contains('open')) {
            panel.classList.remove('open');
            return;
        }
        try {
            const res     = await fetch(`group_members_api.php?action=members&chat_id=${chatId}`);
            const members = await res.json();
            if (!Array.isArray(members)) { showToast('Błąd pobierania uczestników', 'error'); return; }

            const list = document.getElementById('groupMembersList');
            list.innerHTML = '';
            members.forEach(m => {
                const div = document.createElement('div');
                div.className = 'group-member-item';
                const initials = ((m.first_name || '').charAt(0) + (m.last_name || '').charAt(0)).toUpperCase();
                div.innerHTML = `
                    <div class="av-wrap" style="width:36px;height:36px;flex-shrink:0">
                        ${m.avatar_url
                            ? `<img src="${esc(m.avatar_url)}" class="av-img" style="width:36px;height:36px" alt="">`
                            : `<div class="av-placeholder" style="width:36px;height:36px;font-size:13px">${esc(initials)}</div>`}
                    </div>
                    <div>
                        <div class="group-member-name">${esc(m.first_name + ' ' + m.last_name)}</div>
                        <div class="group-member-role">${m.role === 'admin' ? 'Administrator' : 'Uczestnik'}</div>
                    </div>`;
                list.appendChild(div);
            });
            panel.classList.add('open');
        } catch (err) {
            console.error('toggleMembersPanel error:', err);
        }
    },

    // ── Modal nowej grupy ────────────────────────────────────
    openGroupModal() {
        document.getElementById('groupNameInput').value = '';
        document.getElementById('groupDescInput').value = '';
        document.querySelectorAll('.group-member-cb').forEach(cb => { cb.checked = false; });
        const overlay = document.getElementById('groupModalOverlay');
        overlay.style.display = 'flex';
    },

    closeGroupModal() {
        document.getElementById('groupModalOverlay').style.display = 'none';
    },

    async submitCreateGroup() {
        const name = document.getElementById('groupNameInput').value.trim();
        if (!name) { showToast('Podaj nazwę grupy', 'error'); return; }

        const selectedIds = [...document.querySelectorAll('.group-member-cb:checked')]
            .map(cb => cb.value);
        if (selectedIds.length === 0) {
            showToast('Wybierz co najmniej jedną osobę', 'error');
            return;
        }

        const description = document.getElementById('groupDescInput').value.trim();
        const btn = document.getElementById('groupModalCreateBtn');
        btn.disabled = true;
        btn.textContent = 'Tworzę…';

        try {
            const formData = new URLSearchParams();
            formData.append('name', name);
            formData.append('description', description);
            formData.append('csrf_token', APP_CONFIG.csrfToken);
            selectedIds.forEach(id => formData.append('member_ids[]', id));

            const res  = await fetch('create_group_chat.php', {
                method:  'POST',
                headers: {
                    'Content-Type':  'application/x-www-form-urlencoded',
                    'X-CSRF-Token':  APP_CONFIG.csrfToken,
                },
                body: formData.toString(),
            });
            const data = await res.json();

            if (data.error) {
                showToast('Błąd: ' + data.error, 'error');
                return;
            }

            this.closeGroupModal();
            showToast(`Grupa „${data.name}" została utworzona`, 'success');
            this.addGroupToSidebar(data.chat_id, data.name, selectedIds.length + 1);
            this.openGroupChat(data.chat_id, data.name, '', name.substring(0, 2).toUpperCase(), selectedIds.length + 1);
        } catch (err) {
            console.error('submitCreateGroup error:', err);
            showToast('Błąd tworzenia grupy', 'error');
        } finally {
            btn.disabled = false;
            btn.textContent = 'Utwórz grupę';
        }
    },

    addGroupToSidebar(chatId, name, memberCount) {
        const initials = name.substring(0, 2).toUpperCase();
        const convsList = document.getElementById('convsList');

        // Dodaj etykietę sekcji jeśli jej nie ma
        if (!convsList.querySelector('.conv-section-label')) {
            const label = document.createElement('div');
            label.className = 'conv-section-label';
            label.textContent = 'Grupy';
            convsList.appendChild(label);
        }

        const div = document.createElement('div');
        div.className = 'conv-item';
        div.dataset.type        = 'group';
        div.dataset.chatId      = chatId;
        div.dataset.name        = name;
        div.dataset.avatar      = '';
        div.dataset.initials    = initials;
        div.dataset.memberCount = memberCount;
        div.innerHTML = `
            <div class="av-wrap">
                <div class="group-av-placeholder">${esc(initials)}</div>
            </div>
            <div class="conv-info">
                <div class="conv-info-top">
                    <div class="conv-name">${esc(name)}</div>
                    <span class="unread-badge"></span>
                </div>
                <div class="conv-last" id="last-msg-group-${chatId}"></div>
            </div>`;
        div.addEventListener('click', () => {
            this.openGroupChat(chatId, name, '', initials, memberCount);
        });
        convsList.appendChild(div);
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
            headers: {
                'Content-Type': 'application/x-www-form-urlencoded',
                'X-CSRF-Token': APP_CONFIG.csrfToken,
            },
            body: 'action=get_pending_count&csrf_token=' + encodeURIComponent(APP_CONFIG.csrfToken),
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
