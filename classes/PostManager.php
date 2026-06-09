<?php

class PostManager {
    private PDO $pdo;

    public function __construct(PDO $pdo) {
        $this->pdo = $pdo;
    }

    public function create(int $userId, string $content): int {
        $stmt = $this->pdo->prepare(
            "INSERT INTO posts (author_id, content, created_at) VALUES (?, ?, NOW())"
        );
        $stmt->execute([$userId, $content]);
        return (int)$this->pdo->lastInsertId();
    }

    public function edit(int $postId, int $userId, string $newContent, bool $isAdmin = false): bool {
        if ($isAdmin) {
            $stmt = $this->pdo->prepare("UPDATE posts SET content = ?, updated_at = NOW() WHERE post_id = ?");
            return $stmt->execute([$newContent, $postId]);
        }
        $stmt = $this->pdo->prepare(
            "UPDATE posts SET content = ?, updated_at = NOW() WHERE post_id = ? AND author_id = ?"
        );
        return $stmt->execute([$newContent, $postId, $userId]);
    }

    public function delete(int $postId, int $requestingUserId, bool $isAdmin = false): bool {
        $post = $this->getById($postId);
        if (!$post) return false;
        if (!$isAdmin && (int)$post['author_id'] !== $requestingUserId) return false;

        // Usuń pliki mediów
        $media = $this->pdo->prepare("SELECT file_url FROM post_media WHERE post_id = ?");
        $media->execute([$postId]);
        foreach ($media->fetchAll(PDO::FETCH_ASSOC) as $m) {
            $path = __DIR__ . '/../' . $m['file_url'];
            if (file_exists($path)) @unlink($path);
        }

        $stmt = $this->pdo->prepare("DELETE FROM posts WHERE post_id = ?");
        return $stmt->execute([$postId]);
    }

    public function getById(int $id): array|false {
        $stmt = $this->pdo->prepare("SELECT post_id, author_id, content, created_at FROM posts WHERE post_id = ?");
        $stmt->execute([$id]);
        return $stmt->fetch(PDO::FETCH_ASSOC);
    }

    public function report(int $postId, int $reportingUserId, string $reason): bool {
        $allowed = ['spam', 'harassment', 'hate_speech', 'other'];
        if (!in_array($reason, $allowed, true)) $reason = 'other';
        $stmt = $this->pdo->prepare(
            "INSERT INTO reports (post_id, reported_user_id, category, content, reported_at) VALUES (?, ?, ?, '', NOW())"
        );
        return $stmt->execute([$postId, $reportingUserId, $reason]);
    }
}
