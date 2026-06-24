<?php
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/helpers.php';

auth_require_permission('users.manage');
auth_require_post_request();
auth_validate_csrf_request();

$companyId = auth_current_company_id();
$currentUserId = (int) (auth_current_user()['id'] ?? 0);
$id = isset($_POST['id']) ? (int) $_POST['id'] : 0;
$isActive = isset($_POST['is_active']) ? (int) $_POST['is_active'] : 0;

if ($id <= 0 || $id === $currentUserId || $companyId <= 0 || $currentUserId <= 0) {
    auth_redirect('users.php');
}

$userSt = $pdo->prepare('SELECT role FROM users WHERE id = ? AND company_id = ? AND archived_at IS NULL');
$userSt->execute([$id, $companyId]);
$user = $userSt->fetch(PDO::FETCH_ASSOC);
if (!$user) {
    auth_redirect('users.php?status=invalid');
}

if (($user['role'] ?? '') === 'super_admin' && $isActive !== 1 && auth_active_super_admin_count($pdo, $companyId, $id) === 0) {
    auth_redirect('users.php?status=last_admin_blocked');
}

try {
    $pdo->beginTransaction();

    $update = $pdo->prepare('UPDATE users SET is_active = ? WHERE id = ? AND company_id = ? AND archived_at IS NULL');
    $update->execute([$isActive === 1 ? 1 : 0, $id, $companyId]);

    auth_audit_log($pdo, 'user.status_changed', $isActive === 1 ? 'Kullanici aktif edildi.' : 'Kullanici pasife alindi.', [
        'entity_type' => 'user',
        'entity_id' => $id,
        'company_id' => $companyId,
        'metadata' => [
            'is_active' => $isActive === 1 ? 1 : 0,
        ],
    ]);

    $pdo->commit();
} catch (Throwable $exception) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    error_log('user_toggle_failed: ' . $exception->getMessage());
    auth_redirect('users.php?status=toggle_error');
}

auth_redirect('users.php');
