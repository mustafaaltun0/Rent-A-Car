<?php
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/helpers.php';

$authUser = auth_current_user();
if (!$authUser) {
    auth_redirect('login.php');
}

auth_require_post_request();
auth_validate_csrf_request();
app_ensure_schema($pdo, 'auth');

$userId = (int) ($authUser['id'] ?? 0);
$companyId = auth_current_company_id();
$fullName = trim((string) ($_POST['full_name'] ?? ''));
$birthDateInput = trim((string) ($_POST['birth_date'] ?? ''));
$bio = trim((string) ($_POST['bio'] ?? ''));
$removeAvatar = (int) ($_POST['remove_avatar'] ?? 0) === 1;
$avatarFocusX = auth_avatar_focus_value($_POST['avatar_focus_x'] ?? 50, 50);
$avatarFocusY = auth_avatar_focus_value($_POST['avatar_focus_y'] ?? 50, 50);

if ($userId <= 0 || $fullName === '') {
    auth_redirect('account_security.php?status=profile_invalid');
}

$birthDate = null;
if ($birthDateInput !== '') {
    $birthDateTimestamp = strtotime($birthDateInput);
    if ($birthDateTimestamp === false) {
        auth_redirect('account_security.php?status=profile_invalid');
    }

    $birthDate = date('Y-m-d', $birthDateTimestamp);
}

if (mb_strlen($bio, 'UTF-8') > 1000) {
    $bio = mb_substr($bio, 0, 1000, 'UTF-8');
}

$currentUserSt = $pdo->prepare('SELECT id, company_id, full_name, birth_date, bio, avatar_path, avatar_focus_x, avatar_focus_y FROM users WHERE id = ? AND company_id = ? AND archived_at IS NULL LIMIT 1');
$currentUserSt->execute([$userId, $companyId]);
$currentUser = $currentUserSt->fetch(PDO::FETCH_ASSOC);
if (!$currentUser) {
    auth_redirect('account_security.php?status=profile_invalid');
}

$newAvatarRelativePath = (string) ($currentUser['avatar_path'] ?? '');
$newAvatarAbsolutePath = null;
$oldAvatarAbsolutePath = auth_user_avatar_filesystem_path($currentUser);
$newAvatarFocusX = $currentUser['avatar_path'] ? auth_avatar_focus_value($currentUser['avatar_focus_x'] ?? 50, 50) : 50;
$newAvatarFocusY = $currentUser['avatar_path'] ? auth_avatar_focus_value($currentUser['avatar_focus_y'] ?? 50, 50) : 50;

if ($removeAvatar) {
    $newAvatarRelativePath = '';
    $newAvatarFocusX = 50;
    $newAvatarFocusY = 50;
}

$avatarFile = $_FILES['avatar_file'] ?? null;
if (is_array($avatarFile) && (int) ($avatarFile['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
    $uploadResult = auth_store_user_avatar_upload($avatarFile, $companyId, $userId);
    if (!$uploadResult['ok']) {
        auth_redirect('account_security.php?status=' . urlencode((string) ($uploadResult['status'] ?? 'avatar_upload_failed')));
    }

    $newAvatarRelativePath = (string) ($uploadResult['relative_path'] ?? '');
    $newAvatarAbsolutePath = (string) ($uploadResult['absolute_path'] ?? '');
    $newAvatarFocusX = $avatarFocusX;
    $newAvatarFocusY = $avatarFocusY;
} elseif ($newAvatarRelativePath !== '') {
    $newAvatarFocusX = $avatarFocusX;
    $newAvatarFocusY = $avatarFocusY;
}

try {
    $pdo->beginTransaction();

    $update = $pdo->prepare('UPDATE users SET full_name = ?, birth_date = ?, bio = ?, avatar_path = ?, avatar_focus_x = ?, avatar_focus_y = ?, updated_at = NOW() WHERE id = ? AND company_id = ? AND archived_at IS NULL');
    $update->execute([
        $fullName,
        $birthDate,
        $bio !== '' ? $bio : null,
        $newAvatarRelativePath !== '' ? $newAvatarRelativePath : null,
        $newAvatarRelativePath !== '' ? $newAvatarFocusX : null,
        $newAvatarRelativePath !== '' ? $newAvatarFocusY : null,
        $userId,
        $companyId,
    ]);

    auth_audit_log($pdo, 'account.profile_updated', 'Kullanici kendi profilini guncelledi.', [
        'entity_type' => 'user',
        'entity_id' => $userId,
        'company_id' => $companyId,
        'metadata' => [
            'birth_date' => $birthDate,
            'avatar_updated' => $newAvatarAbsolutePath !== null || $removeAvatar,
            'avatar_focus_x' => $newAvatarRelativePath !== '' ? $newAvatarFocusX : null,
            'avatar_focus_y' => $newAvatarRelativePath !== '' ? $newAvatarFocusY : null,
        ],
    ]);

    $pdo->commit();
} catch (Throwable $exception) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }

    if ($newAvatarAbsolutePath && is_file($newAvatarAbsolutePath)) {
        @unlink($newAvatarAbsolutePath);
    }

    error_log('account_profile_save_failed: ' . $exception->getMessage());
    auth_redirect('account_security.php?status=profile_error');
}

if ($oldAvatarAbsolutePath && is_file($oldAvatarAbsolutePath)) {
    $shouldDeleteOldAvatar = false;
    if ($removeAvatar) {
        $shouldDeleteOldAvatar = true;
    } elseif ($newAvatarAbsolutePath && $newAvatarAbsolutePath !== $oldAvatarAbsolutePath) {
        $shouldDeleteOldAvatar = true;
    }

    if ($shouldDeleteOldAvatar) {
        @unlink($oldAvatarAbsolutePath);
    }
}

auth_reload_user($pdo);
auth_rotate_session_id_if_needed(true);
auth_redirect('account_security.php?status=profile_saved');
