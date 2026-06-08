<!DOCTYPE html>
<html lang="pl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>TwarzBlok - Strefa Gier i Rywalizacji</title>
    <link rel="stylesheet" href="assets/css/main.css">
</head>
<body>

<nav class="navbar">
    <div class="navbar-brand">TwarzBlok 🎮</div>
    <div class="navbar-links">
        <a href="index.php">🏠 Dom</a>
        <a href="gra.html"><b>Gry</b></a>
    </div>
    <div><a href="index.php" class="btn btn-secondary">Powrót</a></div>
</nav>

<div class="fb-container game-layout">

    <main class="game-zone">
        <div class="card">
            <h2>🎮 Arena Mini-Gier</h2>
            <p class="text-muted">Rywalizuj na żywo z osobami na czacie portalu TwarzBlok!</p>

            <div class="game-screen">
                <p>串 [ Miejsce na grę w JS ] 串</p>
                <button class="btn btn-primary">KLIKNIJ ABY ZDOBYĆ PKT!</button>
            </div>

            <h3>🏆 Tabela liderów pokoju</h3>
            <ul class="leaderboard-list">
                <li class="leaderboard-item gold"><span>1. MistrzKlawiatury</span><span>4,250 pkt</span></li>
                <li class="leaderboard-item silver"><span>2. AnnaKowalska</span><span>3,810 pkt</span></li>
                <li class="leaderboard-item"><span>3. Ty (Gracz)</span><span>1,200 pkt</span></li>
            </ul>
        </div>
    </main>

    <aside class="chat-sidebar">
        <div class="card chat-window">
            <h3>💬 Czat Pokoju Gier</h3>
            <hr>

            <div class="chat-messages">
                <div class="message received">
                    <span class="message-sender">AnnaKowalska</span>
                    Zaraz Was dogonię w rankingu TwarzBloku!
                </div>
                <div class="message sent">
                    <span class="message-sender">Ty</span>
                    Zobaczymy!
                </div>
            </div>

            <div class="chat-footer">
                <input type="text" class="form-input" placeholder="Napisz do rywali...">
                <button class="btn btn-primary">Wyślij</button>
            </div>
        </div>
    </aside>

</div>

</body>
</html>