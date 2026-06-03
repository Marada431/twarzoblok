<?php
// admin/helpers.php — funkcje pomocnicze panelu

function logModAction(int $moderator_id, string $action_type, string $target_type, int $target_id, ?string $note = null): void {
    try {
        db()->prepare("INSERT INTO mod_logs (moderator_id, action_type, target_type, target_id, note, created_at) VALUES (:mid,:at,:tt,:tid,:note,NOW())")
            ->execute([':mid' => $moderator_id, ':at' => $action_type, ':tt' => $target_type, ':tid' => $target_id, ':note' => $note]);
    } catch (PDOException $e) {
        error_log('logModAction: ' . $e->getMessage());
    }
}

function getSetting(string $key, string $default = ''): string {
    try {
        $s = db()->prepare("SELECT value FROM settings WHERE key_name = :k");
        $s->execute([':k' => $key]);
        $v = $s->fetchColumn();
        return $v !== false ? $v : $default;
    } catch (PDOException $e) {
        return $default;
    }
}

function saveSetting(string $key, string $value): void {
    try {
        db()->prepare("INSERT INTO settings (key_name, value) VALUES (:k, :v) ON DUPLICATE KEY UPDATE value = :v2, updated_at = NOW()")
            ->execute([':k' => $key, ':v' => $value, ':v2' => $value]);
    } catch (PDOException $e) {
        error_log('saveSetting: ' . $e->getMessage());
    }
}

function newReportsCount(): int {
    try {
        return (int)db()->query("SELECT COUNT(*) FROM reports WHERE status = 'new'")->fetchColumn();
    } catch (PDOException $e) { return 0; }
}

function fmtDate(?string $dt): string {
    if (!$dt) return '—';
    return date('d.m.Y H:i', strtotime($dt));
}

function flashSet(string $key, string $msg): void {
    $_SESSION['admin_flash'][$key] = $msg;
}

function flashGet(string $key): ?string {
    if (isset($_SESSION['admin_flash'][$key])) {
        $m = $_SESSION['admin_flash'][$key];
        unset($_SESSION['admin_flash'][$key]);
        return $m;
    }
    return null;
}

function statusBadge(string $status): string {
    $map = [
        'new'         => ['Nowe',        'bdg-new'],
        'in_progress' => ['W toku',      'bdg-prog'],
        'resolved'    => ['Rozpatrzone', 'bdg-ok'],
        'rejected'    => ['Odrzucone',   'bdg-rej'],
        'active'      => ['Aktywny',     'bdg-ok'],
        'blocked'     => ['Zablokowany', 'bdg-err'],
        'pending'     => ['Oczekujący',  'bdg-prog'],
        'user'        => ['Użytkownik',  'bdg-usr'],
        'moderator'   => ['Moderator',   'bdg-mod'],
        'admin'       => ['Admin',       'bdg-adm'],
        'post'        => ['Post',        'bdg-usr'],
        'comment'     => ['Komentarz',   'bdg-usr'],
        'profile'     => ['Profil',      'bdg-mod'],
        'message'     => ['Wiadomość',   'bdg-prog'],
    ];
    $d = $map[$status] ?? [htmlspecialchars($status), 'bdg-usr'];
    return "<span class=\"badge {$d[1]}\">{$d[0]}</span>";
}

function paginationLinks(int $total, int $per_page, int $current_page, string $base_url): string {
    $pages = (int)ceil($total / $per_page);
    if ($pages <= 1) return '';
    $sep = strpos($base_url, '?') !== false ? '&' : '?';
    $html = '<div class="pagination">';
    for ($i = 1; $i <= $pages; $i++) {
        $cls = $i === $current_page ? ' class="active"' : '';
        $html .= "<a href=\"{$base_url}{$sep}page={$i}\"{$cls}>$i</a> ";
    }
    $html .= '</div>';
    return $html;
}
