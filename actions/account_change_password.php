<?php
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/helpers.php';

auth_require_post_request();
auth_validate_csrf_request();

$authUser = auth_current_user();
if (!$authUser) {
    auth_redirect('login.php');
}

$userId = (int) ($authUser['id'] ?? 0);
$companyId = (int) ($authUser['company_id'] ?? 0);
$currentPassword = (string) ($_POST['current_password'] ?? '');
$newPassword = (string) ($_POST['new_password'] ?? '');
$newPasswordConfirm = (string) ($_POST['new_password_confirm'] ?? '');

if ($newPassword !== $newPasswordConfirm) {
    auth_redirect('account_security.php?status=mismatch');
}

$passwordErrors = auth_validate_password_policy($newPassword);
if (!empty($passwordErrors)) {
    auth_redirect('account_security.php?status=weak_password');
}

$st = $pdo->prepare('SELECT password_hash FROM users WHERE id = ? AND company_id = ? AND is_active = 1 AND archived_at IS NULL');
$st->execute([$userId, $companyId]);
$user = $st->fetch(PDO::FETCH_ASSOC);
if (!$user || !password_verify($currentPassword, (string) ($user['password_hash'] ?? ''))) {
    auth_audit_log($pdo, 'auth.password_change_failed', 'Hatali mevcut sifre ile parola degistirme denemesi.', [
        'entity_type' => 'user',
        'entity_id' => $userId,
    ]);
    auth_redirect('account_security.php?status=invalid_current');
}

$update = $pdo->prepare('UPDATE users SET password_hash = ? WHERE id = ? AND company_id = ?');
$update->execute([password_hash($newPassword, PASSWORD_DEFAULT), $userId, $companyId]);

auth_audit_log($pdo, 'auth.password_changed', 'Kullanici kendi sifresini guncelledi.', [
    'entity_type' => 'user',
    'entity_id' => $userId,
    'company_id' => $companyId,
]);

auth_redirect('account_security.php?status=changed');
