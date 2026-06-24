<?php
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/helpers.php';

auth_require_permission('platform.manage');
auth_require_post_request();
auth_validate_csrf_request();

$companyId = isset($_POST['company_id']) ? (int) $_POST['company_id'] : 0;
$id = isset($_POST['id']) ? (int) $_POST['id'] : 0;
$redirectContext = auth_trimmed_string($_POST['context'] ?? '', 20);

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

$userSt = $pdo->prepare('SELECT * FROM users WHERE id = ? AND company_id = ? AND archived_at IS NOT NULL LIMIT 1');
$userSt->execute([$id, $companyId]);
$user = $userSt->fetch(PDO::FETCH_ASSOC);
if (!$user) {
    $redirect('invalid');
}

$update = $pdo->prepare('UPDATE users SET archived_at = NULL, archived_by_user_id = NULL, archive_reason = NULL WHERE id = ? AND company_id = ?');
$update->execute([$id, $companyId]);

auth_audit_log($pdo, 'platform.user_restored', 'Platform yöneticisi firma kullanıcısını arşivden geri yükledi.', [
    'entity_type' => 'user',
    'entity_id' => $id,
    'company_id' => $companyId,
    'metadata' => [
        'username' => (string) ($user['username'] ?? ''),
        'role' => (string) ($user['role'] ?? ''),
    ],
]);

$redirect('user_restored');
