<?php
// ============================================================
//  settings.php – Panel ustawień TwarzBlok
//  Zakładki: profil | adres | konto (usunięcie)
// ============================================================

session_start();
require 'config/database.php';  // udostępnia db() → PDO

// ── OCHRONA ──────────────────────────────────────────────────
if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}
$user_id = (int) $_SESSION['user_id'];

// ── ROUTING ──────────────────────────────────────────────────
$allowed_pages = ['profil', 'adres', 'konto'];
$page = in_array($_GET['page'] ?? '', $allowed_pages) ? $_GET['page'] : 'profil';

// ── KOMUNIKATY ───────────────────────────────────────────────
$success = '';
$error   = '';

// ── HELPER: escape HTML ──────────────────────────────────────
function h(string $s): string { return htmlspecialchars($s, ENT_QUOTES, 'UTF-8'); }

// ── HELPER: katalog uploadów ─────────────────────────────────
define('UPLOAD_DIR', __DIR__ . '/uploads/avatars/');
define('UPLOAD_URL', 'uploads/avatars/');

// ============================================================
//  OBSŁUGA POST
// ============================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    // ────────────────────────────────────────────────────────
    //  POST: PROFIL
    // ────────────────────────────────────────────────────────
    if (isset($_POST['save_profile'])) {

        $first_name    = trim($_POST['first_name']    ?? '');
        $last_name     = trim($_POST['last_name']     ?? '');
        $bio           = trim($_POST['bio']           ?? '');
        $email         = trim($_POST['email']         ?? '');
        $phone         = trim($_POST['phone']         ?? '');
        $city          = trim($_POST['city']          ?? '');
        $dob           = trim($_POST['dob']           ?? '');
        $gender        = trim($_POST['gender']        ?? 'None');
        $privacy_level = trim($_POST['privacy_level'] ?? 'public');

        $valid_genders  = ['M', 'F', 'Other', 'None'];
        $valid_privacy  = ['public', 'private', 'custom'];

        $errs = [];
        if ($first_name === '')                              $errs[] = 'Imię jest wymagane.';
        if ($last_name === '')                               $errs[] = 'Nazwisko jest wymagane.';
        if (!filter_var($email, FILTER_VALIDATE_EMAIL))     $errs[] = 'Podaj prawidłowy adres e-mail.';
        if ($phone !== '' && !preg_match('/^[\d\s\+\-\(\)]{7,20}$/', $phone))
                                                             $errs[] = 'Nieprawidłowy numer telefonu.';
        if ($dob !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $dob))
                                                             $errs[] = 'Nieprawidłowy format daty urodzenia.';
        if (!in_array($gender, $valid_genders))              $errs[] = 'Nieprawidłowa wartość płci.';
        if (!in_array($privacy_level, $valid_privacy))       $errs[] = 'Nieprawidłowy typ widoczności profilu.';

        // ── Upload avatara ────────────────────────────────────
        $avatar_url = null;   // null = bez zmian
        if (!empty($_FILES['avatar']['name'])) {
            $file     = $_FILES['avatar'];
            $allowed  = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];
            $maxSize  = 3 * 1024 * 1024; // 3 MB

            $finfo    = new finfo(FILEINFO_MIME_TYPE);
            $mimeType = $finfo->file($file['tmp_name']);

            if ($file['error'] !== UPLOAD_ERR_OK)       $errs[] = 'Błąd przesyłania pliku.';
            elseif (!in_array($mimeType, $allowed))     $errs[] = 'Dozwolone formaty: JPG, PNG, GIF, WEBP.';
            elseif ($file['size'] > $maxSize)           $errs[] = 'Zdjęcie nie może przekraczać 3 MB.';
            else {
                if (!is_dir(UPLOAD_DIR)) mkdir(UPLOAD_DIR, 0755, true);
                $ext      = pathinfo($file['name'], PATHINFO_EXTENSION);
                $filename = 'avatar_' . $user_id . '_' . time() . '.' . strtolower($ext);
                $destPath = UPLOAD_DIR . $filename;

                if (move_uploaded_file($file['tmp_name'], $destPath)) {
                    $avatar_url = UPLOAD_URL . $filename;

                    // Usuń stare zdjęcie (jeśli istnieje i jest lokalne)
                    $oldQ = db()->prepare("SELECT avatar_url FROM users WHERE user_id = ?");
                    $oldQ->execute([$user_id]);
                    $oldRow = $oldQ->fetch();
                    if ($oldRow && $oldRow['avatar_url'] && file_exists(__DIR__ . '/' . $oldRow['avatar_url'])) {
                        @unlink(__DIR__ . '/' . $oldRow['avatar_url']);
                    }
                } else {
                    $errs[] = 'Nie udało się zapisać zdjęcia na serwerze.';
                }
            }
        }

        if ($errs) {
            $error = implode(' ', $errs);
        } else {
            try {
                if ($avatar_url !== null) {
                    $stmt = db()->prepare(
                        "UPDATE users SET first_name=:fn, last_name=:ln, bio=:bio, email=:email,
                         phone=:phone, city=:city, dob=:dob, gender=:gender,
                         privacy_level=:privacy, avatar_url=:avatar
                         WHERE user_id=:uid"
                    );
                    $stmt->execute([
                        ':fn'      => $first_name,   ':ln'     => $last_name,
                        ':bio'     => $bio,           ':email'  => $email,
                        ':phone'   => $phone,         ':city'   => $city,
                        ':dob'     => $dob ?: null,   ':gender' => $gender,
                        ':privacy' => $privacy_level, ':avatar' => $avatar_url,
                        ':uid'     => $user_id,
                    ]);
                } else {
                    $stmt = db()->prepare(
                        "UPDATE users SET first_name=:fn, last_name=:ln, bio=:bio, email=:email,
                         phone=:phone, city=:city, dob=:dob, gender=:gender,
                         privacy_level=:privacy
                         WHERE user_id=:uid"
                    );
                    $stmt->execute([
                        ':fn'      => $first_name,   ':ln'     => $last_name,
                        ':bio'     => $bio,           ':email'  => $email,
                        ':phone'   => $phone,         ':city'   => $city,
                        ':dob'     => $dob ?: null,   ':gender' => $gender,
                        ':privacy' => $privacy_level,
                        ':uid'     => $user_id,
                    ]);
                }
                $success = 'Dane profilu zostały zaktualizowane.';
            } catch (PDOException $e) {
                $error = 'Błąd zapisu danych. Spróbuj ponownie.';
            }
        }
    }

    // ────────────────────────────────────────────────────────
    //  POST: ADRES
    // ────────────────────────────────────────────────────────
    if (isset($_POST['save_address'])) {

        $country      = trim($_POST['country']      ?? '');
        $state        = trim($_POST['state']        ?? '');
        $postal_code  = trim($_POST['postal_code']  ?? '');
        $municipality = trim($_POST['municipality'] ?? '');
        $city         = trim($_POST['city']         ?? '');
        $street       = trim($_POST['street']       ?? '');
        $house_number = trim($_POST['house_number'] ?? '');

        $errs = [];
        if ($country === '')                               $errs[] = 'Kraj jest wymagany.';
        if (!preg_match('/^\d{2}-\d{3}$/', $postal_code)) $errs[] = 'Kod pocztowy musi być w formacie XX-XXX.';
        if ($city === '')                                  $errs[] = 'Miejscowość jest wymagana.';
        if ($street === '')                                $errs[] = 'Ulica jest wymagana.';
        if ($house_number === '')                          $errs[] = 'Numer domu jest wymagany.';

        if ($errs) {
            $error = implode(' ', $errs);
        } else {
            try {
                $chk = db()->prepare("SELECT address_id FROM addresses WHERE user_id = ? LIMIT 1");
                $chk->execute([$user_id]);
                $exists = (bool) $chk->fetch();

                $sql = $exists
                    ? "UPDATE addresses SET country=:co,state=:st,postal_code=:pc,municipality=:mu,city=:ci,street=:sr,house_number=:hn WHERE user_id=:uid"
                    : "INSERT INTO addresses (country,state,postal_code,municipality,city,street,house_number,user_id) VALUES (:co,:st,:pc,:mu,:ci,:sr,:hn,:uid)";

                db()->prepare($sql)->execute([
                    ':co' => $country, ':st' => $state,   ':pc' => $postal_code,
                    ':mu' => $municipality,               ':ci' => $city,
                    ':sr' => $street,  ':hn' => $house_number, ':uid' => $user_id,
                ]);
                $success = 'Dane adresowe zostały zapisane pomyślnie.';
            } catch (PDOException $e) {
                $error = 'Błąd podczas zapisu danych. Spróbuj ponownie.';
            }
        }
    }

    // ────────────────────────────────────────────────────────
    //  POST: USUNIĘCIE KONTA
    // ────────────────────────────────────────────────────────
    if (isset($_POST['delete_account'])) {

        $confirm_pass  = $_POST['confirm_password']  ?? '';
        $confirm_check = $_POST['confirm_delete']    ?? '';

        if ($confirm_check !== 'on') {
            $error = 'Musisz zaznaczyć potwierdzenie chęci usunięcia konta.';
        } elseif (empty($confirm_pass)) {
            $error = 'Podaj swoje hasło, aby potwierdzić usunięcie konta.';
        } else {
            try {
                $q = db()->prepare("SELECT password_hash FROM users WHERE user_id = ?");
                $q->execute([$user_id]);
                $row = $q->fetch();

                if (!$row || !password_verify($confirm_pass, $row['password_hash'])) {
                    $error = 'Podane hasło jest nieprawidłowe.';
                } else {
                    // Soft-delete: zmień status na 'blocked', wyczyść dane osobowe
                    // Jeśli chcesz trwałego usunięcia, zamień na DELETE FROM users WHERE user_id=?
                    db()->prepare(
                        "UPDATE users
                         SET status='blocked', first_name='[Usunięty]', last_name='',
                             email=CONCAT('deleted_', user_id, '@removed.invalid'),
                             phone=NULL, bio=NULL, avatar_url=NULL
                         WHERE user_id=?"
                    )->execute([$user_id]);

                    // Wyloguj
                    session_destroy();
                    header('Location: login.php?deleted=1');
                    exit;
                }
            } catch (PDOException $e) {
                $error = 'Błąd podczas usuwania konta. Spróbuj ponownie.';
            }
        }
    }
}

