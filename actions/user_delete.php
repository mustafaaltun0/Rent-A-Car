<?php
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/helpers.php';

auth_require_permission('users.manage');
auth_require_post_request();
auth_validate_csrf_request();

$companyId = auth_current_company_id();
$currentUserId = (int) (auth_current_user()['id'] ?? 0);
$id = isset($_POST['id']) ? (int) $_POST['id'] : 0;

if ($id <= 0) {
    auth_redirect('users.php?status=invalid');
}

if ($id === $currentUserId) {
    auth_redirect('users.php?status=self_delete_blocked');
}

$userSt = $pdo->prepare('SELECT * FROM users WHERE id = ? AND company_id = ?');
$userSt->execute([$id, $companyId]);
$user = $userSt->fetch();
if (!$user) {
    auth_redirect('users.php?status=invalid');
}

if (($user['role'] ?? '') === 'super_admin' && auth_active_super_admin_count($pdo, $companyId, $id) === 0) {
    auth_redirect('users.php?status=last_admin_blocked');
}

$delete = $pdo->prepare('UPDATE users SET archived_at = NOW(), archived_by_user_id = ?, archive_reason = ? WHERE id = ? AND company_id = ?');
$delete->execute([$currentUserId > 0 ? $currentUserId : null, 'Kullanici tarafindan arsive alindi.', $id, $companyId]);

auth_audit_log($pdo, 'user.archived', 'Kullanici arsive alindi.', [
    'entity_type' => 'user',
    'entity_id' => $id,
    'metadata' => [
        'username' => (string) ($user['username'] ?? ''),
        'role' => (string) ($user['role'] ?? ''),
    ],
]);

auth_redirect('users.php?status=deleted');
