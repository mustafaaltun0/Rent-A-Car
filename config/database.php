<?php
require_once __DIR__ . '/app.php';

if (!function_exists('app_db_config')) {
    function app_db_config(): array
    {
        return [
            'host' => (string) app_env_value('DB_HOST', 'localhost'),
            'name' => (string) app_env_value('DB_NAME', 'rentacar_db'),
            'user' => (string) app_env_value('DB_USER', 'root'),
            'pass' => (string) app_env_value('DB_PASS', ''),
            'charset' => (string) app_env_value('DB_CHARSET', 'utf8mb4'),
        ];
    }
}

if (!function_exists('app_db_dsn')) {
    function app_db_dsn(array $config): string
    {
        return sprintf(
            'mysql:host=%s;dbname=%s;charset=%s',
            $config['host'],
            $config['name'],
            $config['charset']
        );
    }
}

if (!function_exists('app_db_options')) {
    function app_db_options(): array
    {
        return [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
            PDO::ATTR_STRINGIFY_FETCHES => false,
        ];
    }
}

$appEnv = app_env();
ini_set('display_errors', in_array($appEnv, ['local', 'development'], true) ? '1' : '0');
ini_set('log_errors', '1');

$dbConfig = app_db_config();
$dsn = app_db_dsn($dbConfig);
$options = app_db_options();

try {
    $pdo = new PDO($dsn, $dbConfig['user'], $dbConfig['pass'], $options);
    require_once __DIR__ . '/../includes/auth.php';
    auth_bootstrap($pdo);
} catch (PDOException $e) {
    error_log('[rentecarWeb] Veritabani baglanti hatasi: ' . $e->getMessage());
    http_response_code(500);
    exit('Sistem baglantisinda bir sorun olustu. Lutfen daha sonra tekrar deneyin.');
}
