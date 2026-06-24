<?php
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/helpers.php';

auth_require_permission('rentals.reopen');
auth_require_post_request();
auth_validate_csrf_request();

app_ensure_schema($pdo, 'rental_archive');
$companyId = auth_current_company_id();
$currentUserId = (int) (auth_current_user()['id'] ?? 0);
$id = (int) ($_POST['id'] ?? 0);

if ($id > 0 && $companyId > 0 && $currentUserId > 0) {
    $st = $pdo->prepare('SELECT id, car_id, customer_name, completed FROM rentals WHERE id = ? AND company_id = ? AND archived_at IS NULL');
    $st->execute([$id, $companyId]);
    $rental = $st->fetch(PDO::FETCH_ASSOC) ?: [];
    $carId = (int) ($rental['car_id'] ?? 0);

    if (!$rental || (int) ($rental['completed'] ?? 0) === 0) {
        redirect('../rentals.php?show_all=1&error=rental_reopen_conflict');
    }

    if ($carId > 0) {
        $activeCheck = $pdo->prepare('SELECT COUNT(*) FROM rentals WHERE car_id = ? AND completed = 0 AND id <> ? AND company_id = ? AND archived_at IS NULL');
        $activeCheck->execute([$carId, $id, $companyId]);
        if ((int) $activeCheck->fetchColumn() > 0) {
            redirect('../rentals.php?show_all=1&error=rental_reopen_conflict');
        }
    }

    try {
        $pdo->beginTransaction();

        $pdo->prepare('UPDATE rentals SET completed = 0 WHERE id = ? AND company_id = ? AND archived_at IS NULL')->execute([$id, $companyId]);

        if ($carId > 0) {
            $pdo->prepare('UPDATE cars SET available = 0 WHERE id = ? AND company_id = ?')->execute([$carId, $companyId]);
        }

        auth_audit_log($pdo, 'rental.reopened', 'Tamamlanan kiralama yeniden acildi.', [
            'entity_type' => 'rental',
            'entity_id' => $id,
            'company_id' => $companyId,
            'metadata' => [
                'customer_name' => $rental['customer_name'] ?? null,
                'car_id' => $carId > 0 ? $carId : null,
                'reopened_by_user_id' => $currentUserId,
            ],
        ]);

        $pdo->commit();
    } catch (Throwable $exception) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        error_log('rental_reopen_failed: ' . $exception->getMessage());
        redirect('../rentals.php?show_all=1&error=rental_reopen_error');
    }
}

redirect('../rentals.php');
