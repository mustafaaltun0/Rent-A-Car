<?php
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/helpers.php';

$authUser = auth_current_user();
if (!$authUser) {
    http_response_code(404);
    exit;
}

$companyId = auth_current_company_id();
$carId = (int) ($_GET['id'] ?? 0);
if ($carId <= 0) {
    http_response_code(404);
    exit;
}

$st = $pdo->prepare('SELECT id, company_id, photo_path FROM cars WHERE id = ? AND company_id = ? LIMIT 1');
$st->execute([$carId, $companyId]);
$car = $st->fetch(PDO::FETCH_ASSOC);
if (!$car) {
    http_response_code(404);
    exit;
}

$photoPath = car_photo_filesystem_path($car);
if ($photoPath === null) {
    http_response_code(404);
    exit;
}

$finfo = new finfo(FILEINFO_MIME_TYPE);
$mimeType = (string) $finfo->file($photoPath);
$allowedMimeTypes = ['image/jpeg', 'image/png', 'image/webp'];
if (!in_array($mimeType, $allowedMimeTypes, true)) {
    http_response_code(404);
    exit;
}

header('Content-Type: ' . $mimeType);
header('Content-Length: ' . (string) filesize($photoPath));
header('Content-Disposition: inline; filename="car-photo"');
header('Cache-Control: private, no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Expires: 0');
readfile($photoPath);
exit;
