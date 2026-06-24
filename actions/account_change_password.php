<?php
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/helpers.php';

auth_require_post_request();
auth_validate_csrf_request();
app_ensure_schema($pdo, 'auth');

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

try {
    $st = $pdo->prepare('SELECT password_hash FROM users WHERE id = ? AND company_id = ? AND is_active = 1 AND archived_at IS NULL');
    $st->execute([$userId, $companyId]);
    $user = $st->fetch(PDO::FETCH_ASSOC);
    if (!$user || !password_verify($currentPassword, (string) ($user['password_hash'] ?? ''))) {
        auth_audit_log($pdo, 'auth.password_change_failed', 'Hatali mevcut sifre ile parola degistirme denemesi.', [
            'entity_type' => 'user',
            'entity_id' => $userId,
            'company_id' => $companyId,
            'metadata' => [
                'ip_address' => auth_client_ip(),
            ],
        ]);
        auth_redirect('account_security.php?status=invalid_current');
    }

    $newHash = password_hash($newPassword, PASSWORD_DEFAULT);
    if (!is_string($newHash) || $newHash === '') {
        throw new RuntimeException('Parola hash olusturulamadi.');
    }

    $pdo->beginTransaction();
    $update = $pdo->prepare('UPDATE users SET password_hash = ?, updated_at = NOW() WHERE id = ? AND company_id = ?');
    $update->execute([$newHash, $userId, $companyId]);

    auth_audit_log($pdo, 'auth.password_changed', 'Kullanici kendi sifresini guncelledi.', [
        'entity_type' => 'user',
        'entity_id' => $userId,
        'company_id' => $companyId,
        'metadata' => [
            'ip_address' => auth_client_ip(),
        ],
    ]);

    $pdo->commit();
} catch (Throwable $exception) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }

    error_log('account_change_password_failed: ' . $exception->getMessage());
    auth_redirect('account_security.php?status=error');
}

auth_reload_user($pdo);
auth_rotate_session_id_if_needed(true);
auth_rotate_csrf_token();
auth_redirect('account_security.php?status=changed');
