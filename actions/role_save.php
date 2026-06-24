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
$name = auth_trimmed_string($_POST['name'] ?? '', 120);
$description = auth_nullable_trimmed_string($_POST['description'] ?? '', 255);
$validPermissions = array_keys(auth_manageable_permission_catalog($actor, $companyId));
$submittedPermissions = array_filter(array_map('strval', (array) ($_POST['permissions'] ?? [])));
$permissions = array_values(array_unique(array_values(array_intersect($submittedPermissions, $validPermissions))));

$redirectPath = 'roles.php?company_id=' . $companyId;

if ($companyId <= 0 || $name === '') {
    auth_redirect($redirectPath . '&status=invalid');
}

if (empty($permissions)) {
    auth_redirect($redirectPath . '&status=no_permissions');
}

$companySt = $pdo->prepare('SELECT id FROM companies WHERE id = ? LIMIT 1');
$companySt->execute([$companyId]);
if (!$companySt->fetch(PDO::FETCH_ASSOC)) {
    auth_redirect($redirectPath . '&status=invalid');
}

$nameCheckSql = 'SELECT id FROM company_roles WHERE company_id = ? AND name = ? AND archived_at IS NULL';
$nameCheckParams = [$companyId, $name];
if ($id > 0) {
    $nameCheckSql .= ' AND id <> ?';
    $nameCheckParams[] = $id;
}
$nameCheckSql .= ' LIMIT 1';
$nameCheck = $pdo->prepare($nameCheckSql);
$nameCheck->execute($nameCheckParams);
if ($nameCheck->fetch(PDO::FETCH_ASSOC)) {
    auth_redirect($redirectPath . '&status=role_exists');
}

$buildRoleKey = static function (PDO $pdo, int $companyId, string $roleName): string {
    $baseKey = auth_slugify($roleName);
    if ($baseKey === 'firma') {
        $baseKey = 'rol';
    }

    $roleKey = $baseKey;
    $suffix = 2;
    $check = $pdo->prepare('SELECT COUNT(*) FROM company_roles WHERE company_id = ? AND role_key = ?');
    while (true) {
        $check->execute([$companyId, $roleKey]);
        if ((int) $check->fetchColumn() === 0) {
            return $roleKey;
        }
        $roleKey = $baseKey . '-' . $suffix;
        $suffix++;
    }
};

$pdo->beginTransaction();

try {
    if ($id > 0) {
        $roleSt = $pdo->prepare('SELECT * FROM company_roles WHERE id = ? AND company_id = ? AND archived_at IS NULL LIMIT 1');
        $roleSt->execute([$id, $companyId]);
        $role = $roleSt->fetch(PDO::FETCH_ASSOC);

        if (!$role) {
            throw new RuntimeException('invalid');
        }

        $update = $pdo->prepare('UPDATE company_roles SET name = ?, description = ?, updated_at = NOW() WHERE id = ? AND company_id = ?');
        $update->execute([$name, $description, $id, $companyId]);

        $pdo->prepare('DELETE FROM company_role_permissions WHERE role_id = ?')->execute([$id]);
        $insertPermission = $pdo->prepare('INSERT INTO company_role_permissions (role_id, permission_key) VALUES (?, ?)');
        foreach ($permissions as $permissionKey) {
            $insertPermission->execute([$id, $permissionKey]);
        }

        auth_audit_log($pdo, 'auth.role.updated', 'Özel rol güncellendi.', [
            'entity_type' => 'company_role',
            'entity_id' => $id,
            'company_id' => $companyId,
            'metadata' => [
                'role_name' => $name,
                'permissions' => $permissions,
            ],
        ]);
    } else {
        $roleKey = $buildRoleKey($pdo, $companyId, $name);
        $insert = $pdo->prepare('INSERT INTO company_roles (company_id, name, role_key, description, is_active, created_at, updated_at) VALUES (?, ?, ?, ?, 1, NOW(), NOW())');
        $insert->execute([$companyId, $name, $roleKey, $description]);
        $roleId = (int) $pdo->lastInsertId();

        $insertPermission = $pdo->prepare('INSERT INTO company_role_permissions (role_id, permission_key) VALUES (?, ?)');
        foreach ($permissions as $permissionKey) {
            $insertPermission->execute([$roleId, $permissionKey]);
        }

        auth_audit_log($pdo, 'auth.role.created', 'Özel rol oluşturuldu.', [
            'entity_type' => 'company_role',
            'entity_id' => $roleId,
            'company_id' => $companyId,
            'metadata' => [
                'role_name' => $name,
                'role_key' => $roleKey,
                'permissions' => $permissions,
            ],
        ]);
    }

    $pdo->commit();
    auth_redirect($redirectPath . '&status=saved');
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    error_log('role_save_failed: ' . $e->getMessage());
    auth_redirect($redirectPath . '&status=invalid');
}
