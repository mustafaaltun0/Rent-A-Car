<?php
require_once __DIR__ . '/config/database.php';

$company = auth_current_company($pdo);
if (!$company) {
    http_response_code(404);
    exit;
}

$logoPath = auth_company_logo_filesystem_path($company);
if ($logoPath === null) {
    http_response_code(404);
    exit;
}

$finfo = new finfo(FILEINFO_MIME_TYPE);
$mimeType = (string) $finfo->file($logoPath);
$allowedMimeTypes = ['image/jpeg', 'image/png', 'image/webp'];
if (!in_array($mimeType, $allowedMimeTypes, true)) {
    http_response_code(404);
    exit;
}

header('Content-Type: ' . $mimeType);
header('Content-Length: ' . (string) filesize($logoPath));
header('Content-Disposition: inline; filename="company-logo"');
header('Cache-Control: private, no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Expires: 0');
readfile($logoPath);
exit;
