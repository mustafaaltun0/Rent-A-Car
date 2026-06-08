<?php
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/helpers.php';

auth_require_permission('cars.manage');
auth_require_post_request();
auth_validate_csrf_request();

ensureCarOwnerSchema($pdo);
ensureCarTelematicsSchema($pdo);
ensureCarTelematicsEventSchema($pdo);
ensureCarArchiveSchema($pdo);
$companyId = auth_current_company_id();
$companyName = (string) (auth_current_user()['company_name'] ?? '');

$id = isset($_POST['id']) ? (int) $_POST['id'] : 0;
$ownerName = $companyName !== '' ? $companyName : null;
$telematicsEnabled = isset($_POST['telematics_enabled']) ? 1 : 0;
$telematicsProvider = trim((string) ($_POST['telematics_provider'] ?? ''));
$telematicsDeviceId = trim((string) ($_POST['telematics_device_id'] ?? ''));
$data = [
    $_POST['plate'] ?? '',
    $_POST['brand'] ?? '',
    $_POST['model'] ?? '',
    $ownerName,
    $telematicsEnabled,
    $telematicsProvider !== '' ? $telematicsProvider : null,
    $telematicsDeviceId !== '' ? $telematicsDeviceId : null,
    ($_POST['year'] !== '' ? (int) $_POST['year'] : null),
    $_POST['inspection_date'] ?: null,
    $_POST['insurance_date'] ?: null,
    $_POST['maintenance_date'] ?: null,
    $_POST['maintenance_note'] ?: null,
];

if ($id > 0) {
    $sql = 'UPDATE cars SET plate = ?, brand = ?, model = ?, owner_name = ?, telematics_enabled = ?, telematics_provider = ?, telematics_device_id = ?, year = ?, inspection_date = ?, insurance_date = ?, maintenance_date = ?, maintenance_note = ? WHERE id = ? AND company_id = ? AND archived_at IS NULL';
    $st = $pdo->prepare($sql);
    $data[] = $id;
    $data[] = $companyId;
    $st->execute($data);
} else {
    $sql = 'INSERT INTO cars (company_id, plate, brand, model, owner_name, telematics_enabled, telematics_provider, telematics_device_id, year, inspection_date, insurance_date, maintenance_date, maintenance_note, available) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 1)';
    $st = $pdo->prepare($sql);
    array_unshift($data, $companyId);
    $st->execute($data);
}

redirect('../cars.php');
