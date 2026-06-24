<?php
require_once __DIR__ . '/../config/database.php';

auth_require_post_request();
auth_validate_csrf_request();
app_ensure_schema($pdo, 'auth');

$username = auth_normalize_username((string) ($_POST['username'] ?? ''));
$password = (string) ($_POST['password'] ?? '');

if ($username === '' || $password === '') {
    auth_redirect('login.php?error=invalid');
}

try {
    $rateLimitStatus = auth_login_rate_limit_status($pdo, $username);
    if ($rateLimitStatus['blocked']) {
        auth_audit_log($pdo, 'auth.login_blocked', 'Bloke nedeniyle giris reddedildi.', [
            'entity_type' => 'auth',
            'metadata' => [
                'username' => $username,
                'ip_address' => auth_client_ip(),
                'retry_after' => (int) $rateLimitStatus['retry_after'],
            ],
        ]);
        auth_redirect('login.php?error=locked&retry=' . (int) $rateLimitStatus['retry_after']);
    }

    if (!auth_attempt_login($pdo, $username, $password)) {
        usleep(300000);
        $failureStatus = auth_record_failed_login($pdo, $username);
        auth_audit_log($pdo, 'auth.login_failed', 'Basarisiz giris denemesi.', [
            'entity_type' => 'auth',
            'metadata' => [
                'username' => $username,
                'ip_address' => auth_client_ip(),
                'failed_attempts' => (int) $failureStatus['failed_attempts'],
                'retry_after' => (int) $failureStatus['retry_after'],
            ],
        ]);

        if ($failureStatus['blocked']) {
            auth_redirect('login.php?error=locked&retry=' . (int) $failureStatus['retry_after']);
        }

        auth_redirect('login.php?error=invalid');
    }

    auth_clear_failed_logins($pdo, $username);
    auth_audit_log($pdo, 'auth.login_success', 'Kullanici girisi basarili.', [
        'entity_type' => 'auth',
        'user_id' => (int) (auth_current_user()['id'] ?? 0),
        'company_id' => (int) (auth_current_user()['company_id'] ?? 0),
        'metadata' => [
            'ip_address' => auth_client_ip(),
        ],
    ]);
} catch (Throwable $exception) {
    error_log('auth_login_failed: ' . $exception->getMessage());
    auth_redirect('login.php?error=invalid');
}

auth_redirect('index.php');
