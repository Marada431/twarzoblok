document.addEventListener("DOMContentLoaded", () => {
    loadReels();
    document.getElementById("uploadReelForm").addEventListener("submit", uploadReel);
    document.getElementById("addCommentForm").addEventListener("submit", submitComment);
});

const container = document.getElementById("reelsContainer");
const currentUserId = parseInt(container.getAttribute("data-user-id"));

// Globalny stan dźwięku (domyślnie wyciszony przez politykę przeglądarek)
let globalMuted = true;

function loadReels() {
    fetch("api/reels.php?action=fetch")
        .then(res => res.json())
        .then(reels => {
            container.innerHTML = "";
            if(reels.length === 0) {
                container.innerHTML = "<div class='reel-card'><h2 style='color:white;'>Brak rolek. Dodaj pierwszą!</h2></div>";
                return;
            }

            reels.forEach(reel => {
                const card = document.createElement("div");
                card.className = "reel-card";
                card.setAttribute("data-id", reel.reel_id);

                const isLiked = parseInt(reel.is_liked) === 1 ? "liked" : "";
                const isSaved = parseInt(reel.is_saved) === 1 ? "saved" : "";
                const isAuthor = parseInt(reel.user_id) === currentUserId;

                const deleteButtonHtml = isAuthor ? `
                    <div class="action-group">
                        <button class="action-btn delete-btn" onclick="deleteReel(${reel.reel_id})">
                            <i class="fa-solid fa-trash"></i>
                        </button>
                        <span class="action-count">Usuń</span>
                    </div>` : "";

                // Ikona głośnika w zależności od stanu globalnego
                const muteIcon = globalMuted ? "fa-volume-xmark" : "fa-volume-high";

                card.innerHTML = `
                    <div class="reel-player-wrapper">
                        <video src="${reel.video_url}" loop muted playsinline></video>
                        
                        <button class="mute-btn" onclick="toggleMuteGlobal()">
                            <i class="fa-solid ${muteIcon}"></i>
                        </button>

                        <div class="reel-actions">
                            <div class="action-group">
                                <button class="action-btn ${isLiked}" onclick="toggleLike(${reel.reel_id}, this)">
                                    <i class="fa-solid fa-heart"></i>
                                </button>
                                <span class="action-count">${reel.likes_count}</span>
                            </div>

                            <div class="action-group">
                                <button class="action-btn" onclick="openComments(${reel.reel_id})">
                                    <i class="fa-solid fa-comment"></i>
                                </button>
                                <span class="action-count class-comment-count">${reel.comments_count}</span>
                            </div>

                            <div class="action-group">
                                <button class="action-btn ${isSaved}" onclick="toggleSave(${reel.reel_id}, this)">
                                    <i class="fa-solid fa-bookmark"></i>
                                </button>
                                <span class="action-count">${reel.saves_count}</span>
                            </div>

                            ${deleteButtonHtml}
                        </div>

                        <div class="reel-info">
                            <h3>@${reel.username}</h3>
                            <p>${reel.description || ''}</p>
                        </div>
                    </div>
                `;
                container.appendChild(card);
            });

            initVideoObserver();
        });
}

// Funkcja zarządzająca odtwarzaniem i respektowaniem wyciszenia
function initVideoObserver() {
    const observerOptions = { root: container, rootMargin: "0px", threshold: 0.5 };
    const observer = new IntersectionObserver((entries) => {
        entries.forEach(entry => {
            const video = entry.target.querySelector("video");
            if (!video) return;
            if (entry.isIntersecting) {
                video.muted = globalMuted; // nadaj aktualny stan dźwięku
                video.play().catch(err => console.log("Autoplay blocked: ", err));
            } else {
                video.pause();
                video.currentTime = 0;
            }
        });
    }, observerOptions);

    document.querySelectorAll(".reel-card").forEach(card => observer.observe(card));
}

// Globalne przełączanie wyciszenia dla wszystkich rolek na raz
function toggleMuteGlobal() {
    globalMuted = !globalMuted;

    // Zastosuj do wszystkich wideo na stronie
    document.querySelectorAll(".reel-card video").forEach(video => {
        video.muted = globalMuted;
    });

    // Zaktualizuj wygląd wszystkich ikon głośnika
    document.querySelectorAll(".mute-btn i").forEach(icon => {
        if(globalMuted) {
            icon.className = "fa-solid fa-volume-xmark";
        } else {
            icon.className = "fa-solid fa-volume-high";
        }
    });
}

