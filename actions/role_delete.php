<?php
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/helpers.php';

auth_require_permission('roles.manage');
auth_require_post_request();
auth_validate_csrf_request();

$actor = auth_current_user();
$id = (int) ($_POST['id'] ?? 0);
$requestedCompanyId = (int) ($_POST['company_id'] ?? 0);
$companyId = auth_resolve_role_company_id($requestedCompanyId, $actor);
$redirectPath = 'roles.php?company_id=' . $companyId;

if ($companyId <= 0 || $id <= 0) {
    auth_redirect($redirectPath . '&status=invalid');
}

$roleSt = $pdo->prepare('SELECT * FROM company_roles WHERE id = ? AND company_id = ? AND archived_at IS NULL LIMIT 1');
$roleSt->execute([$id, $companyId]);
$role = $roleSt->fetch(PDO::FETCH_ASSOC);

if (!$role) {
    auth_redirect($redirectPath . '&status=invalid');
}

$usageSt = $pdo->prepare("SELECT COUNT(*) FROM users WHERE company_id = ? AND role = 'custom' AND custom_role_id = ? AND archived_at IS NULL");
$usageSt->execute([$companyId, $id]);
if ((int) $usageSt->fetchColumn() > 0) {
    auth_redirect($redirectPath . '&status=role_in_use');
}

try {
    $pdo->beginTransaction();

    $update = $pdo->prepare('UPDATE company_roles SET is_active = 0, archived_at = NOW(), updated_at = NOW() WHERE id = ? AND company_id = ? AND archived_at IS NULL');
    $update->execute([$id, $companyId]);

    auth_audit_log($pdo, 'auth.role.deleted', 'Ozel rol arsive alindi.', [
        'entity_type' => 'company_role',
        'entity_id' => $id,
        'company_id' => $companyId,
        'metadata' => [
            'role_name' => $role['name'] ?? '',
        ],
    ]);

    $pdo->commit();
} catch (Throwable $exception) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    error_log('role_delete_failed: ' . $exception->getMessage());
    auth_redirect($redirectPath . '&status=invalid');
}

auth_redirect($redirectPath . '&status=deleted');
