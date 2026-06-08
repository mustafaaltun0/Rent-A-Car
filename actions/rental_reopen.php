<?php
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/helpers.php';

auth_require_permission('rentals.manage');
auth_require_post_request();
auth_validate_csrf_request();

ensureRentalArchiveSchema($pdo);
$companyId = auth_current_company_id();
$id = (int) ($_POST['id'] ?? 0);
if ($id > 0) {
    $st = $pdo->prepare('SELECT car_id FROM rentals WHERE id = ? AND company_id = ? AND archived_at IS NULL');
    $st->execute([$id, $companyId]);
    $carId = (int) ($st->fetchColumn() ?: 0);

    if ($carId > 0) {
        $activeCheck = $pdo->prepare('SELECT COUNT(*) FROM rentals WHERE car_id = ? AND completed = 0 AND id <> ? AND company_id = ? AND archived_at IS NULL');
        $activeCheck->execute([$carId, $id, $companyId]);
        if ((int) $activeCheck->fetchColumn() > 0) {
            redirect('../rentals.php?show_all=1&error=rental_reopen_conflict');
        }
    }

    $pdo->prepare('UPDATE rentals SET completed = 0 WHERE id = ? AND company_id = ? AND archived_at IS NULL')->execute([$id, $companyId]);

    if ($carId > 0) {
        $pdo->prepare('UPDATE cars SET available = 0 WHERE id = ? AND company_id = ?')->execute([$carId, $companyId]);
    }
}

redirect('../rentals.php');
