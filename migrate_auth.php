<?php
// migrate_auth.php – Migracja bazy danych dla systemu MVC Auth
// Uruchom JEDNORAZOWO przez przeglądarkę: http://localhost/Troll%202026/twarzoblok-main/migrate_auth.php
// Po udanej migracji usuń ten plik lub zabezpiecz go hasłem.

define('MIGRATION_KEY', 'twarzoblok-migrate-2026');
if (($_GET['key'] ?? '') !== MIGRATION_KEY) {
    die('Brak klucza. Użyj: ?key=' . MIGRATION_KEY);
}

require_once __DIR__ . '/config/database.php';
$pdo = db();

$results = [];

function run(PDO $pdo, string $label, string $sql): void {
    global $results;
    try {
        $pdo->exec($sql);
        $results[] = "✅ {$label}";
    } catch (PDOException $e) {
        $results[] = "⚠️  {$label} – " . $e->getMessage();
    }
}

// ── Sprawdź istniejące kolumny ────────────────────────────
$existing = [];
$stmt = $pdo->query("DESCRIBE users");
while ($row = $stmt->fetch()) {
    $existing[] = $row['Field'];
}

// ── Dodaj brakujące kolumny ───────────────────────────────

if (!in_array('terms_accepted', $existing)) {
    run($pdo, 'Dodanie terms_accepted',
        "ALTER TABLE users
         ADD COLUMN terms_accepted TINYINT(1) NOT NULL DEFAULT 0
         AFTER gender"
    );
} else {
    $results[] = "ℹ️  terms_accepted – już istnieje";
}

if (!in_array('terms_accepted_at', $existing)) {
    run($pdo, 'Dodanie terms_accepted_at',
        "ALTER TABLE users
         ADD COLUMN terms_accepted_at DATETIME NULL DEFAULT NULL
         AFTER terms_accepted"
    );
} else {
    $results[] = "ℹ️  terms_accepted_at – już istnieje";
}

// ── Indeks dla tokenu weryfikacyjnego ─────────────────────
try {
    $pdo->exec("CREATE INDEX idx_verification_token ON users (verification_token)");
    $results[] = "✅ Indeks idx_verification_token – dodany";
} catch (PDOException $e) {
    $results[] = "ℹ️  Indeks idx_verification_token – " . $e->getMessage();
}

// ── Oznacz istniejących użytkowników jako zweryfikowanych ─
run($pdo, 'Oznaczenie is_verified=1 dla istniejących kont',
    "UPDATE users
     SET is_verified = 1,
         terms_accepted = 1,
         terms_accepted_at = COALESCE(created_at, NOW())
     WHERE is_verified = 0"
);

// ── Wyniki ────────────────────────────────────────────────
echo "<!DOCTYPE html><html lang='pl'><head><meta charset='UTF-8'><title>Migracja</title></head><body>";
echo "<h2>Wyniki migracji</h2><ul>";
foreach ($results as $r) {
    echo "<li>{$r}</li>";
}
echo "</ul>";

// Pokaż aktualną strukturę
echo "<h3>Aktualna struktura tabeli users:</h3><table border='1' cellpadding='6'>";
echo "<tr><th>Field</th><th>Type</th><th>Null</th><th>Default</th></tr>";
$stmt = $pdo->query("DESCRIBE users");
while ($row = $stmt->fetch()) {
    echo "<tr><td>{$row['Field']}</td><td>{$row['Type']}</td>"
       . "<td>{$row['Null']}</td><td>{$row['Default']}</td></tr>";
}
echo "</table>";
echo "<p><strong>Po weryfikacji poprawności – usuń ten plik!</strong></p>";
echo "</body></html>";
