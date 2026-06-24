<?php
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/helpers.php';

auth_require_permission('platform.manage');
auth_require_post_request();
auth_validate_csrf_request();

$companyId = isset($_POST['company_id']) ? (int) $_POST['company_id'] : 0;
$isActive = isset($_POST['is_active']) ? (int) $_POST['is_active'] : 0;

if ($companyId <= 0) {
    auth_redirect('companies.php?status=invalid');
}

if ($companyId === auth_current_company_id()) {
    auth_redirect('companies.php?status=self_company_blocked');
}

$companySt = $pdo->prepare('SELECT id, name, is_active FROM companies WHERE id = ? LIMIT 1');
$companySt->execute([$companyId]);
$company = $companySt->fetch(PDO::FETCH_ASSOC);
if (!$company) {
    auth_redirect('companies.php?status=invalid');
}

try {
    $pdo->beginTransaction();

    $update = $pdo->prepare('UPDATE companies SET is_active = ?, updated_at = NOW() WHERE id = ?');
    $update->execute([$isActive === 1 ? 1 : 0, $companyId]);

    auth_audit_log($pdo, 'platform.company_status_changed', $isActive === 1 ? 'Firma tekrar aktif edildi.' : 'Firma pasife alindi.', [
        'entity_type' => 'company',
        'entity_id' => $companyId,
        'metadata' => [
            'company_name' => (string) ($company['name'] ?? ''),
            'is_active' => $isActive === 1 ? 1 : 0,
        ],
    ]);

    $pdo->commit();
} catch (Throwable $exception) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    error_log('platform_company_toggle_failed: ' . $exception->getMessage());
    auth_redirect('companies.php?status=invalid');
}

auth_redirect('companies.php?status=company_status_changed');
