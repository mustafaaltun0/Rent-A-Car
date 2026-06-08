<?php
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/helpers.php';

auth_require_permission('cars.manage');
auth_require_post_request();
auth_validate_csrf_request();

ensureCarArchiveSchema($pdo);
ensureRentalArchiveSchema($pdo);

$id = (int) ($_POST['id'] ?? 0);
$companyId = auth_current_company_id();
$currentUserId = (int) (auth_current_user()['id'] ?? 0);

if ($id > 0) {
    $carSt = $pdo->prepare('SELECT * FROM cars WHERE id = ? AND company_id = ? AND archived_at IS NOT NULL LIMIT 1');
    $carSt->execute([$id, $companyId]);
    $car = $carSt->fetch(PDO::FETCH_ASSOC);

    if ($car) {
        $hasActiveRentalSt = $pdo->prepare('SELECT COUNT(*) FROM rentals WHERE car_id = ? AND company_id = ? AND completed = 0 AND archived_at IS NULL');
        $hasActiveRentalSt->execute([$id, $companyId]);
        $available = (int) $hasActiveRentalSt->fetchColumn() === 0 ? 1 : 0;

        $pdo->prepare('UPDATE cars SET archived_at = NULL, archived_by_user_id = NULL, archive_reason = NULL, available = ? WHERE id = ? AND company_id = ?')->execute([
            $available,
            $id,
            $companyId,
        ]);

        auth_audit_log($pdo, 'car.restored', 'Arac arsivden geri yuklendi.', [
            'entity_type' => 'car',
            'entity_id' => $id,
            'company_id' => $companyId,
            'metadata' => [
                'plate' => $car['plate'] ?? null,
                'restored_by_user_id' => $currentUserId > 0 ? $currentUserId : null,
            ],
        ]);

        redirect('../cars.php?status=restored');
    }
}

redirect('../cars.php?show_archived=1');
