<?php
if (!defined('ERROR_HANDLER_LOADED')) {
    require_once __DIR__ . '/error_handler.php';
    define('ERROR_HANDLER_LOADED', true);
}
$_env_file = __DIR__ . '/../.env';
if (!file_exists($_env_file)) {
    error_log('Brak pliku .env: ' . $_env_file);
    die('Wystąpił błąd techniczny. Spróbuj ponownie później.');
}
$_env = parse_ini_file($_env_file);

define('DB_HOST',       $_env['DB_HOST']      ?? 'localhost');
define('DB_NAME',       $_env['DB_NAME']      ?? '');
define('DB_USER',       $_env['DB_USER']      ?? 'root');
define('DB_PASS',       $_env['DB_PASS']      ?? '');
define('DB_CHARSET',    $_env['DB_CHARSET']   ?? 'utf8mb4');
define('SOCKET_SECRET', $_env['SOCKET_SECRET'] ?? '');

unset($_env_file, $_env);

class Database {
    private static $instance = null;
    private $connection;

    private function __construct() {
        try {
            $this->connection = new PDO(
                "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=" . DB_CHARSET,
                DB_USER,
                DB_PASS,
                [
                    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                    PDO::ATTR_EMULATE_PREPARES   => false,
                ]
            );
        } catch (PDOException $e) {
            error_log('DB Connection Error: ' . $e->getMessage());
            die('Wystąpił błąd techniczny. Spróbuj ponownie później.');
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

function db() {
    return Database::getInstance()->getConnection();
}