// ============================================================
//  WCZYTAJ AKTUALNE DANE
// ============================================================
$prof_defaults = [
    'first_name'=>'','last_name'=>'','bio'=>'','email'=>'','phone'=>'',
    'city'=>'','dob'=>'','gender'=>'None','avatar_url'=>'','privacy_level'=>'public',
];
$q = db()->prepare(
    "SELECT first_name,last_name,bio,email,phone,city,dob,gender,avatar_url,privacy_level
     FROM users WHERE user_id=? LIMIT 1"
);
$q->execute([$user_id]);
$prof = $q->fetch() ?: $prof_defaults;

$addr_defaults = ['country'=>'','state'=>'','postal_code'=>'','municipality'=>'','city'=>'','street'=>'','house_number'=>''];
$q2 = db()->prepare(
    "SELECT country,state,postal_code,municipality,city,street,house_number
     FROM addresses WHERE user_id=? LIMIT 1"
);
$q2->execute([$user_id]);
$addr = $q2->fetch() ?: $addr_defaults;

// Przywróć wpisane wartości po błędzie walidacji
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $error) {
    if ($page === 'profil') $prof = array_merge($prof, array_intersect_key($_POST, $prof_defaults));
    if ($page === 'adres')  $addr = array_merge($addr, array_intersect_key($_POST, $addr_defaults));
}
?>
<!DOCTYPE html>
<html lang="pl">
<head>
<meta charset="UTF-8"/>
<meta name="viewport" content="width=device-width, initial-scale=1.0"/>
<title>TwarzBlok – Ustawienia</title>
    <link rel="stylesheet" href="assets/css/settings.css">
