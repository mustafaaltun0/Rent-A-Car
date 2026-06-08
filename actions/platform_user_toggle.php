<?php
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/helpers.php';

auth_require_permission('platform.manage');
auth_require_post_request();
auth_validate_csrf_request();

$companyId = isset($_POST['company_id']) ? (int) $_POST['company_id'] : 0;
$id = isset($_POST['id']) ? (int) $_POST['id'] : 0;
$isActive = isset($_POST['is_active']) ? (int) $_POST['is_active'] : 0;
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

if ($companyId <= 0 || $id <= 0 || $id === $currentUserId) {
    $redirect('invalid');
}

$userSt = $pdo->prepare('SELECT * FROM users WHERE id = ? AND company_id = ? AND archived_at IS NULL LIMIT 1');
$userSt->execute([$id, $companyId]);
$user = $userSt->fetch(PDO::FETCH_ASSOC);
if (!$user) {
    $redirect('invalid');
}

$existingRole = (string) ($user['role'] ?? '');
if (in_array($existingRole, ['platform_admin', 'super_admin'], true) && $isActive !== 1 && auth_active_super_admin_count($pdo, $companyId, $id) === 0) {
    $redirect('last_admin_blocked');
}

$update = $pdo->prepare('UPDATE users SET is_active = ? WHERE id = ? AND company_id = ? AND archived_at IS NULL');
$update->execute([$isActive === 1 ? 1 : 0, $id, $companyId]);

auth_audit_log($pdo, 'platform.user_status_changed', $isActive === 1 ? 'Platform yoneticisi firma kullanicisini aktif etti.' : 'Platform yoneticisi firma kullanicisini pasife aldi.', [
    'entity_type' => 'user',
    'entity_id' => $id,
    'company_id' => $companyId,
    'metadata' => [
        'username' => (string) ($user['username'] ?? ''),
        'is_active' => $isActive === 1 ? 1 : 0,
    ],
]);

$redirect('user_status_changed');
