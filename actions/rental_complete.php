<?php
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/helpers.php';

auth_require_permission('rentals.manage');
auth_require_post_request();
auth_validate_csrf_request();

ensureRentalExtensionSchema($pdo);
ensureRentalArchiveSchema($pdo);
$companyId = auth_current_company_id();

$id = (int) ($_POST['id'] ?? 0);
$returnKmRaw = preg_replace('/\D+/', '', (string) ($_POST['return_km'] ?? ''));
$returnKm = $returnKmRaw !== '' ? max(0, (int) $returnKmRaw) : null;
if ($id > 0) {
    $st = $pdo->prepare('SELECT car_id, departure_km FROM rentals WHERE id = ? AND company_id = ? AND archived_at IS NULL');
    $st->execute([$id, $companyId]);
    $rental = $st->fetch() ?: [];
    $carId = (int) ($rental['car_id'] ?? 0);
    $departureKm = isset($rental['departure_km']) ? (int) $rental['departure_km'] : null;

    if ($returnKm === null || ($departureKm !== null && $returnKm < $departureKm)) {
        redirect('../rentals.php');
    }

    $pdo->prepare('UPDATE rentals SET completed = 1, return_km = ? WHERE id = ? AND company_id = ? AND archived_at IS NULL')->execute([$returnKm, $id, $companyId]);

    if ($carId > 0) {
        $pdo->prepare('UPDATE cars SET available = 1 WHERE id = ? AND company_id = ?')->execute([$carId, $companyId]);
    }
}

redirect('../rentals.php');
