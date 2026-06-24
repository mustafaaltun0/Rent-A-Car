<?php
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/helpers.php';

auth_require_permission('users.manage');
auth_require_post_request();
auth_validate_csrf_request();

$companyId = auth_current_company_id();
$currentUserId = (int) (auth_current_user()['id'] ?? 0);
$id = isset($_POST['id']) ? (int) $_POST['id'] : 0;

if ($id <= 0 || $companyId <= 0 || $currentUserId <= 0) {
    auth_redirect('users.php?status=invalid');
}

$userSt = $pdo->prepare('SELECT * FROM users WHERE id = ? AND company_id = ? AND archived_at IS NOT NULL');
$userSt->execute([$id, $companyId]);
$user = $userSt->fetch(PDO::FETCH_ASSOC);
if (!$user) {
    auth_redirect('users.php?status=invalid');
}

try {
    $pdo->beginTransaction();

    $update = $pdo->prepare('UPDATE users SET archived_at = NULL, archived_by_user_id = NULL, archive_reason = NULL WHERE id = ? AND company_id = ?');
    $update->execute([$id, $companyId]);

    auth_audit_log($pdo, 'user.restored', 'Kullanici arsivden geri yuklendi.', [
        'entity_type' => 'user',
        'entity_id' => $id,
        'company_id' => $companyId,
        'metadata' => [
            'username' => (string) ($user['username'] ?? ''),
            'role' => (string) ($user['role'] ?? ''),
            'restored_by_user_id' => $currentUserId,
        ],
    ]);

    $pdo->commit();
} catch (Throwable $exception) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    error_log('user_restore_failed: ' . $exception->getMessage());
    auth_redirect('users.php?status=restore_error');
}

auth_redirect('users.php?status=restored');
