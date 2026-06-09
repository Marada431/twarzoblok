<?php
$_eh_env_file = __DIR__ . '/../.env';
$_eh_env = file_exists($_eh_env_file) ? parse_ini_file($_eh_env_file) : [];
$_eh_isProduction = ($_eh_env['APP_ENV'] ?? 'production') === 'production';

if ($_eh_isProduction) {
    ini_set('display_errors', 0);
    ini_set('display_startup_errors', 0);
    error_reporting(0);
    ini_set('log_errors', 1);
    ini_set('error_log', __DIR__ . '/../logs/php_errors.log');
} else {
    ini_set('display_errors', 1);
    ini_set('display_startup_errors', 1);
    error_reporting(E_ALL);
}

unset($_eh_env_file, $_eh_env, $_eh_isProduction);
