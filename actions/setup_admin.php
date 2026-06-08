<?php
require_once __DIR__ . '/../config/database.php';

auth_require_post_request();
auth_validate_csrf_request();

$password = (string) ($_POST['password'] ?? '');
$passwordErrors = auth_validate_password_policy($password);
if (!empty($passwordErrors)) {
    auth_redirect('setup.php?error=' . urlencode(implode(' ', $passwordErrors)));
}

$result = auth_create_initial_admin(
    $pdo,
    (string) ($_POST['company_name'] ?? ''),
    (string) ($_POST['full_name'] ?? ''),
    (string) ($_POST['username'] ?? ''),
    $password
);

if (!$result['ok']) {
    auth_redirect('setup.php?error=' . urlencode($result['message']));
}

auth_audit_log($pdo, 'auth.setup_completed', 'Ilk firma ve yonetici kurulumu tamamlandi.', [
    'company_id' => (int) ($result['company_id'] ?? 0),
    'entity_type' => 'company',
    'entity_id' => (int) ($result['company_id'] ?? 0),
    'metadata' => [
        'username' => (string) ($_POST['username'] ?? ''),
    ],
]);

auth_rotate_csrf_token();
auth_redirect('login.php');
