<?php
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/helpers.php';

auth_require_permission('cars.manage');
auth_require_post_request();
auth_validate_csrf_request();

app_ensure_schema($pdo, 'car_sales', 'rental_archive');
$companyId = auth_current_company_id();
$currentUserId = (int) (auth_current_user()['id'] ?? 0);

$carId = (int) ($_POST['car_id'] ?? 0);
$buyerName = trim((string) ($_POST['buyer_name'] ?? ''));
$buyerPhone = trim((string) ($_POST['buyer_phone'] ?? ''));
$saleDateRaw = trim((string) ($_POST['sale_date'] ?? ''));
$saleDate = $saleDateRaw !== '' ? date('Y-m-d H:i:s', strtotime($saleDateRaw)) : date('Y-m-d H:i:s');
$totalAmount = ($_POST['total_amount'] ?? '') !== '' ? max(0.0, (float) $_POST['total_amount']) : 0.0;
$submittedCollectedAmount = trim((string) ($_POST['collected_amount'] ?? ''));
$collectedAmount = $submittedCollectedAmount === '' ? $totalAmount : min(max(0.0, (float) $submittedCollectedAmount), $totalAmount);
$paymentDueDateRaw = trim((string) ($_POST['payment_due_date'] ?? ''));
$paymentDueDate = $paymentDueDateRaw !== '' ? date('Y-m-d H:i:s', strtotime($paymentDueDateRaw)) : null;
$note = trim((string) ($_POST['note'] ?? ''));

if ($carId <= 0 || $buyerName === '' || $totalAmount <= 0.0) {
    redirect('../cars.php');
}

$carSt = $pdo->prepare('SELECT * FROM cars WHERE id = ? AND company_id = ? AND archived_at IS NULL LIMIT 1');
$carSt->execute([$carId, $companyId]);
$car = $carSt->fetch(PDO::FETCH_ASSOC);
if (!$car || car_is_sold($car)) {
    redirect('../car_detail.php?id=' . $carId . '&status=car_sale_invalid');
}

$activeRentalSt = $pdo->prepare('SELECT COUNT(*) FROM rentals WHERE car_id = ? AND company_id = ? AND completed = 0 AND archived_at IS NULL');
$activeRentalSt->execute([$carId, $companyId]);
if ((int) $activeRentalSt->fetchColumn() > 0) {
    redirect('../car_detail.php?id=' . $carId . '&status=car_sale_invalid');
}

$existingSaleSt = $pdo->prepare("SELECT id FROM car_sales WHERE company_id = ? AND car_id = ? AND sale_status = 'active' LIMIT 1");
$existingSaleSt->execute([$companyId, $carId]);
if ($existingSaleSt->fetchColumn()) {
    redirect('../car_detail.php?id=' . $carId);
}

$paymentStatus = 'collected';
if ($totalAmount > 0.0) {
    if ($collectedAmount <= 0.0001) {
        $paymentStatus = 'pending';
    } elseif ($collectedAmount + 0.0001 < $totalAmount) {
        $paymentStatus = 'partial';
    }
}
if ($paymentStatus === 'collected') {
    $paymentDueDate = null;
}

try {
    $pdo->beginTransaction();

    $saleInsert = $pdo->prepare("
        INSERT INTO car_sales (
            company_id, car_id, buyer_name, buyer_phone, sale_date, total_amount, payment_due_date,
            payment_status, sale_status, collected_at, collected_by_user_id, created_by_user_id, note
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'active', ?, ?, ?, ?)
    ");
    $saleInsert->execute([
        $companyId,
        $carId,
        $buyerName,
        $buyerPhone !== '' ? $buyerPhone : null,
        $saleDate,
        $totalAmount,
        $paymentDueDate,
        $paymentStatus,
        $collectedAmount > 0 ? $saleDate : null,
        $collectedAmount > 0 ? ($currentUserId > 0 ? $currentUserId : null) : null,
        $currentUserId > 0 ? $currentUserId : null,
        $note !== '' ? $note : null,
    ]);
    $saleId = (int) $pdo->lastInsertId();

    if ($collectedAmount > 0.0) {
        $collectionInsert = $pdo->prepare("
            INSERT INTO car_sale_collections (
                company_id, car_sale_id, amount, payment_method, note, collection_status, collected_at, collected_by_user_id
            ) VALUES (?, ?, ?, ?, ?, 'active', ?, ?)
        ");
        $collectionInsert->execute([
            $companyId,
            $saleId,
            $collectedAmount,
            'Satis Pesinat',
            $note !== '' ? $note : null,
            $saleDate,
            $currentUserId > 0 ? $currentUserId : null,
        ]);
    }

    $carUpdate = $pdo->prepare('UPDATE cars SET sold_at = ?, sold_by_user_id = ?, sale_note = ?, available = 0 WHERE id = ? AND company_id = ?');
    $carUpdate->execute([
        $saleDate,
        $currentUserId > 0 ? $currentUserId : null,
        $note !== '' ? $note : null,
        $carId,
        $companyId,
    ]);

    auth_audit_log($pdo, 'car.sold', 'Arac satis kaydi olusturuldu.', [
        'entity_type' => 'car_sale',
        'entity_id' => $saleId,
        'company_id' => $companyId,
        'metadata' => [
            'car_id' => $carId,
            'plate' => $car['plate'] ?? null,
            'buyer_name' => $buyerName,
            'total_amount' => $totalAmount,
            'collected_amount' => $collectedAmount,
            'payment_status' => $paymentStatus,
        ],
    ]);

    $pdo->commit();
} catch (Throwable $exception) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }

    error_log('car_sale_save_failed: ' . $exception->getMessage());
    redirect('../car_detail.php?id=' . $carId . '&status=car_sale_invalid');
}

redirect('../car_detail.php?id=' . $carId . '&status=car_sold');
