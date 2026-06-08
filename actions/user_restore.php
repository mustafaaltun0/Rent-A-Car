<?php
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/helpers.php';

auth_require_permission('users.manage');
auth_require_post_request();
auth_validate_csrf_request();

$companyId = auth_current_company_id();
$id = isset($_POST['id']) ? (int) $_POST['id'] : 0;

if ($id <= 0) {
    auth_redirect('users.php?status=invalid');
}

$userSt = $pdo->prepare('SELECT * FROM users WHERE id = ? AND company_id = ? AND archived_at IS NOT NULL');
$userSt->execute([$id, $companyId]);
$user = $userSt->fetch();
if (!$user) {
    auth_redirect('users.php?status=invalid');
}

$update = $pdo->prepare('UPDATE users SET archived_at = NULL, archived_by_user_id = NULL, archive_reason = NULL WHERE id = ? AND company_id = ?');
$update->execute([$id, $companyId]);

auth_audit_log($pdo, 'user.restored', 'Kullanici arsivden geri yuklendi.', [
    'entity_type' => 'user',
    'entity_id' => $id,
    'metadata' => [
        'username' => (string) ($user['username'] ?? ''),
        'role' => (string) ($user['role'] ?? ''),
    ],
]);

auth_redirect('users.php?status=restored');
