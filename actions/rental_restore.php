<?php
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/helpers.php';

auth_require_permission('rentals.restore');
auth_require_post_request();
auth_validate_csrf_request();

app_ensure_schema($pdo, 'rental_archive');

$companyId = auth_current_company_id();
$currentUserId = (int) (auth_current_user()['id'] ?? 0);
$id = (int) ($_POST['id'] ?? 0);

if ($id <= 0 || $companyId <= 0 || $currentUserId <= 0) {
    redirect('../rentals.php?show_archived=1');
}

$st = $pdo->prepare('SELECT * FROM rentals WHERE id = ? AND company_id = ? AND archived_at IS NOT NULL LIMIT 1');
$st->execute([$id, $companyId]);
$rental = $st->fetch(PDO::FETCH_ASSOC);

if (!$rental) {
    redirect('../rentals.php?show_archived=1');
}

$carId = (int) ($rental['car_id'] ?? 0);
if ($carId > 0 && (int) ($rental['completed'] ?? 0) === 0) {
    $activeCheck = $pdo->prepare('SELECT COUNT(*) FROM rentals WHERE car_id = ? AND completed = 0 AND company_id = ? AND archived_at IS NULL AND id <> ?');
    $activeCheck->execute([$carId, $companyId, $id]);
    if ((int) $activeCheck->fetchColumn() > 0) {
        redirect('../rentals.php?show_archived=1&error=rental_restore_conflict');
    }
}

try {
    $pdo->beginTransaction();
    $pdo->prepare('UPDATE rentals SET archived_at = NULL, archived_by_user_id = NULL, archive_reason = NULL WHERE id = ? AND company_id = ?')->execute([$id, $companyId]);

    if ($carId > 0 && (int) ($rental['completed'] ?? 0) === 0) {
        $pdo->prepare('UPDATE cars SET available = 0 WHERE id = ? AND company_id = ?')->execute([$carId, $companyId]);
    }

    auth_audit_log($pdo, 'rental.restored', 'Kiralama kaydi arsivden geri yuklendi.', [
        'entity_type' => 'rental',
        'entity_id' => $id,
        'company_id' => $companyId,
        'metadata' => [
            'customer_name' => $rental['customer_name'] ?? null,
            'car_id' => $carId > 0 ? $carId : null,
            'restored_by_user_id' => $currentUserId,
        ],
    ]);
    $pdo->commit();
} catch (Throwable $exception) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    error_log('rental_restore_failed: ' . $exception->getMessage());
    redirect('../rentals.php?show_archived=1&status=restore_error');
}

redirect('../rentals.php?status=restored');