// Polubienie i natychmiastowa zmiana licznika na ekranie
function toggleLike(reelId, button) {
    fetch("api/reels.php?action=like", {
        method: "POST",
        headers: { "Content-Type": "application/x-www-form-urlencoded" },
        body: `reel_id=${reelId}`
    })
        .then(res => res.json())
        .then(data => {
            const countSpan = button.nextElementSibling;
            let currentCount = parseInt(countSpan.innerText) || 0;

            if(data.status === "liked") {
                button.classList.add("liked");
                countSpan.innerText = currentCount + 1;
            } else {
                button.classList.remove("liked");
                countSpan.innerText = Math.max(0, currentCount - 1);
            }
        });
}

// Zapisanie i natychmiastowa zmiana licznika na ekranie
function toggleSave(reelId, button) {
    fetch("api/reels.php?action=save", {
        method: "POST",
        headers: { "Content-Type": "application/x-www-form-urlencoded" },
        body: `reel_id=${reelId}`
    })
        .then(res => res.json())
        .then(data => {
            const countSpan = button.nextElementSibling;
            let currentCount = parseInt(countSpan.innerText) || 0;

            if(data.status === "saved") {
                button.classList.add("saved");
                countSpan.innerText = currentCount + 1;
            } else {
                button.classList.remove("saved");
                countSpan.innerText = Math.max(0, currentCount - 1);
            }
        });
}

// Otwieranie komentarzy
function openComments(reelId) {
    document.getElementById("activeReelId").value = reelId;
    toggleCommentsModal(true);
    fetch(`api/reels.php?action=get_comments&reel_id=${reelId}`)
        .then(res => res.json())
        .then(comments => {
            const list = document.getElementById("commentsList");
            list.innerHTML = "";
            comments.forEach(c => {
                list.innerHTML += `<div class='comment-item'><strong>@${c.username}</strong>: ${c.content}</div>`;
            });
        });
}

// Wysyłanie komentarza i inkrementacja licznika na karcie filmu
function submitComment(e) {
    e.preventDefault();
    const reelId = document.getElementById("activeReelId").value;
    const content = document.getElementById("commentContent").value;

    fetch("api/reels.php?action=comment", {
        method: "POST",
        headers: { "Content-Type": "application/x-www-form-urlencoded" },
        body: `reel_id=${reelId}&content=${encodeURIComponent(content)}`
    })
        .then(res => res.json())
        .then(data => {
            if(data.success) {
                document.getElementById("commentContent").value = "";
                openComments(reelId);

                // Znajdź licznik komentarzy na odpowiedniej rolce i zwiększ o 1
                const targetCard = document.querySelector(`.reel-card[data-id="${reelId}"] .class-comment-count`);
                if(targetCard) {
                    targetCard.innerText = (parseInt(targetCard.innerText) || 0) + 1;
                }
            }
        });
}

function deleteReel(reelId) {
    if(confirm("Czy na pewno chcesz usunąć tę rolkę?")) {
        fetch("api/reels.php?action=delete", {
            method: "POST",
            headers: { "Content-Type": "application/x-www-form-urlencoded" },
            body: `reel_id=${reelId}`
        })
            .then(res => res.json())
            .then(data => {
                if(data.success) loadReels();
            });
    }
}

function uploadReel(e) {
    e.preventDefault();
    const formData = new FormData(this);
    const submitBtn = this.querySelector(".submit-btn");
    if(submitBtn) submitBtn.innerText = "Wysyłanie...";

    fetch("api/reels.php?action=upload", { method: "POST", body: formData })
        .then(res => {
            if (!res.ok) throw new Error("Błąd serwera: " + res.status);
            return res.json();
        })
        .then(data => {
            if(submitBtn) submitBtn.innerText = "Opublikuj";
            if(data.success) {
                toggleUploadModal(false);
                document.getElementById("uploadReelForm").reset();
                loadReels();
            } else {
                alert("Błąd: " + data.message);
            }
        })
        .catch(err => {
            if(submitBtn) submitBtn.innerText = "Opublikuj";
            alert("Nie udało się przesłać filmu. Upewnij się, że nie jest za duży.");
        });
}

function toggleUploadModal(show) { document.getElementById("uploadModal").style.display = show ? "flex" : "none"; }
function toggleCommentsModal(show) { document.getElementById("commentsModal").style.display = show ? "flex" : "none"; }
// Funkcja przewijania za pomocą strzałek bocznych
function scrollReels(direction) {
    const reelsContainer = document.getElementById("reelsContainer");
    if (!reelsContainer) return;

    // Pobieramy wysokość jednego filmu (całego okna)
    const scrollAmount = window.innerHeight;

    if (direction === 'up') {
        // Przewiń w górę o wysokość jednego ekranu
        reelsContainer.scrollBy({ top: -scrollAmount, behavior: 'smooth' });
    } else if (direction === 'down') {
        // Przewiń w dół o wysokość jednego ekranu
        reelsContainer.scrollBy({ top: scrollAmount, behavior: 'smooth' });
    }
}