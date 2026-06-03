<?php
session_start();
require_once 'config/database.php';

if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
    header('Location: login.php');
    exit;
}

$current_user_id = (int) $_SESSION['user_id'];

// --- OBSŁUGA REAKCJI (METODA GET) ---
if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['action']) && $_GET['action'] === 'react') {

    $post_id = (int)($_GET['post_id'] ?? 0);
    $type = trim($_GET['type'] ?? 'like');

    $allowed_types = ['like', 'love', 'hug', 'haha', 'wow', 'sad', 'angry'];
    if ($post_id > 0 && in_array($type, $allowed_types)) {
        try {
            // Sprawdzamy, czy użytkownik już dał jakąś reakcję na ten post
            $stmt = db()->prepare("SELECT reaction_id, reaction_type FROM post_reactions WHERE post_id = :pid AND user_id = :uid");
            $stmt->execute([':pid' => $post_id, ':uid' => $current_user_id]);
            $existing = $stmt->fetch();

            if ($existing) {
                if ($existing['reaction_type'] === $type) {
                    // Jeśli kliknął tę samą emotkę drugi raz -> cofamy reakcję (usuwamy z bazy)
                    $del = db()->prepare("DELETE FROM post_reactions WHERE reaction_id = :rid");
                    $del->execute([':rid' => $existing['reaction_id']]);
                } else {
                    // Jeśli kliknął inną emotkę -> aktualizujemy typ reakcji
                    $upd = db()->prepare("UPDATE post_reactions SET reaction_type = :type WHERE reaction_id = :rid");
                    $upd->execute([':type' => $type, ':rid' => $existing['reaction_id']]);
                }
            } else {
                // Jeśli nie reagował wcześniej -> dodajemy nową reakcję do bazy
                $ins = db()->prepare("INSERT INTO post_reactions (post_id, user_id, reaction_type, created_at) VALUES (:pid, :uid, :type, NOW())");
                $ins->execute([':pid' => $post_id, ':uid' => $current_user_id, ':type' => $type]);
            }
        } catch (PDOException $e) {}
    }
    header('Location: login.php');
    exit;
}

// --- OBSŁUGA FORMULARZY POST ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    $action = $_POST['action'];
    $post_id = (int)($_POST['post_id'] ?? 0);

    if ($post_id <= 0) {
        header('Location: login.php');
        exit;
    }

    // --- DODAWANIE KOMENTARZA ---
    // --- DODAWANIE KOMENTARZA ---
    if ($action === 'comment') {
        $comment_content = trim($_POST['comment_content'] ?? '');
        if (!empty($comment_content)) {
            try {
                // Wstawianie komentarza do tabeli 'comments'
                $stmt = db()->prepare("INSERT INTO comments (post_id, author_id, content, created_at) VALUES (:pid, :uid, :content, NOW())");
                $stmt->execute([
                    ':pid' => $post_id,
                    ':uid' => $current_user_id,
                    ':content' => $comment_content
                ]);

                // Pobieramy dane aktualnego użytkownika, aby przekazać je do JavaScriptu do natychmiastowego wyświetlenia
                $user_stmt = db()->prepare("SELECT first_name, last_name, avatar_url FROM users WHERE user_id = :uid");
                $user_stmt->execute([':uid' => $current_user_id]);
                $user_data = $user_stmt->fetch();

                // Zwracamy sukces w formacie JSON i kończymy skrypt, żeby nie wykonywało się przekierowanie na dole pliku
                header('Content-Type: application/json');
                echo json_encode([
                    'success' => true,
                    'user_name' => htmlspecialchars($user_data['first_name'] . ' ' . $user_data['last_name']),
                    'user_avatar' => !empty($user_data['avatar_url']) ? htmlspecialchars($user_data['avatar_url']) : null,
                    'content' => nl2br(htmlspecialchars($comment_content))
                ]);
                exit;

            } catch (PDOException $e) {
                header('Content-Type: application/json');
                echo json_encode(['success' => false, 'message' => 'Błąd bazy danych.']);
                exit;
            }
        } else {
            header('Content-Type: application/json');
            echo json_encode(['success' => false, 'message' => 'Treść komentarza nie może być pusta.']);
            exit;
        }
    }

    // --- EDYCJA POSTA (Zostaje bez zmian) ---
    if ($action === 'edit') {
        $new_content = trim($_POST['content'] ?? '');
        if (!empty($new_content)) {
            $stmt = db()->prepare("UPDATE posts SET content = :content WHERE post_id = :post_id AND (author_id = :author_id OR :role = 'admin')");
            $stmt->execute([':content' => $new_content, ':post_id' => $post_id, ':author_id' => $current_user_id, ':role' => $_SESSION['role'] ?? 'user']);
        }
    }

    // --- USUWANIE POSTA ---
    if ($action === 'delete') {
        try {
            // 1. Najpierw usuwamy komentarze przypisane do tego posta, aby uniknąć błędu klucza obcego
            $stmt_del_comments = db()->prepare("DELETE FROM comments WHERE post_id = :post_id");
            $stmt_del_comments->execute([':post_id' => $post_id]);

            // 2. Usuwamy wszystkie reakcje przypisane do tego posta
            $stmt_del_reactions = db()->prepare("DELETE FROM post_reactions WHERE post_id = :post_id");
            $stmt_del_reactions->execute([':post_id' => $post_id]);

            // 3. Na samym końcu bezpiecznie usuwamy sam post
            $stmt = db()->prepare("DELETE FROM posts WHERE post_id = :post_id AND (author_id = :author_id OR :role = 'admin')");
            $stmt->execute([':post_id' => $post_id, ':author_id' => $current_user_id, ':role' => $_SESSION['role'] ?? 'user']);
        } catch (PDOException $e) {
            // W razie błędu bazy danych, możesz go zalogować
        }
    }
}

// Zamień dotychczasowy header('Location: login.php'); na bezpieczny powrót:
if (isset($_SERVER['HTTP_REFERER'])) {
    header('Location: ' . $_SERVER['HTTP_REFERER']);
} else {
    header('Location: index.php'); // domyślna strona główna, jeśli referer nie istnieje
}
exit;