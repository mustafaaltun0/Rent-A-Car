<?php
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/helpers.php';

auth_require_permission('platform.manage');
auth_require_post_request();
auth_validate_csrf_request();

$companyName = auth_trimmed_string($_POST['company_name'] ?? '', 150);
$legalName = auth_nullable_trimmed_string($_POST['legal_name'] ?? '', 180) ?: $companyName;
$email = auth_nullable_trimmed_string($_POST['email'] ?? '', 150);
$phone = auth_nullable_trimmed_string($_POST['phone'] ?? '', 30);
$city = auth_nullable_trimmed_string($_POST['city'] ?? '', 120);
$adminFullName = auth_trimmed_string($_POST['admin_full_name'] ?? '', 150);
$adminUsername = auth_trimmed_string($_POST['admin_username'] ?? '', 80);
$adminPassword = (string) ($_POST['admin_password'] ?? '');

if ($companyName === '' || $adminFullName === '' || $adminUsername === '') {
    auth_redirect('companies.php?status=invalid');
}

if ($email !== null && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
    auth_redirect('companies.php?status=invalid');
}

$passwordErrors = auth_validate_password_policy($adminPassword);
if (!empty($passwordErrors)) {
    auth_redirect('companies.php?status=weak_password');
}

$companyCheck = $pdo->prepare('SELECT COUNT(*) FROM companies WHERE LOWER(name) = ?');
$companyCheck->execute([mb_strtolower($companyName, 'UTF-8')]);
if ((int) $companyCheck->fetchColumn() > 0) {
    auth_redirect('companies.php?status=company_exists');
}

$usernameCheck = $pdo->prepare('SELECT COUNT(*) FROM users WHERE username = ?');
$usernameCheck->execute([$adminUsername]);
if ((int) $usernameCheck->fetchColumn() > 0) {
    auth_redirect('companies.php?status=username_exists');
}

try {
    $pdo->beginTransaction();

    $slug = auth_resolve_company_slug($pdo, $companyName);
    $insertCompany = $pdo->prepare('INSERT INTO companies (name, legal_name, slug, email, phone, city, is_active, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, 1, NOW(), NOW())');
    $insertCompany->execute([$companyName, $legalName, $slug, $email, $phone, $city]);
    $companyId = (int) $pdo->lastInsertId();

    $insertUser = $pdo->prepare('INSERT INTO users (company_id, full_name, username, password_hash, role, is_active, created_at) VALUES (?, ?, ?, ?, ?, 1, NOW())');
    $insertUser->execute([$companyId, $adminFullName, $adminUsername, password_hash($adminPassword, PASSWORD_DEFAULT), 'super_admin']);
    $userId = (int) $pdo->lastInsertId();

    auth_audit_log($pdo, 'platform.company_created', 'Yeni firma ve ilk yönetici oluşturuldu.', [
        'entity_type' => 'company',
        'entity_id' => $companyId,
        'metadata' => [
            'company_name' => $companyName,
            'admin_user_id' => $userId,
            'admin_username' => $adminUsername,
        ],
    ]);

    $pdo->commit();
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    auth_redirect('companies.php?status=invalid');
}

auth_redirect('companies.php?status=company_saved');
