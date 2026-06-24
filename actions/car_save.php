<?php
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/helpers.php';

auth_require_permission('cars.manage');
auth_require_post_request();
auth_validate_csrf_request();

app_ensure_schema($pdo, 'car_core');

$companyId = auth_current_company_id();
$companyName = (string) (auth_current_user()['company_name'] ?? '');

$id = isset($_POST['id']) ? (int) $_POST['id'] : 0;
$ownerName = $companyName !== '' ? $companyName : null;
$telematicsEnabled = isset($_POST['telematics_enabled']) ? 1 : 0;
$telematicsProvider = trim((string) ($_POST['telematics_provider'] ?? ''));
$telematicsDeviceId = trim((string) ($_POST['telematics_device_id'] ?? ''));
$removePhoto = (int) ($_POST['remove_photo'] ?? 0) === 1;
$photoFocusX = car_photo_focus_value($_POST['photo_focus_x'] ?? 50, 50);
$photoFocusY = car_photo_focus_value($_POST['photo_focus_y'] ?? 50, 50);
$photoPositionX = car_photo_axis_from_focus($photoFocusX, 'x');
$photoPositionY = car_photo_axis_from_focus($photoFocusY, 'y');

if ($companyId <= 0) {
    redirect('../cars.php?status=invalid');
}

$plate = auth_trimmed_string($_POST['plate'] ?? '', 50);
$brand = auth_trimmed_string($_POST['brand'] ?? '', 100);
$model = auth_trimmed_string($_POST['model'] ?? '', 100);
if ($plate === '' || $brand === '' || $model === '') {
    redirect('../cars.php?status=invalid');
}

$existingCar = null;
if ($id > 0) {
    $existingCarSt = $pdo->prepare('SELECT * FROM cars WHERE id = ? AND company_id = ? AND archived_at IS NULL LIMIT 1');
    $existingCarSt->execute([$id, $companyId]);
    $existingCar = $existingCarSt->fetch(PDO::FETCH_ASSOC);
    if (!$existingCar) {
        redirect('../cars.php?status=invalid');
    }
}

$photoRelativePath = (string) ($existingCar['photo_path'] ?? '');
$oldPhotoAbsolutePath = $existingCar ? car_photo_filesystem_path($existingCar) : null;
$newPhotoAbsolutePath = null;

$data = [
    $plate,
    $brand,
    $model,
    $ownerName,
    $telematicsEnabled,
    $telematicsProvider !== '' ? $telematicsProvider : null,
    $telematicsDeviceId !== '' ? $telematicsDeviceId : null,
    (isset($_POST['year']) && $_POST['year'] !== '' ? (int) $_POST['year'] : null),
    $_POST['inspection_date'] ?: null,
    $_POST['insurance_date'] ?: null,
    $_POST['maintenance_date'] ?: null,
    $_POST['maintenance_note'] ?: null,
    $photoPositionX,
    $photoPositionY,
    $photoFocusX,
    $photoFocusY,
];

try {
    $pdo->beginTransaction();

    if ($id > 0) {
        $sql = 'UPDATE cars SET plate = ?, brand = ?, model = ?, owner_name = ?, telematics_enabled = ?, telematics_provider = ?, telematics_device_id = ?, year = ?, inspection_date = ?, insurance_date = ?, maintenance_date = ?, maintenance_note = ?, photo_position_x = ?, photo_position_y = ?, photo_focus_x = ?, photo_focus_y = ? WHERE id = ? AND company_id = ? AND archived_at IS NULL';
        $st = $pdo->prepare($sql);
        $data[] = $id;
        $data[] = $companyId;
        $st->execute($data);
    } else {
        $sql = 'INSERT INTO cars (company_id, plate, brand, model, owner_name, telematics_enabled, telematics_provider, telematics_device_id, year, inspection_date, insurance_date, maintenance_date, maintenance_note, photo_position_x, photo_position_y, photo_focus_x, photo_focus_y, available) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 1)';
        $st = $pdo->prepare($sql);
        array_unshift($data, $companyId);
        $st->execute($data);
        $id = (int) $pdo->lastInsertId();
    }

    if ($removePhoto) {
        $photoRelativePath = '';
    }

    $photoFile = $_FILES['photo_file'] ?? null;
    if (is_array($photoFile) && (int) ($photoFile['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
        $uploadResult = car_store_photo_upload($photoFile, $companyId, $id);
        if (!$uploadResult['ok']) {
            throw new RuntimeException((string) ($uploadResult['status'] ?? 'car_photo_upload_failed'));
        }

        $photoRelativePath = (string) ($uploadResult['relative_path'] ?? '');
        $newPhotoAbsolutePath = (string) ($uploadResult['absolute_path'] ?? '');
    }

    $pdo->prepare('UPDATE cars SET photo_path = ? WHERE id = ? AND company_id = ? AND archived_at IS NULL')->execute([
        $photoRelativePath !== '' ? $photoRelativePath : null,
        $id,
        $companyId,
    ]);

    $pdo->commit();
} catch (Throwable $exception) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    if ($newPhotoAbsolutePath && is_file($newPhotoAbsolutePath)) {
        @unlink($newPhotoAbsolutePath);
    }
    error_log('car_save_failed: ' . $exception->getMessage());
    redirect('../cars.php?status=' . urlencode($exception->getMessage() !== '' ? $exception->getMessage() : 'car_photo_upload_failed'));
}

if ($oldPhotoAbsolutePath && is_file($oldPhotoAbsolutePath)) {
    $shouldDeleteOldPhoto = false;
    if ($removePhoto) {
        $shouldDeleteOldPhoto = true;
    } elseif ($newPhotoAbsolutePath && $newPhotoAbsolutePath !== $oldPhotoAbsolutePath) {
        $shouldDeleteOldPhoto = true;
    }

    if ($shouldDeleteOldPhoto) {
        @unlink($oldPhotoAbsolutePath);
    }
}

redirect('../cars.php?status=saved');
