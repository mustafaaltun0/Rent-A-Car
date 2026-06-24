<?php
require_once __DIR__ . '/../config/database.php';

auth_require_post_request();
auth_validate_csrf_request();

try {
    auth_audit_log($pdo, 'auth.logout', 'Kullanici oturumu kapatildi.', [
        'entity_type' => 'auth',
        'user_id' => (int) (auth_current_user()['id'] ?? 0),
        'company_id' => (int) (auth_current_user()['company_id'] ?? 0),
        'metadata' => [
            'ip_address' => auth_client_ip(),
        ],
    ]);
} catch (Throwable $exception) {
    error_log('logout_audit_failed: ' . $exception->getMessage());
}

auth_logout();
auth_rotate_csrf_token();
auth_redirect('login.php');
