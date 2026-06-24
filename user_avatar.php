<?php
require_once __DIR__ . '/config/database.php';

$authUser = auth_current_user();
if (!$authUser) {
    http_response_code(404);
    exit;
}

$companyId = auth_current_company_id();
$userId = (int) ($_GET['id'] ?? ($authUser['id'] ?? 0));
if ($userId <= 0) {
    http_response_code(404);
    exit;
}

$st = $pdo->prepare('SELECT id, company_id, avatar_path FROM users WHERE id = ? AND company_id = ? AND archived_at IS NULL LIMIT 1');
$st->execute([$userId, $companyId]);
$user = $st->fetch(PDO::FETCH_ASSOC);
if (!$user) {
    http_response_code(404);
    exit;
}

$avatarPath = auth_user_avatar_filesystem_path($user);
if ($avatarPath === null) {
    http_response_code(404);
    exit;
}

$finfo = new finfo(FILEINFO_MIME_TYPE);
$mimeType = (string) $finfo->file($avatarPath);
$allowedMimeTypes = ['image/jpeg', 'image/png', 'image/webp'];
if (!in_array($mimeType, $allowedMimeTypes, true)) {
    http_response_code(404);
    exit;
}

header('Content-Type: ' . $mimeType);
header('Content-Length: ' . (string) filesize($avatarPath));
header('Content-Disposition: inline; filename="user-avatar"');
header('Cache-Control: private, no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Expires: 0');
readfile($avatarPath);
exit;
