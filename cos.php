<?php
// Nazwa naszego ciasteczka
$cookie_name = "uzytkownik";
$komunikat = "";

// 1. ZAPISYWANIE CIASTECZKA (po wysłaniu formularza)
if (isset($_POST['zapisz'])) {
    $cookie_value = htmlspecialchars($_POST['imie']); // Zabezpieczenie przed XSS

    // Ustawiamy cookie na 1 dzień (86400 sekund)
    // "/" oznacza, że cookie jest dostępne w całej witrynie
    setcookie($cookie_name, $cookie_value, time() + 86400, "/");

    // Nadpisujemy tablicę $_COOKIE, aby zmiana była widoczna od razu bez przeładowania strony
    $_COOKIE[$cookie_name] = $cookie_value;
    $komunikat = "Ciasteczko zostało zapisane pomyślnie!";
}

// 2. USUWANIE CIASTECZKA
if (isset($_POST['usun'])) {
    // Ustawiamy czas ważności na wsteczny (np. godzinę temu), co powoduje usunięcie cookie przez przeglądarkę
    setcookie($cookie_name, "", time() - 3600, "/");

    // Usuwamy zmienną z pamięci bieżącego skryptu
    unset($_COOKIE[$cookie_name]);
    $komunikat = "Ciasteczko zostało usunięte!";
}
?>

<!DOCTYPE html>
<html lang="pl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Formularz Cookie PHP</title>
    <style>
        body { font-family: Arial, sans-serif; margin: 40px; background-color: #f4f4f4; }
        .container { background: white; padding: 20px; border-radius: 8px; max-width: 400px; box-shadow: 0 0 10px rgba(0,0,0,0.1); }
        input[type="text"] { width: 92%; padding: 10px; margin-bottom: 10px; border: 1px solid #ccc; border-radius: 4px; }
        button { padding: 10px 15px; border: none; border-radius: 4px; cursor: pointer; color: white; }
        .btn-success { background-color: #28a745; }
        .btn-danger { background-color: #dc3545; }
        .alert { padding: 10px; background-color: #e2e3e5; border-radius: 4px; margin-bottom: 15px; }
    </style>
</head>
<body>

<div class="container">
    <h2>Obsługa Cookies w PHP</h2>

    <?php if (!empty($komunikat)): ?>
        <div class="alert"><strong>Status:</strong> <?php echo $komunikat; ?></div>
    <?php endif; ?>

    <?php if (isset($_COOKIE[$cookie_name])): ?>
        <p>Witaj ponownie, <strong><?php echo $_COOKIE[$cookie_name]; ?></strong>!</p>
        <p>Twoje imię jest zapisane w ciasteczku przeglądarki.</p>

        <form method="POST" action="">
            <button type="submit" name="usun" class="btn-danger">Usuń ciasteczko</button>
        </form>
    <?php else: ?>
        <p>Brak zapisanego ciasteczka. Wpisz swoje imię poniżej:</p>

        <form method="POST" action="">
            <input type="text" name="imie" placeholder="Wpisz swoje imię" required>
            <button type="submit" name="zapisz" class="btn-success">Zapisz w Cookie</button>
        </form>
    <?php endif; ?>
</div>

</body>
</html>