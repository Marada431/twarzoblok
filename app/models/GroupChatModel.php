<?php

class GroupChatModel {
    private PDO $pdo;

    public function __construct(PDO $pdo) {
        $this->pdo = $pdo;
    }

    // ── Tworzenie grupy ────────────────────────────────────────

    public function createGroup(string $name, string $description, int $ownerId, array $memberIds): int {
        $this->pdo->beginTransaction();
        try {
            $stmt = $this->pdo->prepare("
                INSERT INTO chats (chat_type, name, description, created_by, created_at)
                VALUES ('group', :name, :desc, :owner, NOW())
            ");
            $stmt->execute([':name' => $name, ':desc' => $description, ':owner' => $ownerId]);
            $chatId = (int)$this->pdo->lastInsertId();

            $this->addMember($chatId, $ownerId, 'admin', $ownerId);

            foreach ($memberIds as $memberId) {
                if ((int)$memberId !== $ownerId) {
                    $this->addMember($chatId, (int)$memberId, 'member', $ownerId);
                }
            }

            $this->pdo->commit();
            return $chatId;
        } catch (Exception $e) {
            $this->pdo->rollBack();
            error_log('[GroupChatModel] createGroup: ' . $e->getMessage());
            throw $e;
        }
    }

    // ── Uczestnicy ─────────────────────────────────────────────

    public function addMember(int $chatId, int $userId, string $role = 'member', int $addedBy = 0): bool {
        if ($this->isMember($chatId, $userId)) return false;

        $stmt = $this->pdo->prepare("
            INSERT INTO chat_participants (chat_id, user_id, role, joined_at, added_by)
            VALUES (:chat, :user, :role, NOW(), :added_by)
        ");
        return $stmt->execute([
            ':chat'     => $chatId,
            ':user'     => $userId,
            ':role'     => $role,
            ':added_by' => $addedBy,
        ]);
    }

    public function removeMember(int $chatId, int $userId): bool {
        $stmt = $this->pdo->prepare(
            "DELETE FROM chat_participants WHERE chat_id = ? AND user_id = ?"
        );
        return $stmt->execute([$chatId, $userId]);
    }

    public function isMember(int $chatId, int $userId): bool {
        $stmt = $this->pdo->prepare(
            "SELECT participant_id FROM chat_participants WHERE chat_id = ? AND user_id = ? LIMIT 1"
        );
        $stmt->execute([$chatId, $userId]);
        return (bool)$stmt->fetch();
    }

    public function isAdmin(int $chatId, int $userId): bool {
        $stmt = $this->pdo->prepare(
            "SELECT participant_id FROM chat_participants
             WHERE chat_id = ? AND user_id = ? AND role = 'admin' LIMIT 1"
        );
        $stmt->execute([$chatId, $userId]);
        return (bool)$stmt->fetch();
    }

    public function getMembers(int $chatId): array {
        $stmt = $this->pdo->prepare("
            SELECT u.user_id AS id, u.username, u.first_name, u.last_name, u.avatar_url,
                   cp.role, cp.joined_at
            FROM chat_participants cp
            JOIN users u ON u.user_id = cp.user_id
            WHERE cp.chat_id = ?
            ORDER BY cp.role DESC, cp.joined_at ASC
        ");
        $stmt->execute([$chatId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function getGroupInfo(int $chatId): ?array {
        $stmt = $this->pdo->prepare("
            SELECT chat_id, name, description, avatar_url, created_by, created_at
            FROM chats WHERE chat_id = ? AND chat_type = 'group'
        ");
        $stmt->execute([$chatId]);
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    // ── Lista grup użytkownika ─────────────────────────────────

    public function getUserGroups(int $userId): array {
        $stmt = $this->pdo->prepare("
            SELECT
                c.chat_id, c.name, c.avatar_url, c.created_by,
                cp.role,
                mc.cnt              AS member_count,
                lm.content          AS last_msg,
                lm.attachment_url   AS last_attach,
                lm.sent_at          AS last_at,
                COALESCE(ur.cnt, 0) AS unread
            FROM chats c
            JOIN chat_participants cp ON cp.chat_id = c.chat_id AND cp.user_id = ?
            LEFT JOIN (
                SELECT chat_id, COUNT(*) AS cnt FROM chat_participants GROUP BY chat_id
            ) mc ON mc.chat_id = c.chat_id
            LEFT JOIN (
                SELECT chat_id, COUNT(*) AS cnt
                FROM chat_messages
                WHERE sender_id != ? AND is_read = 0 AND status = 'active'
                GROUP BY chat_id
            ) ur ON ur.chat_id = c.chat_id
            LEFT JOIN chat_messages lm ON lm.message_id = (
                SELECT MAX(m2.message_id) FROM chat_messages m2
                WHERE m2.chat_id = c.chat_id AND m2.status = 'active'
            )
            WHERE c.chat_type = 'group'
            ORDER BY (lm.sent_at IS NULL), lm.sent_at DESC
        ");
        $stmt->execute([$userId, $userId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    // ── Wiadomości ─────────────────────────────────────────────

    public function deleteMessage(int $messageId, int $requestingUserId, bool $isAdmin = false): bool {
        if ($isAdmin) {
            $stmt = $this->pdo->prepare(
                "UPDATE chat_messages SET status = 'deleted' WHERE message_id = ?"
            );
            return $stmt->execute([$messageId]);
        }
        $stmt = $this->pdo->prepare(
            "UPDATE chat_messages SET status = 'deleted' WHERE message_id = ? AND sender_id = ?"
        );
        return $stmt->execute([$messageId, $requestingUserId]);
    }
}
