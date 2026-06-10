<?php
session_start();
header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

$userId = $_SESSION['user_id'];

// --- KONFIGURACJA POŁĄCZENIA PDO (Dostosuj do swojego pliku config) ---
$host = '127.0.0.1';
$db   = 'twarzobok';
$user = 'root';
$pass = '';
$charset = 'utf8mb4';

$dsn = "mysql:host=$host;dbname=$db;charset=$charset";
try {
    $pdo = new PDO($dsn, $user, $pass, [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
} catch (\PDOException $e) {
    echo json_encode(['error' => 'Database connection failed']);
    exit;
}
// --------------------------------------------------------------------

$action = $_GET['action'] ?? '';

switch($action) {

    // Pobieranie wszystkich rolek wraz ze statusem interakcji zalogowanego gracza
    case 'fetch':
        $stmt = $pdo->prepare("
            SELECT r.*, u.username, 
                   IFNULL(ri.is_liked, 0) as is_liked, 
                   IFNULL(ri.is_saved, 0) as is_saved,
                   (SELECT COUNT(*) FROM reel_interactions WHERE reel_id = r.reel_id AND is_liked = 1) as likes_count,
                   (SELECT COUNT(*) FROM reel_interactions WHERE reel_id = r.reel_id AND is_saved = 1) as saves_count,
                   (SELECT COUNT(*) FROM reel_comments WHERE reel_id = r.reel_id) as comments_count
            FROM reels r
            JOIN users u ON r.user_id = u.user_id
            LEFT JOIN reel_interactions ri ON r.reel_id = ri.reel_id AND ri.user_id = ?
            ORDER BY r.created_at DESC
        ");
        $stmt->execute([$userId]);
        echo json_encode($stmt->fetchAll());
        break;

    // Przełączanie polubienia (Like)
    case 'like':
        $reelId = $_POST['reel_id'] ?? 0;

        // Sprawdź czy rekord interakcji już istnieje
        $stmt = $pdo->prepare("SELECT is_liked FROM reel_interactions WHERE user_id = ? AND reel_id = ?");
        $stmt->execute([$userId, $reelId]);
        $row = $stmt->fetch();

        if ($row) {
            $newStatus = $row['is_liked'] == 1 ? 0 : 1;
            $update = $pdo->prepare("UPDATE reel_interactions SET is_liked = ? WHERE user_id = ? AND reel_id = ?");
            $update->execute([$newStatus, $userId, $reelId]);
            $statusStr = $newStatus ? "liked" : "unliked";
        } else {
            $insert = $pdo->prepare("INSERT INTO reel_interactions (user_id, reel_id, is_liked) VALUES (?, ?, 1)");
            $insert->execute([$userId, $reelId]);
            $statusStr = "liked";
        }
        echo json_encode(['status' => $statusStr]);
        break;

    // Przełączanie zapisu do zakładek (Save)
    case 'save':
        $reelId = $_POST['reel_id'] ?? 0;

        $stmt = $pdo->prepare("SELECT is_saved FROM reel_interactions WHERE user_id = ? AND reel_id = ?");
        $stmt->execute([$userId, $reelId]);
        $row = $stmt->fetch();

        if ($row) {
            $newStatus = $row['is_saved'] == 1 ? 0 : 1;
            $update = $pdo->prepare("UPDATE reel_interactions SET is_saved = ? WHERE user_id = ? AND reel_id = ?");
            $update->execute([$newStatus, $userId, $reelId]);
            $statusStr = $newStatus ? "saved" : "unsaved";
        } else {
            $insert = $pdo->prepare("INSERT INTO reel_interactions (user_id, reel_id, is_saved) VALUES (?, ?, 1)");
            $insert->execute([$userId, $reelId]);
            $statusStr = "saved";
        }
        echo json_encode(['status' => $statusStr]);
        break;

    // Pobieranie komentarzy dla danej rolki
    case 'get_comments':
        $reelId = $_GET['reel_id'] ?? 0;
        $stmt = $pdo->prepare("
            SELECT rc.*, u.username 
            FROM reel_comments rc 
            JOIN users u ON rc.user_id = u.user_id 
            WHERE rc.reel_id = ? 
            ORDER BY rc.created_at ASC
        ");
        $stmt->execute([$reelId]);
        echo json_encode($stmt->fetchAll());
        break;

    // Dodawanie komentarza
    case 'comment':
        $reelId = $_POST['reel_id'] ?? 0;
        $content = trim($_POST['content'] ?? '');

        if($content !== '') {
            $stmt = $pdo->prepare("INSERT INTO reel_comments (reel_id, user_id, content) VALUES (?, ?, ?)");
            $stmt->execute([$reelId, $userId, $content]);
            echo json_encode(['success' => true]);
        } else {
            echo json_encode(['success' => false, 'message' => 'Pusty komentarz']);
        }
        break;

    // Usuwanie rolki przez właściciela
    case 'delete':
        $reelId = $_POST['reel_id'] ?? 0;

        // Pobierz url pliku, aby usunąć go fizycznie z dysku serwera
        $stmt = $pdo->prepare("SELECT video_url FROM reels WHERE reel_id = ? AND user_id = ?");
        $stmt->execute([$reelId, $userId]);
        $reel = $stmt->fetch();

        if ($reel) {
            $fullPath = "../" . $reel['video_url'];
            if(file_exists($fullPath)) {
                unlink($fullPath);
            }
            $delete = $pdo->prepare("DELETE FROM reels WHERE reel_id = ?");
            $delete->execute([$reelId]);
            echo json_encode(['success' => true]);
        } else {
            echo json_encode(['success' => false, 'message' => 'Brak uprawnień lub film nie istnieje']);
        }
        break;

    // Przesyłanie wideo i zapis do bazy
    case 'upload':
        if (!isset($_FILES['video']) || $_FILES['video']['error'] !== UPLOAD_ERR_OK) {
            echo json_encode(['success' => false, 'message' => 'Błąd przesyłania pliku']);
            exit;
        }

        $description = $_POST['description'] ?? '';
        $fileFile = $_FILES['video'];

        // Sprawdzenie typu pliku
        $allowedTypes = ['video/mp4', 'video/webm', 'video/ogg', 'video/quicktime'];
        if (!in_array($fileFile['type'], $allowedTypes)) {
            echo json_encode(['success' => false, 'message' => 'Niedozwolony format wideo']);
            exit;
        }

        // Ustalenie ścieżki (tworzymy folder uploads/reels jeśli nie istnieje)
        $targetDir = "../uploads/reels/";
        if (!file_exists($targetDir)) {
            mkdir($targetDir, 0777, true);
        }

        $extension = pathinfo($fileFile['name'], PATHINFO_EXTENSION);
        $fileName = uniqid('reel_', true) . '.' . $extension;
        $targetFile = $targetDir . $fileName;

        if (move_uploaded_file($fileFile['tmp_name'], $targetFile)) {
            // Ścieżka relatywna do zapisania w bazie danych
            $dbPath = "uploads/reels/" . $fileName;

            $stmt = $pdo->prepare("INSERT INTO reels (user_id, video_url, description) VALUES (?, ?, ?)");
            $stmt->execute([$userId, $dbPath, $description]);

            echo json_encode(['success' => true]);
        } else {
            echo json_encode(['success' => false, 'message' => 'Nie udało się zapisać pliku na serwerze']);
        }
        break;

    default:
        echo json_encode(['error' => 'Unknown action']);
        break;
}