</head>
<body>

<!-- NAVBAR -->
<nav class="navbar">
    <a href="index.php" class="navbar-brand">TwarzBlok</a>
    <span class="navbar-user"><?= h($prof['first_name'] . ' ' . $prof['last_name']) ?></span>
</nav>

<div class="settings-layout">

    <!-- SIDEBAR -->
    <nav class="settings-nav">
        <h2>Ustawienia</h2>

        <a href="?page=profil" class="nav-item <?= $page==='profil' ? 'active' : '' ?>">
            <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                <path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/>
            </svg>
            Profil
        </a>

        <a href="?page=adres" class="nav-item <?= $page==='adres' ? 'active' : '' ?>">
            <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                <path d="M21 10c0 7-9 13-9 13s-9-6-9-13a9 9 0 0 1 18 0z"/><circle cx="12" cy="10" r="3"/>
            </svg>
            Dane adresowe
        </a>

        <a href="?page=konto" class="nav-item danger <?= $page==='konto' ? 'active' : '' ?>">
            <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                <polyline points="3 6 5 6 21 6"/><path d="M19 6l-1 14H6L5 6"/><path d="M10 11v6"/><path d="M14 11v6"/>
                <path d="M9 6V4h6v2"/>
            </svg>
            Usuń konto
        </a>
    </nav>

    <!-- MAIN -->
    <main>

    <?php /* ════════════════════════ PROFIL ════════════════════════ */ ?>
    <?php if ($page === 'profil'): ?>
    <form method="post" action="?page=profil" enctype="multipart/form-data" novalidate>
    <div class="card">

        <div class="card-header">
            <h1>Profil użytkownika</h1>
            <p>Zarządzaj danymi widocznymi dla innych użytkowników.</p>
        </div>

        <?php if ($success): ?>
        <div class="alert alert-success">
            <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/></svg>
            <?= h($success) ?>
        </div>
        <?php elseif ($error): ?>
        <div class="alert alert-error">
            <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
            <?= h($error) ?>
        </div>
        <?php endif; ?>

        <!-- Zdjęcie profilowe -->
        <div class="form-section">
            <div class="section-label">Zdjęcie profilowe</div>
            <div class="avatar-upload-row">
                <img
                    id="avatarPreview"
                    class="avatar-preview"
                    src="<?= $prof['avatar_url'] ? h($prof['avatar_url']) : 'https://ui-avatars.com/api/?name=' . urlencode($prof['first_name'].' '.$prof['last_name']) . '&background=338336&color=fff&size=80' ?>"
                    alt="Avatar"
                />
                <div class="avatar-upload-meta">
                    <label for="avatar" class="btn">
                        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="17 8 12 3 7 8"/><line x1="12" y1="3" x2="12" y2="15"/></svg>
                        Zmień zdjęcie
                    </label>
                    <input type="file" id="avatar" name="avatar" accept="image/jpeg,image/png,image/gif,image/webp">
                    <span class="avatar-filename" id="avatarFilename">JPG, PNG, GIF lub WEBP · maks. 3 MB</span>
                </div>
            </div>
        </div>

        <!-- Dane osobowe -->
        <div class="form-section">
            <div class="section-label">Dane osobowe</div>
            <div class="grid cols-2" style="margin-bottom:14px">
                <div class="form-group">
                    <label class="form-label" for="first_name">Imię <span class="req">*</span></label>
                    <input type="text" id="first_name" name="first_name" class="form-input"
                           maxlength="50" value="<?= h($prof['first_name']) ?>" required>
                </div>
                <div class="form-group">
                    <label class="form-label" for="last_name">Nazwisko <span class="req">*</span></label>
                    <input type="text" id="last_name" name="last_name" class="form-input"
                           maxlength="50" value="<?= h($prof['last_name']) ?>" required>
                </div>
            </div>
            <div class="grid cols-2" style="margin-bottom:14px">
                <div class="form-group">
                    <label class="form-label" for="dob">Data urodzenia</label>
                    <input type="date" id="dob" name="dob" class="form-input"
                           value="<?= h($prof['dob']) ?>">
                </div>
                <div class="form-group">
                    <label class="form-label" for="gender">Płeć</label>
                    <select id="gender" name="gender" class="form-select">
                        <?php foreach (['None'=>'Nie podano','M'=>'Mężczyzna','F'=>'Kobieta','Other'=>'Inna'] as $val=>$lbl): ?>
                        <option value="<?= $val ?>" <?= $prof['gender']===$val?'selected':'' ?>><?= $lbl ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>
            <div class="grid cols-1">
                <div class="form-group">
                    <label class="form-label" for="bio">O mnie</label>
                    <textarea id="bio" name="bio" class="form-textarea" maxlength="500"
                              placeholder="Kilka słów o sobie…"><?= h($prof['bio'] ?? '') ?></textarea>
                    <span class="field-hint">Maks. 500 znaków.</span>
                </div>
            </div>
        </div>

        <!-- Kontakt -->
        <div class="form-section">
            <div class="section-label">Kontakt</div>
            <div class="grid cols-2" style="margin-bottom:14px">
                <div class="form-group">
                    <label class="form-label" for="email">E-mail <span class="req">*</span></label>
                    <input type="email" id="email" name="email" class="form-input"
                           maxlength="100" value="<?= h($prof['email']) ?>" required>
                </div>
                <div class="form-group">
                    <label class="form-label" for="phone">Telefon</label>
                    <input type="tel" id="phone" name="phone" class="form-input"
                           maxlength="20" placeholder="+48 600 123 456" value="<?= h($prof['phone'] ?? '') ?>">
                </div>
            </div>
            <div class="grid cols-1">
                <div class="form-group">
                    <label class="form-label" for="city_profile">Miasto</label>
                    <input type="text" id="city_profile" name="city" class="form-input"
                           maxlength="100" placeholder="np. Poznań" value="<?= h($prof['city'] ?? '') ?>">
                </div>
            </div>
        </div>

        <!-- Widoczność profilu -->
        <div class="form-section">
            <div class="section-label">Widoczność profilu</div>
            <div class="privay-group">
                <?php
                $privacyOptions = [
                    'public'  => ['<svg><use xlink:href="./icons/symbol-defs.svg#icon-home"></use></svg>', 'Publiczny',  'Widoczny dla wszystkich'],
                    'custom'  => ['<svg><use xlink:href="./icons/symbol-defs.svg#icon-home"></use></svg>', 'Znajomi',    'Widoczny tylko dla znajomych'],
                    'private' => ['<svg><use xlink:href="./icons/symbol-defs.svg#icon-home"></use></svg>', 'Prywatny',   'Widoczny tylko dla Ciebie'],
                ];
                foreach ($privacyOptions as $val => [$icon, $title, $desc]):
                    $checked = $prof['privacy_level'] === $val ? 'checked' : '';
                ?>
                <label class="privacy-card">
                    <input type="radio" name="privacy_level" value="<?= $val ?>" <?= $checked ?>>
                    <span class="privacy-card-icon"><?= $icon ?></span>
                    <span class="privacy-card-title"><?= $title ?></span>
                    <span class="privacy-card-desc"><?= $desc ?></span>
                </label>
                <?php endforeach; ?>
            </div>
        </div>

        <div class="card-footer">
            <button type="reset" class="btn btn-secondary">Cofnij zmiany</button>
            <button type="submit" name="save_profile" class="btn btn-primary">Zapisz profil</button>
        </div>

    </div>
    </form>

    <?php /* ════════════════════════ ADRES ════════════════════════ */ ?>
    <?php elseif ($page === 'adres'): ?>
    <form method="post" action="?page=adres" novalidate>
    <div class="card">

        <div class="card-header">
            <h1>Dane adresowe</h1>
            <p>Zaktualizuj swój adres zamieszkania lub korespondencyjny.</p>
        </div>

        <?php if ($success): ?>
        <div class="alert alert-success">
            <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/></svg>
            <?= h($success) ?>
        </div>
        <?php elseif ($error): ?>
        <div class="alert alert-error">
            <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
            <?= h($error) ?>
        </div>
        <?php endif; ?>

        <div class="form-section">
            <div class="section-label">Kraj i region</div>
            <div class="grid cols-2">
                <div class="form-group">
                    <label class="form-label" for="country">Kraj <span class="req">*</span></label>
                    <input type="text" id="country" name="country" class="form-input"
                           placeholder="np. Polska" maxlength="100" value="<?= h($addr['country']) ?>" required>
                </div>
                <div class="form-group">
                    <label class="form-label" for="state">Województwo</label>
                    <input type="text" id="state" name="state" class="form-input"
                           placeholder="np. Wielkopolskie" maxlength="100" value="<?= h($addr['state']) ?>">
                </div>
            </div>
        </div>

        <div class="form-section">
            <div class="section-label">Kod pocztowy i gmina</div>
            <div class="grid cols-2">
                <div class="form-group">
                    <label class="form-label" for="postal_code">Kod pocztowy <span class="req">*</span></label>
                    <input type="text" id="postal_code" name="postal_code" class="form-input"
                           placeholder="XX-XXX" maxlength="6" value="<?= h($addr['postal_code']) ?>" required>
                    <span class="field-hint">Format: XX-XXX (np. 64-510)</span>
                </div>
                <div class="form-group">
                    <label class="form-label" for="municipality">Gmina</label>
                    <input type="text" id="municipality" name="municipality" class="form-input"
                           placeholder="np. Gmina Szamotuły" maxlength="100" value="<?= h($addr['municipality']) ?>">
                </div>
            </div>
        </div>

        <div class="form-section">
            <div class="section-label">Miejscowość i ulica</div>
            <div class="grid cols-1" style="margin-bottom:14px">
                <div class="form-group">
                    <label class="form-label" for="city">Miejscowość <span class="req">*</span></label>
                    <input type="text" id="city" name="city" class="form-input"
                           placeholder="np. Nowa Wieś" maxlength="100" value="<?= h($addr['city']) ?>" required>
                </div>
            </div>
            <div class="grid cols-21">
                <div class="form-group">
                    <label class="form-label" for="street">Ulica <span class="req">*</span></label>
                    <input type="text" id="street" name="street" class="form-input"
                           placeholder="np. ul. Szkolna" maxlength="100" value="<?= h($addr['street']) ?>" required>
                </div>
                <div class="form-group">
                    <label class="form-label" for="house_number">Nr domu/lok. <span class="req">*</span></label>
                    <input type="text" id="house_number" name="house_number" class="form-input"
                           placeholder="np. 41 lub 41/3" maxlength="20" value="<?= h($addr['house_number']) ?>" required>
                </div>
            </div>
        </div>

        <div class="card-footer">
            <button type="reset" class="btn btn-secondary">Cofnij zmiany</button>
            <button type="submit" name="save_address" class="btn btn-primary">Zapisz adres</button>
        </div>

    </div>
    </form>

    <?php /* ════════════════════════ KONTO ════════════════════════ */ ?>
    <?php elseif ($page === 'konto'): ?>

    <div class="card">
        <div class="card-header">
            <h1>Zarządzanie kontem</h1>
            <p>Nieodwracalne operacje dotyczące Twojego konta.</p>
        </div>

        <?php if ($error): ?>
        <div class="alert alert-error">
            <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
            <?= h($error) ?>
        </div>
        <?php endif; ?>

        <div class="form-section">
            <div class="danger-zone-box">

                <div class="danger-zone-header">
                    <div>
                        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"/><line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/></svg>
                    </div>
                    <div>
                        <h2>Usuń konto</h2>
                        <p>Ta operacja jest nieodwracalna. Twoje dane zostaną trwale usunięte.</p>
                    </div>
                </div>

                <form method="post" action="?page=konto" novalidate>
                <div class="danger-zone-body">

                    <div class="form-group">
                        <label class="form-label" for="confirm_password">Potwierdź hasłem <span class="req">*</span></label>
                        <input type="password" id="confirm_password" name="confirm_password"
                               class="form-input" placeholder="Wpisz aktualne hasło" autocomplete="current-password">
                        <span class="field-hint">Wymagane do weryfikacji tożsamości.</span>
                    </div>

                    <div class="checkbox-row">
                        <input type="checkbox" id="confirm_delete" name="confirm_delete">
                        <label for="confirm_delete">
                            Rozumiem, że usunięcie konta jest <strong>nieodwracalne</strong>
                            i spowoduje utratę wszystkich moich danych, postów oraz historii czatów.
                        </label>
                    </div>

                    <div style="display:flex; justify-content:flex-end;">
                        <button type="submit" name="delete_account" class="btn btn-danger"
                                onclick="return confirm('Czy na pewno chcesz usunąć konto? Tej operacji nie można cofnąć.')">
                            Usuń moje konto
                        </button>
                    </div>

                </div>
                </form>

            </div>
        </div>
    </div>

    <?php endif; ?>
    </main>

</div><!-- /.settings-layout -->

<script>
// ── Avatar: podgląd przed wysłaniem ──────────────────────────
const avatarInput   = document.getElementById('avatar');
const avatarPreview = document.getElementById('avatarPreview');
const avatarFilename = document.getElementById('avatarFilename');

if (avatarInput) {
    avatarInput.addEventListener('change', function () {
        const file = this.files[0];
        if (!file) return;
        avatarFilename.textContent = file.name + ' (' + (file.size / 1024).toFixed(0) + ' KB)';
        const reader = new FileReader();
        reader.onload = e => { avatarPreview.src = e.target.result; };
        reader.readAsDataURL(file);
    });
}

// ── Auto-format kodu pocztowego ──────────────────────────────
const postalInput = document.getElementById('postal_code');
if (postalInput) {
    postalInput.addEventListener('input', function () {
        let v = this.value.replace(/\D/g, '');
        if (v.length > 2) v = v.slice(0, 2) + '-' + v.slice(2, 5);
        this.value = v;
    });
}
</script>
</body>
</html>
