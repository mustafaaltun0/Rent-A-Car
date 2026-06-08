<?php
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/helpers.php';

auth_require_permission('platform.manage');
auth_require_post_request();
auth_validate_csrf_request();

$redirectContext = auth_trimmed_string($_POST['context'] ?? '', 20);
$redirectBase = $redirectContext === 'detail' ? 'company_detail.php?id=' : 'companies.php?status=';
$redirect = function (string $status) use ($redirectBase, $redirectContext, &$companyId): void {
    if ($redirectContext === 'detail') {
        auth_redirect('company_detail.php?id=' . max(0, (int) $companyId) . '&status=' . urlencode($status));
    }
    auth_redirect('companies.php?status=' . urlencode($status));
};

$companyId = isset($_POST['company_id']) ? (int) $_POST['company_id'] : 0;
$id = isset($_POST['id']) ? (int) $_POST['id'] : 0;
$fullName = auth_trimmed_string($_POST['full_name'] ?? '', 150);
$username = auth_trimmed_string($_POST['username'] ?? '', 80);
$role = auth_trimmed_string($_POST['role'] ?? 'viewer', 40);
$password = (string) ($_POST['password'] ?? '');
$currentUserId = (int) (auth_current_user()['id'] ?? 0);

$companySt = $pdo->prepare('SELECT id, name, is_active FROM companies WHERE id = ? LIMIT 1');
$companySt->execute([$companyId]);
$company = $companySt->fetch(PDO::FETCH_ASSOC);

$allowedRoles = array_keys(auth_assignable_role_options(auth_current_user(), $companyId));
if (!$company || $fullName === '' || $username === '' || !in_array($role, $allowedRoles, true)) {
    $redirect('invalid');
}

if ($password !== '') {
    $passwordErrors = auth_validate_password_policy($password);
    if (!empty($passwordErrors)) {
        $redirect('weak_password');
    }
}

if ($id > 0) {
    $existingSt = $pdo->prepare('SELECT * FROM users WHERE id = ? AND company_id = ? AND archived_at IS NULL LIMIT 1');
    $existingSt->execute([$id, $companyId]);
    $existingUser = $existingSt->fetch(PDO::FETCH_ASSOC);
    if (!$existingUser) {
        $redirect('invalid');
    }

    if ($id === $currentUserId && $role !== (string) ($existingUser['role'] ?? '')) {
        $redirect('self_role_locked');
    }

    $existingRole = (string) ($existingUser['role'] ?? '');
    $isExistingAdmin = in_array($existingRole, ['platform_admin', 'super_admin'], true);
    $isTargetAdmin = in_array($role, ['platform_admin', 'super_admin'], true);
    if ($isExistingAdmin && !$isTargetAdmin && auth_active_super_admin_count($pdo, $companyId, $id) === 0) {
        $redirect('last_admin_blocked');
    }

    $usernameCheck = $pdo->prepare('SELECT COUNT(*) FROM users WHERE username = ? AND id <> ?');
    $usernameCheck->execute([$username, $id]);
    if ((int) $usernameCheck->fetchColumn() > 0) {
        $redirect('username_exists');
    }

    if ($password !== '') {
        $update = $pdo->prepare('UPDATE users SET full_name = ?, username = ?, role = ?, password_hash = ? WHERE id = ? AND company_id = ? AND archived_at IS NULL');
        $update->execute([$fullName, $username, $role, password_hash($password, PASSWORD_DEFAULT), $id, $companyId]);
    } else {
        $update = $pdo->prepare('UPDATE users SET full_name = ?, username = ?, role = ? WHERE id = ? AND company_id = ? AND archived_at IS NULL');
        $update->execute([$fullName, $username, $role, $id, $companyId]);
    }

    auth_audit_log($pdo, 'platform.user_updated', 'Platform yoneticisi firma kullanicisini guncelledi.', [
        'entity_type' => 'user',
        'entity_id' => $id,
        'company_id' => $companyId,
        'metadata' => [
            'company_name' => (string) ($company['name'] ?? ''),
            'username' => $username,
            'role' => $role,
        ],
    ]);
} else {
    if ($password === '') {
        $redirect('invalid');
    }

    $usernameCheck = $pdo->prepare('SELECT COUNT(*) FROM users WHERE username = ?');
    $usernameCheck->execute([$username]);
    if ((int) $usernameCheck->fetchColumn() > 0) {
        $redirect('username_exists');
    }

    $insert = $pdo->prepare('INSERT INTO users (company_id, full_name, username, password_hash, role, is_active, created_at) VALUES (?, ?, ?, ?, ?, 1, NOW())');
    $insert->execute([$companyId, $fullName, $username, password_hash($password, PASSWORD_DEFAULT), $role]);
    $userId = (int) $pdo->lastInsertId();

    auth_audit_log($pdo, 'platform.user_created', 'Platform yoneticisi firma kullanicisi olusturdu.', [
        'entity_type' => 'user',
        'entity_id' => $userId,
        'company_id' => $companyId,
        'metadata' => [
            'company_name' => (string) ($company['name'] ?? ''),
            'username' => $username,
            'role' => $role,
        ],
    ]);
}

$redirect('user_saved');
