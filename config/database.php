<?php
define('DB_HOST', 'localhost');
define('DB_NAME', 'twarzobok');
define('DB_USER', 'root');     // Zmień na swojego użytkownika
define('DB_PASS', '');         // Zmień na swoje hasło

class Database {
    private static $instance = null;
    private $connection;

    private function __construct() {
        try {
            $this->connection = new PDO(
                "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=utf8mb4",
                DB_USER,
                DB_PASS,
                [
                    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                    PDO::ATTR_EMULATE_PREPARES => false
                ]
            );
        } catch (PDOException $e) {
            die("Błąd połączenia z bazą danych: " . $e->getMessage());
        }
    }

    public static function getInstance() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    public function getConnection() {
        return $this->connection;
    }
}

// Funkcja pomocnicza do szybkiego dostępu do bazy
function db() {
    return Database::getInstance()->getConnection();
}
// ... na końcu Twojego config.php
define('SOCKET_SECRET', 'bardzo_tajny_klucz_zmien_go');
?>