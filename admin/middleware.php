<?php
// admin/middleware.php — dołącz na początku każdego pliku w /admin/

if (session_status() === PHP_SESSION_NONE) session_start();

if (!function_exists('db')) {
    require_once dirname(__DIR__) . '/config/database.php';
}

// Wykryj BASE_URL (działa z dowolnej głębokości w /admin/)
if (!defined('BASE_URL')) {
    $__path = $_SERVER['SCRIPT_NAME'];
    $__pos  = strpos($__path, '/admin');
    define('BASE_URL', $__pos !== false ? rtrim(substr($__path, 0, $__pos), '/') : '');
}
if (!defined('ADMIN_DIR')) {
    define('ADMIN_DIR', __DIR__);
}

// Sprawdź sesję
if (empty($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
    header('Location: ' . BASE_URL . '/login.php');
    exit;
}

// Pobierz aktualną rolę z bazy (zawsze świeżo)
try {
    $__stmt = db()->prepare("SELECT role, status, banned_until, is_banned_permanent FROM users WHERE user_id = :uid LIMIT 1");
    $__stmt->execute([':uid' => (int)$_SESSION['user_id']]);
    $__u = $__stmt->fetch();
} catch (PDOException $e) {
    header('Location: ' . BASE_URL . '/login.php'); exit;
}

if (!$__u || $__u['status'] === 'blocked') {
    session_destroy();
    header('Location: ' . BASE_URL . '/login.php'); exit;
}

$current_role = $__u['role'];
$_SESSION['role'] = $current_role;

if (!in_array($current_role, ['moderator', 'admin'], true)) {
    header('Location: ' . BASE_URL . '/index.php'); exit;
}
unset($__stmt, $__u, $__path, $__pos);

// ── Matryca uprawnień ──────────────────────────────────
function can(string $permission): bool {
    global $current_role;
    static $matrix = [
        'view_reports'    => ['moderator', 'admin'],
        'delete_content'  => ['moderator', 'admin'],
        'ban_user_temp'   => ['moderator', 'admin'],
        'view_dashboard'  => ['admin'],
        'manage_users'    => ['admin'],
        'manage_roles'    => ['admin'],
        'view_mod_logs'   => ['admin'],
        'manage_groups'   => ['admin'],
        'system_settings' => ['admin'],
    ];
    return isset($matrix[$permission]) && in_array($current_role, $matrix[$permission], true);
}

// ── CSRF ──────────────────────────────────────────────
function csrf_token(): string {
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function csrf_field(): string {
    return '<input type="hidden" name="csrf_token" value="' . htmlspecialchars(csrf_token()) . '">';
}

function csrf_verify(): bool {
    return isset($_POST['csrf_token'], $_SESSION['csrf_token'])
        && hash_equals($_SESSION['csrf_token'], $_POST['csrf_token']);
}

// ── 403 helper ────────────────────────────────────────
function admin_403(string $msg = 'Brak uprawnień'): never {
    http_response_code(403);
    $base = BASE_URL;
    header('Content-Type: text/html; charset=utf-8');
    die("<!doctype html><html lang='pl'><head><meta charset='UTF-8'><title>403</title></head><body style='font-family:sans-serif;padding:40px'><h2>403 – $msg</h2><a href='$base/admin/'>← Powrót do panelu</a></body></html>");
}
