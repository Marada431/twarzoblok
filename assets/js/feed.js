/* feed.js – logika feedu TwarzBlok
 * Wymaga globalnego APP_CONFIG = { csrfToken } ustawionego w PHP przed załadowaniem tego pliku.
 */

const EMOJI_MAP   = {like:'👍',love:'❤️',hug:'🤗',haha:'😆',wow:'😮',sad:'😢',angry:'😡'};
const EMOJI_LABEL = {like:'Lubię to',love:'Super',hug:'Trzymaj się',haha:'Haha',wow:'Wow',sad:'Smutne',angry:'Złość'};

const FeedApp = {
    csrfToken: APP_CONFIG.csrfToken,
    lbMedia:   [],
    lbIdx:     0,

    // ── Media preview ────────────────────────────────────────
    previewMedia(event) {
        const container = document.getElementById('media-preview-container');
        const grid      = document.getElementById('media-preview-grid');
        grid.innerHTML  = '';
        const files     = event.target.files;
        if (!files || files.length === 0) { container.style.display = 'none'; return; }
        container.style.display = 'block';
        Array.from(files).slice(0, 10).forEach((file) => {
            const item = document.createElement('div');
            item.className = 'media-preview-item';
            const rm = document.createElement('button');
            rm.type = 'button'; rm.className = 'rm-preview'; rm.textContent = '✕';
            rm.onclick = () => item.remove();
            if (file.type.startsWith('video/')) {
                const v = document.createElement('video');
                v.src = URL.createObjectURL(file); v.muted = true;
                item.appendChild(v);
            } else {
                const img = document.createElement('img');
                img.src = URL.createObjectURL(file);
                item.appendChild(img);
            }
            item.appendChild(rm);
            grid.appendChild(item);
        });
    },

    // ── Lightbox ─────────────────────────────────────────────
    openLightbox(galleryEl, startIdx) {
        try { this.lbMedia = JSON.parse(galleryEl.dataset.media); } catch(e) { return; }
        this.lbIdx = startIdx;
        document.getElementById('lightbox').classList.add('active');
        this.showLbMedia();
    },

    showLbMedia() {
        const m   = this.lbMedia[this.lbIdx];
        const img = document.getElementById('lb-img');
        const vid = document.getElementById('lb-video');
        const ctr = document.getElementById('lb-counter');
        img.style.display = 'none'; vid.style.display = 'none';
        if (m.type === 'video') { vid.src = m.url; vid.style.display = 'block'; }
        else { img.src = m.url; img.style.display = 'block'; }
        ctr.textContent = this.lbMedia.length > 1 ? `${this.lbIdx+1} / ${this.lbMedia.length}` : '';
        document.querySelector('.lightbox-prev').style.display = this.lbMedia.length > 1 ? 'flex' : 'none';
        document.querySelector('.lightbox-next').style.display = this.lbMedia.length > 1 ? 'flex' : 'none';
    },

    lightboxNav(dir) {
        this.lbIdx = (this.lbIdx + dir + this.lbMedia.length) % this.lbMedia.length;
        this.showLbMedia();
    },

    closeLightbox() {
        document.getElementById('lightbox').classList.remove('active');
        const vid = document.getElementById('lb-video');
        vid.pause(); vid.src = '';
    },

    // ── Reakcje na posty ──────────────────────────────────────
    async toggleReaction(postId, type) {
        const btn     = document.getElementById('react-btn-' + postId);
        const res = await fetch('reactions/toggle.php', {
            method: 'POST',
            headers: {'Content-Type':'application/x-www-form-urlencoded','X-CSRF-Token': this.csrfToken},
            body: `type=${encodeURIComponent(type)}&post_id=${postId}`
        });
        const data = await res.json();
        if (!data.success) return;

        const userReaction = data.user_reaction;
        btn.dataset.userReaction = userReaction || '';
        btn.className = userReaction ? 'action-btn active-reacted' : 'action-btn';
        document.getElementById('react-btn-text-' + postId).textContent =
            userReaction ? (EMOJI_MAP[userReaction] + ' ' + EMOJI_LABEL[userReaction]) : 'Reakcja';

        const summary = document.getElementById('react-summary-' + postId);
        let html = '';
        for (const [t, cnt] of Object.entries(data.counts)) {
            if (cnt > 0) {
                const mine = (t === userReaction) ? ' mine' : '';
                html += `<span class="reaction-badge${mine}" title="${EMOJI_LABEL[t]}">${EMOJI_MAP[t]} <b>${cnt}</b></span>`;
            }
        }
        summary.innerHTML = html;
    },

    // ── Komentarze ────────────────────────────────────────────
    toggleComments(postId) {
        const sec = document.getElementById('comments-' + postId);
        sec.style.display = sec.style.display === 'none' ? 'block' : 'none';
    },

    cancelReply(form) {
        form.querySelector('[name=parent_comment_id]').value = '';
        form.querySelector('[name=reply_to_user_id]').value  = '';
        form.querySelector('.reply-indicator').style.display = 'none';
        form.querySelector('.comment-input').placeholder = 'Napisz komentarz…';
    },

    // ── Modals ────────────────────────────────────────────────
    openModal(type, postId) {
        document.getElementById(type + '-post-id').value = postId;
        document.getElementById('modal-' + type).style.display = 'flex';
    },

    closeModal(type) {
        document.getElementById('modal-' + type).style.display = 'none';
    },

    // ── Karuzela ─────────────────────────────────────────────
    scrollCarousel(dir) {
        document.getElementById('suggestionsCarousel')?.scrollBy({left: dir * 200, behavior:'smooth'});
    },

    // ── Znajomi ──────────────────────────────────────────────
    async addFriend(id, btn) {
        if (btn.disabled) return;
        btn.disabled = true; btn.textContent = '…';
        const res = await fetch('index.php', {
            method: 'POST',
            headers: {'Content-Type':'application/x-www-form-urlencoded','X-CSRF-Token': this.csrfToken},
            body: `action=add_friend&target_user_id=${id}`
        });
        const data = await res.json();
        if (data.success) { btn.textContent = '✓ Wysłano'; btn.style.background = '#42b72a'; }
        else { showToast(data.message, 'error'); btn.disabled = false; btn.textContent = 'Dodaj'; }
    },

    async removeSuggestion(id, btn) {
        const card = btn.closest('.suggestion-card');
        await fetch('index.php', {
            method: 'POST',
            headers: {'Content-Type':'application/x-www-form-urlencoded','X-CSRF-Token': this.csrfToken},
            body: `action=remove_suggestion&target_user_id=${id}`
        });
        if (card) { card.style.opacity = '0'; card.style.transition = 'opacity .3s'; setTimeout(()=>card.remove(), 300); }
    },

    // ── Licznik zaproszeń ─────────────────────────────────────
    async updatePendingCount() {
        const res  = await fetch('index.php', {
            method: 'POST',
            headers: {'Content-Type':'application/x-www-form-urlencoded','X-CSRF-Token': this.csrfToken},
            body: 'action=get_pending_count'
        });
        const data = await res.json();
        if (!data.success) return;
        const badge = document.getElementById('pending-friends-count');
        if (!badge) return;
        badge.textContent  = data.count;
        badge.style.display = data.count > 0 ? 'flex' : 'none';
    },

    // ── Inicjalizacja ─────────────────────────────────────────
    init() {
        this.bindKeyboardEvents();
        this.bindCommentEvents();
        this.bindEditTriggers();
        this.bindLoadMorePosts();
        setInterval(() => this.updatePendingCount(), 15000);
    },

    bindKeyboardEvents() {
        document.addEventListener('keydown', e => {
            if (!document.getElementById('lightbox').classList.contains('active')) return;
            if (e.key === 'ArrowLeft')  this.lightboxNav(-1);
            else if (e.key === 'ArrowRight') this.lightboxNav(1);
            else if (e.key === 'Escape') this.closeLightbox();
        });
        window.onclick = function(e) {
            if (e.target.classList.contains('fb-modal-overlay')) e.target.style.display = 'none';
        };
    },

    // Edycja posta przez data-atrybuty (bez XSS)
    bindEditTriggers() {
        document.addEventListener('click', function(e) {
            const trigger = e.target.closest('.edit-post-trigger');
            if (!trigger) return;
            e.preventDefault();
            document.getElementById('edit-post-id').value      = trigger.dataset.postId;
            document.getElementById('edit-post-content').value = trigger.dataset.postContent;
            document.getElementById('modal-edit').style.display = 'flex';
        });
    },

    bindCommentEvents() {
        // Reakcje na komentarze
        document.addEventListener('click', async (e) => {
            const btn = e.target.closest('.comment-pick-emoji');
            if (!btn) return;
            const type = btn.dataset.type;
            const cid  = btn.dataset.commentId;
            const res = await fetch('reactions/toggle.php', {
                method: 'POST',
                headers: {'Content-Type':'application/x-www-form-urlencoded','X-CSRF-Token': this.csrfToken},
                body: `type=${encodeURIComponent(type)}&comment_id=${cid}`
            });
            const data = await res.json();
            if (!data.success) return;
            const container = document.querySelector(`.comment-reactions[data-comment-id="${cid}"]`);
            if (!container) return;
            container.querySelectorAll('.comment-reaction-badge').forEach(el => el.remove());
            const picker = container.querySelector('.comment-reaction-picker');
            const frag = [];
            for (const [t, cnt] of Object.entries(data.counts)) {
                if (cnt > 0) {
                    const mine = (t === data.user_reaction) ? ' mine' : '';
                    frag.push(`<span class="comment-reaction-badge${mine}" data-type="${t}" title="${EMOJI_LABEL[t]}">${EMOJI_MAP[t]} ${cnt}</span>`);
                }
            }
            picker.insertAdjacentHTML('beforebegin', frag.join(''));
        });

        // Złóż komentarz / odpowiedź
        document.addEventListener('submit', async (e) => {
            const form = e.target.closest('.comment-form');
            if (!form) return;
            e.preventDefault();

            const postId   = form.dataset.postId;
            const input    = form.querySelector('.comment-input');
            const content  = input.value.trim();
            if (!content) return;

            const parentId  = form.querySelector('[name=parent_comment_id]').value;
            const replyToId = form.querySelector('[name=reply_to_user_id]').value;

            const fd = new FormData();
            fd.append('action', 'comment');
            fd.append('post_id', postId);
            fd.append('comment_content', content);
            fd.append('parent_comment_id', parentId);
            fd.append('reply_to_user_id', replyToId);
            fd.append('csrf_token', this.csrfToken);

            const res  = await fetch('post_actions.php', { method:'POST', headers:{'X-CSRF-Token': this.csrfToken}, body: fd });
            const data = await res.json();
            if (!data.success) { showToast(data.message || 'Błąd.', 'error'); return; }

            input.value = '';
            this.cancelReply(form);

            const avatarHtml = data.user_avatar
                ? `<img src="${data.user_avatar}" alt="Avatar" loading="lazy">`
                : `<span class="cmt-av-ph">👤</span>`;
            const replyMention = data.reply_to_username
                ? `<a class="reply-mention" href="#">@${data.reply_to_username}</a> `
                : '';

            const newCmt = `
                <div class="comment-item ${data.parent_comment_id ? 'reply-comment' : ''}" id="comment-${data.comment_id}" data-comment-id="${data.comment_id}">
                    <div class="cmt-avatar">${avatarHtml}</div>
                    <div class="cmt-body">
                        <div class="cmt-bubble">
                            <span class="cmt-author">${escHtml(data.user_name)}</span>
                            <span class="cmt-text">${replyMention}${escHtml(data.content)}</span>
                        </div>
                        <div class="cmt-meta">
                            <div class="comment-reactions" data-comment-id="${data.comment_id}">
                                <div class="comment-reaction-picker" data-comment-id="${data.comment_id}">
                                    ${Object.entries(EMOJI_MAP).map(([t,em])=>`<span class="comment-pick-emoji" data-type="${t}" data-comment-id="${data.comment_id}" title="${EMOJI_LABEL[t]}">${em}</span>`).join('')}
                                </div>
                            </div>
                            <button class="cmt-action-btn reply-btn"
                                    data-comment-id="${data.comment_id}"
                                    data-author-id="${data.author_id || ''}"
                                    data-author-name="${escHtml(data.user_name)}">Odpowiedz</button>
                            <span class="cmt-time">przed chwilą</span>
                        </div>
                        <div class="replies-container" id="replies-${data.comment_id}" data-loaded="0"></div>
                    </div>
                </div>`;

            if (data.parent_comment_id) {
                const repliesBox = document.getElementById('replies-' + data.parent_comment_id);
                if (repliesBox) repliesBox.insertAdjacentHTML('beforeend', newCmt);
            } else {
                const list = document.getElementById('comments-list-' + postId);
                if (list) list.insertAdjacentHTML('afterbegin', newCmt);
                const cntEl = document.getElementById('cmt-count-' + postId);
                if (cntEl) {
                    const m = cntEl.textContent.match(/\d+/);
                    cntEl.textContent = `Komentarz (${m ? parseInt(m[0]) + 1 : 1})`;
                }
            }
        });

        // Odpowiedz
        document.addEventListener('click', (e) => {
            const btn = e.target.closest('.reply-btn');
            if (!btn) return;
            const commentId  = btn.dataset.commentId;
            const authorId   = btn.dataset.authorId;
            const authorName = btn.dataset.authorName;
            const card = btn.closest('.post-feed-card');
            if (!card) return;
            const form = card.querySelector('.comment-form');
            if (!form) return;
            form.querySelector('[name=parent_comment_id]').value = commentId;
            form.querySelector('[name=reply_to_user_id]').value  = authorId;
            const indicator = form.querySelector('.reply-indicator');
            form.querySelector('.reply-label').textContent = 'Odpowiadasz: @' + authorName;
            indicator.style.display = 'flex';
            const input = form.querySelector('.comment-input');
            input.placeholder = '@' + authorName + ' ';
            input.focus();
            const section = form.closest('.comments-section');
            if (section) section.style.display = 'block';
        });

        // Ładuj odpowiedzi
        document.addEventListener('click', async (e) => {
            const btn = e.target.closest('.load-replies-btn');
            if (!btn) return;
            btn.disabled = true;
            btn.textContent = 'Ładowanie…';
            const commentId = btn.dataset.commentId;
            const postId    = btn.closest('.post-feed-card').id.replace('post-', '');
            const res  = await fetch(`comments_load.php?post_id=${postId}&parent_id=${commentId}`);
            const data = await res.json();
            if (!data.success) { btn.disabled = false; return; }
            const container = document.getElementById('replies-' + commentId);
            container.innerHTML = '';
            data.comments.forEach(c => container.appendChild(buildCommentHTML(c)));
            btn.remove();
            container.dataset.loaded = '1';
        });

        // Załaduj więcej komentarzy
        document.addEventListener('click', async (e) => {
            const btn = e.target.closest('.load-more-comments-btn');
            if (!btn) return;
            btn.disabled = true;
            btn.textContent = 'Ładowanie…';
            const postId = btn.dataset.postId;
            const offset = parseInt(btn.dataset.offset);
            const total  = parseInt(btn.dataset.total);
            const res  = await fetch(`comments_load.php?post_id=${postId}&offset=${offset}`);
            const data = await res.json();
            if (!data.success) { btn.disabled = false; return; }
            const list = document.getElementById('comments-list-' + postId);
            data.comments.forEach(c => list.appendChild(buildCommentHTML(c)));
            const newOffset = offset + data.comments.length;
            if (data.has_more) {
                btn.dataset.offset = newOffset;
                btn.disabled = false;
                btn.textContent = `Pokaż więcej komentarzy (${total - newOffset} pozostałych)`;
            } else {
                btn.remove();
            }
        });
    },

    bindLoadMorePosts() {
        const loadMoreBtn = document.getElementById('load-more-posts-btn');
        if (!loadMoreBtn) return;
        loadMoreBtn.addEventListener('click', async () => {
            loadMoreBtn.disabled = true;
            document.getElementById('feed-spinner').style.display = 'block';
            const res = await fetch('index.php', {
                method: 'POST',
                headers: {'Content-Type':'application/x-www-form-urlencoded','X-CSRF-Token': this.csrfToken},
                body: 'action=load_posts&offset=' + loadMoreBtn.dataset.offset
            });
            const data = await res.json();
            document.getElementById('feed-spinner').style.display = 'none';
            if (data.html) {
                document.getElementById('feed-container').insertAdjacentHTML('beforeend', data.html);
                loadMoreBtn.dataset.offset = parseInt(loadMoreBtn.dataset.offset) + 20;
            }
            if (!data.has_more) { loadMoreBtn.remove(); return; }
            loadMoreBtn.disabled = false;
        });
    },
};

