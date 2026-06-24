<?php
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/helpers.php';

auth_require_permission('platform.manage');
auth_require_post_request();
auth_validate_csrf_request();

$companyId = isset($_POST['company_id']) ? (int) $_POST['company_id'] : 0;
$id = isset($_POST['id']) ? (int) $_POST['id'] : 0;
$redirectContext = auth_trimmed_string($_POST['context'] ?? '', 20);
$currentUserId = (int) (auth_current_user()['id'] ?? 0);

$redirect = function (string $status = '') use ($redirectContext, $companyId): void {
    if ($redirectContext === 'detail') {
        $suffix = $status !== '' ? '&status=' . urlencode($status) : '';
        auth_redirect('company_detail.php?id=' . $companyId . $suffix);
    }

    $suffix = $status !== '' ? '?status=' . urlencode($status) : '';
    auth_redirect('companies.php' . $suffix);
};

if ($companyId <= 0 || $id <= 0) {
    $redirect('invalid');
}

if ($id === $currentUserId) {
    $redirect('self_delete_blocked');
}

$userSt = $pdo->prepare('SELECT * FROM users WHERE id = ? AND company_id = ? AND archived_at IS NULL LIMIT 1');
$userSt->execute([$id, $companyId]);
$user = $userSt->fetch(PDO::FETCH_ASSOC);
if (!$user) {
    $redirect('invalid');
}

$existingRole = (string) ($user['role'] ?? '');
if (in_array($existingRole, ['platform_admin', 'super_admin'], true) && auth_active_super_admin_count($pdo, $companyId, $id) === 0) {
    $redirect('last_admin_blocked');
}

$delete = $pdo->prepare('UPDATE users SET archived_at = NOW(), archived_by_user_id = ?, archive_reason = ? WHERE id = ? AND company_id = ?');
$delete->execute([$currentUserId > 0 ? $currentUserId : null, 'Platform yöneticisi tarafından arşive alındı.', $id, $companyId]);

auth_audit_log($pdo, 'platform.user_archived', 'Platform yöneticisi firma kullanıcısını arşive aldı.', [
    'entity_type' => 'user',
    'entity_id' => $id,
    'company_id' => $companyId,
    'metadata' => [
        'username' => (string) ($user['username'] ?? ''),
        'role' => $existingRole,
    ],
]);

$redirect('user_deleted');
