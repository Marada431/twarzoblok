<?php
require_once dirname(__DIR__) . '/middleware.php';
require_once dirname(__DIR__) . '/helpers.php';

if (!can('system_settings')) admin_403();

$pdo = db();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_verify()) admin_403('Nieprawidłowy token CSRF');

    $allowed = ['site_name','registration_open','max_post_images','maintenance_mode'];
    foreach ($allowed as $key) {
        if (isset($_POST[$key])) {
            saveSetting($key, trim($_POST[$key]));
        }
    }
    logModAction((int)$_SESSION['user_id'], 'update_settings', 'report', 0, 'Aktualizacja ustawień systemu');
    flashSet('success', 'Ustawienia zapisane.');
    header('Location: ' . BASE_URL . '/admin/settings/');
    exit;
}

// Pobierz aktualne ustawienia
$settings = [
    'site_name'          => getSetting('site_name', 'TwarzBlok'),
    'registration_open'  => getSetting('registration_open', '1'),
    'max_post_images'    => getSetting('max_post_images', '10'),
    'maintenance_mode'   => getSetting('maintenance_mode', '0'),
];

$page_title  = 'Ustawienia systemu';
$active_page = 'settings';
require_once dirname(__DIR__) . '/components/layout_start.php';
?>

<div class="admin-page-header">
    <h1>⚙️ Ustawienia systemu</h1>
</div>

<div class="card" style="max-width:560px">
    <form method="POST">
        <?= csrf_field() ?>

        <div class="admin-form-group">
            <label>Nazwa serwisu</label>
            <input type="text" name="site_name" value="<?= htmlspecialchars($settings['site_name']) ?>" required maxlength="100">
        </div>

        <div class="admin-form-group">
            <label>Rejestracja otwarta</label>
            <select name="registration_open">
                <option value="1" <?= $settings['registration_open']==='1'?'selected':'' ?>>Tak – rejestracja aktywna</option>
                <option value="0" <?= $settings['registration_open']==='0'?'selected':'' ?>>Nie – zablokuj nowe konta</option>
            </select>
        </div>

        <div class="admin-form-group">
            <label>Maks. zdjęć na post</label>
            <input type="number" name="max_post_images" value="<?= (int)$settings['max_post_images'] ?>" min="1" max="20" style="max-width:80px">
        </div>

        <div class="admin-form-group">
            <label>Tryb konserwacji</label>
            <select name="maintenance_mode">
                <option value="0" <?= $settings['maintenance_mode']==='0'?'selected':'' ?>>Wyłączony</option>
                <option value="1" <?= $settings['maintenance_mode']==='1'?'selected':'' ?>>Włączony – serwis niedostępny dla gości</option>
            </select>
        </div>

        <button type="submit" class="btn-sm btn-primary btn-lg">💾 Zapisz ustawienia</button>
    </form>
</div>

<?php if ($settings['maintenance_mode'] === '1'): ?>
    <div class="alert alert-warning">⚠️ Tryb konserwacji jest WŁĄCZONY. Niezalogowani użytkownicy widzą stronę przerwy technicznej.</div>
<?php endif; ?>

<?php require_once dirname(__DIR__) . '/components/layout_end.php'; ?>
