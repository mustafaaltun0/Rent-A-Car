<?php
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/helpers.php';

app_ensure_schema($pdo, 'car_core', 'rental_archive');

auth_require_permission('cars.archive');
auth_require_post_request();
auth_validate_csrf_request();

$id = (int) ($_POST['id'] ?? 0);
$companyId = auth_current_company_id();
$currentUserId = (int) (auth_current_user()['id'] ?? 0);

if ($id > 0 && $companyId > 0 && $currentUserId > 0) {
    $carSt = $pdo->prepare('SELECT * FROM cars WHERE id = ? AND company_id = ? AND archived_at IS NULL LIMIT 1');
    $carSt->execute([$id, $companyId]);
    $car = $carSt->fetch(PDO::FETCH_ASSOC);

    if ($car) {
        $activeRentalSt = $pdo->prepare('SELECT COUNT(*) FROM rentals WHERE car_id = ? AND company_id = ? AND completed = 0 AND archived_at IS NULL');
        $activeRentalSt->execute([$id, $companyId]);
        if ((int) $activeRentalSt->fetchColumn() > 0) {
            redirect('../cars.php?error=car_archive_active_rental');
        }

        try {
            $pdo->beginTransaction();

            $archiveReason = 'Kullanici tarafindan arsive alindi.';
            $pdo->prepare('UPDATE cars SET archived_at = NOW(), archived_by_user_id = ?, archive_reason = ?, available = 0 WHERE id = ? AND company_id = ?')->execute([
                $currentUserId,
                $archiveReason,
                $id,
                $companyId,
            ]);

            auth_audit_log($pdo, 'car.archived', 'Arac arsive alindi.', [
                'entity_type' => 'car',
                'entity_id' => $id,
                'company_id' => $companyId,
                'metadata' => [
                    'plate' => $car['plate'] ?? null,
                    'archived_by_user_id' => $currentUserId,
                ],
            ]);

            $pdo->commit();
            redirect('../cars.php?status=archived');
        } catch (Throwable $exception) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            error_log('car_delete_failed: ' . $exception->getMessage());
            redirect('../cars.php?status=archive_error');
        }
    }
}

redirect('../cars.php');
