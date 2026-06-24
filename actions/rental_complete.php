<?php
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/helpers.php';

auth_require_permission('rentals.complete');
auth_require_post_request();
auth_validate_csrf_request();

app_ensure_schema($pdo, 'rental_core');
$companyId = auth_current_company_id();
$currentUserId = (int) (auth_current_user()['id'] ?? 0);

$id = (int) ($_POST['id'] ?? 0);
$returnKmRaw = preg_replace('/\D+/', '', (string) ($_POST['return_km'] ?? ''));
$returnKm = $returnKmRaw !== '' ? max(0, (int) $returnKmRaw) : null;
if ($id > 0 && $companyId > 0 && $currentUserId > 0) {
    $st = $pdo->prepare('SELECT id, car_id, departure_km, completed, customer_name FROM rentals WHERE id = ? AND company_id = ? AND archived_at IS NULL');
    $st->execute([$id, $companyId]);
    $rental = $st->fetch(PDO::FETCH_ASSOC) ?: [];
    $carId = (int) ($rental['car_id'] ?? 0);
    $departureKm = isset($rental['departure_km']) ? (int) $rental['departure_km'] : null;

    if (!$rental || (int) ($rental['completed'] ?? 0) === 1) {
        redirect('../rentals.php?status=complete_invalid');
    }

    if ($returnKm === null || ($departureKm !== null && $returnKm < $departureKm)) {
        redirect('../rentals.php?status=complete_invalid');
    }

    try {
        $pdo->beginTransaction();
        $pdo->prepare('UPDATE rentals SET completed = 1, return_km = ? WHERE id = ? AND company_id = ? AND archived_at IS NULL')->execute([$returnKm, $id, $companyId]);

        if ($carId > 0) {
            $pdo->prepare('UPDATE cars SET available = 1 WHERE id = ? AND company_id = ?')->execute([$carId, $companyId]);
        }

        auth_audit_log($pdo, 'rental.completed', 'Kiralama teslim alinarak tamamlandi.', [
            'entity_type' => 'rental',
            'entity_id' => $id,
            'company_id' => $companyId,
            'metadata' => [
                'customer_name' => $rental['customer_name'] ?? null,
                'car_id' => $carId > 0 ? $carId : null,
                'return_km' => $returnKm,
                'completed_by_user_id' => $currentUserId,
            ],
        ]);

        $pdo->commit();
    } catch (Throwable $exception) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        error_log('rental_complete_failed: ' . $exception->getMessage());
        redirect('../rentals.php?status=complete_error');
    }
}

redirect('../rentals.php');
