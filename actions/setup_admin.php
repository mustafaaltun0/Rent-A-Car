<?php
require_once __DIR__ . '/../config/database.php';

auth_require_post_request();
auth_validate_csrf_request();
app_ensure_schema($pdo, 'auth');

if (auth_has_users($pdo)) {
    auth_audit_log($pdo, 'auth.setup_blocked', 'Tamamlanmis kurulum tekrar calistirilmaya calisildi.', [
        'entity_type' => 'auth',
        'metadata' => [
            'ip_address' => auth_client_ip(),
        ],
    ]);
    auth_redirect('setup.php?error=Kurulum%20zaten%20tamamlanmis.');
}

$password = (string) ($_POST['password'] ?? '');
$passwordErrors = auth_validate_password_policy($password);
if (!empty($passwordErrors)) {
    auth_redirect('setup.php?error=' . urlencode(implode(' ', $passwordErrors)));
}

try {
    $result = auth_create_initial_admin(
        $pdo,
        (string) ($_POST['company_name'] ?? ''),
        (string) ($_POST['full_name'] ?? ''),
        (string) ($_POST['username'] ?? ''),
        $password
    );

    if (!$result['ok']) {
        auth_audit_log($pdo, 'auth.setup_failed', 'Ilk kurulum denemesi basarisiz oldu.', [
            'entity_type' => 'auth',
            'metadata' => [
                'ip_address' => auth_client_ip(),
                'username' => auth_normalize_username((string) ($_POST['username'] ?? '')),
                'message' => (string) ($result['message'] ?? ''),
            ],
        ]);
        auth_redirect('setup.php?error=' . urlencode((string) ($result['message'] ?? 'Kurulum basarisiz.')));
    }

    auth_audit_log($pdo, 'auth.setup_completed', 'Ilk firma ve yonetici kurulumu tamamlandi.', [
        'company_id' => (int) ($result['company_id'] ?? 0),
        'entity_type' => 'company',
        'entity_id' => (int) ($result['company_id'] ?? 0),
        'metadata' => [
            'username' => auth_normalize_username((string) ($_POST['username'] ?? '')),
            'ip_address' => auth_client_ip(),
        ],
    ]);
} catch (Throwable $exception) {
    error_log('setup_admin_failed: ' . $exception->getMessage());
    auth_redirect('setup.php?error=Kurulum%20sirasinda%20beklenmeyen%20bir%20hata%20olustu.');
}

auth_rotate_csrf_token();
auth_redirect('login.php');