// ── Problem 7: buildCommentHTML przez <template> ──────────────
function buildCommentHTML(c) {
    const tpl  = document.getElementById('comment-tpl');
    const node = tpl.content.cloneNode(true);
    const root = node.querySelector('.comment-item');

    root.id = `comment-${c.comment_id}`;
    root.dataset.commentId = c.comment_id;
    root.dataset.authorId  = c.author_id;
    if (c.is_reply) root.classList.add('reply-comment');

    // Avatar
    const avDiv = root.querySelector('.cmt-avatar');
    if (c.author_avatar) {
        const img = document.createElement('img');
        img.src = escHtml(c.author_avatar); img.alt = 'Avatar'; img.loading = 'lazy';
        avDiv.appendChild(img);
    } else {
        avDiv.innerHTML = '<span class="cmt-av-ph">👤</span>';
    }

    // Autor
    root.querySelector('.cmt-author').textContent = c.author_name;

    // Treść (z ewentualną wzmianką)
    const textEl = root.querySelector('.cmt-text');
    if (c.reply_to_username) {
        const mention = document.createElement('a');
        mention.className = 'reply-mention'; mention.href = '#';
        mention.textContent = '@' + c.reply_to_username;
        textEl.appendChild(mention);
        textEl.appendChild(document.createTextNode(' '));
    }
    // Zachowaj nowe linie
    c.content.split('\n').forEach((line, i, arr) => {
        textEl.appendChild(document.createTextNode(line));
        if (i < arr.length - 1) textEl.appendChild(document.createElement('br'));
    });

    // Czas
    root.querySelector('.cmt-time').textContent = c.created_at_rel || '';

    // Reakcje
    const reactDiv = root.querySelector('.comment-reactions');
    reactDiv.dataset.commentId = c.comment_id;
    (c.reactions || []).forEach(r => {
        if (r.cnt <= 0) return;
        const span = document.createElement('span');
        span.className = `comment-reaction-badge${r.is_mine ? ' mine' : ''}`;
        span.dataset.type = r.reaction_type;
        span.title = EMOJI_LABEL[r.reaction_type] || '';
        span.textContent = `${EMOJI_MAP[r.reaction_type] || ''} ${r.cnt}`;
        reactDiv.insertBefore(span, reactDiv.querySelector('.comment-reaction-picker'));
    });
    const picker = root.querySelector('.comment-reaction-picker');
    picker.dataset.commentId = c.comment_id;
    Object.entries(EMOJI_MAP).forEach(([t, em]) => {
        const span = document.createElement('span');
        span.className = 'comment-pick-emoji'; span.dataset.type = t;
        span.dataset.commentId = c.comment_id; span.title = EMOJI_LABEL[t];
        span.textContent = em;
        picker.appendChild(span);
    });

    // Przycisk odpowiedzi
    const replyBtn = root.querySelector('.reply-btn');
    replyBtn.dataset.commentId  = c.comment_id;
    replyBtn.dataset.authorId   = c.author_id;
    replyBtn.dataset.authorName = c.author_name;

    // Kontener odpowiedzi
    const repliesDiv = root.querySelector('.replies-container');
    repliesDiv.id = `replies-${c.comment_id}`;
    repliesDiv.dataset.loaded = '0';
    if (!c.is_reply && c.reply_count > 0) {
        const btn = document.createElement('button');
        btn.className = 'load-replies-btn';
        btn.dataset.commentId = c.comment_id; btn.dataset.count = c.reply_count;
        btn.textContent = `Zobacz ${c.reply_count} odpowiedzi`;
        repliesDiv.appendChild(btn);
    }

    return node;
}

function escHtml(str) {
    if (!str) return '';
    return String(str).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;').replace(/'/g,'&#39;');
}

// Globalne wrappery dla onclick w PHP-generowanym HTML
function toggleReaction(postId, type) { return FeedApp.toggleReaction(postId, type); }
function toggleComments(postId)       { return FeedApp.toggleComments(postId); }
function openModal(type, postId)      { return FeedApp.openModal(type, postId); }
function closeModal(type)             { return FeedApp.closeModal(type); }
function scrollCarousel(dir)          { return FeedApp.scrollCarousel(dir); }
function addFriend(id, btn)           { return FeedApp.addFriend(id, btn); }
function removeSuggestion(id, btn)    { return FeedApp.removeSuggestion(id, btn); }
function cancelReply(form)            { return FeedApp.cancelReply(form); }
function openLightbox(el, idx)        { return FeedApp.openLightbox(el, idx); }
function lightboxNav(dir)             { return FeedApp.lightboxNav(dir); }
function closeLightbox()              { return FeedApp.closeLightbox(); }
function previewMedia(event)          { return FeedApp.previewMedia(event); }

document.addEventListener('DOMContentLoaded', () => FeedApp.init());
