<?php
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/helpers.php';

auth_require_permission('users.manage');
auth_require_post_request();
auth_validate_csrf_request();

$companyId = auth_current_company_id();
$currentUserId = (int) (auth_current_user()['id'] ?? 0);
$id = isset($_POST['id']) ? (int) $_POST['id'] : 0;
$fullName = trim($_POST['full_name'] ?? '');
$username = trim($_POST['username'] ?? '');
$role = trim($_POST['role'] ?? 'viewer');
$password = (string) ($_POST['password'] ?? '');

$roleSelection = auth_parse_role_selection($role);
$allowedRoles = array_keys(auth_assignable_role_options_db($pdo, auth_current_user(), $companyId));
if ($fullName === '' || $username === '' || !in_array($role, $allowedRoles, true)) {
    auth_redirect('users.php?status=invalid');
}

if ($password !== '') {
    $passwordErrors = auth_validate_password_policy($password);
    if (!empty($passwordErrors)) {
        auth_redirect('users.php?status=weak_password');
    }
}

if ($id > 0) {
    $existingSt = $pdo->prepare('SELECT * FROM users WHERE id = ? AND company_id = ? AND archived_at IS NULL');
    $existingSt->execute([$id, $companyId]);
    $existingUser = $existingSt->fetch();
    if (!$existingUser) {
        auth_redirect('users.php?status=invalid');
    }

    $existingCustomRoleId = isset($existingUser['custom_role_id']) ? (int) $existingUser['custom_role_id'] : null;
    $isSameEffectiveRole = $roleSelection['role'] === ($existingUser['role'] ?? '')
        && (int) ($roleSelection['custom_role_id'] ?? 0) === (int) ($existingCustomRoleId ?? 0);
    if ($id === $currentUserId && !$isSameEffectiveRole) {
        auth_redirect('users.php?status=self_role_locked');
    }

    if (($existingUser['role'] ?? '') === 'super_admin' && $role !== 'super_admin' && auth_active_super_admin_count($pdo, $companyId, $id) === 0) {
        auth_redirect('users.php?status=last_admin_blocked');
    }

    $usernameCheck = $pdo->prepare('SELECT COUNT(*) FROM users WHERE username = ? AND id <> ?');
    $usernameCheck->execute([$username, $id]);
    if ((int) $usernameCheck->fetchColumn() > 0) {
        auth_redirect('users.php?status=username_exists');
    }

    if ($password !== '') {
        $update = $pdo->prepare('UPDATE users SET full_name = ?, username = ?, role = ?, custom_role_id = ?, password_hash = ? WHERE id = ? AND company_id = ? AND archived_at IS NULL');
        $update->execute([$fullName, $username, $roleSelection['role'], $roleSelection['custom_role_id'], password_hash($password, PASSWORD_DEFAULT), $id, $companyId]);
    } else {
        $update = $pdo->prepare('UPDATE users SET full_name = ?, username = ?, role = ?, custom_role_id = ? WHERE id = ? AND company_id = ? AND archived_at IS NULL');
        $update->execute([$fullName, $username, $roleSelection['role'], $roleSelection['custom_role_id'], $id, $companyId]);
    }

    auth_audit_log($pdo, 'user.updated', 'Kullanıcı bilgileri güncellendi.', [
        'entity_type' => 'user',
        'entity_id' => $id,
        'metadata' => [
            'username' => $username,
            'role' => $role,
            'effective_role' => $roleSelection['role'],
            'custom_role_id' => $roleSelection['custom_role_id'],
        ],
    ]);
} else {
    if ($password === '') {
        auth_redirect('users.php?status=invalid');
    }

    $usernameCheck = $pdo->prepare('SELECT COUNT(*) FROM users WHERE username = ?');
    $usernameCheck->execute([$username]);
    if ((int) $usernameCheck->fetchColumn() > 0) {
        auth_redirect('users.php?status=username_exists');
    }

    $insert = $pdo->prepare('INSERT INTO users (company_id, full_name, username, password_hash, role, custom_role_id, is_active) VALUES (?, ?, ?, ?, ?, ?, 1)');
    $insert->execute([$companyId, $fullName, $username, password_hash($password, PASSWORD_DEFAULT), $roleSelection['role'], $roleSelection['custom_role_id']]);
    $newUserId = (int) $pdo->lastInsertId();

    auth_audit_log($pdo, 'user.created', 'Yeni kullanıcı oluşturuldu.', [
        'entity_type' => 'user',
        'entity_id' => $newUserId,
        'metadata' => [
            'username' => $username,
            'role' => $role,
            'effective_role' => $roleSelection['role'],
            'custom_role_id' => $roleSelection['custom_role_id'],
        ],
    ]);
}

auth_redirect('users.php?status=saved');
