<?php
require_once __DIR__ . '/../config/app.php';

if (session_status() === PHP_SESSION_NONE) {
    $isHttps = app_request_is_https();
    $secureCookie = app_session_cookie_secure();

    ini_set('session.use_strict_mode', '1');
    ini_set('session.use_only_cookies', '1');
    ini_set('session.cookie_httponly', '1');
    if ($secureCookie) {
        ini_set('session.cookie_secure', '1');
    } else {
        ini_set('session.cookie_secure', '0');
    }

    session_name('rentecar_session');
    if (PHP_VERSION_ID >= 70300) {
        session_set_cookie_params([
            'lifetime' => 0,
            'path' => '/',
            'domain' => '',
            'secure' => $secureCookie,
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
    } else {
        session_set_cookie_params(0, '/; samesite=Lax', '', $secureCookie, true);
    }

    $sessionPath = trim((string) session_save_path());
    if ($sessionPath !== '') {
        $pathParts = preg_split('/[;,]/', $sessionPath) ?: [];
        $resolvedPath = trim((string) end($pathParts));
        if ($resolvedPath !== '' && (!is_dir($resolvedPath) || !is_writable($resolvedPath))) {
            session_save_path(sys_get_temp_dir());
        }
    }

    session_start();
}

require_once __DIR__ . '/modules/auth_core_helpers.php';
require_once __DIR__ . '/modules/auth_operations_helpers.php';
require_once __DIR__ . '/schema_helpers.php';
require_once __DIR__ . '/modules/auth_session_helpers.php';
